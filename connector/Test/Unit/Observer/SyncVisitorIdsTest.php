<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Connector\Test\Unit\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;
use Variant\Connector\Model\VisitorIds;
use Variant\Connector\Observer\SyncVisitorIds;

class SyncVisitorIdsTest extends TestCase
{
    private const ALLOWED = ['variant_attribution' => '1'];

    /**
     * Run the observer on `$quote` for a request carrying `$cookies`, in `$area`.
     *
     * @param DataObject $quote
     * @param array<string, string> $cookies
     * @param string|null $area null: no area set yet
     * @return void
     */
    private function sync(DataObject $quote, array $cookies, ?string $area = Area::AREA_FRONTEND): void
    {
        $manager = $this->createMock(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(
            static fn ($name) => $cookies[$name] ?? null
        );
        $state = $this->createMock(State::class);
        if ($area === null) {
            $state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }
        (new SyncVisitorIds($manager, new VisitorIds(), $state))
            ->execute(new Observer(['event' => new Event(['quote' => $quote])]));
    }

    public function testTheCookiesAreCopiedOntoTheCart(): void
    {
        $quote = new DataObject();
        $this->sync($quote, self::ALLOWED + ['gb_anonymous_id' => 'user_a1', 'gb_session_id' => 's1']);

        $this->assertSame('user_a1', $quote->getData('variant_user_id'));
        $this->assertSame('s1', $quote->getData('variant_session_id'));
    }

    public function testTheCheckoutsRestCallsCountToo(): void
    {
        $quote = new DataObject();
        $this->sync($quote, self::ALLOWED + ['gb_anonymous_id' => 'user_a1'], Area::AREA_WEBAPI_REST);

        $this->assertSame('user_a1', $quote->getData('variant_user_id'));
    }

    public function testANewerVisitOverwritesTheIds(): void
    {
        $quote = new DataObject(['variant_user_id' => 'user_old', 'variant_session_id' => 's_old']);
        $this->sync($quote, self::ALLOWED + ['gb_anonymous_id' => 'user_new', 'gb_session_id' => 's_new']);

        $this->assertSame('user_new', $quote->getData('variant_user_id'));
        $this->assertSame('s_new', $quote->getData('variant_session_id'));
    }

    public function testARequestWithoutTheShoppersCookiesKeepsTheIds(): void
    {
        // A payment callback or a cron recollect carries no shopper cookies.
        $quote = new DataObject(['variant_user_id' => 'user_a1', 'variant_session_id' => 's1']);
        $this->sync($quote, []);
        $this->sync($quote, [], null);

        $this->assertSame('user_a1', $quote->getData('variant_user_id'));
        $this->assertSame('s1', $quote->getData('variant_session_id'));
    }

    public function testWithoutAnalyticsConsentNothingIsKept(): void
    {
        // Gate tracking: the visitor cookie exists before consent, to keep assignments sticky.
        $quote = new DataObject();
        $this->sync($quote, ['gb_anonymous_id' => 'user_a1', 'gb_session_id' => 's1']);

        $this->assertNull($quote->getData('variant_user_id'));
        $this->assertNull($quote->getData('variant_session_id'));
    }

    public function testAWithdrawalClearsIdsStampedWhileItWasAllowed(): void
    {
        $quote = new DataObject(['variant_user_id' => 'user_a1', 'variant_session_id' => 's1']);
        $this->sync($quote, ['gb_anonymous_id' => 'user_a1', 'gb_session_id' => 's1', 'variant_attribution' => '']);

        $this->assertNull($quote->getData('variant_user_id'));
        $this->assertNull($quote->getData('variant_session_id'));
    }

    public function testAnAdminOrderNeverTakesTheStaffMembersIds(): void
    {
        $quote = new DataObject();
        $this->sync(
            $quote,
            self::ALLOWED + ['gb_anonymous_id' => 'user_staff', 'gb_session_id' => 's_staff'],
            Area::AREA_ADMINHTML
        );

        $this->assertNull($quote->getData('variant_user_id'));
        $this->assertNull($quote->getData('variant_session_id'));
    }

    public function testAMalformedCookieIsIgnored(): void
    {
        $quote = new DataObject(['variant_user_id' => 'user_a1', 'variant_session_id' => 's1']);
        $this->sync($quote, self::ALLOWED + ['gb_anonymous_id' => 'user_a2', 'gb_session_id' => '<script>']);

        $this->assertSame('user_a2', $quote->getData('variant_user_id'));
        $this->assertSame('s1', $quote->getData('variant_session_id'));
    }

    public function testAnUnchangedCartIsNotTouched(): void
    {
        $quote = $this->getMockBuilder(DataObject::class)->onlyMethods(['getData', 'setData'])->getMock();
        $quote->method('getData')->willReturnCallback(
            static fn ($key) => ['variant_user_id' => 'user_a1', 'variant_session_id' => 's1'][$key] ?? null
        );
        // Leaves the cart unchanged, so Magento has nothing extra to save.
        $quote->expects($this->never())->method('setData');

        $this->sync($quote, self::ALLOWED + ['gb_anonymous_id' => 'user_a1', 'gb_session_id' => 's1']);
    }
}
