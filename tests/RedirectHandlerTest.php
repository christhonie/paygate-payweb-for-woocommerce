<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The browser redirect handler, wc-api=WC_Gateway_PayGate_Redirect.
 */
final class RedirectHandlerTest extends TestCase
{
    private const ORDER_ID       = 691;
    private const PAY_REQUEST_ID = '6A6A4D5F-0C1B-4F5E-9E0A-1234567890AB';

    protected function setUp(): void
    {
        World::reset();
    }

    protected function tearDown(): void
    {
        $errors = array_filter(World::$log, fn($l) => $l['level'] === 'error');
        self::assertSame([], array_values($errors), 'the handler hit an error path');
    }

    private function redirect(TestablePortal $portal, string $status, ?string $checksum = null): void
    {
        $reference = self::ORDER_ID . '-' . self::ORDER_ID;
        $checksum ??= md5(TestablePortal::MERCHANT_ID . self::PAY_REQUEST_ID . $status . $reference . TestablePortal::KEY);

        $_GET  = ['wc-api' => 'WC_Gateway_PayGate_Redirect', 'gid' => (string)self::ORDER_ID];
        $_POST = wp_magic_quotes_post([
            'PAY_REQUEST_ID'     => self::PAY_REQUEST_ID,
            'TRANSACTION_STATUS' => $status,
            'CHECKSUM'           => $checksum,
        ]);

        $portal->run(fn() => $portal->check_paygate_response());
    }

    private static function query(string $status, string $transactionId = '655927600'): array
    {
        return [
            'PAY_REQUEST_ID'     => self::PAY_REQUEST_ID,
            'TRANSACTION_STATUS' => $status,
            'TRANSACTION_ID'     => $transactionId,
            'RESULT_DESC'        => $status === '1' ? 'Auth Done' : 'Not Done',
        ];
    }

    public static function failingStatuses(): array
    {
        return ['declined' => ['2'], 'user cancelled' => ['4']];
    }

    /**
     * A redirect carrying status 2 or 4 for a paid order leaves its status and paid
     * date unchanged, adds one note, and lands on the order-received page.
     */
    #[DataProvider('failingStatuses')]
    public function testFailingStatusForPaidOrderChangesNothing(string $status): void
    {
        $order  = World::addOrder(FakeOrder::paid(self::ORDER_ID));
        $portal = TestablePortal::create();
        $portal->queryResponse = self::query($status);

        $this->redirect($portal, $status);

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
        self::assertSame('2026-10-01 10:00:00', $order->datePaid);
        self::assertCount(1, $order->notes);
        self::assertStringContainsString('Redirect ignored', $order->notes[0]);
        self::assertSame(['https://shop.test/checkout/order-received/691/?key=wc_order_691'], World::$redirects);
        self::assertStringNotContainsString('cancel_order', implode(' ', World::$redirects));
        self::assertSame([], $portal->queried, 'a paid order needs no PayWeb query');
    }

    /**
     * A redirect with an invalid checksum is rejected whether or not the
     * order is paid, and changes no order.
     */
    public function testInvalidChecksumForUnpaidOrderChangesNothing(): void
    {
        $order  = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create();
        $portal->queryResponse = self::query('1');

        $this->redirect($portal, '1', str_repeat('0', 32));

        self::assertSame('pending', $order->get_status());
        self::assertSame([], $order->statusHistory);
        self::assertSame([], $order->notes, 'a forged request must not write onto the order');
        self::assertSame([], $portal->queried);
    }

    public function testInvalidChecksumForPaidOrderChangesNothing(): void
    {
        $order  = World::addOrder(FakeOrder::paid(self::ORDER_ID));
        $portal = TestablePortal::create();

        $this->redirect($portal, '4', str_repeat('0', 32));

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
        self::assertSame([], $order->notes);
    }

    /**
     * A forged request - a guessed order number, any pay request id - must not be answered
     * with a URL that carries the order key, paid or unpaid.
     */
    #[DataProvider('anyOrder')]
    public function testInvalidChecksumNeverRevealsTheOrderKey(FakeOrder $order): void
    {
        World::addOrder($order);
        $portal = TestablePortal::create();

        $this->redirect($portal, '1', str_repeat('0', 32));

        self::assertSame(['https://shop.test/my-account/'], World::$redirects);
        self::assertStringNotContainsString('wc_order_', implode(' ', World::$redirects));
    }

    public static function anyOrder(): array
    {
        return [
            'unpaid' => [new FakeOrder(self::ORDER_ID, 'pending')],
            'paid'   => [FakeOrder::paid(self::ORDER_ID)],
            'failed' => [new FakeOrder(self::ORDER_ID, 'failed')],
        ];
    }

