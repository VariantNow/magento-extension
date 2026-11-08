<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Plugin;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Quote\Discount;
use Psr\Log\LoggerInterface;
use Variant\Pricing\Model\ActiveKeys;
use Variant\Pricing\Model\BundleConfig;
use Variant\Pricing\Model\BundleTag;
use Variant\Pricing\Model\Discount\Bundles;
use Variant\Pricing\Model\Discount\Calculator;
use Variant\Pricing\Model\Discount\Line;
use Variant\Pricing\Model\DiscountConfig;

/**
 * Runs right after Magento's own discount collector and adds Variant's discounts — bundle
 * discounts, then the shopper's experiment discounts — to each item's ordinary discount fields.
 * Everything downstream — tax, the "Discount" total, invoices, refunds, payment line items,
 * order emails — already understands those, so Variant's discounts need no total, column or
 * template of their own.
 */
class ApplyExperimentDiscounts
{
    /**
     * @param DiscountConfig $config
     * @param ActiveKeys $activeKeys
     * @param Calculator $calculator
     * @param BundleConfig $bundleConfig
     * @param BundleTag $bundleTag
     * @param Bundles $bundles
     * @param PriceCurrencyInterface $priceCurrency
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly DiscountConfig $config,
        private readonly ActiveKeys $activeKeys,
        private readonly Calculator $calculator,
        private readonly BundleConfig $bundleConfig,
        private readonly BundleTag $bundleTag,
        private readonly Bundles $bundles,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Apply the cart's bundle discounts and the shopper's experiment discounts to this address's items.
     *
     * @param Discount $subject
     * @param Discount $result
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return Discount
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCollect(
        Discount $subject,
        $result,
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        // Totals are collected many times per request; never carry the last pass's offer names over.
        $shippingAssignment->getShipping()->getAddress()->setData(KeepDiscountDescription::MESSAGES, []);
        $items = $this->topLevelItems($shippingAssignment);
        if (!$items) {
            return $result;
        }
        $messages = [];
        // Bundles go first, so an experiment discount only takes what a bundle left.
        try {
            $bundles = $this->bundleConfig->load();
            if ($bundles) {
                $discounts = $this->bundles->calculate($bundles, $this->lines($items));
                $messages += $this->apply($discounts, $items, $quote, $total);
            }
        } catch (\Throwable $e) {
            // A broken bundle must never break the cart: log it and charge full price.
            $this->logger->error('Variant bundle discount skipped: ' . $e->getMessage());
        }
        $keys = $this->activeKeys->parse((string)$quote->getData(ActiveKeys::FIELD));
        try {
            $experiments = $keys ? $this->config->load() : [];
            if ($experiments) {
                $discounts = $this->calculator->calculate(
                    $experiments,
                    $keys,
                    $this->lines($items),
                    $this->config->receiptKeys()
                );
                $messages += $this->apply($discounts, $items, $quote, $total);
            }
        } catch (\Throwable $e) {
            // A broken experiment must never break the cart: log it and charge full price.
            $this->logger->error('Variant experiment discount skipped: ' . $e->getMessage());
        }
        if ($messages) {
            $this->describe(array_keys($messages), $shippingAssignment, $total);
        }
        return $result;
    }

    /**
     * The calculators' view of the items, with what is already discounted on each.
     *
     * @param AbstractItem[] $items
     * @return Line[]
     */
    private function lines(array $items): array
    {
        $lines = [];
        foreach ($items as $id => $item) {
            $lines[] = $this->line((string)$id, $item);
        }
        return $lines;
    }

    /**
     * Items Magento prices directly: configurable parents rather than their children.
     *
     * @param ShippingAssignmentInterface $shippingAssignment
     * @return AbstractItem[] keyed by a stable per-request id
     */
    private function topLevelItems(ShippingAssignmentInterface $shippingAssignment): array
    {
        $items = [];
        foreach ($shippingAssignment->getItems() as $index => $item) {
            if ($item->getParentItem() || ($item->getHasChildren() && $item->isChildrenCalculated())) {
                // Bundles priced per child are left alone rather than discounted twice.
                continue;
            }
            $items[(string)($item->getId() ?: 'new-' . $index)] = $item;
        }
        return $items;
    }

