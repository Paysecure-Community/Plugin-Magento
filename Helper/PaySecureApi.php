<?php
namespace PaySecure\Payments\Helper;

class PaySecureApi
{
    const PAYSECURE_API_URL = "https://api.paysecure.net";

    private $private_key;
    private $brand_id;
    private $logger;
    private $debug;

    public function __construct(
        $private_key,
        $brand_id,
        $debug,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->private_key = $private_key;
        $this->brand_id = $brand_id;
        $this->debug = $debug;
        $this->logger = $logger;
    }

    /**
     * @param $params = [
     *     'success_redirect' => $u . "&action=paid",
     *     'failure_redirect' => $u . "&action=cancel",
     *     'creator_agent' => 'Woocommerce v3 module: '
     *         . SPELL_MODULE_VERSION,
     *     'reference' => (string) $o_id,
     *     'purchase' => [
     *         "currency" => $o->get_currency(),
     *         "products" => [
     *             [
     *                 'name' => 'Payment',
     *                 'price' => round($o->calculate_totals() * 100),
     *                 'quantity' => 1,
     *             ],
     *         ],
     *     ],
     *     'brand_id' => $this->settings['brand-id'],
     *     'client' => [
     *         'email' => $o->get_billing_email(),
     *         'phone' => $o->get_billing_phone(),
     *         'full_name' => $o->get_billing_first_name() . ' '
     *             . $o->get_billing_last_name(),
     *         'street_address' => $o->get_billing_address_1() . ' '
     *             . $o->get_billing_address_2(),
     *         'country' => $o->get_billing_country(),
     *         'city' => $o->get_billing_city(),
     *         'zip_code' => $o->get_shipping_postcode(),
     *         'shipping_street_address' => $o->get_shipping_address_1()
     *             . ' ' . $o->get_shipping_address_2(),
     *         'shipping_country' => $o->get_shipping_country(),
     *         'shipping_city' => $o->get_shipping_city(),
     *         'shipping_zip_code' => $o->get_shipping_postcode(),
     *     ],
     * ]
     * @return array|null [
     *     'id' => (int),
     *     'checkout_url' => (string),
     * ]
     */
    public function createPayment($params): ?array
    {
        $this->logInfo("loading payment form");
        return $this->call('POST', '/purchases/', $params);
    }

    /**
     * @return array [
     *     'available_payment_methods' => ['maestro','mastercard','sepa_credit_transfer_qr','visa'],
     *     'by_country' => ['any' => ['card','sepa_credit_transfer_qr']],
     *     'country_names' => ['any' => 'Other'],
     *     'names' => [
     *         'card' => 'Bank cards',
     *         'sepa_credit_transfer_qr' => 'SEPA Credit Transfer (QR)',
     *     ],
     *     'logos' => [
     *         'card' => [
     *             '/static/images/icon-maestro.svg',
     *             '/static/images/icon-mastercard.svg',
     *             '/static/images/icon-visa.svg',
     *         ],
     *         'sepa_credit_transfer_qr' => '/static/images/icon-sepa_credit_transfer_qr.svg',
     *     ],
     * ]
     */
    public function paymentMethods($currency, $language)
    {
        $this->logInfo("fetching payment methods");
        return $this->call(
            'GET',
            "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}"
        );
    }

    public function purchases($payment_id)
    {
        return $this->call('GET', "/purchases/{$payment_id}/");
    }

    public function wasPaymentSuccessful($payment_id)
    {
        $this->logInfo(sprintf("validating payment: %s", $payment_id));
        $result = $this->purchases($payment_id);
        $this->logInfo(sprintf(
            "success check result: %s",
            var_export($result, true)
        ));
        return $result && $result['status'] == 'paid';
    }

    public function refundPayment($payment_id, $params)
    {
        $this->logInfo(sprintf("refunding payment: %s", $payment_id));

        $result = $this->call('GET', "/purchases/{$payment_id}/refund/", $params);

        $this->logInfo(sprintf(
            "payment refund result: %s",
            var_export($result, true)
        ));

        return $result;
    }

    private function call($method, $route, $params = [])
    {
        $private_key = $this->private_key;

        if (is_array($params)) {
            $params = json_encode($params);
        }

        $this->logInfo($params);

        $response = $this->request(
            $method,
            sprintf("%s/api/v1%s", self::PAYSECURE_API_URL, $route),
            $params,
            [
                'Content-type: application/json',
                'Authorization: ' . "Bearer " . $private_key,
            ]
        );

        $this->logInfo(sprintf('PaySecure Gateway Response: %s', $response));

        $result = json_decode($response, true);
        if (!$result) {
            $this->logError('JSON parsing error/NULL API response');
            return null;
        }

        if (!empty($result['errors'])) {
            $this->logError('API error', $result['errors']);
            return null;
        }

        return $result;
    }

    /**
     * @param $method
     * @param $url
     * @param string|null $params
     * @param array $headers
     * @return bool|string
     */
    private function request($method, $url, string|null $params = null, array $headers = []): bool|string
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        $standard_method = strtoupper($method);

        $this->logger->info(json_encode(func_get_args()));

        if (in_array($standard_method, ['POST', 'PUT'])) {
            $option_name = "CURLOPT_{$standard_method}";
            curl_setopt($ch, constant($option_name), 1);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->logInfo(sprintf(
            "%s `%s`\n%s\n%s",
            $method,
            $url,
            var_export($params, true),
            var_export($headers, true)
        ));
        $response = curl_exec($ch);

        switch ($code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            case 200:
            case 201:
                break;
            default:
                $this->logError(
                    sprintf("%s %s: %d", $method, $url, $code),
                    $response
                );
        }

        if (!$response) {
            $this->logError('curl', curl_error($ch));
        }

        curl_close($ch);
        // phpcs:enable

        return $response;
    }

    public function logInfo($text, $error_data = null): void
    {
        if ($this->debug) {
            $this->logger->info("PaySecure Gateway Info: " . $text . ";");
        }
    }

    public function logError($error_text, $error_data = null): void
    {
        $error_text = "PaySecure Gateway Error: " . $error_text . ";";
        if ($error_data) {
            $error_text .= " ERROR DATA: " . var_export($error_data, true) . ";";
        }
        $this->logger->error($error_text);
    }
}
