<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;

/**
 * What the page being rendered is, for experiment targeting: Variant's storefront script reads
 * it off the config tag, the way a Shopify theme tells it from Liquid. A Magento URL
 * (/radiant-tee.html) says neither the page type nor the product.
 *
 * Handles are what the editor's pickers save: a product's URL key (its SKU without one), a
 * category's URL key (its id without one), both at the default scope, which is what Variant reads
 * through the store's REST API; a store view's own URL key would never match a saved rule. A
 * product page also lists its categories, for "product is in collection" rules.
 */
class PageContext
{
    /**
     * Magento's full action names, as the page types targeting rules use.
     */
    private const PAGE_TYPES = [
        'cms_index_index' => 'home',
        'catalog_product_view' => 'product',
        'catalog_category_view' => 'collection',
        'checkout_cart_index' => 'cart',
        'checkout_index_index' => 'checkout',
        'catalogsearch_result_index' => 'search',
        'catalogsearch_advanced_result' => 'search',
        'cms_page_view' => 'page',
    ];

    /**
     * Action prefixes of the "My Account" area, whichever module owns the page.
     */
    private const ACCOUNT_PREFIXES = [
        'customer_',
        'sales_order_',
        'sales_guest_',
        'wishlist_',
        'review_customer_',
        'newsletter_manage_',
        'downloadable_customer_',
        'vault_cards_',
        'paypal_billing_agreement_',
    ];

    /**
     * The default (all store views) scope, which the store's REST API answers in.
     */
    private const DEFAULT_SCOPE = 0;

    /**
     * Below this level a category is Magento's root or a store's root, never shown as a collection.
     */
    private const FIRST_LISTED_LEVEL = 2;

    /**
     * @param RequestInterface $request
     * @param Registry $registry
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Registry $registry
    ) {
    }

    /**
     * The config tag's attributes for this page.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $type = $this->pageType();
        $attributes = ['data-variant-page-type' => $type];
        if ($type === 'product') {
            $product = $this->registry->registry('current_product');
            if ($product instanceof Product) {
                $urlKey = $product->getResource()
                    ->getAttributeRawValue((int)$product->getId(), 'url_key', self::DEFAULT_SCOPE);
                $attributes['data-variant-page-handle'] = (string)($urlKey ?: $product->getSku());
                $attributes['data-variant-product-collections'] = implode(',', $this->categoryHandles($product));
            }
        } elseif ($type === 'collection') {
            $category = $this->registry->registry('current_category');
            if ($category instanceof Category) {
                $attributes['data-variant-page-handle'] = $this->categoryHandle($category, false);
            }
        }
        return $attributes;
    }

    /**
     * The page type targeting rules know this action by; `other` for the rest.
     *
     * @return string
     */
    private function pageType(): string
    {
        $action = $this->request instanceof Http ? (string)$this->request->getFullActionName() : '';
        if (isset(self::PAGE_TYPES[$action])) {
            return self::PAGE_TYPES[$action];
        }
        foreach (self::ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return 'account';
            }
        }
        return 'other';
    }

    /**
     * The handles of the categories the product is in, store roots left out.
     *
     * @param Product $product
     * @return string[]
     */
    private function categoryHandles(Product $product): array
    {
        $handles = [];
        $categories = $product->getCategoryCollection()
            ->setStoreId(self::DEFAULT_SCOPE)
            ->addAttributeToSelect('url_key');
        foreach ($categories as $category) {
            if ((int)$category->getLevel() >= self::FIRST_LISTED_LEVEL) {
                $handles[] = $this->categoryHandle($category);
            }
        }
        return array_values(array_unique($handles));
    }

    /**
     * A category's handle: its URL key, or its id without one.
     *
     * A category of the default-scope collection already holds that URL key; the page's own
     * category is read at that scope.
     *
     * @param Category $category
     * @param bool $defaultScope Whether $category was loaded at the default scope.
     * @return string
     */
    private function categoryHandle(Category $category, bool $defaultScope = true): string
    {
        $urlKey = $defaultScope
            ? $category->getUrlKey()
            : $category->getResource()
                ->getAttributeRawValue((int)$category->getId(), 'url_key', self::DEFAULT_SCOPE);
        return (string)($urlKey ?: $category->getId());
    }
}
