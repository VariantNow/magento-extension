<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;
use Variant\Pricing\Model\ActiveKeys;
use Variant\Pricing\Model\PriceTest\Resolver;
use Variant\Pricing\Model\PriceTestConfig;

/**
 * Before totals are collected, gives each cart item its price-test price — what Shopify's Cart
 * Transform does with a line update. The price goes in as the item's custom price, which
 * Magento's own totals use everywhere after: subtotal, tax, discounts (experiment ones
 * included), the order, invoices and refunds.
 *
 * The item remembers which arm priced it, so the custom price is lifted again once the arm is
 * no longer active or no longer prices it. A custom price Variant did not set, such as one an
 * admin typed on an order, is never touched.
 */
class ApplyPriceTests implements ObserverInterface
{
    /**
     * @param PriceTestConfig $config
     * @param ActiveKeys $activeKeys
     * @param Resolver $resolver
     * @param PriceCurrencyInterface $priceCurrency
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PriceTestConfig $config,
        private readonly ActiveKeys $activeKeys,
        private readonly Resolver $resolver,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        /** @var Quote|null $quote */
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote) {
            return;
        }
        try {
            $keys = $this->activeKeys->parse((string)$quote->getData(ActiveKeys::PRICE_FIELD));
            $rules = $keys ? $this->resolver->liveRules($keys, $this->config->load()) : [];
            foreach ($quote->getAllVisibleItems() as $item) {
                $this->price($item, $rules, $quote);
            }
        } catch (\Throwable $e) {
            // A broken price test must never break the cart: the items keep their own prices.
            $this->logger->error('Variant price test skipped: ' . $e->getMessage());
        }
    }

    /**
     * Set, or lift, one item's price-test price.
     *
     * @param Item $item
     * @param array $rules
     * @param Quote $quote
     */
    private function price(Item $item, array $rules, Quote $quote): void
    {
        $product = $item->getProduct();
        $resolved = $rules && $product && $product->getTypeId() !== 'bundle'
            ? $this->resolver->resolve(
                $rules,
                (string)$item->getProductId(),
                $this->variantId($item),
                $this->variantTitle($item)
            )
            : null;

        if ($resolved === null) {
            if ($item->getData(ActiveKeys::ITEM_PRICE_FIELD)) {
                $item->setCustomPrice(null);
                $item->setOriginalCustomPrice(null);
                $item->setData(ActiveKeys::ITEM_PRICE_FIELD, null);
            }
            return;
        }
        if ($item->getCustomPrice() !== null && !$item->getData(ActiveKeys::ITEM_PRICE_FIELD)) {
            return;
        }

        // The item's own price, in the base currency rules are written in: special prices,
        // catalog price rules and, for a configurable, the chosen child's price included.
        $original = (float)$product->getPriceModel()->getFinalPrice($item->getQty(), $product);
        $base = $this->resolver->price($original, $resolved);
        $price = $this->priceCurrency->convertAndRound($base, $quote->getStore());
        $item->setCustomPrice($price);
        $item->setOriginalCustomPrice($price);
        $product->setIsSuperMode(true);
        $item->setData(ActiveKeys::ITEM_PRICE_FIELD, $resolved['key']);
    }

    /**
     * The variant a rule's `vr` names: a configurable's chosen child, else the product itself.
     *
     * @param Item $item
     * @return string
     */
    private function variantId(Item $item): string
    {
        foreach ($item->getChildren() as $child) {
            return (string)$child->getProductId();
        }
        return (string)$item->getProductId();
    }

    /**
     * The chosen options' values joined with " / ", the title the catalog gives the variant.
     *
     * @param Item $item
     * @return string
     */
    private function variantTitle(Item $item): string
    {
        $product = $item->getProduct();
        if ($product->getTypeId() !== 'configurable') {
            return 'Default Title';
        }
        $values = array_map(
            fn ($option) => (string)($option['value'] ?? ''),
            (array)$product->getTypeInstance()->getSelectedAttributesInfo($product)
        );
        return implode(' / ', $values);
    }
}
