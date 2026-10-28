<?php
/*
 * Released under the GNU General Public License
 */

/**
 * Checksum validation and order-status decisions for PayWeb responses.
 *
 * Kept free of WordPress and WooCommerce calls so that every rule can be unit tested.
 * The handlers in WC_Gateway_PayGate_Portal, the cron and the admin query action ask
 * this class what to do, and then do it.
 */
class WC_Gateway_PayGate_Rules
{
    /** PayWeb TRANSACTION_STATUS values. */
    const STATUS_NOT_DONE  = 0;
    const STATUS_APPROVED  = 1;
    const STATUS_DECLINED  = 2;
    const STATUS_CANCELLED = 3;
    const STATUS_USER_CANCELLED = 4;
    const STATUS_RECEIVED_BY_PAYGATE = 5;
    const STATUS_SETTLEMENT_VOIDED = 7;

    /** Decisions returned by decide(). */
    const COMPLETE = 'complete';
    const FAIL     = 'fail';
    const LEAVE    = 'leave';

    /**
     * Notify fields in the order PayGate documents them. The checksum is the MD5 of the
     * values present, in this order, followed by the encryption key.
     */
    const NOTIFY_FIELD_ORDER = [
        'PAYGATE_ID',
        'PAY_REQUEST_ID',
        'REFERENCE',
        'TRANSACTION_STATUS',
        'RESULT_CODE',
        'AUTH_CODE',
        'CURRENCY',
        'AMOUNT',
        'RESULT_DESC',
        'TRANSACTION_ID',
        'RISK_INDICATOR',
        'PAY_METHOD',
        'PAY_METHOD_DETAIL',
        'USER1',
        'USER2',
        'USER3',
        'VAULT_ID',
        'PAYVAULT_DATA_1',
        'PAYVAULT_DATA_2',
    ];

    /**
     * Validate the checksum of a notify payload.
     *
     * $data must hold the values exactly as PayGate sent them: unslashed, not escaped or
     * sanitised. Escaping changes a value and so breaks a genuine checksum.
     *
     * The documented field order is tried first. The order of arrival is tried as well,
     * because the vendor gateway has always validated that way, and any field PayGate adds
     * later would otherwise fail every notify. Both variants also try an AUTH_CODE of
     * "null" as empty, which the vendor gateway did. Every variant still needs the key.
     */
    public static function isValidNotifyChecksum(array $data, string $encryptionKey): bool
    {
        if (!isset($data['CHECKSUM']) || !is_string($data['CHECKSUM']) || $encryptionKey === '') {
            return false;
        }
        $expected = strtolower($data['CHECKSUM']);

        $documented = [];
        foreach (self::NOTIFY_FIELD_ORDER as $field) {
            if (array_key_exists($field, $data)) {
                $documented[$field] = $data[$field];
            }
        }
        foreach ($data as $field => $value) {
            if ($field !== 'CHECKSUM' && !array_key_exists($field, $documented)) {
                $documented[$field] = $value;
            }
        }

        $arrival = $data;
        unset($arrival['CHECKSUM']);

        foreach ([$documented, $arrival] as $fields) {
            foreach ([false, true] as $nullAuthCodeIsEmpty) {
                if (hash_equals($expected, self::checksum($fields, $encryptionKey, $nullAuthCodeIsEmpty))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate the checksum PayGate posts back with the browser redirect.
     * Source: PAYGATE_ID . PAY_REQUEST_ID . TRANSACTION_STATUS . REFERENCE . key.
     */
    public static function isValidRedirectChecksum(
        string $paygateId,
        string $payRequestId,
        string $transactionStatus,
        string $reference,
        string $checksum,
        string $encryptionKey
    ): bool {
        if ($checksum === '' || $encryptionKey === '') {
            return false;
        }

        return hash_equals(
            md5($paygateId . $payRequestId . $transactionStatus . $reference . $encryptionKey),
            strtolower($checksum)
        );
    }

    /**
     * What a PayWeb status may do to an order.
     *
     * A paid order is never moved: not to Failed, Pending or Cancelled, and not completed
     * a second time. An unpaid order is completed on approval, failed on decline or
     * cancellation, and otherwise left as it is.
     *
     * @param bool $orderIsPaid       WC_Order::is_paid()
     * @param int|string|null $status TRANSACTION_STATUS from an authenticated source
     */
    public static function decide(bool $orderIsPaid, $status): string
    {
        if ($orderIsPaid) {
            return self::LEAVE;
        }

        if (!is_numeric($status)) {
            return self::LEAVE;
        }

        switch ((int)$status) {
            case self::STATUS_APPROVED:
                return self::COMPLETE;
            case self::STATUS_DECLINED:
            case self::STATUS_CANCELLED:
            case self::STATUS_USER_CANCELLED:
                return self::FAIL;
            default:
                return self::LEAVE;
        }
    }

    /**
     * Raw request values for checksum validation and logging: WordPress adds slashes to
     * $_POST (wp_magic_quotes), so they are removed here; nothing else is changed.
     * Non-scalar values are dropped, since PayGate never sends them.
     */
    public static function rawFields(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (is_scalar($value)) {
                $result[(string)$key] = stripslashes((string)$value);
            }
        }

        return $result;
    }

    private static function checksum(array $fields, string $encryptionKey, bool $nullAuthCodeIsEmpty): string
    {
        $source = '';
        foreach ($fields as $field => $value) {
            if ($nullAuthCodeIsEmpty && $field === 'AUTH_CODE' && $value === 'null') {
                continue;
            }
            $source .= (string)$value;
        }

        return md5($source . $encryptionKey);
    }
}
