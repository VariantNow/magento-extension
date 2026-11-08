<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Controller\Bundle;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Variant\Pricing\Model\BundleConfig;
use Variant\Pricing\Model\BundleTag;

/**
 * POST /variant/bundle/add — adds every item of one Variant bundle to the cart in one request:
 * Magento's counterpart of the single `/cart/add.js` call with an `items` array that Shopify
 * storefronts make. Each item goes through Magento's own add-to-cart (the cart model the
 * Add to Cart button uses), so stock, salable quantity, product options, price and the
 * store's own add-to-cart plugins and events all apply as they do there.
 *
 * All or nothing: the cart is saved only once every item was added, so a failed item leaves
 * the cart as it was. Each item is tagged with the bundle and this add (see BundleTag).
 *
 * Parameters: `form_key`, and `bundle`, JSON:
 * {"bundle_id": uuid, "instance_id": "…", "items": [{"product_id": 12, "variant_id": 34, "quantity": 1}]}
 * `variant_id` is a configurable's child, as the bundle's storefront data lists it.
 */
class Add implements HttpPostActionInterface
{
    private const MAX_ITEMS = 50;
    private const MAX_QTY = 10000;
    private const INSTANCE_ID = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param JsonFactory $jsonFactory
     * @param Cart $cart
     * @param ProductRepositoryInterface $products
     * @param StoreManagerInterface $storeManager
     * @param ManagerInterface $events
     * @param BundleConfig $bundleConfig
     * @param BundleTag $bundleTag
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly JsonFactory $jsonFactory,
        private readonly Cart $cart,
        private readonly ProductRepositoryInterface $products,
        private readonly StoreManagerInterface $storeManager,
        private readonly ManagerInterface $events,
        private readonly BundleConfig $bundleConfig,
        private readonly BundleTag $bundleTag,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $input = json_decode((string)$this->request->getParam('bundle'), true);
        try {
            [$bundleId, $instanceId, $items] = $this->validate($input);
        } catch (LocalizedException $e) {
            return $this->error(400, $e->getMessage());
        }
        $bundle = $this->bundleConfig->bundle($bundleId);
        if ($bundle === null) {
            return $this->error(404, (string)__('Bundle %1 is not published to this store.', $bundleId));
        }
        $name = is_string($bundle['n'] ?? null) ? $bundle['n'] : '';

