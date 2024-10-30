<?php
/*
Plugin Name:       CryptoPanel Payment Gateway for Woocommerce
Description:       The CryptoPanel Payment Gateway for WooCommerce allows you to receive payments in the cryptocurrencies Bitcoin, Bitcoin Cash, Dash and Litecoin
Version:           1.2.1
Author:            LAMP solutions GmbH
Author URI:        https://www.cryptopanel.de
License:           GPLv2
Text Domain:       cryptopanel-payment-gateway
Requires at least: 4.4
Tested up to: 5.6.1
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define('CRYPTOPANEL_PAYMENT_GATEWAY_DIR', plugin_dir_path(__FILE__));


add_action('plugins_loaded', 'init_cryptogate_gateway_class');


function cryptogate_load_textdomain() {
    load_plugin_textdomain( 'cryptopanel-payment-gateway', false, basename( dirname( __FILE__ ) ) . '/languages' );
}


function cryptogate_check_upgrade() {
    $settings = get_option( 'woocommerce_cryptogate_settings', null );
    if(empty($settings) ||!is_array($settings)) {
        return;
    }
    if($settings['enabled'] != "yes") {
        return;
    }
    if(empty($settings['apiKey']) || empty($settings['apiUrl'])) {
        return;
    }

    // Bootstrap Service
    require_once CRYPTOPANEL_PAYMENT_GATEWAY_DIR."/lib/CryptoGatePaymentService.php";
    /** @var CryptoGatePaymentService $service */
    $service = new CryptoGatePaymentService($settings['apiKey'], $settings['apiUrl'], $settings['customer_data'], $settings['currencies']);


    // Get Plugin / WordPress Version Data
    if( !function_exists('get_plugin_data') ){
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    }
    $plugin_data = get_plugin_data( __FILE__ );

    global $wp_version;

    $dataCurrent = $wp_version . "-" .  @$plugin_data['Version'];
    $dataInstalled = get_option( "cryptogate_version_info" );

    if($dataInstalled !== $dataCurrent) {
        update_option("cryptogate_version_info", $dataCurrent);
        try {
            $response = $service->testPayment(array(
                'wordpress_version' => $wp_version,
                'plugin_version' => @$plugin_data['Version']
            ));
        } catch (\Exception $e) {}
    }

}

add_action('plugins_loaded', 'cryptogate_check_upgrade', 10, 2);

add_action( 'init', 'cryptogate_load_textdomain' );

