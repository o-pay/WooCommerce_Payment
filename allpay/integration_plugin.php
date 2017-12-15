<?php
/**
* @copyright  Copyright © 2017 O'Pay Electronic Payment Co., Ltd.(https://www.opay.tw)
* @version 1.1.1207
*
* Plugin Name: WooCommerce O'Pay Payment
* Plugin URI: https://www.opay.tw
* Description: O'Pay Integration Payment Gateway for WooCommerce
* Version: 1.1.1207
* Author: O'Pay Electronic Payment Co., Ltd.
* Author URI: https://www.opay.tw
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once(ABSPATH . 'wp-admin/includes/file.php');
define( 'WC_OPAY_VERSION', '3.1.6' );
define( 'WC_OPAY_MIN_PHP_VER', '5.0.0' );
define( 'WC_OPAY_MIN_WC_VER', '2.5.0' );

class WC_OPay_Payment {
    
    private static $instance;

    /**
    * Returns the *Singleton* instance of this class.
    *
    * @return Singleton The *Singleton* instance.
    */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Notices (array)
     * @var array
     */
    public $notices = array();

    protected function __construct() {
        add_action( 'admin_init', array( $this, 'check_environment' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    /**
     * Init the plugin after plugins_loaded so environment variables are set.
     */
    public function init() {
        // Don't hook anything else in the plugin if we're in an incompatible environment
        if ( self::get_environment_warning() ) {
            return;
        }


        // Init the gateway itself
        $this->init_gateways();
    }


    /**
     * Allow this class and other classes to add slug keyed notices (to avoid duplication)
     */
    public function add_admin_notice( $slug, $class, $message ) {
        $this->notices[ $slug ] = array(
            'class'   => $class,
            'message' => $message,
        );
    }

    /**
    * check_environment
    */
    public function check_environment() {
        $environment_warning = self::get_environment_warning();

        if ( $environment_warning && is_plugin_active( plugin_basename( __FILE__ ) ) ) {
            $this->add_admin_notice( 'bad_environment', 'error', $environment_warning );
        }
    }

    /**
     * Checks the environment for compatibility problems.  Returns a string with the first incompatibility
     * found or false if the environment has no problems.
     */
    static function get_environment_warning() {

        if ( version_compare( phpversion(), WC_OPAY_MIN_PHP_VER, '<' ) ) {
            $message = __( '%1$sWooCommerce O\'Pay Payment Gateway%2$s - The minimum PHP version required for this plugin is %3$s. You are running %4$s.', 'allpay' );

            return sprintf( $message, WC_OPAY_MIN_PHP_VER, phpversion() );
        }

        if ( ! defined( 'WC_VERSION' ) ) {
            $message = __( '%1$sWooCommerce O\'Pay Payment Gateway%2$s requires WooCommerce to be activated to work.', 'allpay' );

            return sprintf( $message, '<strong>', '</strong>' );
        }

        if ( version_compare( WC_VERSION, WC_OPAY_MIN_WC_VER, '<' ) ) {
            $message = __( '%1$sWooCommerce O\'Pay Payment Gateway%2$s - The minimum WooCommerce version required for this plugin is %3$s. You are running %4$s.', 'allpay' );

            return sprintf( $message, WC_OPAY_MIN_WC_VER, WC_VERSION );
        }

        if ( ! function_exists( 'curl_init' ) ) {
            $message = __( '%1$sWooCommerce O\'Pay Payment Gateway%2$s - cURL is not installed.', 'allpay' );

            return sprintf( $message, '<strong>', '</strong>' );
        }

        return false;
    }

    /**
     * Display any notices we've collected thus far (e.g. for connection, disconnection)
     */
    public function admin_notices() {

        foreach ( (array) $this->notices as $notice_key => $notice ) {
            echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
            echo wp_kses( $notice['message'], array( 
                    'a' => array(
                        'href' => array()
                    ),
                    'strong' => array(),
                ) );
            echo '</p></div>';
        }
    }

    /**
    * Init OPay payment gateway
    */
    public function init_gateways() {

        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        if ( class_exists( 'WC_Payment_Gateway_CC' ) ) {
            include_once( dirname( __FILE__ ) . '/lib/AllPay.Payment.Integration.php' );    // 載入SDK
            include_once( dirname( __FILE__ ) . '/lib/class-wc-gateway-allpay.php' );
        }

        // 載入語系檔
        load_plugin_textdomain( 'allpay', false, plugin_basename( dirname( __FILE__ ) ) . '/translation' );

        add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
        add_filter( 'woocommerce_order_details_after_order_table', array( $this, 'order_details_payment_method' ), 10, 2 );
    }

    function order_details_payment_method($order)
    {
        if ( $this->is_woocommerce_payment_active() ) return;

        $args = array( 
            'post_id' => $order->get_id()
        );

        $comments = get_comments($args);

        $orderDetails = [];
        $search = [
            'Getting Code Result : (10100073)Get CVS Code Succeeded.',
            'Getting Code Result : (2)Get VirtualAccount Succeeded'
        ];
        if (is_array($comments)) {
            foreach ($comments as $comment) {
                if (
                    (strpos($comment->comment_content, '(10100073)') && strpos($comment->comment_content, 'CVS')) ||
                    (strpos($comment->comment_content, '(2)') && strpos($comment->comment_content, 'ATM'))
                ) {
                    $orderDetails = str_replace($search, '', $comment->comment_content);
                }
            }
        }

        if (sizeof($orderDetails) > 0) {
            echo '
                <h2 style="margin-top: 0px;padding-top: 0px;">' . __( 'Order note', 'ecpay' ) . '</h2>
                <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                    <tfoot>
                        <tr>
                            <th scope="row">' . __( 'Payment Method', 'ecpay' ) . ': </th>
                            <td>
                                ' . print_r($orderDetails, true) . '
                            </td>
                        </tr>
                    </tfoot>
                </table>
            ';
        }
    }

    function is_woocommerce_payment_active()
    {
        $payment_active = false ;
        $active_plugins = (array) get_option( 'active_plugins', array() );

        $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

        foreach($active_plugins as $key => $value) {
            if ( ( strpos($value, 'ecpay/integration_plugin.php') !== false ) ) {
                $payment_active = true;
            }
        }

        return $payment_active;
    }

    /**
     * Add the gateways to WooCommerce
     */
    public function add_gateways( $methods ) {
        $methods[] = 'WC_Gateway_Allpay';
        $methods[] = 'WC_Gateway_Allpay_DCA';
        return $methods;
    }
}

WC_OPay_Payment::get_instance();
?>