<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The server-to-server notify handler, wc-api=WC_Gateway_PayGate_Notify.
 */
final class NotifyHandlerTest extends TestCase
{
    private const ORDER_ID = 32213;

    protected function setUp(): void
    {
        World::reset();
    }

    protected function tearDown(): void
    {
        $errors = array_filter(World::$log, fn($l) => $l['level'] === 'error');
        self::assertSame([], array_values($errors), 'the handler hit an error path');
    }

    /**
     * A notify for ORDER_ID, signed the way PayGate signs it unless $checksum is given.
     */
    private static function payload(string $status = '1', array $override = []): array
    {
        $data = array_merge([
            'PAYGATE_ID'         => TestablePortal::MERCHANT_ID,
            'PAY_REQUEST_ID'     => '23B785AE-C96C-32AF-4879-D2C9363DB6E8',
            'REFERENCE'          => self::ORDER_ID . '-' . self::ORDER_ID,
            'TRANSACTION_STATUS' => $status,
            'RESULT_CODE'        => $status === '1' ? '990017' : '990028',
            'AUTH_CODE'          => $status === '1' ? '5T8A0Z' : '',
            'CURRENCY'           => 'ZAR',
            'AMOUNT'             => '44500',
            'RESULT_DESC'        => $status === '1' ? 'Auth Done' : 'Transaction cancelled',
            'TRANSACTION_ID'     => '655927600',
            'RISK_INDICATOR'     => 'AX',
            'PAY_METHOD'         => 'CC',
            'PAY_METHOD_DETAIL'  => 'Visa',
            'USER3'              => 'woocommerce-v1.7.1',
        ], $override);
        $data['CHECKSUM'] = md5(implode('', $data) . TestablePortal::KEY);

        return $data;
    }

    private function notify(TestablePortal $portal, array $payload): void
    {
        $_GET  = ['wc-api' => 'WC_Gateway_PayGate_Notify'];
        $_POST = wp_magic_quotes_post($payload);

        $portal->run(fn() => $portal->check_paygate_notify_response());
    }

    /**
     * A notify with an invalid checksum for an unpaid Pending order leaves its
     * status unchanged and adds one note.
     */
    public function testInvalidChecksumForUnpaidOrderAddsOneNoteOnly(): void
    {
        $order   = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $payload = self::payload('1');
        $payload['CHECKSUM'] = str_repeat('0', 32);

        $this->notify(TestablePortal::create(), $payload);

        self::assertSame('pending', $order->get_status());
        self::assertSame([], $order->statusHistory);
        self::assertCount(1, $order->notes);
        self::assertStringContainsString('Notify rejected: checksum failed', $order->notes[0]);
    }

    public function testInvalidChecksumForFailedOrderLeavesItFailed(): void
    {
        $order   = World::addOrder(new FakeOrder(self::ORDER_ID, 'failed'));
        $payload = self::payload('1');
        $payload['CHECKSUM'] = str_repeat('0', 32);

        $this->notify(TestablePortal::create(), $payload);

        self::assertSame('failed', $order->get_status());
        self::assertSame([], $order->statusHistory);
    }

    /**
     * For a paid order a notify, valid or not, changes nothing.
     */
    public function testInvalidChecksumForPaidOrderChangesNothing(): void
    {
        $order   = World::addOrder(FakeOrder::paid(self::ORDER_ID));
        $payload = self::payload('4');
        $payload['CHECKSUM'] = str_repeat('0', 32);

        $this->notify(TestablePortal::create(), $payload);

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
        self::assertSame('2026-10-01 10:00:00', $order->datePaid);
    }

    public function testValidCancellationForPaidOrderChangesNothing(): void
    {
        $order = World::addOrder(FakeOrder::paid(self::ORDER_ID));

        $this->notify(TestablePortal::create(), self::payload('4'));

        self::assertSame('completed', $order->get_status());
        self::assertSame([], $order->statusHistory);
    }

    /**
     * The raw payload is logged when the checksum fails, logging setting or not.
     */
    public function testInvalidChecksumIsLoggedWithTheRawPayload(): void
    {
        World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));
        $payload = self::payload('1', ['RESULT_DESC' => "Auth Done 'quoted'"]);
        $payload['CHECKSUM'] = str_repeat('0', 32);

        $this->notify(TestablePortal::create(), $payload);

        $warnings = array_values(array_filter(World::$log, fn($l) => $l['level'] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Notify checksum failed', $warnings[0]['message']);
        // Raw: unslashed and not HTML-escaped.
        self::assertStringContainsString("Auth Done 'quoted'", $warnings[0]['message']);
    }

    /**
     * A genuine notify passes. Its values reach the handler slashed by WordPress;
     * one carries characters the vendor gateway's escaping altered.
     */
    public function testGenuineNotifyCompletesTheOrder(): void
    {
        $order = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));

        $this->notify(TestablePortal::create(), self::payload('1', ['USER1' => "O'Brien & Sons"]));

        self::assertSame('processing', $order->get_status());
        self::assertSame('655927600', $order->transactionId);
        self::assertCount(1, $order->notes);
        self::assertStringStartsWith('Response via Notify: Transaction successful', $order->notes[0]);
    }

    public function testGenuineDeclineFailsUnpaidOrderAndNoteSaysNotify(): void
    {
        $order = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));

        $this->notify(TestablePortal::create(), self::payload('2'));

        self::assertSame('failed', $order->get_status());
        self::assertStringStartsWith('Response via Notify', $order->notes[0]);
    }

    public function testGenuineUserCancelNoteSaysNotify(): void
    {
        $order = World::addOrder(new FakeOrder(self::ORDER_ID, 'pending'));

        $this->notify(TestablePortal::create(), self::payload('4'));

        self::assertSame('failed', $order->get_status());
        self::assertStringStartsWith('Response via Notify: User cancelled', $order->notes[0]);
    }
}
