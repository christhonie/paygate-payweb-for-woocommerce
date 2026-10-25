<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    /**
     * The sample notify payload from PayGate's documentation, with its documented checksum
     * for the key "secret". Values are as PayGate sends them, URL-decoded.
     */
    public static function documentedNotify(): array
    {
        return [
            'PAYGATE_ID'         => '10011072130',
            'PAY_REQUEST_ID'     => '23B785AE-C96C-32AF-4879-D2C9363DB6E8',
            'REFERENCE'          => 'pgtest_123456789',
            'TRANSACTION_STATUS' => '1',
            'RESULT_CODE'        => '990017',
            'AUTH_CODE'          => '5T8A0Z',
            'CURRENCY'           => 'ZAR',
            'AMOUNT'             => '3299',
            'RESULT_DESC'        => 'Auth Done',
            'TRANSACTION_ID'     => '78705178',
            'RISK_INDICATOR'     => 'AX',
            'PAY_METHOD'         => 'CC',
            'PAY_METHOD_DETAIL'  => 'Visa',
            'CHECKSUM'           => 'f57ccf051307d8d0a0743b31ea379aa1',
        ];
    }

    public function testDocumentedSampleNotifyPasses(): void
    {
        self::assertTrue(WC_Gateway_PayGate_Rules::isValidNotifyChecksum(self::documentedNotify(), 'secret'));
    }

    public function testDocumentedSampleNotifyPassesWhateverTheArrivalOrder(): void
    {
        $data = array_reverse(self::documentedNotify(), true);

        self::assertTrue(WC_Gateway_PayGate_Rules::isValidNotifyChecksum($data, 'secret'));
    }

    public function testWrongKeyFails(): void
    {
        self::assertFalse(WC_Gateway_PayGate_Rules::isValidNotifyChecksum(self::documentedNotify(), 'not-secret'));
    }

    public function testAlteredValueFails(): void
    {
        $data           = self::documentedNotify();
        $data['AMOUNT'] = '1';

        self::assertFalse(WC_Gateway_PayGate_Rules::isValidNotifyChecksum($data, 'secret'));
    }

    public function testMissingChecksumFails(): void
    {
        $data = self::documentedNotify();
        unset($data['CHECKSUM']);

        self::assertFalse(WC_Gateway_PayGate_Rules::isValidNotifyChecksum($data, 'secret'));
    }

    public function testEmptyKeyFails(): void
    {
        self::assertFalse(WC_Gateway_PayGate_Rules::isValidNotifyChecksum(self::documentedNotify(), ''));
    }

    public function testUppercaseChecksumPasses(): void
    {
        $data             = self::documentedNotify();
        $data['CHECKSUM'] = strtoupper($data['CHECKSUM']);

        self::assertTrue(WC_Gateway_PayGate_Rules::isValidNotifyChecksum($data, 'secret'));
    }

    /**
     * Why genuine notifies failed: the vendor gateway hashed values after WordPress had
     * slashed them and after htmlspecialchars. A value with a quote or an ampersand then
     * never matches. rawFields() undoes the slashes and changes nothing else.
     */
    public function testValueWithQuoteAndAmpersandPassesAfterRawFields(): void
    {
        $data                = self::documentedNotify();
        $data['RESULT_DESC'] = "Auth Done & approved 'ok'";
        unset($data['CHECKSUM']);
        $data['CHECKSUM'] = md5(implode('', $data) . 'secret');

        $asWordPressDeliversIt = wp_magic_quotes_post($data);

        self::assertFalse(WC_Gateway_PayGate_Rules::isValidNotifyChecksum($asWordPressDeliversIt, 'secret'));
        self::assertTrue(WC_Gateway_PayGate_Rules::isValidNotifyChecksum(
            WC_Gateway_PayGate_Rules::rawFields($asWordPressDeliversIt),
            'secret'
        ));
    }

    public function testAuthCodeNullIsAcceptedAsEmpty(): void
    {
        $data              = self::documentedNotify();
        $data['AUTH_CODE'] = '';
        unset($data['CHECKSUM']);
        $data['CHECKSUM']  = md5(implode('', $data) . 'secret');
        $data['AUTH_CODE'] = 'null';

        self::assertTrue(WC_Gateway_PayGate_Rules::isValidNotifyChecksum($data, 'secret'));
    }

    public function testDocumentedRedirectChecksum(): void
    {
        // Redirect checksum: PAYGATE_ID . PAY_REQUEST_ID . TRANSACTION_STATUS . REFERENCE . key
        $checksum = md5('10011072130' . '23B785AE-C96C-32AF-4879-D2C9363DB6E8' . '1' . 'pgtest_123456789' . 'secret');

        self::assertTrue(WC_Gateway_PayGate_Rules::isValidRedirectChecksum(
            '10011072130', '23B785AE-C96C-32AF-4879-D2C9363DB6E8', '1', 'pgtest_123456789', $checksum, 'secret'
        ));
        self::assertFalse(WC_Gateway_PayGate_Rules::isValidRedirectChecksum(
            '10011072130', '23B785AE-C96C-32AF-4879-D2C9363DB6E8', '4', 'pgtest_123456789', $checksum, 'secret'
        ));
        self::assertFalse(WC_Gateway_PayGate_Rules::isValidRedirectChecksum(
            '10011072130', '23B785AE-C96C-32AF-4879-D2C9363DB6E8', '1', 'pgtest_123456789', '', 'secret'
        ));
    }

    public static function decisions(): array
    {
        return [
            'unpaid, approved'       => [false, '1', WC_Gateway_PayGate_Rules::COMPLETE],
            'unpaid, declined'       => [false, '2', WC_Gateway_PayGate_Rules::FAIL],
            'unpaid, cancelled'      => [false, '3', WC_Gateway_PayGate_Rules::FAIL],
            'unpaid, user cancelled' => [false, '4', WC_Gateway_PayGate_Rules::FAIL],
            'unpaid, not done'       => [false, '0', WC_Gateway_PayGate_Rules::LEAVE],
            'unpaid, received'       => [false, '5', WC_Gateway_PayGate_Rules::LEAVE],
            'unpaid, no status'      => [false, '', WC_Gateway_PayGate_Rules::LEAVE],
            'unpaid, null'           => [false, null, WC_Gateway_PayGate_Rules::LEAVE],
            'paid, approved'         => [true, '1', WC_Gateway_PayGate_Rules::LEAVE],
            'paid, declined'         => [true, '2', WC_Gateway_PayGate_Rules::LEAVE],
            'paid, user cancelled'   => [true, '4', WC_Gateway_PayGate_Rules::LEAVE],
            'paid, not done'         => [true, '0', WC_Gateway_PayGate_Rules::LEAVE],
        ];
    }

    #[DataProvider('decisions')]
    public function testDecide(bool $paid, $status, string $expected): void
    {
        self::assertSame($expected, WC_Gateway_PayGate_Rules::decide($paid, $status));
    }
}
