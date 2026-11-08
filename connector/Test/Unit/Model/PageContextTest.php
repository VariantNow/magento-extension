<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Model;

use ArrayIterator;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\PageContext;

class PageContextTest extends TestCase
{
    /**
     * A PageContext on a request for $action, with $registered in the registry.
     *
     * @param string $action
     * @param array<string, object> $registered
     * @return PageContext
     */
    private function context(string $action, array $registered = []): PageContext
    {
        $request = $this->createMock(Http::class);
        $request->method('getFullActionName')->willReturn($action);
        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->willReturnCallback(static fn ($key) => $registered[$key] ?? null);
        return new PageContext($request, $registry);
    }

    /**
     * A category double.
     *
     * @param int $id
     * @param string|null $urlKey
     * @param int $level
     * @return Category
     */
    private function category(int $id, ?string $urlKey, int $level = 2): Category
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getUrlKey')->willReturn($urlKey);
        $category->method('getLevel')->willReturn($level);
        return $category;
    }

    /**
     * A product double whose default-scope URL key is $urlKey (false: none).
     *
     * @param int $id
     * @param string|false $urlKey
     * @param string $sku
     * @param Collection $categories
     * @return Product
     */
    private function product(int $id, $urlKey, string $sku, Collection $categories): Product
    {
        $resource = $this->createMock(ProductResource::class);
        $resource->method('getAttributeRawValue')->with($id, 'url_key', 0)->willReturn($urlKey);
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getResource')->willReturn($resource);
        $product->method('getCategoryCollection')->willReturn($categories);
        return $product;
    }

    public function testNamesAProductPageByUrlKeyWithItsCategories(): void
    {
        $categories = $this->createMock(Collection::class);
        $categories->method('setStoreId')->willReturnSelf();
        $categories->method('addAttributeToSelect')->willReturnSelf();
        $categories->method('getIterator')->willReturn(new ArrayIterator([
            $this->category(2, 'default-category', 1),
            $this->category(20, 'women'),
            $this->category(25, 'tops-women', 3),
            $this->category(31, null, 3),
        ]));
        $product = $this->product(1812, 'breathe-easy-tank', 'MT07', $categories);

        $this->assertSame(
            [
                'data-variant-page-type' => 'product',
                'data-variant-page-handle' => 'breathe-easy-tank',
                'data-variant-product-collections' => 'women,tops-women,31',
            ],
            $this->context('catalog_product_view', ['current_product' => $product])->attributes()
        );
    }

    public function testFallsBackToTheSkuForAProductWithoutAUrlKey(): void
    {
        $categories = $this->createMock(Collection::class);
        $categories->method('setStoreId')->willReturnSelf();
        $categories->method('addAttributeToSelect')->willReturnSelf();
        $categories->method('getIterator')->willReturn(new ArrayIterator([]));
        $product = $this->product(1812, false, 'MT07', $categories);

        $attributes = $this->context('catalog_product_view', ['current_product' => $product])->attributes();

        $this->assertSame('MT07', $attributes['data-variant-page-handle']);
        $this->assertSame('', $attributes['data-variant-product-collections']);
    }

    public function testNamesACategoryPageByItsDefaultScopeUrlKey(): void
    {
        // The store view calls it "hauts-femmes"; the editor saved the default-scope key.
        $category = $this->category(25, 'hauts-femmes', 3);
        $resource = $this->createMock(CategoryResource::class);
        $resource->method('getAttributeRawValue')->with(25, 'url_key', 0)->willReturn('tops-women');
        $category->method('getResource')->willReturn($resource);

        $this->assertSame(
            ['data-variant-page-type' => 'collection', 'data-variant-page-handle' => 'tops-women'],
            $this->context('catalog_category_view', ['current_category' => $category])->attributes()
        );
    }

    public function testMapsOtherPagesToTheirTargetingType(): void
    {
        $types = [
            'cms_index_index' => 'home',
            'checkout_cart_index' => 'cart',
            'checkout_index_index' => 'checkout',
            'catalogsearch_result_index' => 'search',
            'cms_page_view' => 'page',
            'customer_account_index' => 'account',
            'sales_order_history' => 'account',
            'wishlist_index_index' => 'account',
            'review_customer_index' => 'account',
            'contact_index_index' => 'other',
        ];
        foreach ($types as $action => $type) {
            $this->assertSame(['data-variant-page-type' => $type], $this->context($action)->attributes(), $action);
        }
    }
}
