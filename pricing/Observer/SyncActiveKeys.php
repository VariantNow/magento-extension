<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use Variant\Pricing\Model\ActiveKeys;

/**
 * Before totals are collected, copies the shopper's `variant_discounts` and `variant_prices`
 * cookies onto the cart.
 *
 * The cart, not the cookie, is what the discount and the price test read: Magento also
 * recollects totals with no browser involved (REST checkouts, admin orders, price reindexes),
 * and those keep the arms the shopper activated. A request without a cookie leaves its field
 * on the cart as it is, except a storefront page request: a page always carries the cookie
 * while it is set, so its absence there means the activation lapsed (the cookie expired) and
 * a long-lived cart, such as a signed-in customer's, must stop applying the old arms.
 */
class SyncActiveKeys implements ObserverInterface
{
    /**
     * @param CookieManagerInterface $cookies
     * @param ActiveKeys $activeKeys
     * @param State $state
     */
    public function __construct(
        private readonly CookieManagerInterface $cookies,
        private readonly ActiveKeys $activeKeys,
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
        if (!$quote) {
            return;
        }
        foreach (ActiveKeys::COOKIE_FIELDS as $cookie => $field) {
            $raw = $this->cookies->getCookie($cookie);
            if ($raw === null) {
                if ($this->isStorefrontPage() && $quote->getData($field) !== null) {
                    $quote->setData($field, null);
                }
                continue;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the storefront writes it with encodeURIComponent
            $value = $this->activeKeys->format($this->activeKeys->parse(rawurldecode($raw)));
            if ($quote->getData($field) !== $value) {
                $quote->setData($field, $value);
            }
        }
    }

    /**
     * Whether this is a storefront page request, as opposed to REST, admin or cron.
     *
     * @return bool
     */
    private function isStorefrontPage(): bool
    {
        try {
            return $this->state->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
