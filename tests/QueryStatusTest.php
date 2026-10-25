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
        $order = FakeOrder::paid(1);

        WC_Gateway_PayGate_Cron::updateOrderStatus($status, $order, '');

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
    }

    #[DataProvider('statuses')]
    public function testAdminQueryLeavesPaidOrder(string $status): void
    {
        $order = FakeOrder::paid(1);

        $text = WC_Gateway_PayGate_Admin_Actions::applyQueryStatus($order, $status, '');

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
        self::assertStringContainsString('already paid', $text);
    }

    public function testAdminQueryCompletesUnpaidOrderOnApproval(): void
    {
        $order = new FakeOrder(1, 'pending');

        WC_Gateway_PayGate_Admin_Actions::applyQueryStatus($order, '1', '');

        self::assertSame('processing', $order->get_status());
    }
}
