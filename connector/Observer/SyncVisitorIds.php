<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use Variant\Connector\Model\VisitorIds;

/**
 * Before totals are collected, copies the shopper's Variant visitor and session ids from their
 * cookies onto the cart.
 *
 * Magento collects totals on every cart change, on the cart page and at each checkout step, and
 * saves the cart right after, so the ids ride along in a write Magento makes anyway.
 *
 * - A request without the visitor cookie leaves the cart as it is: Magento also collects totals
 *   with no shopper's browser involved (payment callbacks, cron, price reindexes). Unlike the
 *   activated discount and price-test arms, a visitor id does not lapse.
 * - A shopper's request without analytics consent (no `variant_attribution` cookie, see
 *   VisitorIds::ATTRIBUTION_COOKIE) clears them: with Gate tracking the visitor cookie exists
 *   before consent, and a withdrawal must reach a cart that was stamped while it was allowed.
 * - The admin is skipped: staff who browsed the storefront carry its root-domain cookies there,
 *   and an order they create for a customer must not carry the staff member's tests.
 */
class SyncVisitorIds implements ObserverInterface
{
    /**
     * @param CookieManagerInterface $cookies
     * @param VisitorIds $visitorIds
     * @param State $state
     */
    public function __construct(
        private readonly CookieManagerInterface $cookies,
        private readonly VisitorIds $visitorIds,
        private readonly State $state
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        /** @var Quote|null $quote */
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote || $this->isAdmin()) {
            return;
        }
        if ($this->visitorIds->parse($this->cookies->getCookie(VisitorIds::USER_COOKIE)) === null) {
            return;
        }
        $allowed = $this->visitorIds->attributionAllowed(
            $this->cookies->getCookie(VisitorIds::ATTRIBUTION_COOKIE)
        );
        foreach (VisitorIds::COOKIE_FIELDS as $cookie => $field) {
            $id = $allowed ? $this->visitorIds->parse($this->cookies->getCookie($cookie)) : null;
            if ($allowed && $id === null) {
                continue;
            }
            if ($quote->getData($field) !== $id) {
                $quote->setData($field, $id);
            }
        }
    }

    /**
     * Whether this is an admin request, such as an order staff create for a customer.
     *
     * @return bool
     */
    private function isAdmin(): bool
    {
        try {
            return $this->state->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
