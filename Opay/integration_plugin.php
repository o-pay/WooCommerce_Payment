<?php
/**
* @copyright  Copyright © 2017 O'Pay Electronic Payment Co., Ltd.(https://www.opay.tw)
* @version 1.1.0911
*
* Plugin Name: WooCommerce O'Pay Payment
* Plugin URI: https://www.opay.tw
* Description: O'Pay Integration Payment Gateway for WooCommerce
* Version: 1.1.0911
* Author: O'Pay Electronic Payment Co., Ltd.
* Author URI: https://www.opay.tw
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once(ABSPATH . 'wp-admin/includes/file.php');

    add_action('plugins_loaded', 'allpay_integration_plugin_init', 0);
    
    function allpay_integration_plugin_init()
    {
        # Make sure WooCommerce is setted.
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        class WC_Gateway_Allpay extends WC_Payment_Gateway
        {
            public $allpay_test_mode;
            public $allpay_merchant_id;
            public $allpay_hash_key;
            public $allpay_hash_iv;
            public $allpay_choose_payment;
            public $allpay_payment_methods;
            public $allpay_domain;
            
            public function __construct()
            {
                # Load the translation
                $this->allpay_domain = 'allpay';
                load_plugin_textdomain($this->allpay_domain, false, '/allpay/translation');
                
                # Initialize construct properties
                $this->id = 'allpay';
                
                # Title of the payment method shown on the admin page
                $this->method_title = $this->tran('OPay');

                # If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image
                $this->icon = apply_filters('woocommerce_allpay_icon', plugins_url('images/icon.png', __FILE__));
                
                # Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
                $this->has_fields = true;
                
                # Load the form fields
                $this->init_form_fields();
                
                # Load the administrator settings
                $this->init_settings();
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->allpay_test_mode = $this->get_option('allpay_test_mode');
                $this->allpay_merchant_id = $this->get_option('allpay_merchant_id');
                $this->allpay_hash_key = $this->get_option('allpay_hash_key');
                $this->allpay_hash_iv = $this->get_option('allpay_hash_iv');
                $this->allpay_payment_methods = $this->get_option('allpay_payment_methods');
                
                # Register a action to save administrator settings
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                
                # Register a action to redirect to allPay payment center
                add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
                
                # Register a action to process the callback
                add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));
            }
            
            /**
             * Initialise Gateway Settings Form Fields
             */
            public function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => $this->tran('Enable/Disable'),
                        'type' => 'checkbox',
                        'label' => $this->tran('Enable'),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => $this->tran('Title'),
                        'type' => 'text',
                        'description' => $this->tran('This controls the title which the user sees during checkout.'),
                        'default' => $this->tran('allPay')
                    ),
                    'description' => array(
                        'title' => $this->tran('Description'),
                        'type' => 'textarea',
                        'description' => $this->tran('This controls the description which the user sees during checkout.')
                    ),
                    'allpay_test_mode' => array(
                        'title' => $this->tran('Test Mode'),
                        'label' => $this->tran('Enable'),
                        'type' => 'checkbox',
                        'description' => $this->tran('Test order will add date as prefix.'),
                        'default' => 'no'
                    ),
                    'allpay_merchant_id' => array(
                        'title' => $this->tran('Merchant ID'),
                        'type' => 'text',
                        'default' => '2000132'
                    ),
                    'allpay_hash_key' => array(
                        'title' => $this->tran('Hash Key'),
                        'type' => 'text',
                        'default' => '5294y06JbISpM5x9'
                    ),
                    'allpay_hash_iv' => array(
                        'title' => $this->tran('Hash IV'),
                        'type' => 'text',
                        'default' => 'v77hoKGq4kWxNNIS'
                    ),
                    'allpay_payment_methods' => array(
                        'title' => $this->tran('Payment Method'),
                        'type' => 'multiselect',
                        'description' => $this->tran('Press CTRL and the right button on the mouse to select multi payments.'),
                        'options' => array(
                            'Credit' => $this->get_payment_desc('Credit'),
                            'Credit_3' => $this->get_payment_desc('Credit_3'),
                            'Credit_6' => $this->get_payment_desc('Credit_6'),
                            'Credit_12' => $this->get_payment_desc('Credit_12'),
                            'Credit_18' => $this->get_payment_desc('Credit_18'),
                            'Credit_24' => $this->get_payment_desc('Credit_24'),
                            'WebATM' => $this->get_payment_desc('WebATM'),
                            'ATM' => $this->get_payment_desc('ATM'),
                            'CVS' => $this->get_payment_desc('CVS'),
                            'Alipay' => $this->get_payment_desc('Alipay'),
                            'Tenpay' => $this->get_payment_desc('Tenpay'),
                            'TopUpUsed' => $this->get_payment_desc('TopUpUsed')
                        )
                    )
                );
            }
            
            /**
             * Set the admin title and description
             */
            public function admin_options()
            {
                echo $this->add_next_line('<h3>' . $this->tran('OPay Integration Payments') . '</h3>');
                echo $this->add_next_line('<p>' . $this->tran('OPay is the most popular payment gateway for online shopping in Taiwan') . '</p>');
                echo $this->add_next_line('<table class="form-table">');
                
                # Generate the HTML For the settings form.
                $this->generate_settings_html();
                echo $this->add_next_line('</table>');
            }
            
            /**
             * Display the form when chooses allPay payment
             */
            public function payment_fields()
            {
                if (!empty($this->description)) {
                    echo $this->add_next_line($this->description . '<br /><br />');
                }
                echo $this->tran('Payment Method') . ' : ';
                echo $this->add_next_line('<select name="allpay_choose_payment">');
                foreach ($this->allpay_payment_methods as $payment_method) {
                    echo $this->add_next_line('  <option value="' . $payment_method . '">');
                    echo $this->add_next_line('    ' . $this->get_payment_desc($payment_method));
                    echo $this->add_next_line('  </option>');
                }
                echo $this->add_next_line('</select>');
            }
            
            /**
             * Check the payment method and the chosen payment
             */
            public function validate_fields()
            {
                $choose_payment = $_POST['allpay_choose_payment'];
                $payment_desc = $this->get_payment_desc($choose_payment);
                if ($_POST['payment_method'] == $this->id && !empty($payment_desc)) {
                    $this->allpay_choose_payment = $choose_payment;
                    return true;
                } else {
                    $this->allPay_add_error($this->tran('Invalid payment method.'));
                    return false;
                }
            }
            
            /**
             * Process the payment
             */
            public function process_payment($order_id)
            {
                # Update order status
                $order = new WC_Order($order_id);
                $order->update_status('pending', $this->tran('Awaiting OPay payment'));
                
                # Set the allPay payment type to the order note
                $order->add_order_note($this->allpay_choose_payment, true);
                
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true)
                );
            }
            
            /**
             * Redirect to allPay
             */
            public function receipt_page($order_id)
            {
                # Clean the cart
                global $woocommerce;
                $woocommerce->cart->empty_cart();
                $order = new WC_Order($order_id);
                
                try {
                    $this->invoke_allpay_module();
                    $aio = new AllInOne();
                    $aio->Send['MerchantTradeNo'] = '';
                    $service_url = '';
                    if ($this->allpay_test_mode == 'yes') {
                        $service_url = 'https://payment-stage.allpay.com.tw/Cashier/AioCheckOut';
                        $aio->Send['MerchantTradeNo'] = date('YmdHis');
                    } else {
                        $service_url = 'https://payment.allpay.com.tw/Cashier/AioCheckOut';
                    }
                    $aio->MerchantID = $this->allpay_merchant_id;
                    $aio->HashKey = $this->allpay_hash_key;
                    $aio->HashIV = $this->allpay_hash_iv;
                    $aio->ServiceURL = $service_url;
                    $aio->Send['ReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_Allpay', home_url('/'));
                    $aio->Send['ClientBackURL'] = home_url('?page_id=' . get_option('woocommerce_myaccount_page_id') . '&view-order=' . $order->get_id());
                    $aio->Send['MerchantTradeNo'] .= $order->get_id();
                    $aio->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');

                    // 接收額外回傳參數 提供電子發票使用 v1.1.0911
                    $aio->Send['NeedExtraPaidInfo'] = 'Y';
                    
                    # Set the product info
                    $aio->Send['TotalAmount'] = $order->get_total();
                    array_push(
                        $aio->Send['Items'],
                        array(
                            'Name'     => '網路商品一批',
                            'Price'    => $aio->Send['TotalAmount'],
                            'Currency' => $order->get_currency(),
                            'URL'      => '',
                            'Quantity' => 1
                        )
                    );
                    
                    $aio->Send['TradeDesc'] = 'OPay_module_woocommerce_1.1.0901';
                    
                    # Get the chosen payment and installment
                    $notes = $order->get_customer_order_notes();
                    $choose_payment = '';
                    $choose_installment = '';
                    if (isset($notes[0])) {
                        $chooseParam = explode('_', $notes[0]->comment_content);
                        $choose_payment =isset($chooseParam[0]) ? $chooseParam[0] : '';
                        $choose_installment = isset($chooseParam[1]) ? $chooseParam[1] : '';
                    }
                    $aio->Send['ChoosePayment'] = $choose_payment;
                    
                    # Set the extend information
                    switch ($aio->Send['ChoosePayment']) {
                        case 'Credit':
                            # Do not support UnionPay
                            $aio->SendExtend['UnionPay'] = false;
                            
                            # Credit installment parameters
                            if (!empty($choose_installment)) {
                                $aio->SendExtend['CreditInstallment'] = $choose_installment;
                                $aio->SendExtend['InstallmentAmount'] = $aio->Send['TotalAmount'];
                                $aio->SendExtend['Redeem'] = false;
                            }
                            break;
                        case 'WebATM':
                            break;
                        case 'ATM':
                            $aio->SendExtend['ExpireDate'] = 3;
                            $aio->SendExtend['PaymentInfoURL'] = $aio->Send['ReturnURL'];
                            break;
                        case 'CVS':
                            $aio->SendExtend['Desc_1'] = '';
                            $aio->SendExtend['Desc_2'] = '';
                            $aio->SendExtend['Desc_3'] = '';
                            $aio->SendExtend['Desc_4'] = '';
                            $aio->SendExtend['PaymentInfoURL'] = $aio->Send['ReturnURL'];
                            break;
                        case 'Alipay':
                            $aio->SendExtend['Email'] = $order->billing_email;
                            $aio->SendExtend['PhoneNo'] = $order->billing_phone;
                            $aio->SendExtend['UserName'] = $order->billing_first_name . ' ' . $order->billing_last_name;
                            break;
                        case 'Tenpay':
                            $aio->SendExtend['ExpireTime'] = date('Y/m/d H:i:s', strtotime('+3 days'));
                            break;
                        case 'TopUpUsed':
                            break;
                        default:
                            throw new Exception($this->tran('Invalid payment method.'));
                            break;
                    }
                    $aio->CheckOut();
                    exit;
                } catch(Exception $e) {
                    $this->allPay_add_error($e->getMessage());
                }
            }
            
            /**
             * Process the callback
             */
            public function receive_response()
            {
                $result_msg = '1|OK';
                $order = null;
                try {
                    # Retrieve the check out result
                    $this->invoke_allpay_module();
                    $aio = new AllInOne();
                    $aio->HashKey = $this->allpay_hash_key;
                    $aio->HashIV = $this->allpay_hash_iv;
                    $aio->MerchantID = $this->allpay_merchant_id;
                    $allpay_feedback = $aio->CheckOutFeedback();
                    unset($aio);
                    if(count($allpay_feedback) < 1) {
                        throw new Exception('Get allPay feedback failed.');
                    } else {
                        # Get the cart order id
                        $cart_order_id = $allpay_feedback['MerchantTradeNo'];
                        if ($this->allpay_test_mode == 'yes') {
                            $cart_order_id = substr($allpay_feedback['MerchantTradeNo'], 14);
                        }
                        
                        # Get the cart order amount
                        $order = new WC_Order($cart_order_id);
                        $cart_amount = $order->get_total();
                        
                        # Check the amounts
                        $allpay_amount = $allpay_feedback['TradeAmt'];
                        if (round($cart_amount) != $allpay_amount) {
                            throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                        }
                        else
                        {
                            # Set the common comments
                            $comments = sprintf(
                                $this->tran('Payment Method : %s<br />Trade Time : %s<br />'),
                                $allpay_feedback['PaymentType'],
                                $allpay_feedback['TradeDate']
                            );
                            
                            # Set the getting code comments
                            $return_code = $allpay_feedback['RtnCode'];
                            $return_message = $allpay_feedback['RtnMsg'];
                            $get_code_result_comments = sprintf(
                                $this->tran('Getting Code Result : (%s)%s'),
                                $return_code,
                                $return_message
                            );
                            
                            # Set the payment result comments
                            $payment_result_comments = sprintf(
                                $this->tran('Payment Result : (%s)%s'),
                                $return_code,
                                $return_message
                            );
                            
                            # Set the fail message
                            $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);
                            
                            # Get allPay payment method
                            $allpay_payment_method = $this->get_payment_method($allpay_feedback['PaymentType']);
                            
                            # Set the order comments
                            switch($allpay_payment_method) {
                                
                                case PaymentMethod::Credit:
                                    if ($return_code != 1 and $return_code != 800) {
                                        throw new Exception($fail_msg);
                                    } else {
                                        if (!$this->is_order_complete($order) || ( isset($allpay_feedback['TotalSuccessTimes']) && !empty($allpay_feedback['TotalSuccessTimes']) ) ) {
                                            $this->confirm_order($order, $payment_result_comments, $allpay_feedback);
                                        } else {
                                            # The order already paid or not in the standard procedure, do nothing
                                        }
                                    }
                                break;

                                case PaymentMethod::WebATM:
                                case PaymentMethod::Alipay:
                                case PaymentMethod::Tenpay:
                                case PaymentMethod::TopUpUsed:
                                    if ($return_code != 1 and $return_code != 800) {
                                        throw new Exception($fail_msg);
                                    } else {
                                        if (!$this->is_order_complete($order)) {

                                            $this->confirm_order($order, $payment_result_comments, $allpay_feedback);
                                        } else {
                                            # The order already paid or not in the standard procedure, do nothing
                                        }
                                    }
                                    break;
                                case PaymentMethod::ATM:
                                    if ($return_code != 1 and $return_code != 2 and $return_code != 800) {
                                        throw new Exception($fail_msg);
                                    } else {
                                        if ($return_code == 2) {
                                            # Set the getting code result
                                            $comments .= $this->get_order_comments($allpay_feedback);
                                            $comments .= $get_code_result_comments;
                                            $order->add_order_note($comments);
                                        } else {
                                            if (!$this->is_order_complete($order)) {
                                                $this->confirm_order($order, $payment_result_comments, $allpay_feedback);
                                            } else {
                                                # The order already paid or not in the standard procedure, do nothing
                                            }
                                        }
                                    }
                                    break;
                                case PaymentMethod::CVS:
                                    if ($return_code != 1 and $return_code != 800 and $return_code != 10100073) {
                                        throw new Exception($fail_msg);
                                    } else {
                                        if ($return_code == 10100073) {
                                            # Set the getting code result
                                            $comments .= $this->get_order_comments($allpay_feedback);
                                            $comments .= $get_code_result_comments;
                                            $order->add_order_note($comments);
                                        } else {
                                            if (!$this->is_order_complete($order)) {
                                                $this->confirm_order($order, $payment_result_comments, $allpay_feedback);
                                            } else {
                                                # The order already paid or not in the standard procedure, do nothing
                                            }
                                        }
                                    }
                                    break;
                                default:
                                    throw new Exception('Invalid payment method of the order ' . $cart_order_id . '.');
                                    break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    if (!empty($order)) {
                        $comments .= sprintf($this->tran('Faild To Pay<br />Error : %s<br />'), $error);
                        $order->update_status('failed', $comments);
                    }
                    
                    # Set the failure result
                    $result_msg = '0|' . $error;
                }
                echo $result_msg;
                exit;
            }
            
            
            # Custom function
            
            /**
             * Translate the content
             * @param  string   translate target
             * @return string   translate result
             */
            private function tran($content)
            {
                return __($content, $this->allpay_domain);
            }
            
            /**
             * Get the payment method description
             * @param  string   payment name
             * @return string   payment method description
             */
            private function get_payment_desc($payment_name)
            {
                $payment_desc = array(
                    'Credit' => $this->tran('Credit'),
                    'Credit_3' => $this->tran('Credit(3 Installments)'),
                    'Credit_6' => $this->tran('Credit(6 Installments)'),
                    'Credit_12' => $this->tran('Credit(12 Installments)'),
                    'Credit_18' => $this->tran('Credit(18 Installments)'),
                    'Credit_24' => $this->tran('Credit(24 Installments)'),
                    'WebATM' => $this->tran('WEB-ATM'),
                    'ATM' => $this->tran('ATM'),
                    'CVS' => $this->tran('CVS'),
                    'Alipay' => $this->tran('Alipay'),
                    'Tenpay' => $this->tran('Tenpay'),
                    'TopUpUsed' => $this->tran('TopUpUsed')
                );
                
                return $payment_desc[$payment_name];
            }
            
            /**
             * Add a next line character
             * @param  string   content
             * @return string   content with next line character
             */
            private function add_next_line($content)
            {
                return $content . "\n";
            }
            
            /**
             * Invoke allPay module
             */
            private function invoke_allpay_module()
            {
                if (!class_exists('AllInOne')) {
                    if (!require(plugin_dir_path(__FILE__) . '/lib/AllPay.Payment.Integration.php')) {
                        throw new Exception($this->tran('OPay module missed.'));
                    }
                }
            }
            
            /**
             * Format the version description
             * @param  string   version string
             * @return string   version description
             */
            private function format_version_desc($version)
            {
                return str_replace('.', '_', $version);
            }
            
            /**
             * Add a WooCommerce error message
             * @param  string   error message
             */
            private function allPay_add_error($error_message)
            {
                wc_add_notice($error_message, 'error');
            }
            
            /**
             * Check if the order status is complete
             * @param  object   order
             * @return boolean  is the order complete
             */
            private function is_order_complete($order)
            {
                $status = '';
                $status = (method_exists($order,'get_status') == true )? $order->get_status(): $order->status;

                if ($status == 'pending') {
                    return false;
                } else {
                    return true;
                }
            }
            
            /**
             * Get the payment method from the payment_type
             * @param  string   payment type
             * @return string   payment method
             */
            private function get_payment_method($payment_type)
            {
                $info_pieces = explode('_', $payment_type);
                
                return $info_pieces[0];
            }
            
            /**
             * Get the order comments
             * @param  array    allPay feedback
             * @return string   order comments
             */
            function get_order_comments($allpay_feedback)
            {
                $comments = array(
                    'ATM' => 
                        sprintf(
                          $this->tran('Bank Code : %s<br />Virtual Account : %s<br />Payment Deadline : %s<br />'),
                            $allpay_feedback['BankCode'],
                            $allpay_feedback['vAccount'],
                            $allpay_feedback['ExpireDate']
                        ),
                    'CVS' => 
                        sprintf(
                            $this->tran('Trade Code : %s<br />Payment Deadline : %s<br />'),
                            $allpay_feedback['PaymentNo'],
                            $allpay_feedback['ExpireDate']
                        )
                );
                $payment_method = $this->get_payment_method($allpay_feedback['PaymentType']);
                
                return $comments[$payment_method];
            }
            
            /**
             * Complete the order and add the comments
             * @param  object   order
             */
            
            function confirm_order($order, $comments, $allpay_feedback)
            {
                $order->add_order_note($comments, true);
                $order->payment_complete();

                // 加入信用卡後四碼，提供電子發票開立使用
                if(isset($allpay_feedback['card4no']) && !empty($allpay_feedback['card4no']))
                {
                    add_post_meta( $order->get_id(), 'card4no', $allpay_feedback['card4no'], true);
                }

                // call invoice model
                $invoice_active_ecpay = 0 ;
                $invoice_active_allpay = 0 ;

                $active_plugins = (array) get_option( 'active_plugins', array() );
                $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

                foreach ($active_plugins as $key => $value) {
                    if (strpos($value,'/woocommerce-ecpayinvoice.php') !== false) {
                        $invoice_active_ecpay = 1;
                    }

                    if (strpos($value,'/woocommerce-allpayinvoice.php') !== false) {
                        $invoice_active_allpay = 1;
                    }
                }


                if ($invoice_active_ecpay == 0 && $invoice_active_allpay == 1) { // allpay
                    if (is_file( get_home_path() . '/wp-content/plugins/allpay_invoice/woocommerce-allpayinvoice.php')) {
                        $aConfig_Invoice = get_option('wc_allpayinvoice_active_model');

                        // 記錄目前成功付款到第幾次
                        $nTotalSuccessTimes = ( isset($allpay_feedback['TotalSuccessTimes']) && ( empty($allpay_feedback['TotalSuccessTimes']) || $allpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $allpay_feedback['TotalSuccessTimes'] ;
                        update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                        if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_allpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_allpay_invoice_auto'] == 'auto' ) {
                            do_action('allpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                        }
                    }
                } elseif ($invoice_active_ecpay == 1 && $invoice_active_allpay == 0) { // ecpay
                    if (is_file( get_home_path() . '/wp-content/plugins/ecpay_invoice/woocommerce-ecpayinvoice.php')) {
                        $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

                        // 記錄目前成功付款到第幾次
                        $nTotalSuccessTimes = ( isset($allpay_feedback['TotalSuccessTimes']) && ( empty($allpay_feedback['TotalSuccessTimes']) || $allpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $allpay_feedback['TotalSuccessTimes'] ;
                        update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                        if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_ecpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_ecpay_invoice_auto'] == 'auto' ) {
                            do_action('ecpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                        }
                    }
                }
            }
        }

        class WC_Gateway_Allpay_DCA extends WC_Payment_Gateway
        {
            public $allpay_test_mode;
            public $allpay_merchant_id;
            public $allpay_hash_key;
            public $allpay_hash_iv;
            public $allpay_choose_payment;
            public $allpay_domain;
            public $allpay_dca_payment;

            public function __construct()
            {
                # Load the translation
                $this->allpay_domain = 'allpay_dca';

                # Initialize construct properties
                $this->id = 'allpay_dca';

                # If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image
                $this->icon = '';

                # Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
                $this->has_fields = true;

                # Title of the payment method shown on the admin page
                $this->method_title = __('OPay Paid Automatically', 'allpay');
                $this->method_description = __('Enable to use OPay Paid Automatically', 'allpay');

                # Load the form fields
                $this->init_form_fields();

                # Load the administrator settings
                $this->init_settings();
                $this->title = $this->get_option( 'title' );

                $admin_options = get_option('woocommerce_allpay_settings');
                $this->allpay_test_mode = $admin_options['allpay_test_mode'];
                $this->allpay_merchant_id = $admin_options['allpay_merchant_id'];
                $this->allpay_hash_key = $admin_options['allpay_hash_key'];
                $this->allpay_hash_iv = $admin_options['allpay_hash_iv'];
                $this->allpay_dca_payment = $this->getallpayDcaPayment();

                $this->allpay_dca = get_option( 'woocommerce_allpay_dca',
                    array(
                        array(
                            'periodType' => $this->get_option( 'periodType' ),
                            'frequency' => $this->get_option( 'frequency' ),
                            'execTimes' => $this->get_option( 'execTimes' ),
                        ),
                    )
                );

                # Register a action to save administrator settings
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_dca_details' ) );
                
                # Register a action to redirect to allpay payment center
                add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
                
                # Register a action to process the callback
                add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));
            }
            
            /**
             * Initialise Gateway Settings Form Fields
             */
            public function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => __( 'Enable/Disable', 'woocommerce' ),
                        'type'    => 'checkbox',
                        'label'   => __( 'Enable OPay Paid Automatically', 'allpay' ),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title'       => __( 'Title', 'woocommerce' ),
                        'type'        => 'text',
                        'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                        'default'     => __( 'OPay Paid Automatically', 'allpay' ),
                        'desc_tip'    => true,
                    ),
                    'allpay_dca' => array(
                        'type'        => 'allpay_dca'
                    ),
                );
            }

            public function generate_allpay_dca_html()
            {
                ob_start();

                ?>
                <tr valign="top">
                    <th scope="row" class="titledesc"><?php echo __('OPay Paid Automatically Details', 'allpay'); ?></th>
                    <td class="forminp" id="allpay_dca">
                        <table class="widefat wc_input_table sortable" cellspacing="0" style="width: 600px;">
                            <thead>
                                <tr>
                                    <th class="sort">&nbsp;</th>
                                    <th><?php echo __('Peroid Type', 'allpay'); ?></th>
                                    <th><?php echo __('Frequency', 'allpay'); ?></th>
                                    <th><?php echo __('Execute Times', 'allpay'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="accounts">
                                <?php
                                    if (
                                        sizeof($this->allpay_dca) === 1
                                        && $this->allpay_dca[0]["periodType"] === ''
                                        && $this->allpay_dca[0]["frequency"] === ''
                                        && $this->allpay_dca[0]["execTimes"] === ''
                                    ) {
                                        // 初始預設定期定額方式
                                        $this->allpay_dca = [
                                            [
                                                'periodType' => "Y",
                                                'frequency' => "1",
                                                'execTimes' => "6",
                                            ],
                                            [
                                                'periodType' => "M",
                                                'frequency' => "1",
                                                'execTimes' => "12",
                                            ],
                                        ];
                                    }

                                    $i = -1;
                                    if ( is_array($this->allpay_dca) ) {
                                        foreach ( $this->allpay_dca as $dca ) {
                                            $i++;
                                            echo '<tr class="account">
                                                <td class="sort"></td>
                                                <td><input type="text" class="fieldPeriodType" value="' . esc_attr( $dca['periodType'] ) . '" name="periodType[' . $i . ']" maxlength="1" required /></td>
                                                <td><input type="number" class="fieldFrequency" value="' . esc_attr( $dca['frequency'] ) . '" name="frequency[' . $i . ']"  min="1" max="365" required /></td>
                                                <td><input type="number" class="fieldExecTimes" value="' . esc_attr( $dca['execTimes'] ) . '" name="execTimes[' . $i . ']"  min="2" max="999" required /></td>
                                            </tr>';
                                        }
                                    }
                                ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="4">
                                        <a href="#" class="add button"><?php echo __('add', 'allpay'); ?></a>
                                        <a href="#" class="remove_rows button"><?php echo __('remove', 'allpay'); ?></a>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                        <p class="description"><?php echo __('Don\'t forget to save after make any changes.', 'allpay'); ?></p>
                        <p id="fieldsNotification" style="display: none;"><?php echo __('OPay paid automatically details has been repeatedly, please confirm again.', 'allpay'); ?></p>
                        <script type="text/javascript">
                            jQuery(function() {
                                jQuery('#allpay_dca').on( 'click', 'a.add', function() {
                                    var size = jQuery('#allpay_dca').find('tbody .account').length;

                                    jQuery('<tr class="account">\
                                            <td class="sort"></td>\
                                            <td><input type="text" class="fieldPeriodType" name="periodType[' + size + ']" maxlength="1" required /></td>\
                                            <td><input type="number" class="fieldFrequency" name="frequency[' + size + ']" min="1" max="365" required /></td>\
                                            <td><input type="number" class="fieldExecTimes" name="execTimes[' + size + ']" min="2" max="999" required /></td>\
                                        </tr>').appendTo('#allpay_dca table tbody');
                                    return false;
                                });

                                jQuery('#allpay_dca').on( 'blur', 'input', function() {
                                    let field = this.value.trim();
                                    let indexStart = this.name.search(/[[]/g);
                                    let indexEnd = this.name.search(/[\]]/g);
                                    let fieldIndex = this.name.substring(indexStart + 1, indexEnd);
                                    let fieldPeriodType = document.getElementsByName('periodType[' + fieldIndex + ']')[0].value;

                                    if (
                                        (validateFields.periodType(field) === false && this.className === 'fieldPeriodType') ||
                                        (validateFields.frequency(fieldPeriodType, field) === false && this.className === 'fieldFrequency') ||
                                        (validateFields.execTimes(fieldPeriodType, field) === false && this.className === 'fieldExecTimes')
                                    ){
                                        this.value = '';
                                    }
                                });

                                jQuery('#allpay_dca').on( 'blur', 'tbody', function() {
                                    fields.process();
                                });

                                jQuery('body').on( 'click', '#mainform', function() {
                                    fields.process();
                                });
                            });

                            var data = {
                                'periodType': ['D', 'M', 'Y'],
                                'frequency': ['365', '12', '1'],
                                'execTimes': ['999', '99', '9']
                            };

                            var fields = {
                                get: function() {
                                    var field = jQuery('#allpay_dca').find('tbody .account td input');
                                    var fieldsInput = [];
                                    var fieldsTmp = [];
                                    var i = 0;
                                    Object.keys(field).forEach(function(key) {
                                        if (field[key].value != null) {
                                            i++;
                                            if (i % 3 == 0) {
                                                fieldsTmp.push(field[key].value);
                                                fieldsInput.push(fieldsTmp);
                                                fieldsTmp = [];
                                            } else {
                                                fieldsTmp.push(field[key].value);
                                            }
                                        }
                                    });

                                    return fieldsInput;
                                },
                                check: function(inputs) {
                                    var errorFlag = 0;
                                    inputs.forEach(function(key1, index1) {
                                        inputs.forEach(function(key2, index2) {
                                            if (index1 !== index2) {
                                                if (key1[0] === key2[0] && key1[1] === key2[1] && key1[2] === key2[2]) {
                                                    errorFlag++;
                                                }
                                            }
                                        })
                                    });

                                    return errorFlag;
                                },
                                process: function() {
                                    if (fields.check(fields.get()) > 0) {
                                        document.getElementById('fieldsNotification').style = 'color: #ff0000;';
                                        document.querySelector('input[name="save"]').disabled = true;
                                    } else {
                                        document.getElementById('fieldsNotification').style = 'display: none;';
                                        document.querySelector('input[name="save"]').disabled = false;
                                    }
                                }
                            }

                            var validateFields = {
                                periodType: function(field) {
                                    return (data.periodType.indexOf(field) != -1);
                                },
                                frequency: function(periodType, field) {
                                    let maxFrequency = parseInt(data.frequency[data.periodType.indexOf(periodType)], 10);
                                    return ((field > 0) && ((maxFrequency + 1) > field));
                                },
                                execTimes: function(periodType, field) {
                                    let maxExecTimes = parseInt(data.execTimes[data.periodType.indexOf(periodType)], 10);
                                    return ((field > 1) && ((maxExecTimes + 1) > field));
                                }
                            };
                        </script>
                    </td>
                </tr>
                <?php
                return ob_get_clean();
            }

            /**
             * Save account details table.
             */
            public function save_dca_details()
            {
                $allpayDca = array();

                if ( isset( $_POST['periodType'] ) ) {

                    $periodTypes = array_map( 'wc_clean', $_POST['periodType'] );
                    $frequencys = array_map( 'wc_clean', $_POST['frequency'] );
                    $execTimes = array_map( 'wc_clean', $_POST['execTimes'] );

                    foreach ( $periodTypes as $i => $name ) {
                        if ( ! isset( $periodTypes[ $i ] ) ) {
                            continue;
                        }

                        $allpayDca[] = array(
                            'periodType' => $periodTypes[ $i ],
                            'frequency' => $frequencys[ $i ],
                            'execTimes' => $execTimes[ $i ],
                        );
                    }
                }

                update_option( 'woocommerce_allpay_dca', $allpayDca );
            }

            /**
             * Display the form when chooses allpay payment
             */
            public function payment_fields()
            {
                global $woocommerce;
                $allpayDCA = get_option('woocommerce_allpay_dca');
                $periodTypeMethod = [
                    'Y' => ' ' . __('year', 'allpay'),
                    'M' => ' ' . __('month', 'allpay'),
                    'D' => ' ' . __('day', 'allpay')
                ];
                $allpay = '';
                foreach ($allpayDCA as $dca) {
                    $option = sprintf(
                            __('NT$ %d / %s %s, up to a maximun of %s', 'allpay'),
                            (int)$woocommerce->cart->total,
                            $dca['frequency'],
                            $periodTypeMethod[$dca['periodType']],
                            $dca['execTimes']
                        );
                    $allpay .= '
                        <option value="' . $dca['periodType'] . '_' . $dca['frequency'] . '_' . $dca['execTimes'] . '">
                            ' . $option . '
                        </option>';
                }
                echo '
                    <select id="allpay_dca_payment" name="allpay_dca_payment">
                        <option>------</option>
                        ' . $allpay . '
                    </select>
                    <div id="allpay_dca_show"></div>
                    <hr style="margin: 12px 0px;background-color: #eeeeee;">
                    <p style="font-size: 0.8em;color: #c9302c;">
                        你將使用<strong>歐付寶定期定額信用卡付款</strong>，請留意你所購買的商品為<strong>非單次扣款</strong>商品。
                    </p>
                ';
            }

            public function getallpayDcaPayment()
            {
                global $woocommerce;
                $allpayDCA = get_option('woocommerce_allpay_dca');
                $allpay = [];
                if (is_array($allpayDCA)) {
                    foreach ($allpayDCA as $dca) {
                        array_push($allpay, $dca['periodType'] . '_' . $dca['frequency'] . '_' . $dca['execTimes']);
                    }
                }

                return $allpay;
            }

            /**
             * Translate the content
             * @param  string   translate target
             * @return string   translate result
             */
            private function tran($content)
            {
                return __($content, $this->allpay_domain);
            }

            /**
             * Invoke allPay module
             */
            private function invoke_allpay_module()
            {
                if (!class_exists('AllInOne')) {
                    if (!require(plugin_dir_path(__FILE__) . '/lib/AllPay.Payment.Integration.php')) {
                        throw new Exception($this->tran('allPay module missed.'));
                    }
                }
            }

            /**
             * Check the payment method and the chosen payment
             */
            public function validate_fields()
            {
                $choose_payment = $_POST['allpay_dca_payment'];

                if ($_POST['payment_method'] == $this->id && in_array($choose_payment, $this->allpay_dca_payment)) {
                    $this->allpay_choose_payment = $choose_payment;
                    return true;
                } else {
                    $this->allpay_add_error($this->tran('Invalid payment method.'));
                    return false;
                }
            }

            /**
             * Add a WooCommerce error message
             * @param  string   error message
             */
            private function allpay_add_error($error_message)
            {
                wc_add_notice($error_message, 'error');
            }
            
            /**
             * Process the payment
             */
            public function process_payment($order_id)
            {
                # Update order status
                $order = new WC_Order($order_id);
                $order->update_status('pending', $this->tran('Awaiting allpay payment'));
                
                # Set the allpay payment type to the order note
                $order->add_order_note('Credit_' . $this->allpay_choose_payment, true);
                
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true)
                );
            }

            /**
             * Redirect to allpay
             */
            public function receipt_page($order_id)
            {
                # Clean the cart
                global $woocommerce;
                $woocommerce->cart->empty_cart();
                $order = new WC_Order($order_id);
                
                try {
                    $this->invoke_allpay_module();
                    $aio = new AllInOne();
                    $aio->Send['MerchantTradeNo'] = '';
                    $service_url = '';
                    if ($this->allpay_test_mode == 'yes') {
                        $service_url = 'https://payment-stage.allpay.com.tw/Cashier/AioCheckOut';
                        $aio->Send['MerchantTradeNo'] = date('YmdHis');
                    } else {
                        $service_url = 'https://payment.allpay.com.tw/Cashier/AioCheckOut';
                    }
                    $aio->MerchantID = $this->allpay_merchant_id;
                    $aio->HashKey = $this->allpay_hash_key;
                    $aio->HashIV = $this->allpay_hash_iv;
                    $aio->ServiceURL = $service_url;
                    $aio->Send['ReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_Allpay', home_url('/'));
                    $aio->Send['ClientBackURL'] = home_url('?page_id=' . get_option('woocommerce_myaccount_page_id') . '&view-order=' . $order->get_id());
                    $aio->Send['MerchantTradeNo'] .= $order->get_id();
                    $aio->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');

                    // 接收額外回傳參數 提供電子發票使用 v1.1.0911
                    $aio->Send['NeedExtraPaidInfo'] = 'Y';
                    
                    # Set the product info
                    $aio->Send['TotalAmount'] = $order->get_total();
                    array_push(
                        $aio->Send['Items'],
                        array(
                            'Name'     => '網路商品一批',
                            'Price'    => $aio->Send['TotalAmount'],
                            'Currency' => $order->get_currency(),
                            'URL'      => '',
                            'Quantity' => 1
                        )
                    );
                    
                    $aio->Send['TradeDesc'] = 'OPay_module_woocommerce_1.1.0901';
                    
                    $notes = $order->get_customer_order_notes();
                    $PeriodType = '';
                    $Frequency = '';
                    $ExecTimes = '';
                    if (isset($notes[0])) {
                        list($ChoosePayment, $PeriodType, $Frequency, $ExecTimes) = explode('_', $notes[0]->comment_content);
                    }
                    $aio->Send['ChoosePayment'] = 'Credit';
                    $aio->SendExtend['UnionPay'] = false;
                    $aio->SendExtend['PeriodAmount'] = $aio->Send['TotalAmount'];
                    $aio->SendExtend['PeriodType'] = $PeriodType;
                    $aio->SendExtend['Frequency'] = $Frequency;
                    $aio->SendExtend['ExecTimes'] = $ExecTimes;
                    $aio->SendExtend['PeriodReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_Allpay_DCA', home_url('/'));
                    $aio->CheckOut();
                    exit;
                } catch(Exception $e) {
                    $this->allpay_add_error($e->getMessage());
                }
            }
            
            /**
             * Process the callback
             */
            public function receive_response()
            {
                $result_msg = '1|OK';
                $order = null;
                try {
                    # Retrieve the check out result
                    $this->invoke_allpay_module();
                    $aio = new AllInOne();
                    $aio->HashKey = $this->allpay_hash_key;
                    $aio->HashIV = $this->allpay_hash_iv;
                    $aio->MerchantID = $this->allpay_merchant_id;
                    $allpay_feedback = $aio->CheckOutFeedback();
                    unset($aio);
                    if(count($allpay_feedback) < 1) {
                        throw new Exception('Get allPay feedback failed.');
                    } else {
                        # Get the cart order id
                        $cart_order_id = $allpay_feedback['MerchantTradeNo'];
                        if ($this->allpay_test_mode == 'yes') {
                            $cart_order_id = substr($allpay_feedback['MerchantTradeNo'], 14);
                        }
                        
                        # Get the cart order amount
                        $order = new WC_Order($cart_order_id);
                        $cart_amount = $order->get_total();
                        
                        # Check the amounts
                        $allpay_amount = $allpay_feedback['Amount'];
                        if (round($cart_amount) != $allpay_amount) {
                            throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                        }
                        else
                        {
                            # Set the common comments
                            $comments = sprintf(
                                $this->tran('Payment Method : %s<br />Trade Time : %s<br />'),
                                $allpay_feedback['PaymentType'],
                                $allpay_feedback['TradeDate']
                            );
                            
                            # Set the getting code comments
                            $return_code = $allpay_feedback['RtnCode'];
                            $return_message = $allpay_feedback['RtnMsg'];
                            $get_code_result_comments = sprintf(
                                $this->tran('Getting Code Result : (%s)%s'),
                                $return_code,
                                $return_message
                            );
                            
                            # Set the payment result comments
                            $payment_result_comments = sprintf(
                                $this->tran('Payment Result : (%s)%s'),
                                $return_code,
                                $return_message
                            );
                            
                            # Set the fail message
                            $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);
                            
                            # Set the order comments
                            if ($return_code != 1 and $return_code != 800) {
                                throw new Exception($fail_msg);
                            } else {
                                if (!$this->is_order_complete($order) || ( isset($allpay_feedback['TotalSuccessTimes']) && !empty($allpay_feedback['TotalSuccessTimes']) ) ) {
                                    $this->confirm_order($order, $payment_result_comments, $allpay_feedback);
                                } else {
                                    # The order already paid or not in the standard procedure, do nothing
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    if (!empty($order)) {
                        $comments .= sprintf($this->tran('Faild To Pay<br />Error : %s<br />'), $error);
                        $order->update_status('failed', $comments);
                    }
                    
                    # Set the failure result
                    $result_msg = '0|' . $error;
                }
                echo $result_msg;
                exit;
            }
            
            /**
             * Check if the order status is complete
             * @param  object   order
             * @return boolean  is the order complete
             */
            private function is_order_complete($order)
            {
                $status = '';
                $status = (method_exists($order,'get_status') == true )? $order->get_status(): $order->status;

                if ($status == 'pending') {
                    return false;
                } else {
                    return true;
                }
            }

            /**
             * Complete the order and add the comments
             * @param  object   order
             */
            function confirm_order($order, $comments, $allpay_feedback)
            {
                $order->add_order_note($comments, true);
                $order->payment_complete();

                // 加入信用卡後四碼，提供電子發票開立使用
                if(isset($allpay_feedback['card4no']) && !empty($allpay_feedback['card4no']))
                {
                    add_post_meta( $order->get_id(), 'card4no', $allpay_feedback['card4no'], true);
                }



                // call invoice model
                $invoice_active_ecpay = 0 ;
                $invoice_active_allpay = 0 ;

                $active_plugins = (array) get_option( 'active_plugins', array() );
                $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

                foreach ($active_plugins as $key => $value) {
                    if (strpos($value,'/woocommerce-ecpayinvoice.php') !== false) {
                        $invoice_active_ecpay = 1;
                    }

                    if (strpos($value,'/woocommerce-allpayinvoice.php') !== false) {
                        $invoice_active_allpay = 1;
                    }
                }


                if ($invoice_active_ecpay == 0 && $invoice_active_allpay == 1) { // allpay
                    if (is_file( get_home_path() . '/wp-content/plugins/allpay_invoice/woocommerce-allpayinvoice.php')) {
                        $aConfig_Invoice = get_option('wc_allpayinvoice_active_model');

                        // 記錄目前成功付款到第幾次
                        $nTotalSuccessTimes = ( isset($allpay_feedback['TotalSuccessTimes']) && ( empty($allpay_feedback['TotalSuccessTimes']) || $allpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $allpay_feedback['TotalSuccessTimes'] ;
                        update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                        if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_allpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_allpay_invoice_auto'] == 'auto' ) {
                            
                            do_action('allpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                        }
                    }
                } elseif ($invoice_active_ecpay == 1 && $invoice_active_allpay == 0) { // ecpay
                    if (is_file( get_home_path() . '/wp-content/plugins/ecpay_invoice/woocommerce-ecpayinvoice.php')) {
                        $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

                        // 記錄目前成功付款到第幾次
                        $nTotalSuccessTimes = ( isset($allpay_feedback['TotalSuccessTimes']) && ( empty($allpay_feedback['TotalSuccessTimes']) || $allpay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $allpay_feedback['TotalSuccessTimes'] ;
                        update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                        if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_ecpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_ecpay_invoice_auto'] == 'auto' ) {
                            do_action('ecpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                        }
                    }
                }
            }
        }

        /**
         * Add the Gateway Plugin to WooCommerce
         * */
        function woocommerce_add_allpay_plugin($methods)
        {
            $methods[] = 'WC_Gateway_Allpay';
            $methods[] = 'WC_Gateway_Allpay_DCA';

            return $methods;
        }

        add_filter('woocommerce_payment_gateways', 'woocommerce_add_allpay_plugin');
    }
?>