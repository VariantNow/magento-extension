<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Observer\CopyVisitorIdsToOrder;

class CopyVisitorIdsToOrderTest extends TestCase
{
    /**
     * Run the observer on a quote and the order being placed from it.
     *
     * @param DataObject $quote
     * @param DataObject $order
     * @return void
     */
    private function copy(DataObject $quote, DataObject $order): void
    {
        (new CopyVisitorIdsToOrder())
            ->execute(new Observer(['event' => new Event(['quote' => $quote, 'order' => $order])]));
    }

    public function testTheCartsIdsAreCopiedOntoTheOrder(): void
    {
        $order = new DataObject();
        $this->copy(new DataObject(['variant_user_id' => 'user_a1', 'variant_session_id' => 's1']), $order);

        $this->assertSame('user_a1', $order->getData('variant_user_id'));
        $this->assertSame('s1', $order->getData('variant_session_id'));
    }

    public function testAZeroIdIsStillAnId(): void
    {
        $order = new DataObject();
        $this->copy(new DataObject(['variant_user_id' => '0', 'variant_session_id' => '0']), $order);

        $this->assertSame('0', $order->getData('variant_user_id'));
        $this->assertSame('0', $order->getData('variant_session_id'));
    }

    public function testACartNeverSeenByTheStorefrontLeavesTheOrderWithout(): void
    {
        // An admin or phone order: its shopper was never in a test.
        $order = new DataObject();
        $this->copy(new DataObject(), $order);

        $this->assertNull($order->getData('variant_user_id'));
        $this->assertNull($order->getData('variant_session_id'));
    }

    public function testNothingHappensWithoutAQuoteOrAnOrder(): void
    {
        $quote = new DataObject(['variant_user_id' => 'user_a1']);
        (new CopyVisitorIdsToOrder())->execute(new Observer(['event' => new Event(['quote' => $quote])]));

        $this->assertSame('user_a1', $quote->getData('variant_user_id'));
    }
}
