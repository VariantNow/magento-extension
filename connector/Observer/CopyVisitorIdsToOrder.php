<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Variant\Connector\Model\VisitorIds;

/**
 * Copies the Variant visitor and session ids the cart was last seen with onto the order, which
 * Variant reads back to match the purchase to the tests its shopper saw.
 *
 * A fieldset.xml mapping does not survive here: QuoteManagement merges the converted order
 * through OrderInterface's getters, which silently drops any field the interface does not
 * declare. This event hands over the quote and the order just before the order is saved.
 */
class CopyVisitorIdsToOrder implements ObserverInterface
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
        foreach (VisitorIds::COOKIE_FIELDS as $field) {
            $id = $quote->getData($field);
            // Not a truthiness test: "0" is a valid id.
            if ($id !== null && $id !== '') {
                $order->setData($field, $id);
            }
        }
    }
}
