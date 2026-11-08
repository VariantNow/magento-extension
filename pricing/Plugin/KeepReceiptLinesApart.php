<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Cart;
use Magento\Framework\App\RequestInterface;
use Variant\Pricing\Model\ActiveKeys;
use Variant\Pricing\Model\Discount\Receipts;

/**
 * An add that carries a receipt (`variant_rec_token`: a recommendation's or a stamped product
 * form's) gets its own cart item. Without this Magento merges it into an item of the same
 * product already in the cart, and the receipt would then cover every unit of that item;
 * Shopify keeps a line with different properties apart in the same way. The receipt rides as
 * a custom option, which is what Magento compares before merging.
 */
class KeepReceiptLinesApart
{
    public const OPTION = 'variant_rec_token';

    /**
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Tag the product being added with the request's receipt.
     *
     * @param Cart $subject
     * @param mixed $productInfo
     * @param mixed $requestInfo
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAddProduct(Cart $subject, $productInfo, $requestInfo = null)
    {
        $raw = $this->request->getParam(ActiveKeys::RECEIPT_FIELD);
        if ($productInfo instanceof Product && is_string($raw) && strlen($raw) <= 255
            && Receipts::parse($raw) !== null) {
            $productInfo->addCustomOption(self::OPTION, $raw);
        }
        return null;
    }
}
