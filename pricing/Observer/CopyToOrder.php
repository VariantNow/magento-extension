<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Variant\Pricing\Model\ActiveKeys;

/**
 * Copies the arms a cart was discounted and priced with, and each item's receipt and pricing
 * arm, onto the order.
 *
 * A fieldset.xml mapping does not survive here: QuoteManagement merges the converted order
 * through OrderInterface's getters, which silently drops any field the interface does not
 * declare. This event hands over the quote and the order just before the order is saved.
 */
class CopyToOrder implements ObserverInterface
{
    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        /** @var Quote|null $quote */
        $quote = $observer->getEvent()->getData('quote');
        /** @var Order|null $order */
        $order = $observer->getEvent()->getData('order');
        if (!$quote || !$order) {
            return;
        }
        $order->setData(ActiveKeys::FIELD, $quote->getData(ActiveKeys::FIELD));
        $order->setData(ActiveKeys::PRICE_FIELD, $quote->getData(ActiveKeys::PRICE_FIELD));
        foreach ($order->getAllItems() as $orderItem) {
            $quoteItem = $quote->getItemById($orderItem->getQuoteItemId());
            if (!$quoteItem) {
                continue;
            }
            foreach ([ActiveKeys::RECEIPT_FIELD, ActiveKeys::ITEM_PRICE_FIELD] as $field) {
                if ($quoteItem->getData($field)) {
                    $orderItem->setData($field, $quoteItem->getData($field));
                }
            }
        }
    }
}
