<?php
/**
 * Created by IntelliJ IDEA.
 * User: avcherkasov
 * Date: 20/04/2017
 * Time: 13:10
 */

class RBKmoney_Payform_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * e.g. http{s}://{your-site}/rbkmoney/payment/redirect
     */
    public function redirectAction()
    {
        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template','payform',array('template' => 'payform/redirect.phtml'));
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    /**
     * e.g. http{s}://{your-site}/rbkmoney/payment/notification
     */
    public function notificationAction()
    {
        $body = file_get_contents('php://input');

        /** @var RBKmoney_Payform_Helper_Data $payform */
        $payform = Mage::helper("payform");;

        if (empty($_SERVER[$payform::SIGNATURE])) {
            static::outputWithExit('Signature missing');
        }

        $requiredFields = [
            $payform::SHOP_ID,
            $payform::INVOICE_ID,
            $payform::PAYMENT_ID,
            $payform::AMOUNT,
            $payform::CURRENCY,
            $payform::CREATED_AT,
            $payform::METADATA,
            $payform::STATUS,
            $payform::EVENT_TYPE,
        ];
        $data = json_decode($body, TRUE);
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                static::outputWithExit('Missing a required field:' . $field);
            }
        }

        if (empty($data[$payform::METADATA][$payform::ORDER_ID])) {
            static::outputWithExit(('Missing order number'));
        }

        $signature = base64_decode($_SERVER[$payform::SIGNATURE]);
        if (!static::verificationSignature($body, $signature, $payform->getCallbackPublicKey())) {
            static::outputWithExit('Signature no verification '. $payform->getCallbackPublicKey());
        }

        if ($data[$payform::SHOP_ID] != $payform->getShopId()) {
            static::outputWithExit('Store number does not match');
        }

        $orderId =  $data[$payform::METADATA][$payform::ORDER_ID];

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);

        switch ($data[$payform::STATUS]) {
            case 'paid':
                $order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, true, 'Payment Success.');
                $order->getPayment()->setLastTransId($data[$payform::INVOICE_ID]);
                $order->getPayment()->setAdditionalInformation($data);
                $order->save();
                break;
            case 'cancelled':
                $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Payment Cancelled.');
                $order->sale();
                break;
            default:
                // nothing
        }

        static::outputWithExit('OK', $payform::HTTP_CODE_OK);
    }

    /**
     * Verification signature
     *
     * @param string $data
     * @param string $signature
     * @param string $public_key
     *
     * @return bool
     */
    private static function verificationSignature($data = '', $signature = '', $public_key = '')
    {
        if (empty($data) || empty($signature) || empty($public_key)) {
            return FALSE;
        }
        $public_key_id = openssl_get_publickey($public_key);
        if (empty($public_key_id)) {
            return FALSE;
        }
        $verify = openssl_verify($data, $signature, $public_key_id, OPENSSL_ALGO_SHA256);
        return ($verify == 1);
    }

    private static function outputWithExit($message, $header = RBKmoney_Payform_Helper_Data::HTTP_CODE_BAD_REQUEST)
    {
        $response = ['message' => $message];
        http_response_code($header);
        echo json_encode($response);
        exit();
    }

}