    /**
     * The calculator's view of one cart item, its children's product ids and SKUs included.
     *
     * @param string $id
     * @param AbstractItem $item
     * @return Line
     */
    private function line(string $id, AbstractItem $item): Line
    {
        $product = $item->getProduct();
        $refs = [(string)$item->getProductId(), (string)$item->getSku()];
        foreach ($item->getChildren() as $child) {
            $refs[] = (string)$child->getProductId();
            $refs[] = (string)$child->getSku();
        }
        if ($product) {
            $refs[] = (string)$product->getSku();
        }
        $categories = $product ? array_map('strval', (array)$product->getCategoryIds()) : [];
        $receipt = $item->getData(ActiveKeys::RECEIPT_FIELD);
        $bundle = $this->bundleTag->read($item);
        return new Line(
            $id,
            (int)$item->getQty(),
            (float)$item->getBaseRowTotal(),
            (string)$item->getProductId(),
            array_values(array_unique(array_filter($refs))),
            $categories,
            is_string($receipt) && $receipt !== '' ? $receipt : null,
            (float)$item->getBaseDiscountAmount(),
            false,
            $bundle['bundleId'] ?? null,
            $bundle['instanceId'] ?? null
        );
    }

    /**
     * Add the amounts to the items and the discount total.
     *
     * @param array $discounts
     * @param AbstractItem[] $items
     * @param Quote $quote
     * @param Total $total
     * @return array<string, true> the offer names applied
     */
    private function apply(array $discounts, array $items, Quote $quote, Total $total): array
    {
        $rate = (float)$quote->getBaseToQuoteRate() ?: 1.0;
        $messages = [];
        foreach ($discounts as $id => $discount) {
            $item = $items[$id];
            $base = (float)$discount['amount'];
            $amount = $this->priceCurrency->round($base * $rate);
            $item->setBaseDiscountAmount((float)$item->getBaseDiscountAmount() + $base);
            $item->setDiscountAmount((float)$item->getDiscountAmount() + $amount);
            $item->setBaseOriginalDiscountAmount((float)$item->getBaseOriginalDiscountAmount() + $base);
            $item->setOriginalDiscountAmount((float)$item->getOriginalDiscountAmount() + $amount);
            $total->addBaseTotalAmount('discount', -$base);
            $total->addTotalAmount('discount', -$amount);
            foreach ($discount['messages'] as $message) {
                $messages[$message] = true;
            }
        }
        return $messages;
    }

    /**
     * Name the offers in the discount description and carry the new totals onto the address.
     *
     * @param string[] $messages
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     */
    private function describe(array $messages, ShippingAssignmentInterface $shippingAssignment, Total $total): void
    {
        $total->setDiscountDescription(
            KeepDiscountDescription::withMessages((string)$total->getDiscountDescription(), $messages)
        );
        $total->setSubtotalWithDiscount($total->getSubtotal() + $total->getDiscountAmount());
        $total->setBaseSubtotalWithDiscount($total->getBaseSubtotal() + $total->getBaseDiscountAmount());

        $address = $shippingAssignment->getShipping()->getAddress();
        $address->setData(KeepDiscountDescription::MESSAGES, $messages);
        $address->setDiscountDescription($total->getDiscountDescription());
        $address->setDiscountAmount($total->getDiscountAmount());
        $address->setBaseDiscountAmount($total->getBaseDiscountAmount());
        $address->setSubtotalWithDiscount($total->getSubtotal() + $total->getDiscountAmount());
        $address->setBaseSubtotalWithDiscount($total->getBaseSubtotal() + $total->getBaseDiscountAmount());
    }
}
