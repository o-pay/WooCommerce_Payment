<?php

class WC_Gateway_Opay extends WC_Payment_Gateway
{
    public $opay_test_mode;
    public $opay_merchant_id;
    public $opay_hash_key;
    public $opay_hash_iv;
    public $opay_choose_payment;
    public $opay_payment_methods;
    public $opay_domain;
    
    public function __construct()
    {
        # Load the translation
        $this->opay_domain = 'opay';
        load_plugin_textdomain($this->opay_domain, false, '/opay/translation');
        
        # Initialize construct properties
        $this->id = 'opay';
        
        # Title of the payment method shown on the admin page
        $this->method_title = $this->tran('O\'Pay');

        # If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image
        $this->icon = apply_filters('woocommerce_opay_icon', plugins_url('images/icon.png', dirname( __FILE__ )));
        
        # Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
        $this->has_fields = true;
        
        # Load the form fields
        $this->init_form_fields();
        
        # Load the administrator settings
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->opay_test_mode = $this->get_option('opay_test_mode');
        $this->opay_merchant_id = $this->get_option('opay_merchant_id');
        $this->opay_hash_key = $this->get_option('opay_hash_key');
        $this->opay_hash_iv = $this->get_option('opay_hash_iv');
        $this->opay_payment_methods = $this->get_option('opay_payment_methods');
        
        # Register a action to save administrator settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        
        # Register a action to redirect to O'Pay payment center
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        
        # Register a action to process the callback
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));

        add_action( 'woocommerce_thankyou_opay', array( $this, 'thankyou_page' ) );

        add_filter('woocommerce_available_payment_gateways', array( $this, 'woocs_filter_gateways' ) );
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
                'default' => $this->tran('O\'Pay')
            ),
            'description' => array(
                'title' => $this->tran('Description'),
                'type' => 'textarea',
                'description' => $this->tran('This controls the description which the user sees during checkout.')
            ),
            'opay_test_mode' => array(
                'title' => $this->tran('Test Mode'),
                'label' => $this->tran('Enable'),
                'type' => 'checkbox',
                'description' => $this->tran('Test order will add date as prefix.'),
                'default' => 'no'
            ),
            'opay_merchant_id' => array(
                'title' => $this->tran('Merchant ID'),
                'type' => 'text',
                'default' => '2000132'
            ),
            'opay_hash_key' => array(
                'title' => $this->tran('Hash Key'),
                'type' => 'text',
                'default' => '5294y06JbISpM5x9'
            ),
            'opay_hash_iv' => array(
                'title' => $this->tran('Hash IV'),
                'type' => 'text',
                'default' => 'v77hoKGq4kWxNNIS'
            ),
            'opay_payment_methods' => array(
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
        echo $this->add_next_line('<h3>' . $this->tran('O\'Pay Integration Payments') . '</h3>');
        echo $this->add_next_line('<p>' . $this->tran('O\'Pay is the most popular payment gateway for online shopping in Taiwan') . '</p>');
        echo $this->add_next_line('<table class="form-table">');
        
        # Generate the HTML For the settings form.
        $this->generate_settings_html();
        echo $this->add_next_line('</table>');
    }
    
    /**
     * Display the form when chooses O'Pay payment
     */
    public function payment_fields()
    {
        if (!empty($this->description)) {
            echo $this->add_next_line($this->description . '<br /><br />');
        }
        echo $this->tran('Payment Method') . ' : ';
        echo $this->add_next_line('<select name="opay_choose_payment">');
        foreach ($this->opay_payment_methods as $payment_method) {
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
        $choose_payment = $_POST['opay_choose_payment'];
        $payment_desc = $this->get_payment_desc($choose_payment);
        if ($_POST['payment_method'] == $this->id && !empty($payment_desc)) {
            $this->opay_choose_payment = $choose_payment;
            return true;
        } else {
            $this->opay_add_error($this->tran('Invalid payment method.'));
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
        $order->update_status('pending', $this->tran('Awaiting O\'Pay payment'));
        
        # Set the O'Pay payment type to the order note
        $order->add_order_note($this->opay_choose_payment, true);
        
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }
    
    /**
     * Redirect to O'Pay
     */
    public function receipt_page($order_id)
    {
        # Clean the cart
        global $woocommerce;
        $woocommerce->cart->empty_cart();
        $order = new WC_Order($order_id);
        
        try {
            $this->invoke_opay_module();
            $aio = new AllInOne();
            $aio->Send['MerchantTradeNo'] = '';
            $service_url = '';
            if ($this->opay_test_mode == 'yes') {
                $service_url = 'https://payment-stage.opay.tw/Cashier/AioCheckOut';
                $aio->Send['MerchantTradeNo'] = date('YmdHis');
            } else {
                $service_url = 'https://payment.opay.tw/Cashier/AioCheckOut';
            }
            $aio->MerchantID = $this->opay_merchant_id;
            $aio->HashKey = $this->opay_hash_key;
            $aio->HashIV = $this->opay_hash_iv;
            $aio->ServiceURL = $service_url;
            $aio->Send['ReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_Opay', home_url('/'));
            $aio->Send['ClientBackURL'] = $this->get_return_url($order);
            $aio->Send['MerchantTradeNo'] .= $order->get_id();
            $aio->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');

            // 接收額外回傳參數 提供電子發票使用 v1.1.0911
            $aio->Send['NeedExtraPaidInfo'] = 'Y';
            
            # Set the product info
            $aio->Send['TotalAmount'] = round($order->get_total(), 0);
            array_push(
                $aio->Send['Items'],
                array(
                    'Name'     => '網路商品一批',
                    'Price'    => $aio->Send['TotalAmount'],
                    'Currency' => $order->get_currency(),
                    'Quantity' => 1,
                    'URL'      => '',
                )
            );
            
            $aio->Send['TradeDesc'] = 'OPay_module_woocommerce';
            
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
                case 'TopUpUsed':
                    break;
                default:
                    throw new Exception($this->tran('Invalid payment method.'));
                    break;
            }
            $aio->CheckOut();
            exit;
        } catch(Exception $e) {
            $this->opay_add_error($e->getMessage());
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
            $this->invoke_opay_module();
            $aio = new AllInOne();
            $aio->HashKey = $this->opay_hash_key;
            $aio->HashIV = $this->opay_hash_iv;
            $aio->MerchantID = $this->opay_merchant_id;
            $opay_feedback = $aio->CheckOutFeedback();
            unset($aio);
            if(count($opay_feedback) < 1) {
                throw new Exception('Get O\'Pay feedback failed.');
            } else {
                # Get the cart order id
                $cart_order_id = $opay_feedback['MerchantTradeNo'];
                if ($this->opay_test_mode == 'yes') {
                    $cart_order_id = substr($opay_feedback['MerchantTradeNo'], 14);
                }
                
                # Get the cart order amount
                $order = new WC_Order($cart_order_id);
                $cart_amount = round($order->get_total(), 0);;
                
                # Check the amounts
                $opay_amount = $opay_feedback['TradeAmt'];
                if ($cart_amount != $opay_amount) {
                    throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                }
                else
                {
                    # Set the common comments
                    $comments = sprintf(
                        $this->tran('Payment Method : %s<br />Trade Time : %s<br />'),
                        $opay_feedback['PaymentType'],
                        $opay_feedback['TradeDate']
                    );
                    
                    # Set the getting code comments
                    $return_code = $opay_feedback['RtnCode'];
                    $return_message = $opay_feedback['RtnMsg'];
                    $get_code_result_comments = sprintf(
                        $this->tran('Getting Code Result : (%s)%s'),
                        $return_code,
                        $return_message
                    );
                    
                    # Set the payment result comments
                    $payment_result_comments = sprintf(
                        __($this->method_title . ' Payment Result : (%s)%s', 'opay'),
                        $return_code,
                        $return_message
                    );
                    
                    # Set the fail message
                    $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);
                    
                    # Get O'Pay payment method
                    $opay_payment_method = $this->get_payment_method($opay_feedback['PaymentType']);
                    
                    # Set the order comments
                    switch($opay_payment_method) {
                        
                        case PaymentMethod::Credit:
                            if ($return_code != 1 and $return_code != 800)
                            {
                                throw new Exception($fail_msg);
                            }
                            else
                            {
                                if (!$this->is_order_complete($order))
                                {
                                    $this->confirm_order($order, $payment_result_comments, $opay_feedback);

                                    // 增加付款狀態
                                    add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                }
                                else
                                {
                                    $nOpay_Payment_Tag = get_post_meta($order->id, 'opay_payment_tag', true);
                                    if($nOpay_Payment_Tag == 0)
                                    {
                                        $order->add_order_note($payment_result_comments, 1);
                                        add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                    }
                                }
                            }
                        break;

                        case PaymentMethod::WebATM:
                        case PaymentMethod::TopUpUsed:
                            if ($return_code != 1 and $return_code != 800)
                            {
                                throw new Exception($fail_msg);
                            }
                            else
                            {
                                if (!$this->is_order_complete($order))
                                {

                                    $this->confirm_order($order, $payment_result_comments, $opay_feedback);

                                    // 增加付款狀態
                                    add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                }
                                else
                                {
                                    $nOpay_Payment_Tag = get_post_meta($order->id, 'opay_payment_tag', true);
                                    if($nOpay_Payment_Tag == 0)
                                    {
                                        $order->add_order_note($payment_result_comments, 1);
                                        add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                    }
                                }
                            }
                            break;
                        case PaymentMethod::ATM:
                            if ($return_code != 1 and $return_code != 2 and $return_code != 800)
                            {
                                throw new Exception($fail_msg);
                            }
                            else
                            {
                                if ($return_code == 2)
                                {
                                    # Set the getting code result
                                    $comments .= $this->get_order_comments($opay_feedback);
                                    $comments .= $get_code_result_comments;
                                    $order->add_order_note($comments);

                                    // 紀錄付款資訊提供感謝頁面使用
                                    add_post_meta( $order->id, 'payment_method', 'ATM', true);
                                    add_post_meta( $order->id, 'BankCode', $opay_feedback['BankCode'], true);
                                    add_post_meta( $order->id, 'vAccount', $opay_feedback['vAccount'], true);
                                    add_post_meta( $order->id, 'ExpireDate', $opay_feedback['ExpireDate'], true);
                                }
                                else
                                {
                                    if (!$this->is_order_complete($order))
                                    {
                                        $this->confirm_order($order, $payment_result_comments, $opay_feedback);

                                        // 增加付款狀態
                                        add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                    }
                                    else
                                    {
                                        $nOpay_Payment_Tag = get_post_meta($order->id, 'opay_payment_tag', true);
                                        if($nOpay_Payment_Tag == 0)
                                        {
                                            $order->add_order_note($payment_result_comments, 1);
                                            add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                        }
                                    }
                                }
                            }
                            break;
                        case PaymentMethod::CVS:
                            if ($return_code != 1 and $return_code != 800 and $return_code != 10100073)
                            {
                                throw new Exception($fail_msg);
                            }
                            else
                            {
                                if ($return_code == 10100073)
                                {
                                    # Set the getting code result
                                    $comments .= $this->get_order_comments($opay_feedback);
                                    $comments .= $get_code_result_comments;
                                    $order->add_order_note($comments);

                                    // 紀錄付款資訊提供感謝頁面使用
                                    add_post_meta( $order->id, 'payment_method', 'CVS', true);
                                    add_post_meta( $order->id, 'PaymentNo', $opay_feedback['PaymentNo'], true);
                                    add_post_meta( $order->id, 'ExpireDate', $opay_feedback['ExpireDate'], true);
                                }
                                else
                                {
                                    if (!$this->is_order_complete($order))
                                    {
                                        $this->confirm_order($order, $payment_result_comments, $opay_feedback);

                                        // 增加付款狀態
                                        add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                    }
                                    else
                                    {
                                        $nOpay_Payment_Tag = get_post_meta($order->id, 'opay_payment_tag', true);
                                        if($nOpay_Payment_Tag == 0)
                                        {
                                            $order->add_order_note($payment_result_comments, 1);
                                            add_post_meta( $order->id, 'opay_payment_tag', 1, true);  
                                        }
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
                $order->add_order_note($comments);
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
        return __($content, $this->opay_domain);
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
     * Invoke O'Pay module
     */
    private function invoke_opay_module()
    {
        if (!class_exists('AllInOne')) {
            if (!require(plugin_dir_path(__FILE__) . '/lib/AllPay.Payment.Integration.php')) {
                throw new Exception($this->tran('O\'Pay module missed.'));
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
    private function opay_add_error($error_message)
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
     * @param  array    O'Pay feedback
     * @return string   order comments
     */
    function get_order_comments($opay_feedback)
    {
        $comments = array(
            'ATM' => 
                sprintf(
                  $this->tran('Bank Code : %s<br />Virtual Account : %s<br />Payment Deadline : %s<br />'),
                    $opay_feedback['BankCode'],
                    $opay_feedback['vAccount'],
                    $opay_feedback['ExpireDate']
                ),
            'CVS' => 
                sprintf(
                    $this->tran('Trade Code : %s<br />Payment Deadline : %s<br />'),
                    $opay_feedback['PaymentNo'],
                    $opay_feedback['ExpireDate']
                )
        );
        $payment_method = $this->get_payment_method($opay_feedback['PaymentType']);
        
        return $comments[$payment_method];
    }
    
    /**
     * Complete the order and add the comments
     * @param  object   order
     */
    
    function confirm_order($order, $comments, $opay_feedback)
    {
        $order->add_order_note($comments, true);
        $order->payment_complete();

        // 加入信用卡後四碼，提供電子發票開立使用
        if(isset($opay_feedback['card4no']) && !empty($opay_feedback['card4no']))
        {
            add_post_meta( $order->get_id(), 'card4no', $opay_feedback['card4no'], true);
        }

        // call invoice model
        $invoice_active_ecpay = 0 ;
        $invoice_active_opay = 0 ;

        $active_plugins = (array) get_option( 'active_plugins', array() );
        $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

        foreach ($active_plugins as $key => $value) {
            if (strpos($value,'/woocommerce-ecpayinvoice.php') !== false) {
                $invoice_active_ecpay = 1;
            }

            if (strpos($value,'/woocommerce-opayinvoice.php') !== false) {
                $invoice_active_opay = 1;
            }
        }


        if ($invoice_active_ecpay == 0 && $invoice_active_opay == 1) { // opay
            if (is_file( get_home_path() . '/wp-content/plugins/opay_invoice/woocommerce-opayinvoice.php')) {
                $aConfig_Invoice = get_option('wc_opayinvoice_active_model');

                // 記錄目前成功付款到第幾次
                $nTotalSuccessTimes = ( isset($opay_feedback['TotalSuccessTimes']) && ( empty($opay_feedback['TotalSuccessTimes']) || $opay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $opay_feedback['TotalSuccessTimes'] ;
                update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_opay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_opay_invoice_auto'] == 'auto' ) {
                    do_action('opay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                }
            }
        } elseif ($invoice_active_ecpay == 1 && $invoice_active_opay == 0) { // ecpay
            if (is_file( get_home_path() . '/wp-content/plugins/ecpay_invoice/woocommerce-ecpayinvoice.php')) {
                $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

                // 記錄目前成功付款到第幾次
                $nTotalSuccessTimes = ( isset($opay_feedback['TotalSuccessTimes']) && ( empty($opay_feedback['TotalSuccessTimes']) || $opay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $opay_feedback['TotalSuccessTimes'] ;
                update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_ecpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_ecpay_invoice_auto'] == 'auto' ) {
                    do_action('ecpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                }
            }
        }
    }

    /**
    * Output for the order received page.
    *
    * @param int $order_id
    */
    public function thankyou_page( $order_id ) {

        $this->payment_details( $order_id );

    }

    /**
     * Get payment details and place into a list format.
     *
     * @param int $order_id
     */
    private function payment_details( $order_id = '' ) {

        $account_html = ''; 
        $has_details = false ;
        $a_has_details = array();

        $payment_method = get_post_meta($order_id, 'payment_method', true);

        switch($payment_method) {
            case PaymentMethod::CVS:
                $PaymentNo = get_post_meta($order_id, 'PaymentNo', true);
                $ExpireDate = get_post_meta($order_id, 'ExpireDate', true);

                $a_has_details = array(
                    'PaymentNo' => array(
                                'label' => __( 'PaymentNo', 'opay' ),
                                'value' => $PaymentNo
                            ),
                    'ExpireDate' => array(
                                'label' => __( 'ExpireDate', 'opay' ),
                                'value' => $ExpireDate
                            )
                );

                $has_details = true ;
            break;

            case PaymentMethod::ATM:
                $BankCode = get_post_meta($order_id, 'BankCode', true);
                $vAccount = get_post_meta($order_id, 'vAccount', true);
                $ExpireDate = get_post_meta($order_id, 'ExpireDate', true);

                $a_has_details = array(
                    'BankCode' => array(
                                'label' => __( 'BankCode', 'opay' ),
                                'value' => $BankCode
                            ),
                    'vAccount' => array(
                                'label' => __( 'vAccount', 'opay' ),
                                'value' => $vAccount
                            ),
                    'ExpireDate' => array(
                                'label' => __( 'ExpireDate', 'opay' ),
                                'value' => $ExpireDate
                            )
                );


                $has_details = true ;
            break;
        }

        $account_html .= '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">' . PHP_EOL;

        foreach($a_has_details as $field_key => $field ) {
            $account_html .= '<li class="' . esc_attr( $field_key ) . '">' . wp_kses_post( $field['label'] ) . ': <strong>' . wp_kses_post( wptexturize( $field['value'] ) ) . '</strong></li>' . PHP_EOL ;
        }

        $account_html .= '</ul>';


        if ( $has_details ) {
            echo '<section><h2>' . __( 'Payment details', 'opay' ) . '</h2>' . PHP_EOL . $account_html . '</section>';
        }
    }

    /**
    * 過濾重複付款
    */
    public function woocs_filter_gateways($gateway_list)
    {
       if(isset($_GET['pay_for_order']))
       {
            unset($gateway_list['opay']);
            unset($gateway_list['opay_dca']);
       }
       return $gateway_list;
    }
}

class WC_Gateway_Opay_DCA extends WC_Payment_Gateway
{
    public $opay_test_mode;
    public $opay_merchant_id;
    public $opay_hash_key;
    public $opay_hash_iv;
    public $opay_choose_payment;
    public $opay_domain;
    public $opay_dca_payment;

    public function __construct()
    {
        # Load the translation
        $this->opay_domain = 'opay_dca';

        # Initialize construct properties
        $this->id = 'opay_dca';

        # If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image
        $this->icon = '';

        # Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
        $this->has_fields = true;

        # Title of the payment method shown on the admin page
        $this->method_title = __('O\'Pay Paid Automatically', 'opay');
        $this->method_description = __('Enable to use O\'Pay Paid Automatically', 'opay');

        # Load the form fields
        $this->init_form_fields();

        # Load the administrator settings
        $this->init_settings();
        $this->title = $this->get_option( 'title' );

        $admin_options = get_option('woocommerce_opay_settings');
        $this->opay_test_mode = $admin_options['opay_test_mode'];
        $this->opay_merchant_id = $admin_options['opay_merchant_id'];
        $this->opay_hash_key = $admin_options['opay_hash_key'];
        $this->opay_hash_iv = $admin_options['opay_hash_iv'];
        $this->opay_dca_payment = $this->getopayDcaPayment();

        $this->opay_dca = get_option( 'woocommerce_opay_dca',
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
        
        # Register a action to redirect to opay payment center
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        
        # Register a action to process the callback
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));

        add_action( 'woocommerce_thankyou_opay', array( $this, 'thankyou_page' ) );

        add_filter('woocommerce_available_payment_gateways', array( $this, 'woocs_filter_gateways' ) );
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
                'label'   => __( 'Enable O\'Pay Paid Automatically', 'opay' ),
                'default' => 'no'
            ),
            'title' => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                'default'     => __( 'O\'Pay Paid Automatically', 'opay' ),
                'desc_tip'    => true,
            ),
            'opay_dca' => array(
                'type'        => 'opay_dca'
            ),
        );
    }

    public function generate_opay_dca_html()
    {
        ob_start();

        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo __('O\'Pay Paid Automatically Details', 'opay'); ?></th>
            <td class="forminp" id="opay_dca">
                <table class="widefat wc_input_table sortable" cellspacing="0" style="width: 600px;">
                    <thead>
                        <tr>
                            <th class="sort">&nbsp;</th>
                            <th><?php echo __('Peroid Type', 'opay'); ?></th>
                            <th><?php echo __('Frequency', 'opay'); ?></th>
                            <th><?php echo __('Execute Times', 'opay'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="accounts">
                        <?php
                            if (
                                sizeof($this->opay_dca) === 1
                                && $this->opay_dca[0]["periodType"] === ''
                                && $this->opay_dca[0]["frequency"] === ''
                                && $this->opay_dca[0]["execTimes"] === ''
                            ) {
                                // 初始預設定期定額方式
                                $this->opay_dca = [
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
                            if ( is_array($this->opay_dca) ) {
                                foreach ( $this->opay_dca as $dca ) {
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
                                <a href="#" class="add button"><?php echo __('add', 'opay'); ?></a>
                                <a href="#" class="remove_rows button"><?php echo __('remove', 'opay'); ?></a>
                            </th>
                        </tr>
                    </tfoot>
                </table>
                <p class="description"><?php echo __('Don\'t forget to save after make any changes.', 'opay'); ?></p>
                <p id="fieldsNotification" style="display: none;"><?php echo __('O\'Pay paid automatically details has been repeatedly, please confirm again.', 'opay'); ?></p>
                <script type="text/javascript">
                    jQuery(function() {
                        jQuery('#opay_dca').on( 'click', 'a.add', function() {
                            var size = jQuery('#opay_dca').find('tbody .account').length;

                            jQuery('<tr class="account">\
                                    <td class="sort"></td>\
                                    <td><input type="text" class="fieldPeriodType" name="periodType[' + size + ']" maxlength="1" required /></td>\
                                    <td><input type="number" class="fieldFrequency" name="frequency[' + size + ']" min="1" max="365" required /></td>\
                                    <td><input type="number" class="fieldExecTimes" name="execTimes[' + size + ']" min="2" max="999" required /></td>\
                                </tr>').appendTo('#opay_dca table tbody');
                            return false;
                        });

                        jQuery('#opay_dca').on( 'blur', 'input', function() {
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

                        jQuery('#opay_dca').on( 'blur', 'tbody', function() {
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
                            var field = jQuery('#opay_dca').find('tbody .account td input');
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
        $opayDca = array();

        if ( isset( $_POST['periodType'] ) ) {

            $periodTypes = array_map( 'wc_clean', $_POST['periodType'] );
            $frequencys = array_map( 'wc_clean', $_POST['frequency'] );
            $execTimes = array_map( 'wc_clean', $_POST['execTimes'] );

            foreach ( $periodTypes as $i => $name ) {
                if ( ! isset( $periodTypes[ $i ] ) ) {
                    continue;
                }

                $opayDca[] = array(
                    'periodType' => $periodTypes[ $i ],
                    'frequency' => $frequencys[ $i ],
                    'execTimes' => $execTimes[ $i ],
                );
            }
        }

        update_option( 'woocommerce_opay_dca', $opayDca );
    }

    /**
     * Display the form when chooses opay payment
     */
    public function payment_fields()
    {
        global $woocommerce;
        $opayDCA = get_option('woocommerce_opay_dca');
        $periodTypeMethod = [
            'Y' => ' ' . __('year', 'opay'),
            'M' => ' ' . __('month', 'opay'),
            'D' => ' ' . __('day', 'opay')
        ];
        $opay = '';
        foreach ($opayDCA as $dca) {
            $option = sprintf(
                    __('NT$ %d / %s %s, up to a maximun of %s', 'opay'),
                    (int)$woocommerce->cart->total,
                    $dca['frequency'],
                    $periodTypeMethod[$dca['periodType']],
                    $dca['execTimes']
                );
            $opay .= '
                <option value="' . $dca['periodType'] . '_' . $dca['frequency'] . '_' . $dca['execTimes'] . '">
                    ' . $option . '
                </option>';
        }
        echo '
            <select id="opay_dca_payment" name="opay_dca_payment">
                <option>------</option>
                ' . $opay . '
            </select>
            <div id="opay_dca_show"></div>
            <hr style="margin: 12px 0px;background-color: #eeeeee;">
            <p style="font-size: 0.8em;color: #c9302c;">
                你將使用<strong>歐付寶定期定額信用卡付款</strong>，請留意你所購買的商品為<strong>非單次扣款</strong>商品。
            </p>
        ';
    }

    public function getopayDcaPayment()
    {
        global $woocommerce;
        $opayDCA = get_option('woocommerce_opay_dca');
        $opay = [];
        if (is_array($opayDCA)) {
            foreach ($opayDCA as $dca) {
                array_push($opay, $dca['periodType'] . '_' . $dca['frequency'] . '_' . $dca['execTimes']);
            }
        }

        return $opay;
    }

    /**
     * Translate the content
     * @param  string   translate target
     * @return string   translate result
     */
    private function tran($content)
    {
        return __($content, $this->opay_domain);
    }

    /**
     * Invoke O'Pay module
     */
    private function invoke_opay_module()
    {
        if (!class_exists('AllInOne')) {
            if (!require(plugin_dir_path(__FILE__) . '/lib/AllPay.Payment.Integration.php')) {
                throw new Exception($this->tran('O\'Pay module missed.'));
            }
        }
    }

    /**
     * Check the payment method and the chosen payment
     */
    public function validate_fields()
    {
        $choose_payment = $_POST['opay_dca_payment'];

        if ($_POST['payment_method'] == $this->id && in_array($choose_payment, $this->opay_dca_payment)) {
            $this->opay_choose_payment = $choose_payment;
            return true;
        } else {
            $this->opay_add_error($this->tran('Invalid payment method.'));
            return false;
        }
    }

    /**
     * Add a WooCommerce error message
     * @param  string   error message
     */
    private function opay_add_error($error_message)
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
        $order->update_status('pending', $this->tran('Awaiting opay payment'));
        
        # Set the opay payment type to the order note
        $order->add_order_note('Credit_' . $this->opay_choose_payment, true);
        
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }

    /**
     * Redirect to opay
     */
    public function receipt_page($order_id)
    {
        # Clean the cart
        global $woocommerce;
        $woocommerce->cart->empty_cart();
        $order = new WC_Order($order_id);
        
        try {
            $this->invoke_opay_module();
            $aio = new AllInOne();
            $aio->Send['MerchantTradeNo'] = '';
            $service_url = '';
            if ($this->opay_test_mode == 'yes') {
                $service_url = 'https://payment-stage.opay.tw/Cashier/AioCheckOut';
                $aio->Send['MerchantTradeNo'] = date('YmdHis');
            } else {
                $service_url = 'https://payment.opay.tw/Cashier/AioCheckOut';
            }
            $aio->MerchantID = $this->opay_merchant_id;
            $aio->HashKey = $this->opay_hash_key;
            $aio->HashIV = $this->opay_hash_iv;
            $aio->ServiceURL = $service_url;
            $aio->Send['ReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_Opay', home_url('/'));
            $aio->Send['ClientBackURL'] = $this->get_return_url($order);
            $aio->Send['MerchantTradeNo'] .= $order->get_id();
            $aio->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');

            // 接收額外回傳參數 提供電子發票使用 v1.1.0911
            $aio->Send['NeedExtraPaidInfo'] = 'Y';
            
            # Set the product info
            $aio->Send['TotalAmount'] = round($order->get_total(), 0);;
            array_push(
                $aio->Send['Items'],
                array(
                    'Name'     => '網路商品一批',
                    'Price'    => $aio->Send['TotalAmount'],
                    'Currency' => $order->get_currency(),
                    'Quantity' => 1,
                    'URL'      => '',
                )
            );
            
            $aio->Send['TradeDesc'] = 'opay_module_woocommerce';
            
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
            $aio->SendExtend['PeriodReturnURL'] = add_query_arg('wc-api', 'WC_Gateway_Opay_DCA', home_url('/'));
            $aio->CheckOut();
            exit;
        } catch(Exception $e) {
            $this->opay_add_error($e->getMessage());
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
            $this->invoke_opay_module();
            $aio = new AllInOne();
            $aio->HashKey = $this->opay_hash_key;
            $aio->HashIV = $this->opay_hash_iv;
            $aio->MerchantID = $this->opay_merchant_id;
            $opay_feedback = $aio->CheckOutFeedback();
            unset($aio);
            if(count($opay_feedback) < 1) {
                throw new Exception('Get O\'Pay feedback failed.');
            } else {
                # Get the cart order id
                $cart_order_id = $opay_feedback['MerchantTradeNo'];
                if ($this->opay_test_mode == 'yes') {
                    $cart_order_id = substr($opay_feedback['MerchantTradeNo'], 14);
                }
                
                # Get the cart order amount
                $order = new WC_Order($cart_order_id);
                $cart_amount = round($order->get_total(), 0);;
                
                # Check the amounts
                $opay_amount = $opay_feedback['Amount'];
                if ($cart_amount != $opay_amount) {
                    throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                }
                else
                {
                    # Set the common comments
                    $comments = sprintf(
                        $this->tran('Payment Method : %s<br />Trade Time : %s<br />'),
                        $opay_feedback['PaymentType'],
                        $opay_feedback['TradeDate']
                    );
                    
                    # Set the getting code comments
                    $return_code = $opay_feedback['RtnCode'];
                    $return_message = $opay_feedback['RtnMsg'];
                    $get_code_result_comments = sprintf(
                        $this->tran('Getting Code Result : (%s)%s'),
                        $return_code,
                        $return_message
                    );
                    
                    # Set the payment result comments
                    $payment_result_comments = sprintf(
                        __( $this->method_title . ' Payment Result : (%s)%s', 'opay'),
                        $return_code,
                        $return_message
                    );
                    
                    # Set the fail message
                    $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);
                    
                    # Set the order comments
                    if ($return_code != 1 and $return_code != 800) {
                        throw new Exception($fail_msg);
                    } else {
                        if (!$this->is_order_complete($order) || ( isset($opay_feedback['TotalSuccessTimes']) && !empty($opay_feedback['TotalSuccessTimes']) ) ) {
                            $this->confirm_order($order, $payment_result_comments, $opay_feedback);
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
                $order->add_order_note($comments);
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
    function confirm_order($order, $comments, $opay_feedback)
    {
        $order->add_order_note($comments, true);
        $order->payment_complete();

        // 加入信用卡後四碼，提供電子發票開立使用
        if(isset($opay_feedback['card4no']) && !empty($opay_feedback['card4no']))
        {
            add_post_meta( $order->get_id(), 'card4no', $opay_feedback['card4no'], true);
        }



        // call invoice model
        $invoice_active_ecpay = 0 ;
        $invoice_active_opay = 0 ;

        $active_plugins = (array) get_option( 'active_plugins', array() );
        $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

        foreach ($active_plugins as $key => $value) {
            if (strpos($value,'/woocommerce-ecpayinvoice.php') !== false) {
                $invoice_active_ecpay = 1;
            }

            if (strpos($value,'/woocommerce-opayinvoice.php') !== false) {
                $invoice_active_opay = 1;
            }
        }


        if ($invoice_active_ecpay == 0 && $invoice_active_opay == 1) { // opay
            if (is_file( get_home_path() . '/wp-content/plugins/opay_invoice/woocommerce-opayinvoice.php')) {
                $aConfig_Invoice = get_option('wc_opayinvoice_active_model');

                // 記錄目前成功付款到第幾次
                $nTotalSuccessTimes = ( isset($opay_feedback['TotalSuccessTimes']) && ( empty($opay_feedback['TotalSuccessTimes']) || $opay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $opay_feedback['TotalSuccessTimes'] ;
                update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_opay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_opay_invoice_auto'] == 'auto' ) {
                    
                    do_action('opay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                }
            }
        } elseif ($invoice_active_ecpay == 1 && $invoice_active_opay == 0) { // ecpay
            if (is_file( get_home_path() . '/wp-content/plugins/ecpay_invoice/woocommerce-ecpayinvoice.php')) {
                $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

                // 記錄目前成功付款到第幾次
                $nTotalSuccessTimes = ( isset($opay_feedback['TotalSuccessTimes']) && ( empty($opay_feedback['TotalSuccessTimes']) || $opay_feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $opay_feedback['TotalSuccessTimes'] ;
                update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_ecpay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_ecpay_invoice_auto'] == 'auto' ) {
                    do_action('ecpay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                }
            }
        }
    }

    /**
    * Output for the order received page.
    *
    * @param int $order_id
    */
    public function thankyou_page( $order_id ) {

        $this->payment_details( $order_id );

    }


    /**
     * Get payment details and place into a list format.
     *
     * @param int $order_id
     */
    private function payment_details( $order_id = '' ) {

    }

    /**
    * 過濾重複付款
    */
    public function woocs_filter_gateways($gateway_list)
    {
       if(isset($_GET['pay_for_order']))
       {
            unset($gateway_list['opay']);
            unset($gateway_list['opay_dca']);
       }
       return $gateway_list;
    }
}