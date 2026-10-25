<?php

declare(strict_types=1);

/*
 * WordPress and WooCommerce functions the gateway calls on the paths under test.
 */

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function wp_json_encode($data)
{
    return json_encode($data);
}

function get_transient($key)
{
    return World::$transients[$key] ?? false;
}

function set_transient($key, $value, $expiration = 0)
{
    World::$transients[$key] = $value;

    return true;
}

function wc_get_order($id)
{
    return World::$orders[(int)$id] ?? false;
}

function wc_get_logger()
{
    return new FakeLogger();
}

function wp_redirect($location, $status = 302)
{
    World::$redirects[] = (string)$location;

    return true;
}

function get_permalink($id = 0)
{
    return 'https://shop.test/my-account/';
}

function wc_get_page_id($page)
{
    return 1;
}

function WC()
{
    static $wc = null;
    if ($wc === null) {
        $wc = new class {
            public $session = null;
            public $cart;

            public function __construct()
            {
                $this->cart = new class {
                    public function empty_cart(): void
                    {
                    }
                };
            }
        };
    }

    return $wc;
}

/**
 * WordPress adds slashes to $_POST before any handler runs (wp_magic_quotes). Tests build
 * requests through this so that the gateway sees what it sees in production.
 */
function wp_magic_quotes_post(array $fields): array
{
    return array_map(static fn($v) => is_string($v) ? addslashes($v) : $v, $fields);
}

function esc_html__($text, $domain = 'default')
{
    return esc_html($text);
}
