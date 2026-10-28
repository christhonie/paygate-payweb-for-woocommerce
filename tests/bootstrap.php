<?php
/*
 * Test bootstrap: just enough of WordPress and WooCommerce, in memory, to run the
 * gateway's handlers through to their end. Every fake records what the gateway did so
 * that a test can assert on it.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/Support/World.php';
require __DIR__ . '/Support/FakeOrder.php';
require __DIR__ . '/Support/wordpress.php';

require __DIR__ . '/../paygate-payweb-for-woocommerce/classes/WC_Gateway_PayGate.php';

require __DIR__ . '/Support/HaltException.php';
require __DIR__ . '/Support/TestablePortal.php';
