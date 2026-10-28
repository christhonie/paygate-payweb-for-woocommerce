<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Neither the cron nor the admin query action can set a paid order to Failed.
 */
final class QueryStatusTest extends TestCase
{
    protected function setUp(): void
    {
        World::reset();
    }

    public static function statuses(): array
    {
        return ['not done' => ['0'], 'declined' => ['2'], 'user cancelled' => ['4'], 'no answer' => ['Null']];
    }

    #[DataProvider('statuses')]
    public function testCronLeavesPaidOrder(string $status): void
    {
        $order = World::addOrder(FakeOrder::paid(1));

        WC_Gateway_PayGate_Cron::updateOrderStatus($status, $order, '');

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
    }

    /**
     * Notify paid the order while the cron's PayWeb query ran. The cron still holds the copy
     * from the order list it loaded at the start, which says Pending; it must re-read the
     * order from storage and leave it paid. The query typically answers for the oldest,
     * abandoned pay request, so "not done" or "cancelled" is the likely answer here.
     */
    #[DataProvider('statuses')]
    public function testCronRereadsAnOrderPaidDuringTheQuery(string $status): void
    {
        $stale  = new FakeOrder(1, 'pending');
        $stored = World::addOrder(FakeOrder::paid(1));

        $text = WC_Gateway_PayGate_Cron::updateOrderStatus($status, $stale, '');

        self::assertSame('completed', $stored->get_status());
        self::assertSame([], $stored->statusHistory);
        self::assertSame([], $stale->statusHistory, 'the stale copy must not be written');
        self::assertSame([1], World::$cacheCleared);
        self::assertStringContainsString('already paid', $text);
    }

    public function testCronSetsUnpaidOrderProcessingOnApproval(): void
    {
        $order = World::addOrder(new FakeOrder(1, 'pending'));

        WC_Gateway_PayGate_Cron::updateOrderStatus('1', $order, '');

        self::assertSame('processing', $order->get_status());
    }

    #[DataProvider('statuses')]
    public function testAdminQueryLeavesPaidOrder(string $status): void
    {
        $order = World::addOrder(FakeOrder::paid(1));

        $text = WC_Gateway_PayGate_Admin_Actions::applyQueryStatus($order, $status, '');

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
        self::assertStringContainsString('already paid', $text);
    }

    /**
     * Notify paid the order while the PayWeb query ran. The admin action still holds the copy
     * it loaded before the query, which says Pending; it must re-read the order from storage
     * and leave it paid.
     */
    #[DataProvider('statuses')]
    public function testAdminQueryRereadsAnOrderPaidDuringTheQuery(string $status): void
    {
        $stale  = new FakeOrder(1, 'pending');
        $stored = World::addOrder(FakeOrder::paid(1));

        $text = WC_Gateway_PayGate_Admin_Actions::applyQueryStatus($stale, $status, '');

        self::assertSame('completed', $stored->get_status());
        self::assertSame([], $stored->statusHistory);
        self::assertSame([], $stale->statusHistory, 'the stale copy must not be written');
        self::assertSame([1], World::$cacheCleared);
        self::assertStringContainsString('already paid', $text);
    }

    public function testAdminQueryCompletesUnpaidOrderOnApproval(): void
    {
        $order = World::addOrder(new FakeOrder(1, 'pending'));

        WC_Gateway_PayGate_Admin_Actions::applyQueryStatus($order, '1', '');

        self::assertSame('processing', $order->get_status());
    }
}