function init_cryptogate_gateway_class(){
    if(!class_exists('WC_Payment_Gateway')) return;

    require_once CRYPTOPANEL_PAYMENT_GATEWAY_DIR."/lib/CryptoGatePaymentService.php";

    class WC_Gateway_CryptoGate extends WC_Payment_Gateway {

        public $gateway="";

        public $icon;

        public $apiKey;

        public $apiUrl;

        public $preselected_currency='';

        /**
        Constructor for the gateway.
         */
        public function __construct() {

            $this->icon=plugin_dir_url( __FILE__ )."src/ico.png";

            $init=get_option("cryptopanel-payment-gateway_init");
            if(empty($init)){
                $this->do_process_admin_options();
                update_option("cryptopanel-payment-gateway_init","true");
            }

            $this->id                 = 'cryptogate';
            $this->icon               = apply_filters('woocommerce_cryptogate_gateway_icon', $this->icon);
            $this->has_fields         = false;
            $this->method_title       = __( 'CryptoPanel Payment Gateway', 'cryptopanel-payment-gateway' );
            $this->method_description = __( 'Enables payments with the CryptoGate Gateway.', 'cryptopanel-payment-gateway' );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );
            $this->instructions = $this->get_option( 'instructions', @$this->description );
            $this->error = $this->get_option( 'error', @$this->error );
            $this->order_status = $this->get_option( 'order_status', 'completed' );
            $this->apiUrl       = $this->get_option( 'apiUrl' );
            $this->apiKey       = $this->get_option( 'apiKey' );
            $this->customer_data = $this->get_option( 'customer_data' );
            $this->currencies = $this->get_option( 'currencies' );
            $this->singlePaymentGateways       = $this->get_option( 'singlePaymentGateways' );
            $this->waitInBlock       = $this->get_option( 'wait_in_block' );
            $this->payNowButton       = $this->get_option( 'pay_now_button' );
            $this->iframeMode       = $this->get_option( 'iframe_mode' );

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            if(empty($this->preselected_currency)) {
                add_action('woocommerce_thankyou_cryptogate', array($this, 'thankyou_page'));
                add_action('woocommerce_thankyou', array($this, 'thankyou_page'), 10, 1);
            }

            add_action('woocommerce_receipt_cryptogate', array($this, 'receipt_page'));

            // CryptoGateer Emails
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

            add_filter('woocommerce_gateway_icon',  array( $this, 'process_cryptogate_icon'),20,2);

            add_action( 'woocommerce_api_wc_gateway_cryptogate',
                array( $this, 'check_callback_response' ) );

        }

        /**
        Initialise Gateway Settings Form Fields.
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'cryptopanel-payment-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Activate CryptoPanel Payment Gateway', 'cryptopanel-payment-gateway' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __( 'Title', 'cryptopanel-payment-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'cryptopanel-payment-gateway' ),
                    'default'     => __( 'CryptoPanel Payment Gateway', 'cryptopanel-payment-gateway' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'cryptopanel-payment-gateway' ),
                    'type'        => 'textarea',
                    'description' => __( 'The description which the user sees during checkout.', 'cryptopanel-payment-gateway' ),
                    'default'     => __('Pay Securely with Bitcoin, Bitcoin Cash or Litecoin.', 'cryptopanel-payment-gateway'),
                    'desc_tip'    => true,
                ),
                'instructions' => array(
                    'title'       => __( 'Note', 'cryptopanel-payment-gateway' ),
                    'type'        => 'textarea',
                    'description' => __( 'A Note, which will be displayed during customers checkout and inside the order email.', 'cryptopanel-payment-gateway' ),
                    'default'     => ' ',
                    'desc_tip'    => true,
                ),
                'error' => array(
                    'title'       => __( 'Error', 'cryptopanel-payment-gateway' ),
                    'type'        => 'textarea',
                    'description' => __( 'An error has occured.', 'cryptopanel-payment-gateway' ),
                    'default'     => __( 'An error has occured. Please try again. If the problem persists please contact us.', 'cryptopanel-payment-gateway' ),
                    'desc_tip'    => true,
                ),
                'apiUrl' => array(
                    'title'       => __( 'CryptoGate URL', 'cryptopanel-payment-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'Your CryptoGate URL', 'cryptopanel-payment-gateway' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'apiKey' => array(
                    'title'       => __( 'Merchant Api Token', 'cryptopanel-payment-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'Your Merchant Api Token', 'cryptopanel-payment-gateway' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'wait_in_block' => array(
                    'title'   => __( 'Double Spend Protection', 'cryptopanel-payment-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Mark order as paid when the transaction is stored in the blockchain', 'cryptopanel-payment-gateway' ),
                    'default' => 'yes'
                ),
                'pay_now_button' => array(
                    'title'   => __( 'Order confirmation payment button', 'cryptopanel-payment-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Display a crypto payment button in the order confirmation email', 'cryptopanel-payment-gateway' ),
                    'default' => 'no'
                ),
                'iframe_mode' => array(
                    'title'   => __( 'Iframe payment page', 'cryptopanel-payment-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Integrate the payment page as an iframe on the website', 'cryptopanel-payment-gateway' ),
                    'default' => 'no'
                ),
                'customer_data' => array(
                    'title'   => __( 'Transmit customer order data', 'cryptopanel-payment-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Display customer order data at <a target="_blank" href="https://member.cryptopanel.de/member/transaction">CryptoPanel -> CryptoGate > Transactions</a>', 'cryptopanel-payment-gateway' ),
                    'description' => __('Under certain cirumstances you need an Order data processing contract (=ADV) from your service provider. In case you are using CryptoPanel, the ADV contract can be requested <a target="_blank" href ="https://www.cryptopanel.de/">here</a>', 'cryptopanel-payment-gateway'),
                    'default' => 'no'
                ),
                'singlePaymentGateways' => array(
                    'title'   => __( 'Single Currency Gateway', 'cryptopanel-payment-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable single currency woocommerce gateways for Bitcoin, Litecoin, Bitcoin Cash and Dash.', 'cryptopanel-payment-gateway' ),
                    'description' => __('', 'cryptopanel-payment-gateway'),
                    'default' => 'no'
                ),
            );
            if(get_option("cryptopanel-payment-gateway_currencies")){
                $currencies=json_decode(get_option("cryptopanel-payment-gateway_currencies"));
                if($currencies){
                    $this->form_fields['currencies'] = array(
                        'title'       => __( 'Currencies', 'cryptopanel-payment-gateway' ),
                        'type'        => 'multiselect',
                        'description' => __( 'Select enabled currencies', 'cryptopanel-payment-gateway' ),
                        'default'     => $currencies,
                        'options'    =>  array_combine($currencies,$currencies),
                        'css' => "height:100px;"
                    );
                }
            }

        }

        public function parent_process_admin_options() {
            return parent::process_admin_options();
        }


        public function receipt_page($order_id) {
            $order = wc_get_order( $order_id );
            $service = new CryptoGatePaymentService($this->apiKey,$this->apiUrl);

            $cryptoGateGatewayUrl = $service->getInternalorderData($order_id)['payment_url'];
            include_once  CRYPTOPANEL_PAYMENT_GATEWAY_DIR."/lib/iframe.php";
        }

        /**
        Output for the order received page.
         */
        public function thankyou_page($order_id) {


            $order = wc_get_order( $order_id );
            $service = new CryptoGatePaymentService($this->apiKey,$this->apiUrl);
            $orderData = $service->getInternalorderData($order_id);
            if($service->validatePayment()){
                if ( $this->instructions ) {
                    if($this->waitInBlock === "yes") {
                        $order_notes = wc_get_order_notes(['order_id' => $order_id, 'type' => 'internal']);
                        if(count($order_notes) < 2) {
                            $order->add_order_note(__( 'Checkout with crypto payment. Waiting for Transaction', 'cryptopanel-payment-gateway' ));
                        }
                    } else {
                        $order->payment_complete($orderData['uuid']);
                    }
                    WC()->cart->empty_cart();
                    //echo wpautop( wptexturize( $this->instructions ) );
                }
            }
            else{
                echo wpautop( wptexturize( $this->error ) );
            }


        }

        /**
        Add content to the WC emails.
         *
        @access public
        @param WC_Order $order
        @param bool $sent_to_admin
        @param bool $plain_text
         */
        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
            if ( $this->instructions && ! $sent_to_admin && strpos($order->payment_method,'cryptogate') === 0 && $order->has_status( 'on-hold' ) ) {
                echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;

                if($this->payNowButton != "yes") {
                    return;
                }

                $service = $this->getCryptoPaymentService();
                $orderData = $service->getInternalorderData($order->get_id());
                if(!$orderData || !isset($orderData['payment_url'])) {
                    return;
                }

                if($plain_text) {
                    echo "\n";
                    echo __( 'Pay now', 'cryptopanel-payment-gateway' );
                    echo ":";
                    echo "\n";
                    echo $orderData['payment_url'];
                    echo "\n";
                } else {
                    $markup = <<<EOF
                    <style type="text/css">
                    .cryptogate-button {
                      background-color: #4CAF50;
                      border: none;
                      color: white;
                      padding: 15px 32px;
                      text-align: center;
                      text-decoration: none;
                      display: inline-block;
                      font-size: 16px;
                    }
                    </style>
                    
                    <a href="%s" class="cryptogate-button">%s</a>
                    <br/><br/>
EOF;
                    printf($markup, $orderData['payment_url'], __('Pay now', 'cryptopanel-payment-gateway'));
                }
            }
        }

        public function payment_fields(){
            if ( $description = $this->get_description() ) {
                echo wpautop( wptexturize( $description ) );
            }
        }

        /**
        Process the payment and return the result.
         *
        @param int $order_id
        @return array
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            $service = $this->getCryptoPaymentService();
            $paymentData = $this->getCryptoPayment($order);
            $service->setInternalOrderData($order_id, $paymentData);

            // Mark as on-hold and send order info email
            $order->update_status('on-hold', __( 'Awaiting payment', 'cryptopanel-payment-gateway' ));

            if($this->iframeMode === "yes") {
                $url = $order->get_checkout_payment_url( true );
            } else {
                $url = $paymentData['payment_url'];
            }

            return array(
                'result'   => 'success',
                'redirect' => $url,
            );
        }

        protected function getCallbackUrl(WC_Order $order) {
            $endpoint = WC()->api_request_url( 'WC_Gateway_CryptoGatePayment' );
            $urlParsed = parse_url($endpoint);
            parse_str(@$urlParsed['query'], $query);
            $query['order_id'] = $order->get_id();
            $urlParsed['query'] = http_build_query($query);

            return $this->buildUrl($urlParsed);
        }

        private function buildUrl(array $parts) {
            return (isset($parts['scheme']) ? "{$parts['scheme']}:" : '') .
                ((isset($parts['user']) || isset($parts['host'])) ? '//' : '') .
                (isset($parts['user']) ? "{$parts['user']}" : '') .
                (isset($parts['pass']) ? ":{$parts['pass']}" : '') .
                (isset($parts['user']) ? '@' : '') .
                (isset($parts['host']) ? "{$parts['host']}" : '') .
                (isset($parts['port']) ? ":{$parts['port']}" : '') .
                (isset($parts['path']) ? "{$parts['path']}" : '') .
                (isset($parts['query']) ? "?{$parts['query']}" : '') .
                (isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
        }

        protected function getCryptoPaymentService() {
            $currencies = !empty($this->preselected_currency) ? [strtoupper($this->preselected_currency)] : $this->currencies;

            /** @var CryptoGatePaymentService $service */
            $service = new CryptoGatePaymentService(
                $this->apiKey,
                $this->apiUrl,
                $this->customer_data,
                $currencies);

            return $service;
        }

        protected function getCustomerSupportData() {
            if( !function_exists('get_plugin_data') ){
                require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            }
            $plugin_data = get_plugin_data( __FILE__ );

            global $wp_version;

            return array(
                'wordpress_version' => $wp_version,
                'plugin_version' => @$plugin_data['Version']
            );
        }


        /**
        Returns the URL of the payment provider. This has to be replaced with the real payment provider URL
         *
        @return string
         */
        protected function getCryptoPayment($order) {
            $service = $this->getCryptoPaymentService();

            $paymentData = $service->createCryptoPayment(
                $order,
                $this->get_return_url( $order ),
                $order->get_cancel_order_url(),
                $this->getCallbackUrl( $order ),
                $this->getCustomerSupportData()
            );

            return $paymentData;
        }


        public function process_admin_options(){
            $this->do_process_admin_options();
            parent::process_admin_options();
        }

        public function do_process_admin_options(){
            $this->init_settings();

            if( !empty($this->get_option( 'apiUrl' ))
                &&
                !empty($this->get_option( 'apiKey' ))){

                $api_url = $this->get_option( 'apiUrl' );
                if(strpos("/api/woocommerce",$api_url) === false){
                    $api_url.="/api/woocommerce";
                }

                $url=$api_url."/supported_currencies?api_key=".$this->get_option( 'apiKey' );
                $url.="&selected_currencies=".implode(",",$this->currencies);

                $response = wp_remote_get($url);

                if ( is_wp_error( $response ) ) {
                    return false;
                }

                $data=json_decode($response['body']);

                update_option("cryptopanel-payment-gateway_icon",$data->image);
                update_option("cryptopanel-payment-gateway_currencies",json_encode($data->currencies));

            }
        }

        public function process_cryptogate_icon($icon = '', $id = ''){
            if (!$id) {
                return $icon;
            }

            if(!empty($this->preselected_currency)) return $icon;

            $payment_gateways = WC()->payment_gateways()->payment_gateways();

            if (!isset($payment_gateways[$id])) {
                return $icon;
            }

            /* @var $payment_gateway WC_Payment_Gateway */
            $payment_gateway    = $payment_gateways[$id];
            if($payment_gateway->get_method_title()==$this->method_title && get_option("cryptopanel-payment-gateway_icon")){

                return '<img src="' . get_option("cryptopanel-payment-gateway_icon") . '" 
                alt="' . esc_attr( $payment_gateway->get_title() ) . '" />';

            }

            return $icon;
        }

    }


    class WC_Gateway_CryptoGate_SingleCurrency extends WC_Gateway_CryptoGate {
        public function __construct() {

            $this->id                 = 'cryptogate_'.$this->preselected_currency;
            $this->has_fields         = false;
            $this->method_title       = sprintf(__( '%s Payment', 'cryptopanel-payment-gateway' ), strtoupper($this->preselected_currency));
            $this->method_description = __( 'Enables payments with the CryptoGate Gateway.', 'cryptopanel-payment-gateway' );
            $this->icon=plugin_dir_url( __FILE__ )."src/".$this->preselected_currency.".png";

            $this->init_settings();
            $this->init_form_fields();

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );


            // Set Data from parent gateway
            $parent_gw_settings = get_option( $this->plugin_id . 'cryptogate' . '_settings', null );
            $this->instructions = @$parent_gw_settings['instructions'];
            $this->error = @$parent_gw_settings['instructions'];
            $this->order_status = @$parent_gw_settings['order_status'];
            $this->apiUrl       = @$parent_gw_settings['apiUrl'];
            $this->apiKey       = @$parent_gw_settings['apiKey'];
            $this->customer_data = @$parent_gw_settings['customer_data'];
            $this->currencies = @$parent_gw_settings['currencies'];
            $this->waitInBlock = @$parent_gw_settings['wait_in_block'];
            $this->payNowButton = @$parent_gw_settings['pay_now_button'];
            $this->iframeMode = @$parent_gw_settings['iframe_mode'];
        }

        public function process_admin_options() {
            $this->init_settings();

            $post_data = $this->get_post_data();

            foreach ( $this->get_form_fields() as $key => $field ) {
                if ( 'title' !== $this->get_field_type( $field ) ) {
                    try {
                        $this->settings[ $key ] = $this->get_field_value( $key, $field, $post_data );
                    } catch ( Exception $e ) {
                        $this->add_error( $e->getMessage() );
                    }
                }
            }
            return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'cryptopanel-payment-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => sprintf(__( 'Activate CryptoPanel Payment Gateway %s', 'cryptopanel-payment-gateway' ), strtoupper($this->preselected_currency)),
                    'default' => 'no'
                ),
                'title' => array(
                    'title'       => __( 'Title', 'cryptopanel-payment-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'cryptopanel-payment-gateway' ),
                    'default'     => sprintf(__( 'CryptoPanel Payment Gateway %s', 'cryptopanel-payment-gateway' ), strtoupper($this->preselected_currency)),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'cryptopanel-payment-gateway' ),
                    'type'        => 'textarea',
                    'description' => __( 'The description which the user sees during checkout.', 'cryptopanel-payment-gateway' ),
                    'default'     => sprintf(__('Pay Securely with %s', 'cryptopanel-payment-gateway'), strtoupper($this->preselected_currency)),
                    'desc_tip'    => true,
                )
            );
        }
    }

    class WC_Gateway_CryptoGate_BTC extends WC_Gateway_CryptoGate_SingleCurrency {
        public $preselected_currency = 'btc';
    }

    class WC_Gateway_CryptoGate_LTC extends WC_Gateway_CryptoGate_SingleCurrency {
        public $preselected_currency = 'ltc';
    }

    class WC_Gateway_CryptoGate_DASH extends WC_Gateway_CryptoGate_SingleCurrency {
        public $preselected_currency = 'dash';
    }

    class WC_Gateway_CryptoGate_BCH extends WC_Gateway_CryptoGate_SingleCurrency {
        public $preselected_currency = 'bch';
    }

}

