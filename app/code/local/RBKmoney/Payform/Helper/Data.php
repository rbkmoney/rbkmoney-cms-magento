<?php

class RBKmoney_Payform_Helper_Data extends Mage_Core_Helper_Data  {

    const COMMON_PATH = 'payment/payform/';

    /**
     * Payment form
     */
    const PAYMENT_FORM_URL = 'https://checkout.rbk.money/payframe/payframe.js';
    const API_URL = 'https://api.rbk.money/v1/';

    /**
     * Create invoice settings
     */
    const CREATE_INVOICE_TEMPLATE_DUE_DATE = 'Y-m-d\TH:i:s\Z';
    const CREATE_INVOICE_DUE_DATE = '+1 days';

    /**
     * HTTP status code
     */
    const HTTP_CODE_OK = 200;
    const HTTP_CODE_CREATED = 201;
    const HTTP_CODE_BAD_REQUEST = 400;

    /**
     * Constants for Callback
     */
    const SHOP_ID = 'shop_id';
    const INVOICE_ID = 'invoice_id';
    const PAYMENT_ID = 'payment_id';
    const AMOUNT = 'amount';
    const CURRENCY = 'currency';
    const CREATED_AT = 'created_at';
    const METADATA = 'metadata';
    const STATUS = 'status';
    const SIGNATURE = 'HTTP_X_SIGNATURE';
    const ORDER_ID = 'order_id';
    const EVENT_TYPE = 'event_type';

    /**
     * Openssl verify
     */
    const OPENSSL_VERIFY_SIGNATURE_IS_CORRECT = 1;

    /**
     * Constants fields settings
     */
    const SETTINGS_SHOP_ID = 'shop_id';
    const SETTINGS_PAYMENT_FORM_LOGO = 'payment_form_logo';
    const SETTINGS_PAYMENT_FORM_COMPANY_NAME = 'payment_form_company_name';
    const SETTINGS_PRIVATE_KEY = 'private_key';
    const SETTINGS_CALLBACK_PUBLIC_KEY = 'callback_public_key';

    public function getShopId() {
        return (int)Mage::getStoreConfig(static::COMMON_PATH . static::SETTINGS_SHOP_ID);
    }

    public function getPrivateKey() {
        return Mage::getStoreConfig(static::COMMON_PATH . static::SETTINGS_PRIVATE_KEY);
    }

    public function getCallbackPublicKey() {
        return Mage::getStoreConfig(static::COMMON_PATH . static::SETTINGS_CALLBACK_PUBLIC_KEY);
    }

    public function getPaymentFormLogo() {
        return Mage::getStoreConfig(static::COMMON_PATH . static::SETTINGS_PAYMENT_FORM_LOGO);
    }

    public function getPaymentFormCompanyName() {
        return Mage::getStoreConfig(static::COMMON_PATH . static::SETTINGS_PAYMENT_FORM_COMPANY_NAME);
    }

    public function getSuccessUrl() {
        return Mage::getUrl('checkout/onepage/success', array('_secure' => false));
    }

    public function getFailUrl() {
        return Mage::getUrl('checkout/onepage/error', array('_secure' => false));
    }

    /**
     * Create invoice access token
     *
     * @param $invoice_id
     *
     * @return string
     * @throws Exception
     */
    public function createInvoiceAccessToken($invoice_id)
    {
        $url = $this->_prepareApiUrl('processing/invoices/' . $invoice_id . '/access_tokens');
        $response = $this->_send($url, $this->_getHeaders(), '', 'access_tokens');

        if ($response['http_code'] != static::HTTP_CODE_CREATED) {
            throw new Exception('Возникла ошибка при создания токена для инвойса');
        }

        $response_decode = json_decode($response['body'], true);
        $access_token = !empty($response_decode['payload']) ? $response_decode['payload'] : '';

        return $access_token;
    }


    /**
     * Create invoice
     *
     * @param $order
     *
     * @return string
     * @throws Exception
     */
    public function createInvoice(Mage_Sales_Model_Order $order)
    {
        $data = [
            'shopID' => $this->getShopId(),
            'amount' => $this->_prepareAmount(number_format($order->getGrandTotal(), 2)),
            'metadata' => $this->_prepareMetadata($order),
            'dueDate' => $this->_prepareDueDate(),
      //      'currency' => $order->getBaseCurrency()->getCode(),
            'currency' => "RUB",
            'product' => $order->getId(),
            'description' => "",
        ];

        $url = $this->_prepareApiUrl('processing/invoices');
        $response = $this->_send($url, $this->_getHeaders(), json_encode($data), 'init_invoice');

        if ($response['http_code'] != static::HTTP_CODE_CREATED) {
            $message = 'Возникла ошибка при создания инвойса';
            throw new Exception($message);
        }

        $response_decode = json_decode($response['body'], true);
        $invoice_id = !empty($response_decode['id']) ? $response_decode['id'] : '';

        return $invoice_id;
    }

    /**
     * Send request
     *
     * @param string $url
     * @param array $headers
     * @param string $data
     *
     * @return array
     */
    function _send($url = '', $headers = [], $data = '')
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($curl);
        $info = curl_getinfo($curl);
        $curlErrNo = curl_errno($curl);

        $response = [
            'http_code' => $info['http_code'],
            'body' => $body,
            'error' => $curlErrNo,
        ];

        curl_close($curl);

        return $response;
    }

    /**
     * Get headers
     *
     * @return array
     */
    private function _getHeaders()
    {
        $headers = [];
        $headers[] = 'X-Request-ID: ' . uniqid();
        $headers[] = 'Authorization: Bearer ' . $this->getPrivateKey();
        $headers[] = 'Content-type: application/json; charset=utf-8';
        $headers[] = 'Accept: application/json';

        return $headers;
    }

    /**
     * Prepare metadata
     *
     * @param Mage_Sales_Model_Order $order Object
     *
     * @return array
     */
    private function _prepareMetadata(Mage_Sales_Model_Order $order)
    {
        return [
            'cms' => 'Magento',
            'cms_version' =>  Mage::getVersion(),
            'module' => 'rbkmoney',
            'order_id' => $order->getId(),
        ];

    }

    /**
     * Prepare due date
     *
     * @return string
     */
    private function _prepareDueDate()
    {
        date_default_timezone_set('UTC');
        return date(RBKmoney_Payform_Helper_Data::CREATE_INVOICE_TEMPLATE_DUE_DATE, strtotime(RBKmoney_Payform_Helper_Data::CREATE_INVOICE_DUE_DATE));
    }

    /**
     * Prepare amount (e.g. 124.24 -> 12424)
     *
     * @param $amount int
     *
     * @return int
     */
    private function _prepareAmount($amount)
    {
        return $amount * 100;
    }

    /**
     * Prepare api URL
     *
     * @param string $path
     * @param array $query_params
     *
     * @return string
     */
    private function _prepareApiUrl($path = '', $query_params = [])
    {
        $url = rtrim(static::API_URL, '/') . '/' . $path;
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        return $url;
    }

}