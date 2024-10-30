<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class CryptoGatePaymentService {

    public function __construct(
        $pApiKey,
        $pApiUrl,
        $pTransmitCustomerData=false,
        $pSelectedCurrencies=[]){
        $this->apiKey=$pApiKey;
        $this->apiUrl=$pApiUrl;
        $this->transmitCustomerData=$pTransmitCustomerData;
        $this->selectedCurrencies=$pSelectedCurrencies;

        if(strpos("/api/woocommerce",$this->apiUrl) === false){
            $this->apiUrl.="/api/woocommerce";
        }

    }


    /**
     * @param array $payment_data
     * @return string
     */
    public function createPaymentToken($payment_data)
    {
        return sha1("salt_12adasd".json_encode($payment_data));
    }

    public function testPayment($supportData) {
        $parameters['token'] = '__not_set__';
        $parameters['api_key'] = $this->apiKey;

        $parameters["amount"] = 1.00;
        $parameters["currency"] = 'EUR';

        $parameters["memo"] = '__not_set__';
        $parameters["seller_name"] = '__not_set__';

        $parameters["first_name"] = "";
        $parameters["last_name"] = "";
        $parameters["email"] = "";

        $parameters["return_url"] = "__not_set__";
        $parameters["callback_url"] = "__not_set__";
        $parameters["ipn_url"] = "__not_set__";
        $parameters["cancel_url"] = "__not_set__";

        $parameters["wordpress_version"] = $supportData["wordpress_version"];
        $parameters["plugin_version"] = $supportData["plugin_version"];

        $response = wp_remote_post($this->apiUrl."/create", array(
            'method' => 'POST',
            'timeout' => 15,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => $parameters,
            'cookies' => array()
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }

        return json_decode($response['body'], true);
    }

    public function createCryptoPayment($order=null,
                                     $returnurl,
                                     $cancelurl,
                                     $callbackUrl,
                                     $supportData) {


        $parameters['token'] = $this->createPaymentToken($order->get_id());
        $parameters['api_key'] = $this->apiKey;

        $parameters["amount"] = $order->data["total"];
        $parameters["currency"] = $order->data['currency'];
        $parameters["selected_currencies"] = $this->selectedCurrencies;

        $parameters["memo"] = sprintf(__('Your order at %s', 'cryptopanel-payment-gateway'), $_SERVER['SERVER_NAME']);
        $parameters["seller_name"] = $order->billing_company;

        if($this->transmitCustomerData && $this->transmitCustomerData!=="no") {
            $parameters["first_name"] = $order->data["billing"]['first_name'];
            $parameters["last_name"] = $order->data["billing"]['last_name'];
            $parameters["email"] = $order->data["billing"]['email'];
        }
        else{
            $parameters["first_name"] = "";
            $parameters["last_name"] = "";
            $parameters["email"] = "";
        }

        $parameters["return_url"] = $returnurl;
        $parameters["callback_url"] = $callbackUrl;
        $parameters["ipn_url"] = $callbackUrl;
        $parameters["cancel_url"] = $cancelurl;

        $parameters["wordpress_version"] = $supportData["wordpress_version"];
        $parameters["plugin_version"] = $supportData["plugin_version"];

        $response = wp_remote_post($this->apiUrl."/create", array(
            'method' => 'POST',
            'timeout' => 15,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => $parameters,
            'cookies' => array()
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }

        return json_decode($response['body'], true);
    }

    public function getInternalorderData($order_id) {
        $dataSerialized = get_post_meta($order_id, 'cryptogate_payment_data', true);
        return unserialize($dataSerialized);
    }

    public function setInternalOrderData($order_id, $data) {
        update_post_meta($order_id, 'cryptogate_payment_data', serialize($data));
    }

    public function validatePayment() {

        $parameters=[
            "uuid" => $_GET["uuid"],
            "token" => $_GET["token"],
            "status" => $_GET["status"],
            'api_key' => $this->apiKey
        ];

        $response = wp_remote_post($this->apiUrl."/verify", array(
            'method' => 'POST',
            'timeout' => 15,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => $parameters,
            'cookies' => array()
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $verify = json_decode($response['body'], true);

        if($verify['token'] == $_GET["token"] && !empty($_GET["token"]) && !empty($verify['token'])) {
            return $verify;
        }

        return false;
    }
}
