<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Model;

/**
 * Who the shopper is to Variant: the visitor and session ids Variant's storefront script keeps in
 * the `gb_anonymous_id` and `gb_session_id` cookies, which its experiment exposures are recorded
 * under. They are kept on the cart and copied to the order, so a purchase can be matched to the
 * tests its shopper saw, even when the order is placed or paid without the shopper's browser.
 */
class VisitorIds
{
    public const USER_COOKIE = 'gb_anonymous_id';
    public const SESSION_COOKIE = 'gb_session_id';
    public const USER_FIELD = 'variant_user_id';
    public const SESSION_FIELD = 'variant_session_id';
    /**
     * Set to `1` by the consent bridge while Variant's runtime allows analytics for this visitor
     * (always, with the shop's Cookie consent setting Off). Without it, purchases are not
     * attributed: with Gate tracking the visitor cookie exists before consent, to keep test
     * assignments sticky, and must not be used for attribution until then.
     */
    public const ATTRIBUTION_COOKIE = 'variant_attribution';
    /** Cookie => cart and order field. */
    public const COOKIE_FIELDS = [
        self::USER_COOKIE => self::USER_FIELD,
        self::SESSION_COOKIE => self::SESSION_FIELD,
    ];
    /**
     * `user_<uuid>` and a bare uuid today; any short token, so ids an older script wrote still
     * match. The cookie is the shopper's to edit: nothing else is stored.
     */
    private const ID = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Whether the attribution cookie allows matching this visitor's purchases to their tests.
     *
     * @param string|null $raw
     * @return bool
     */
    public function attributionAllowed(?string $raw): bool
    {
        return $raw === '1';
    }

    /**
     * The id a raw cookie value holds; null when it holds none.
     *
     * @param string|null $raw The cookie as the request carries it (URL-encoded).
     * @return string|null
     */
    public function parse(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the storefront writes it with encodeURIComponent
        $id = trim(rawurldecode($raw));
        return preg_match(self::ID, $id) ? $id : null;
    }
}