add_filter( 'woocommerce_payment_gateways', 'add_cryptogate_gateway_class' );
function add_cryptogate_gateway_class( $methods ) {
    $methods[] = 'WC_Gateway_CryptoGate';

    $options = get_option('woocommerce_cryptogate_settings');
    if(!empty($options) && $options['singlePaymentGateways'] == 'yes') {
        $methods[] = 'WC_Gateway_CryptoGate_BTC';
        $methods[] = 'WC_Gateway_CryptoGate_LTC';
        $methods[] = 'WC_Gateway_CryptoGate_BCH';
        $methods[] = 'WC_Gateway_CryptoGate_DASH';
    }

    return $methods;

}

add_action('woocommerce_checkout_process', 'process_cryptogate_payment');
function process_cryptogate_payment(){
    $method = $_POST['payment_method'];
    if (strpos($method,'cryptogate') !== 0) {
        return;
    }
}

/**
Update the order meta with field value
 */
add_action( 'woocommerce_checkout_update_order_meta', 'cryptogate_payment_update_order_meta' );
function cryptogate_payment_update_order_meta( $order_id ) {
    $method = $_POST['payment_method'];
    if (strpos($method,'cryptogate') !== 0) {
        return;
    }
}

/**
Display field value on the order edit page
 */
add_action( 'woocommerce_admin_order_data_after_billing_address', 'cryptogate_checkout_field_display_admin_order_meta', 10, 1 );
function cryptogate_checkout_field_display_admin_order_meta($order) {
    $method = get_post_meta($order->id, '_payment_method', true);
    if (strpos($method,'cryptogate') !== 0) {
        return;
    }

    $mobile = get_post_meta($order->id, 'mobile', true);
    $transaction = get_post_meta($order->id, 'transaction', true);
}


