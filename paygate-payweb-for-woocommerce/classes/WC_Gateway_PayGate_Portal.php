<?php
/*
 * Copyright (c) 2026 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

use Payfast\PayfastCommon\Gateway\Request\PaymentRequest;

class WC_Gateway_PayGate_Portal extends WC_Gateway_PayGate
{
    protected $error_desc = 'Checksum validation error.';

    /**
     * End the request. A seam so that tests can run a handler through to its end.
     */
    protected function halt(): never
    {
        exit();
    }

    public static function getPayRequestIdNotes($notes)
    {
        $payRequestId = '';
        foreach ($notes as $note) {
            if (strpos($note->comment_content, 'Pay Request Id:') !== false) {
                preg_match('/.*Pay Request Id: ([\dA-Z\-]+)/', $note->comment_content, $a);
                if (isset($a[1])) {
                    $payRequestId = $a[1];
                }
            }
        }

        return $payRequestId;
    }

    /**
     * Does the initiate to Paygate
     *
     * @param $order_id
     *
     * @return array|WP_Error
     */
    public function initiate_transaction($order_id)
    {
        $order       = new WC_Order($order_id);
        $customer_id = $order->get_customer_id();
        unset($this->data_to_send);

        $reference = $this->getOrderReference($order);

        // Construct variables for post
        $order_total        = $order->get_total();
        $this->data_to_send = array(
            self::REFERENCE    => $reference,
            'AMOUNT'           => number_format($order_total, 2, '', ''),
            'CURRENCY'         => $order->get_currency(),
            'RETURN_URL'       => $this->redirect_url . '&gid=' . $order_id,
            'TRANSACTION_DATE' => (new \DateTime())->format('Y-m-d H:i:s'),
            'LOCALE'           => 'en-za',
            'COUNTRY'          => 'ZAF',
            'EMAIL'            => $order->get_billing_email(),
        );

        $vaultableMethod = $this->setVaultableMethod();

        $this->checkNotifyIpn();

        $this->data_to_send['USER3'] = 'woocommerce-v' . $this->version;

        $newPaymentMethod = false;
        if ($wcsession = WC()->session) {
            $t                = $wcsession->get(self::NEW_PAYMENT_METHOD_SESSION);
            $newPaymentMethod = $this->isPaymentMethodNew($t, $newPaymentMethod);
        }

        if ($vaultableMethod && $this->payVault == 'yes') {
            // Tokenisation is enabled for store
            // Check if customer has existing tokens or chooses to tokenise
            $custVault = get_post_meta($customer_id, 'wc-' . $this->id . self::NEW_PAYMENT_METHOD, true);
            $newVault  = $noVault = false;
            if ($wcsession = WC()->session) {
                $newVault = $wcsession->get('wc-' . $this->id . self::PAYMENT_TOKEN) === 'new';
                $noVault  = $wcsession->get('wc-' . $this->id . self::PAYMENT_TOKEN) === 'no';
                $tokenSet = $wcsession->get('wc-' . $this->id . self::PAYMENT_TOKEN);
            }

            switch ($vaultableMethod) {
                case !$custVault && $newPaymentMethod:
                case $newVault:
                    // Customer requesting remember card number
                    update_post_meta($customer_id, 'wc-' . $this->id . self::NEW_PAYMENT_METHOD, true);
                    $this->data_to_send[self::VAULT] = 1;
                    break;
                case $noVault:
                    update_post_meta($customer_id, 'wc-' . $this->id . self::NEW_PAYMENT_METHOD, false);
                    break;
                case isset($tokenSet):
                    $this->data_to_send[self::VAULT_ID] = $tokenSet;
                    unset($this->data_to_send['PAY_METHOD_DETAIL']);
                    break;
                case $newPaymentMethod:
                    $this->data_to_send[self::VAULT] = 1;
                    break;
                default:
                    $this->data_to_send[self::VAULT] = 0;
                    break;
            }
        }


        try {
            $payweb   = new PaymentRequest($this->merchant_id, $this->encryption_key);
            $response = $payweb->initiate($this->data_to_send);
        } catch (Exceptione $e) {
            echo esc_html('Error initiating transaction: ' . $e->getMessage());
        }

        parse_str($response, $parsed_response);

        if (empty($response) || array_key_exists(
                'ERROR',
                $parsed_response
            ) || !array_key_exists(self::PAY_REQUEST_ID, $parsed_response)) {
            $this->msg[self::WC_CLASS] = 'woocommerce-error';
            $this->msg[self::MESSAGE]  = "Thank you for shopping with us. However, we were unable to initiate your payment. Please try again.";
            $this->isOrderFailed($order, $response);

            $cancel_text   = __('Cancel order &amp; restore cart', 'paygate-payweb-for-woocommerce');
            $cancel_link   = '<br><a class="button wc-forward" href="' . esc_url(
                    $order->get_cancel_order_url()
                ) . '">' . $cancel_text . '</a>';
            $error_message = $this->show_message($cancel_link);

            return new WP_Error(
                'paygate-error',
                sprintf(
                // translators: %s is the error message returned by the payment gateway.
                    __('Payment error: %s', 'paygate-payweb-for-woocommerce'),
                    $error_message
                )
            );
        } else {
            if ($wcsession = WC()->session) {
                $wcsession->set('PARSED_RESPONSE', $parsed_response);
            }
        }

        $this->initiate_response = $parsed_response;

        // Add order note with the PAY_REQUEST_ID for custom query
        $order->add_order_note(
            'Initiate on Payfast Gateway started. Pay Request Id: ' . $parsed_response[self::PAY_REQUEST_ID]
        );

        if (!$order->has_status(self::PENDING)) {
            $order->update_status(self::PENDING);
        }

        return $parsed_response;
    }

    /**
     * Generate the Paygate button link.
     * Redirect case
     *
     * @param $order_id
     *
     * @return string
     * @since 1.0.0
     *
     */
    public function getRedirectHTML($order_id)
    {
        $parsed_response = '';
        try {
            $payweb          = new PaymentRequest($this->merchant_id, $this->encryption_key);
            $parsed_response = $this->initiate_transaction($order_id);
        } catch (Exception $e) {
            echo esc_html('Error initiating transaction: ' . $e->getMessage());
        }

        if ($this->settings[self::ALTERNATECARTHANDLING] == 'yes') {
            WC()->cart->empty_cart();
        }

        if (!is_wp_error($parsed_response)) {
            unset($parsed_response[self::CHECKSUM]);
            $checksum       = esc_attr(md5(implode('', $parsed_response) . $this->encryption_key));
            $pay_request_id = esc_attr($parsed_response[self::PAY_REQUEST_ID]);

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- getRedirectHTML() returns trusted redirect HTML
            echo $payweb->getRedirectHTML($pay_request_id, $checksum);
        } else {
            echo esc_html($parsed_response->get_error_message());
        }
    }

    /**
     * Check for valid Paygate Redirect - from iFrame or  Redirect
     *
     * @since 1.0.0
     */
    public function check_paygate_response(): void
    {
        $this->logging ? self::$wc_logger->add('payfast-gateway-web', 'Redirect POST: ' . json_encode($_POST)) : '';

        // Sanitise GET and POST arrays
        $post = $this->sanitizeFields($_POST);
        $get  = $this->sanitizeFields($_GET);

        // Only process if IPN is disabled
        if (!(isset($get['gid']) && isset($post[self::PAY_REQUEST_ID]))) {
            $this->halt();
        }

        if (!$order_id = $get['gid']) {
            wp_redirect(get_permalink(wc_get_page_id('myaccount')));
        }

        if ($order_id == '') {
            $this->halt();
        }

        $transient = get_transient('PAYGATE_PAYWEB_RED_' . $post[self::PAY_REQUEST_ID]);
        if ($transient !== false) {
            $this->halt();
        }
        set_transient('PAYGATE_PAYWEB_RED_' . $post[self::PAY_REQUEST_ID], $post[self::PAY_REQUEST_ID], 15);

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->halt();
        }
        $customer_id = $order->get_customer_id();

        $raw            = WC_Gateway_PayGate_Rules::rawFields($_POST);
        $pay_request_id = $raw[self::PAY_REQUEST_ID] ?? '';
        $reference      = $this->getOrderReference($order);

        // A paid order is never changed by a browser redirect, whatever it carries. The buyer
        // may be replaying a page from a payment completed days earlier.
        if ($order->is_paid()) {
            $order->add_order_note(
                'Response via Redirect ignored: the order is already paid.' . self::PAY_REQUEST_ID_TEXT . esc_html($pay_request_id) . self::BR
            );
            $this->redirectAfterOrder($this->get_return_url($order));
            $this->halt();
        }

        if (!WC_Gateway_PayGate_Rules::isValidRedirectChecksum(
            (string)$this->merchant_id,
            $pay_request_id,
            $raw[self::TRANSACTION_STATUS] ?? '',
            $reference,
            $raw[self::CHECKSUM] ?? '',
            (string)$this->encryption_key
        )) {
            $this->logChecksumFailure('Redirect', $raw);
            $order->add_order_note(
                'Response via Redirect rejected: checksum failed. Order status unchanged.' . self::PAY_REQUEST_ID_TEXT . esc_html($pay_request_id) . self::BR
            );
            $this->redirectAfterOrder($order->get_checkout_payment_url());
            $this->halt();
        }

        try {
            $parsed_response = $this->paywebQuery($pay_request_id, $order) ?? [];

            // Act on the status PayWeb returned to this server, never on the one in the POST.
            $status = $parsed_response[self::TRANSACTION_STATUS] ?? '';

            if ((int)$status === 1) {
                $this->vaultCard($parsed_response, $customer_id);
            }

            $transaction_id = isset($parsed_response[self::TRANSACTION_ID]) ? $parsed_response[self::TRANSACTION_ID] : "";
            $result_desc    = isset($parsed_response[self::RESULT_DESC]) ? $parsed_response[self::RESULT_DESC] : "";

            // Re-read from storage, bypassing this request's caches: notify may have paid the
            // order while the PayWeb query ran.
            $order = self::freshOrder((int)$order_id);
            if (!$order) {
                $this->halt();
            }
            if ($order->is_paid()) {
                $this->redirectAfterOrder($this->get_return_url($order));
                $this->halt();
            }

            $this->processOrderFinal($status, $order, $transaction_id, $result_desc, $pay_request_id);
        } catch (Exception $e) {
            wc_get_logger()->error('Redirect: PayWeb query failed: ' . $e->getMessage(), ['source' => 'payfast-gateway-web']);
            echo esc_html__('We could not confirm your payment. Please check your order status shortly.', 'paygate-payweb-for-woocommerce');
        }
    }

    /**
     * Check for valid Paygate Notify
     *
     * @since 1.0.0
     */

    public function check_paygate_notify_response(): void
    {
        // Log notify response for debugging purposes
        $this->logIPNRequest();

        // Tell Paygate notify we have received
        echo 'OK';

        if ($this->settings[self::DISABLENOTIFY] == 'yes') {
            $this->halt();
        }

        if (!isset($_POST)) {
            $this->halt();
        }

        // Get notify data
        $paygate_data = $this->sanitizeFields($_POST);

        $order_id = '';
        if (!isset($paygate_data[self::REFERENCE])) {
            $this->halt();
        }

        $transient = get_transient('PAYGATE_PAYWEB_IPN_' . $paygate_data[self::REFERENCE]);
        if ($transient !== false) {
            $this->halt();
        }
        set_transient('PAYGATE_PAYWEB_IPN_' . $paygate_data[self::REFERENCE], $paygate_data[self::REFERENCE], 15);

        $order_id = explode("-", $paygate_data[self::REFERENCE]);
        $order_id = trim($order_id[0]);

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->halt();
        }

        // Check if the order has already been paid - exit if so
        if ($order->is_paid()) {
            $order->add_order_note('Notify: Order is already paid... exiting');
            $this->halt();
        }

        // Verify security signature over the values exactly as PayGate sent them
        $this->verifySecuritySignature(WC_Gateway_PayGate_Rules::rawFields($_POST), $order);

        $customer_id = $order->get_customer_id();

        if ((int)$paygate_data[self::TRANSACTION_STATUS] === 1) {
            $this->vaultCard($paygate_data, $customer_id);
        }

        if ($order->has_status(self::PROCESSING) || $order->has_status(self::COMPLETED)) {
            $this->halt();
        }

        $transaction_id = $paygate_data[self::TRANSACTION_ID] ?? "";
        $result_desc    = $paygate_data[self::RESULT_DESC] ?? "";
        $pay_request_id = $paygate_data[self::PAY_REQUEST_ID] ?? "";

        switch ((int)$paygate_data[self::TRANSACTION_STATUS]) {
            case 1:
                $this->processOrderFinalSuccess($order, $transaction_id, $pay_request_id, 'ipn');
                $this->halt();
                break;
            case 2:
                $this->add_notice('The transaction was declined', self::ERROR, $order_id);
                $this->processOrderFinalFail($order, $transaction_id, $pay_request_id, $result_desc, 'ipn');
                $this->halt();
                break;
            case 4:
                $this->add_notice('The transaction was cancelled', self::ERROR, $order_id);
                $this->processOrderFinalCancel($order, $transaction_id, $pay_request_id, 'ipn');
                $this->halt();
                break;
            default:
                if (!$order->has_status(self::PENDING)) {
                    if ($this->logging) {
                        self::$wc_logger->add('payfast-gateway-web', 'Reached default in switch statement');
                    }
                    $order->add_order_note(
                        'Response via Notify, RESULT_DESC: ' . $result_desc . self::PAYGATE_TRANS_ID . $transaction_id . self::PAY_REQUEST_ID_TEXT . $pay_request_id . self::BR
                    );
                    $order->update_status(self::PENDING);
                }
                $redirect_link = self::SCRIPT_WIN_TOP_LOCAT_HREF . $this->get_return_url($order) . self::SCRIPT_TAG;
                echo esc_html($redirect_link);
                $this->halt();
                break;
        }
    }

    /**
     * @param $payRequestId
     * @param $order
     *
     * @return mixed
     */
    public function paywebQuery($payRequestId, $order): mixed
    {
        $reference = $this->getOrderReference($order);

        try {
            $payweb   = new PaymentRequest($this->merchant_id, $this->encryption_key);
            $response = $payweb->query($payRequestId, $reference);
            parse_str($response, $parsed_response);

            return $parsed_response;
        } catch (Exception $e) {
            wc_get_logger()->error('PayWeb query failed: ' . $e->getMessage(), ['source' => 'payfast-gateway-web']);

            return null;
        }
    }

    /**
     * @param $response
     * @param $payRequestId
     *
     * @return string
     */
    public function paywebQueryText($response, $payRequestId)
    {
        $transactionStatus = !empty($response[self::TRANSACTION_STATUS]) ? $response[self::TRANSACTION_STATUS] : 'Null';
        $resultCode        = !empty($response[self::RESULT_CODE]) ? $response[self::RESULT_CODE] : 'Null';
        $resultDesc        = !empty($response[self::RESULT_DESC]) ? $response[self::RESULT_DESC] : 'Null';

        return
            'Pay_Request_Id: ' . $payRequestId . '<br>' .
            'Transaction Status: ' . $transactionStatus . '=>' . $this->paywebStatus[(int)$transactionStatus] . '<br>' .
            'Result Code: ' . $resultCode . '<br>' .
            'Result Description: ' . $resultDesc;
    }

    /**
     * @param $response
     *
     * @return string
     */
    public function paywebQueryStatus($response): string
    {
        return !empty($response[self::TRANSACTION_STATUS]) ? $response[self::TRANSACTION_STATUS] : 'Null';
    }

    /**
     * @param $t
     * @param true $newPaymentMethod
     *
     * @return true
     */
    public function isPaymentMethodNew($t, $newPaymentMethod): bool
    {
        if (isset($t) && $t === '1') {
            $newPaymentMethod = true;
        }

        return $newPaymentMethod;
    }

    /**
     * @return void
     */
    public function logIPNRequest(): void
    {
        if ($this->logging) {
            self::$wc_logger->add('payfast-gateway-web', 'Notify POST: ' . json_encode($_POST));
            self::$wc_logger->add('payfast-gateway-web', 'Notify GET: ' . json_encode($_GET));
        }
    }

    /**
     * @param array $paygate_data
     * @param $order
     *
     * @return void
     */
    public function verifySecuritySignature(array $paygate_data, $order): void
    {
        if (!$this->validateChecksumNotify($paygate_data)) {
            // A message that fails its checksum is not trusted, so it must not change the
            // order. The cron and the admin query read the real state from PayWeb.
            $this->logChecksumFailure('Notify', $paygate_data);
            $order->add_order_note(
                'Response via Notify rejected: checksum failed. Order status unchanged.' . self::PAY_REQUEST_ID_TEXT . esc_html($paygate_data[self::PAY_REQUEST_ID] ?? '') . self::BR
            );
            $this->halt();
        } else {
            if ($this->logging) {
                self::$wc_logger->add('payfast-gateway-web', 'Validated checksum with data ' . json_encode($paygate_data));
            }
        }
    }

    /**
     * @param $order
     *
     * @return void
     */
    public function alternativeCartMechanism($order): void
    {
        if (count($order->get_items()) > 0) {
            foreach ($order->get_items() as $product) {
                $product_id   = isset($product['product_id']) ? (int)$product['product_id'] : 0;
                $quantity     = isset($product['quantity']) ? (int)$product['quantity'] : 1;
                $variation_id = isset($product['variation_id']) ? (int)$product['variation_id'] : 0;
                $variation    = isset($product['variation']) ? (array)$product['variation'] : array();
                WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
            }
            WC()->cart->calculate_totals();
        }
    }

    /**
     * @return void
     */
    public function checkNotifyIpn(): void
    {
        if ($this->settings[self::DISABLENOTIFY] != 'yes') {
            $this->data_to_send['NOTIFY_URL'] = $this->notify_url;
        }
    }

    /**
     * @param WC_Order $order
     * @param string $response
     *
     * @return void
     */
    public function isOrderFailed(WC_Order $order, string $response): void
    {
        if (!$order->has_status(self::FAILED)) {
            $order->add_order_note(
                'Response from initiating payment:' .
                wp_json_encode($this->data_to_send) . ' ' . $response
            );
            $order->update_status(self::FAILED);
        }
    }

    /**
     * @param $order
     *
     * @return string
     */
    protected function getOrderReference($order): string
    {
        $order_id  = $order->get_id();
        $reference = $order_id . '-' . $order->get_order_number();

        if ($this->customPGReference) {
            $reference = $order->get_order_number();
        }

        // Set reference if custom order meta is set
        if ($this->order_meta_reference != '') {
            $reference_meta = $order->get_meta(sanitize_key($this->order_meta_reference), true);
            $reference      .= !empty($reference_meta) ? '-' . $reference_meta : '';
        }

        return $reference;
    }

    /**
     * @return bool
     */
    protected function setVaultableMethod(): bool
    {
        $wcsession        = WC()->session;
        $subpaymentmethod = $wcsession->get(self::SUB_PAYMENT_METHOD);
        $vaultableMethod  = false;
        if (isset($subpaymentmethod) && $subpaymentmethod != '') {
            $this->data_to_send['PAY_METHOD'] = substr($subpaymentmethod, 0, 2);
            if (isset($this->paymentTypes[$subpaymentmethod]) && $this->paymentTypes[$subpaymentmethod] != '') {
                $this->data_to_send['PAY_METHOD_DETAIL'] = $this->paymentTypes[$subpaymentmethod];
            } else {
                $this->data_to_send['PAY_METHOD_DETAIL'] = '';
            }
            if ($this->data_to_send['PAY_METHOD'] === self::CREDIT_CARD_METHOD) {
                $vaultableMethod = true;
            }
        } elseif ($this->payVault === 'yes') {
            $vaultableMethod = true;
        }

        return $vaultableMethod;
    }

    /**
     * Apply a PayWeb status to an order, from the redirect handler.
     *
     * $status must come from an authenticated source: the server-side PayWeb query, never the
     * browser POST. WC_Gateway_PayGate_Rules::decide() says what it may do; a paid order is
     * never moved.
     *
     * @param $status
     * @param $order
     * @param $transaction_id
     * @param $result_desc
     * @param $pay_request_id
     */
    protected function processOrderFinal($status, $order, $transaction_id, $result_desc, $pay_request_id)
    {
        if ($this->settings[self::ALTERNATECARTHANDLING] == 'yes' && !$order->is_paid()) {
            // Alternative cart mechanism
            $this->alternativeCartMechanism($order);
        }
        switch (WC_Gateway_PayGate_Rules::decide($order->is_paid(), $status)) {
            case WC_Gateway_PayGate_Rules::COMPLETE:
                $this->processOrderFinalSuccess($order, $transaction_id, $pay_request_id);
                $this->halt();
            case WC_Gateway_PayGate_Rules::FAIL:
                if ((int)$status === WC_Gateway_PayGate_Rules::STATUS_DECLINED) {
                    $this->add_notice('The transaction was declined', self::ERROR, $order->get_id());
                    $this->processOrderFinalFail($order, $transaction_id, $pay_request_id, $result_desc);
                } else {
                    $this->add_notice('The transaction was cancelled', self::ERROR, $order->get_id());
                    $this->processOrderFinalCancel($order, $transaction_id, $pay_request_id);
                }
                $this->halt();
            default:
                if ($order->is_paid()) {
                    $this->redirectAfterOrder($this->get_return_url($order));
                    $this->halt();
                }
                $order->add_order_note(
                    'Response via Redirect, no final status (TRANSACTION_STATUS ' . esc_html((string)$status) . '), order status unchanged. RESULT_DESC: ' . esc_html($result_desc) . self::PAYGATE_TRANS_ID . esc_html($transaction_id) . self::PAY_REQUEST_ID_TEXT . esc_html($pay_request_id) . self::BR
                );
                $this->add_notice(
                    'Your payment has not been confirmed yet. If you were charged, the order will update shortly; otherwise you can try again.',
                    self::ERROR
                );
                // Back to the pay page, not the cancel link: the payment may still complete.
                $this->redirectAfterOrder($order->get_checkout_payment_url());
                $this->halt();
        }
    }

    /**
     * @param $redirect_link
     */
    protected function redirectAfterOrder($redirect_link): void
    {
        $redirect_link = str_replace('&amp;', '&', $redirect_link);
        wp_redirect($redirect_link);
    }

    /**
     * Complete an order on an approved transaction.
     *
     * Both paths may complete the order: the notify status is protected by its checksum, and
     * the redirect status comes from the server-side PayWeb query. Whichever arrives first
     * completes it; the other finds the order paid and leaves it.
     *
     * @param $order
     * @param $transaction_id
     * @param $pay_request_id
     * @param string $source 'redirect' or 'ipn'
     */
    protected function processOrderFinalSuccess($order, $transaction_id, $pay_request_id, $source = 'redirect'): void
    {
        WC()->cart->empty_cart();

        if (!$order->is_paid()) {
            $order->add_order_note(
                'Response via ' . $this->pathName($source) . ': Transaction successful' . self::PAYGATE_TRANS_ID . esc_html($transaction_id) . self::PAY_REQUEST_ID_TEXT . esc_html($pay_request_id) . self::BR
            );
            $order->payment_complete((string)$transaction_id);
        }

        $redirect_link = $this->get_return_url($order);
        $this->custom_print_notices();
        $this->redirectAfterOrder($redirect_link);
    }

    /**
     * @param $order
     * @param $transaction_id
     * @param $pay_request_id
     * @param string $source 'redirect' or 'ipn'
     */
    protected function processOrderFinalCancel($order, $transaction_id, $pay_request_id, $source = 'redirect'): void
    {
        $this->failUnpaidOrder(
            $order,
            'Response via ' . $this->pathName($source) . ': User cancelled transaction' . self::PAY_REQUEST_ID_TEXT . esc_html($pay_request_id) . self::BR
        );
    }

    /**
     * @param $order
     * @param $transaction_id
     * @param $pay_request_id
     * @param $result_desc
     * @param string $source 'redirect' or 'ipn'
     */
    protected function processOrderFinalFail($order, $transaction_id, $pay_request_id, $result_desc, $source = 'redirect'): void
    {
        $this->failUnpaidOrder(
            $order,
            'Response via ' . $this->pathName($source) . ', RESULT_DESC: ' . esc_html($result_desc) . self::PAY_REQUEST_ID_TEXT . esc_html($pay_request_id) . self::BR
        );
    }

    /**
     * Set an unpaid order to Failed and send the buyer to the cancel link. A paid order is
     * left as it is, and the buyer goes to the order-received page instead: the cancel link
     * would let WooCommerce cancel it.
     */
    private function failUnpaidOrder($order, string $note): void
    {
        if ($order->is_paid()) {
            $order->add_order_note($note . 'Ignored: the order is already paid.' . self::BR);
            $this->redirectAfterOrder($this->get_return_url($order));

            return;
        }

        if (!$order->has_status(self::FAILED)) {
            $order->add_order_note($note);
            $order->update_status(self::FAILED);
        }
        $this->custom_print_notices();
        $this->redirectAfterOrder($order->get_cancel_order_url());
    }

    private function pathName(string $source): string
    {
        return $source === 'ipn' ? 'Notify' : 'Redirect';
    }

    /**
     * @param $parsed_response
     * @param $customer_id
     */
    protected function vaultCard($parsed_response, $customer_id): void
    {
        if ($this->payVault == 'yes') {
            $this->vaultCard = get_post_meta(
                $customer_id,
                'wc-' . $this->id . self::NEW_PAYMENT_METHOD,
                true
            );

            if ($this->vaultCard && array_key_exists(self::VAULT_ID, $parsed_response)) {
                // Save Token details
                $this->saveToken($parsed_response, $customer_id);
            }
        }
    }

    /**
     * @param array $paygate_data raw notify values, see WC_Gateway_PayGate_Rules::rawFields()
     */
    protected function validateChecksumNotify($paygate_data)
    {
        return WC_Gateway_PayGate_Rules::isValidNotifyChecksum($paygate_data, (string)$this->encryption_key);
    }

    /**
     * Log a message that failed its checksum, with its raw values, whether or not logging is
     * enabled: the payload is the only evidence of why a genuine message fails. PayGate sends
     * masked card numbers only, so there is nothing in it to redact.
     */
    protected function logChecksumFailure(string $path, array $raw): void
    {
        wc_get_logger()->warning(
            $path . ' checksum failed. Raw payload: ' . wp_json_encode($raw),
            ['source' => 'payfast-gateway-web']
        );
    }

    /**
     * @param $fields
     *
     * @return array
     */
    protected function sanitizeFields($fields)
    {
        $result = [];
        foreach ($fields as $key => $field) {
            $result[$key] = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
        }

        return $result;
    }

    /**
     * @param $data
     * @param $customer_id
     */
    protected function saveToken($data, $customer_id)
    {
        $this->vaultId = $data[self::VAULT_ID];
        $card          = isset($data[self::PAYVAULT_DATA_1]) ? $data[self::PAYVAULT_DATA_1] : "";
        $expiry        = isset($data[self::PAYVAULT_DATA_2]) ? $data[self::PAYVAULT_DATA_2] : "";
        $cardType      = isset($data[self::PAY_METHOD_DETAIL]) ? $data[self::PAY_METHOD_DETAIL] : "";

        // Get existing tokens for user
        $tokenDs = new WC_Payment_Token_Data_Store();
        $tokens  = $tokenDs->get_tokens(
            [
                'user_id' => $customer_id,
            ]
        );

        $exists = false;

        foreach ($tokens as $token) {
            if ($token->token == $this->vaultId) {
                $exists = true;
            }
        }

        if (!$exists) {
            $token = new WC_Payment_Token_CC();

            $token->set_token($this->vaultId);
            $token->set_gateway_id($this->id);
            $token->set_card_type(strtolower($cardType));
            $token->set_last4(substr($card, -4));
            $token->set_expiry_month(substr($expiry, 0, 2));
            $token->set_expiry_year(substr($expiry, -4));
            $token->set_user_id($customer_id);
            $token->set_default(true);

            $token->save();
        }
    }
}
