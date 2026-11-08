<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Plugin;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\ShippingAssignment;
use Magento\SalesRule\Model\Quote\Address\Total\ShippingDiscount;

/**
 * Magento's shipping-discount collector runs after the discount collector and, once a
 * shipping method is chosen, rebuilds the discount description from Magento's own rules.
 * That would drop the experiment offers' names from "Discount (…)" at checkout and on the
 * order, so they are put back here. The amounts are never touched.
 */
class KeepDiscountDescription
{
    public const MESSAGES = 'variant_discount_messages';

    /**
     * Add the experiment offers' names back to the rebuilt discount description.
     *
     * @param ShippingDiscount $subject
     * @param ShippingDiscount $result
     * @param Quote $quote
     * @param ShippingAssignment $shippingAssignment
     * @param Total $total
     * @return ShippingDiscount
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCollect(
        ShippingDiscount $subject,
        $result,
        Quote $quote,
        ShippingAssignment $shippingAssignment,
        Total $total
    ) {
        $address = $shippingAssignment->getShipping()->getAddress();
        $messages = (array)$address->getData(self::MESSAGES);
        if (!$messages) {
            return $result;
        }
        $description = self::withMessages((string)$total->getDiscountDescription(), $messages);
        $total->setDiscountDescription($description);
        $address->setDiscountDescription($description);
        return $result;
    }

    /**
     * The description with each offer name it lacks appended, comma-separated.
     *
     * Offer names may contain commas themselves, so whole names are looked for, never split.
     *
     * @param string $description
     * @param array $messages
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- pure string helper shared by both discount plugins
    public static function withMessages(string $description, array $messages): string
    {
        $description = trim($description);
        foreach (array_unique(array_filter(array_map('strval', $messages))) as $message) {
            if (strpos(', ' . $description . ', ', ', ' . $message . ', ') === false) {
                $description = $description === '' ? $message : $description . ', ' . $message;
            }
        }
        return $description;
    }
}