add_action( 'woocommerce_api_wc_gateway_cryptogatepayment', 'cryptogate_callback_handler');
function cryptogate_callback_handler() {
    // Check CryptoGate Settings
    $settings = get_option( 'woocommerce_cryptogate_settings', null );
    if(empty($settings) ||!is_array($settings)) {
        return;
    }
    if($settings['enabled'] != "yes") {
        return;
    }

    // Bootstrap Service
    require_once CRYPTOPANEL_PAYMENT_GATEWAY_DIR."/lib/CryptoGatePaymentService.php";
    /** @var CryptoGatePaymentService $service */
    $service = new CryptoGatePaymentService($settings['apiKey'], $settings['apiUrl'], $settings['customer_data'], $settings['currencies']);

    // Validate Callback
    $validatedData = $service->validatePayment();
    if(!$validatedData) {
        return;
    }

    $order_id = $_REQUEST['order_id'];
    $order = wc_get_order( $order_id );
    $paymentOrderData = $service->getInternalorderData($order_id);

    // Order Id and Payment Id do not match
    if($paymentOrderData['uuid'] != $validatedData['uuid']) {
        return;
    }

    // Completed orders - nothing to do
    if(in_array($order->get_status('callback'), array('processing', 'completed'))) {
        return;
    }

    $waitInBlock = $settings['wait_in_block'] === "yes";
    $isInBlock = $validatedData['inBlock'];

    if($waitInBlock) {
        if($isInBlock) {
            $order->payment_complete($paymentOrderData['uuid']);
        }
    } else {
        $order->payment_complete($paymentOrderData['uuid']);
    }

    die();
}

function cryptogate_onhold_valid_payment_status( $array, WC_Order $instance ) {
    if($instance->get_payment_method() === "cryptogate") {
        $array[] = 'on-hold';
    }
    return $array;
};

add_filter( 'woocommerce_valid_order_statuses_for_payment', 'cryptogate_onhold_valid_payment_status', 10, 2 );