        $added = [];
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
            foreach ($items as $position => $item) {
                $product = $this->product($item['product_id'], $storeId);
                $tag = $this->bundleTag->encode($bundleId, $instanceId, $name, $position);
                $product->addCustomOption(BundleTag::OPTION, $tag);
                $params = ['qty' => $item['quantity'], BundleTag::OPTION => json_decode($tag, true)];
                if ($item['variant_id'] !== null && $item['variant_id'] !== $item['product_id']) {
                    $params['super_attribute'] = $this->superAttributes($product, $item['variant_id']);
                }
                $this->cart->addProduct($product, $params);
                $added[] = ['product' => $product, 'item' => $item];
            }
            $this->cart->save();
        } catch (LocalizedException $e) {
            // Nothing was saved: the cart stays exactly as it was before this add.
            return $this->error(400, $e->getMessage(), $item['product_id'] ?? null);
        } catch (\Throwable $e) {
            $this->logger->error('Variant bundle add failed: ' . $e->getMessage());
            return $this->error(500, (string)__('The bundle could not be added to the cart.'));
        }

        foreach ($added as $entry) {
            $this->events->dispatch(
                'checkout_cart_add_product_complete',
                ['product' => $entry['product'], 'request' => $this->request, 'response' => $this->response]
            );
        }
        return $this->jsonFactory->create()->setData([
            'success' => true,
            'bundle_id' => $bundleId,
            'instance_id' => $instanceId,
            'items' => array_map(
                fn ($entry) => [
                    'product_id' => $entry['item']['product_id'],
                    'variant_id' => $entry['item']['variant_id'],
                    'sku' => (string)$entry['product']->getSku(),
                    'quantity' => $entry['item']['quantity'],
                ],
                $added
            ),
            'cart_quantity' => (float)$this->cart->getQuote()->getItemsQty(),
        ]);
    }

    /**
     * The request as [bundleId, instanceId, items], each item's ids and quantity as integers.
     *
     * @param mixed $input
     * @return array
     * @throws LocalizedException
     */
    private function validate($input): array
    {
        if (!is_array($input)) {
            throw new LocalizedException(__('"bundle" must be a JSON object.'));
        }
        $bundleId = $input['bundle_id'] ?? null;
        $instanceId = $input['instance_id'] ?? null;
        $items = $input['items'] ?? null;
        if (!is_string($bundleId) || $bundleId === '') {
            throw new LocalizedException(__('"bundle_id" is required.'));
        }
        if (!is_string($instanceId) || !preg_match(self::INSTANCE_ID, $instanceId)) {
            throw new LocalizedException(__('"instance_id" must be 1–64 letters, digits, "_", ".", ":" or "-".'));
        }
        if (!is_array($items) || !$items || count($items) > self::MAX_ITEMS || !array_is_list($items)) {
            throw new LocalizedException(__('"items" must list 1–%1 items.', self::MAX_ITEMS));
        }
        $clean = [];
        foreach ($items as $item) {
            $productId = $this->positiveInt($item['product_id'] ?? null);
            $variantId = isset($item['variant_id']) ? $this->positiveInt($item['variant_id']) : null;
            $quantity = $this->positiveInt($item['quantity'] ?? 1);
            if ($productId === null || (isset($item['variant_id']) && $variantId === null)
                || $quantity === null || $quantity > self::MAX_QTY) {
                throw new LocalizedException(
                    __('Each item needs a numeric "product_id", an optional "variant_id" and a quantity.')
                );
            }
            $clean[] = ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $quantity];
        }
        return [$bundleId, $instanceId, $clean];
    }

    /**
     * A fresh product instance, so one item's tag never leaks onto another item of the same product.
     *
     * @param int $productId
     * @param int $storeId
     * @return ProductInterface
     * @throws LocalizedException
     */
    private function product(int $productId, int $storeId): ProductInterface
    {
        try {
            return $this->products->getById($productId, false, $storeId, true);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Product %1 does not exist.', $productId));
        }
    }

    /**
     * The configurable's options that select this child, as Add to Cart's `super_attribute`.
     *
     * @param ProductInterface $product
     * @param int $childId
     * @return array<string, string>
     * @throws LocalizedException
     */
    private function superAttributes(ProductInterface $product, int $childId): array
    {
        if ($product->getTypeId() !== 'configurable') {
            throw new LocalizedException(__('Product %1 has no variant %2.', $product->getId(), $childId));
        }
        $type = $product->getTypeInstance();
        $child = null;
        foreach ($type->getUsedProducts($product) as $used) {
            if ((int)$used->getId() === $childId) {
                $child = $used;
                break;
            }
        }
        if ($child === null) {
            throw new LocalizedException(__('Product %1 has no variant %2.', $product->getId(), $childId));
        }
        $selection = [];
        foreach ($type->getConfigurableAttributes($product) as $attribute) {
            $code = $attribute->getProductAttribute()->getAttributeCode();
            $selection[(string)$attribute->getAttributeId()] = (string)$child->getData($code);
        }
        return $selection;
    }

    /**
     * The value as a positive integer, or null when it is not one.
     *
     * @param mixed $value
     * @return int|null
     */
    private function positiveInt($value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $int = (int)$value;
            return $int > 0 ? $int : null;
        }
        return null;
    }

    /**
     * A JSON error response.
     *
     * @param int $status
     * @param string $message
     * @param int|null $productId the item that failed, when one did
     * @return Json
     */
    private function error(int $status, string $message, ?int $productId = null): Json
    {
        return $this->jsonFactory->create()
            ->setHttpResponseCode($status)
            ->setData(array_filter(
                ['success' => false, 'error' => $message, 'product_id' => $productId],
                fn ($v) => $v !== null
            ));
    }
}
