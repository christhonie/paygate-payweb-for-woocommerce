<?php

declare(strict_types=1);

/**
 * WooCommerce base classes the gateway extends or type-hints. Only what the gateway calls.
 */
class WC_Payment_Gateway
{
    public $id = 'paygate';
    public $settings = [];

    public function get_return_url($order = null)
    {
        return 'https://shop.test/checkout/order-received/' . $order->get_id() . '/?key=' . $order->get_order_key();
    }
}

class WC_Order
{
}

/**
 * A stateful order. Status rules follow WooCommerce: processing and completed are the paid
 * statuses, and payment_complete() acts only on an order that still needs payment.
 */
final class FakeOrder extends WC_Order
{
    /** @var list<string> */
    public array $notes = [];
    /** @var list<string> */
    public array $statusHistory = [];
    public ?string $datePaid = null;
    public string $transactionId = '';

    public function __construct(private int $id, private string $status = 'pending')
    {
    }

    public static function paid(int $id, string $transactionId = '655927600'): self
    {
        $order                = new self($id, 'completed');
        $order->datePaid      = '2026-10-01 10:00:00';
        $order->transactionId = $transactionId;

        return $order;
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_order_number(): string
    {
        return (string)$this->id;
    }

    /** WooCommerce's per-order secret: it unlocks the order-received and pay pages. */
    public function get_order_key(): string
    {
        return 'wc_order_' . $this->id;
    }

    public function get_customer_id(): int
    {
        return 0;
    }

    public function get_meta($key, $single = true)
    {
        return '';
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function has_status($status): bool
    {
        return in_array($this->status, (array)$status, true);
    }

    public function is_paid(): bool
    {
        return $this->has_status(['processing', 'completed']);
    }

    public function update_status($status, $note = ''): void
    {
        $status = str_replace('wc-', '', (string)$status);
        if ($note !== '') {
            $this->notes[] = $note;
        }
        if ($status !== $this->status) {
            $this->statusHistory[] = $this->status . '->' . $status;
            $this->status          = $status;
        }
    }

    public function add_order_note($note): int
    {
        $this->notes[] = (string)$note;

        return count($this->notes);
    }

    public function payment_complete($transaction_id = ''): bool
    {
        if (!$this->has_status(['pending', 'failed', 'on-hold', 'cancelled'])) {
            return false;
        }
        if ($transaction_id !== '') {
            $this->transactionId = (string)$transaction_id;
        }
        $this->datePaid ??= '2026-10-25 12:00:00';
        $this->update_status('processing');

        return true;
    }

    public function get_checkout_payment_url(): string
    {
        return 'https://shop.test/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->get_order_key();
    }

    public function get_cancel_order_url(): string
    {
        return 'https://shop.test/cart/?cancel_order=true&order_key=' . $this->get_order_key() . '&order_id=' . $this->id;
    }

    public function get_items(): array
    {
        return [];
    }
}
