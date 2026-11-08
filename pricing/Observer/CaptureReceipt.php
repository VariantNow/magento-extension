<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item;
use Variant\Pricing\Model\ActiveKeys;
use Variant\Pricing\Model\Discount\Receipts;

/**
 * Keeps the receipt a Variant recommendation or checkout card sent with an add to cart
 * (`variant_rec_token`) on the cart item — Magento's counterpart of Shopify's
 * `_variant_rec_token` line property. It is only a claim: the discount verifies it against
 * the item's real product before it counts.
 */
class CaptureReceipt implements ObserverInterface
{
    /**
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        $raw = $this->request->getParam(ActiveKeys::RECEIPT_FIELD);
        if (!is_string($raw) || strlen($raw) > 255 || Receipts::parse($raw) === null) {
            return;
        }
        /** @var Item|null $item */
        $item = $observer->getEvent()->getData('quote_item');
        if (!$item) {
            return;
        }
        $item = $item->getParentItem() ?: $item;
        $item->setData(ActiveKeys::RECEIPT_FIELD, $raw);
    }
}
