<?php

declare(strict_types=1);

/**
 * The real portal handlers, with three seams replaced: the request ends by exception, the
 * PayWeb query returns a scripted answer, and notices are not rendered. The constructor is
 * skipped; it reads settings and registers WordPress hooks, none of which is under test.
 */
final class TestablePortal extends WC_Gateway_PayGate_Portal
{
    public const MERCHANT_ID = '10011072130';
    public const KEY         = 'secret';

    /** @var array<string, string>|null the parsed PayWeb query response */
    public ?array $queryResponse = null;
    /** @var list<string> pay request ids queried */
    public array $queried = [];
    /** Runs during the PayWeb query, to simulate what happens meanwhile. */
    public ?Closure $onQuery = null;

    public static function create(array $settings = []): self
    {
        /** @var self $portal */
        $portal = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $portal->settings = $settings + [
            self::DISABLENOTIFY         => 'no',
            self::ALTERNATECARTHANDLING => 'no',
        ];
        $portal->merchant_id          = self::MERCHANT_ID;
        $portal->encryption_key       = self::KEY;
        $portal->customPGReference    = false;
        $portal->order_meta_reference = '';
        $portal->payVault             = 'no';
        $portal->logging              = false;
        self::$wc_logger              = new FakeLogger();

        return $portal;
    }

    /**
     * Run a handler and return normally where the gateway would have exited.
     */
    public function run(callable $handler): void
    {
        ob_start();
        try {
            $handler();
        } catch (HaltException) {
            // the request ended
        } finally {
            ob_end_clean();
        }
    }

    protected function halt(): never
    {
        throw new HaltException();
    }

    public function paywebQuery($payRequestId, $order): mixed
    {
        $this->queried[] = (string)$payRequestId;
        if ($this->onQuery !== null) {
            ($this->onQuery)();
        }

        return $this->queryResponse;
    }

    public function add_notice($message, $notice_type = 'success', $order_id = '')
    {
    }

    protected function custom_print_notices($return = false)
    {
    }
}