    public function testInvalidChecksumIsLoggedWithTheRawPayload(): void
    {
        World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create();

        $this->redirect($portal, '1', str_repeat('0', 32));

        $warnings = array_values(array_filter(World::$log, fn($l) => $l['level'] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Redirect checksum failed', $warnings[0]['message']);
        self::assertStringContainsString(self::PAY_REQUEST_ID, $warnings[0]['message']);
    }

    /**
     * The status acted on is the one PayWeb returns, not the one in the POST.
     */
    public function testPostSaysApprovedButPayWebSaysCancelled(): void
    {
        $order  = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create();
        $portal->queryResponse = self::query('4');

        $this->redirect($portal, '1');

        self::assertSame([self::PAY_REQUEST_ID], $portal->queried);
        self::assertSame('failed', $order->get_status());
        self::assertFalse($order->is_paid());
    }

    public function testPostSaysCancelledButPayWebSaysApproved(): void
    {
        $order  = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create();
        $portal->queryResponse = self::query('1', '605241759');

        $this->redirect($portal, '4');

        self::assertSame('processing', $order->get_status());
        self::assertSame('605241759', $order->transactionId);
    }

    /**
     * Approval from the redirect completes the order even with notify enabled: the status
     * comes from the server-side query, so it can be trusted. The note names the path.
     */
    public function testApprovalCompletesTheOrderAndTheNoteSaysRedirect(): void
    {
        $order  = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create([WC_Gateway_PayGate::DISABLENOTIFY => 'no']);
        $portal->queryResponse = self::query('1');

        $this->redirect($portal, '1');

        self::assertSame('processing', $order->get_status());
        self::assertSame('655927600', $order->transactionId);
        self::assertCount(1, $order->notes);
        self::assertStringStartsWith('Response via Redirect: Transaction successful', $order->notes[0]);
        self::assertStringNotContainsString('Notify', $order->notes[0]);
        self::assertSame(['https://shop.test/checkout/order-received/691/?key=wc_order_691'], World::$redirects);
    }

    public function testDeclineNoteSaysRedirectWithNotifyEnabled(): void
    {
        $order  = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create([WC_Gateway_PayGate::DISABLENOTIFY => 'no']);
        $portal->queryResponse = self::query('2');

        $this->redirect($portal, '2');

        self::assertSame('failed', $order->get_status());
        self::assertStringStartsWith('Response via Redirect', $order->notes[0]);
    }

    /**
     * A genuine successful redirect replayed after the 15-second window changes
     * nothing.
     */
    public function testSuccessfulRedirectReplayedLaterChangesNothing(): void
    {
        $order  = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create();
        $portal->queryResponse = self::query('1');

        $this->redirect($portal, '1');
        self::assertSame('processing', $order->get_status());
        $notesAfterPayment   = count($order->notes);
        $historyAfterPayment = $order->statusHistory;

        World::$transients = []; // the 15-second replay guard has expired
        World::$redirects  = [];
        $portal->queryResponse = self::query('4'); // whatever PayWeb would now say
        $this->redirect($portal, '1');

        self::assertSame('processing', $order->get_status());
        self::assertSame($historyAfterPayment, $order->statusHistory);
        self::assertCount($notesAfterPayment + 1, $order->notes);
        self::assertSame(['https://shop.test/checkout/order-received/691/?key=wc_order_691'], World::$redirects);
    }

    /**
     * A status that is not final leaves an unpaid order as it is and sends the buyer back to
     * pay, not to the cancel link.
     */
    public function testNotDoneLeavesUnpaidOrderPending(): void
    {
        $order  = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $portal = TestablePortal::create();
        $portal->queryResponse = self::query('0');

        $this->redirect($portal, '0');

        self::assertSame('pending', $order->get_status());
        self::assertSame(['https://shop.test/checkout/order-pay/691/?pay_for_order=true&key=wc_order_691'], World::$redirects);
    }

    /**
     * Notify completed the order between the checksum check and the query.
     */
    public function testOrderPaidByNotifyDuringTheQueryIsLeftAlone(): void
    {
        World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $paidByNotify = FakeOrder::paid(self::ORDER_ID);
        $portal = TestablePortal::create();
        $portal->queryResponse = self::query('4');
        $portal->onQuery = static function () use ($paidByNotify) {
            World::$orders[self::ORDER_ID] = $paidByNotify;
        };

        $this->redirect($portal, '4');

        self::assertSame('completed', $paidByNotify->get_status());
        self::assertSame([], $paidByNotify->statusHistory);
        self::assertSame([], $paidByNotify->notes);
        self::assertSame([self::ORDER_ID], World::$cacheCleared);
        self::assertSame(['https://shop.test/checkout/order-received/691/?key=wc_order_691'], World::$redirects);
    }
}
