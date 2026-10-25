<?php

declare(strict_types=1);

/**
 * The in-memory state the WordPress fakes read and write. Reset before every test.
 */
final class World
{
    /** @var array<int, FakeOrder> */
    public static array $orders = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var list<string> */
    public static array $redirects = [];
    /** @var list<array{level: string, message: string}> */
    public static array $log = [];

    public static function reset(): void
    {
        self::$orders     = [];
        self::$transients = [];
        self::$redirects  = [];
        self::$log        = [];
        $_POST            = [];
        $_GET             = [];
    }

    public static function addOrder(FakeOrder $order): FakeOrder
    {
        self::$orders[$order->get_id()] = $order;

        return $order;
    }
}

/**
 * WC_Logger stand-in.
 */
final class FakeLogger
{
    public function add($handle, $message): bool
    {
        World::$log[] = ['level' => 'debug', 'message' => (string)$message];

        return true;
    }

    public function warning($message, $context = []): void
    {
        World::$log[] = ['level' => 'warning', 'message' => (string)$message];
    }

    public function error($message, $context = []): void
    {
        World::$log[] = ['level' => 'error', 'message' => (string)$message];
    }

    public function info($message, $context = []): void
    {
        World::$log[] = ['level' => 'info', 'message' => (string)$message];
    }
}
