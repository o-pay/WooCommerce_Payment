<?php

class WC_Gateway_Opay extends WC_Payment_Gateway
{
    public $merchant_id;
    public $hash_key;
    public $hash_iv;
    public $choose_payment;
    public $payment_methods;
    public $domain;
    public $paymentHelper;

    public function __construct()
    {
        # Load the translation
        $this->domain = 'opay';
        $this->context = 'O\'Pay';
        load_plugin_textdomain($this->domain, false, '/'. $this->id .'/translation');

        # Initialize construct properties
        $this->id = 'opay';

        # Title of the payment method shown on the admin page
        $this->method_title = $this->tran($this->context);
        $this->method_description = $this->tran($this->context . ' is the most popular payment gateway for online shopping in Taiwan');

        # If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image
        $this->icon = apply_filters('woocommerce_'. $this->id .'_icon', plugins_url('images/icon.png', dirname( __FILE__ )));

        # Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
        $this->has_fields = true;

        # Load the form fields
        $this->init_form_fields();

        # Load the administrator settings
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->merchant_id = $this->get_option($this->id . '_merchant_id');
        $this->hash_key = $this->get_option($this->id . '_hash_key');
        $this->hash_iv = $this->get_option($this->id . '_hash_iv');
        $this->payment_methods = $this->get_option($this->id . '_payment_methods');

        # Load the helper
        $this->paymentHelper = new paymentHelper();
        $this->paymentHelper->setMerchantId($this->merchant_id);

        # Register a action to save administrator settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        # Register a action to redirect to O'Pay payment center
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        # Register a action to process the callback
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));

        add_action('woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

        add_filter('woocommerce_available_payment_gateways', array( $this, 'woocs_filter_gateways' ) );
    }

    /**
     * 載入參數設定欄位
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        $this->form_fields = include( untrailingslashit( plugin_dir_path( WC_OPAY_MAIN_FILE ) ) . '/lib/settings-'. $this->id .'.php' );
    }

    /**
     * Set the admin title and description
     */
    public function admin_options()
    {
        echo $this->add_next_line('<h3>' . $this->tran($this->context . ' Integration Payments') . '</h3>');
        echo $this->add_next_line('<p>' . $this->tran($this->context. ' is the most popular payment gateway for online shopping in Taiwan') . '</p>');
        echo $this->add_next_line('<table class="form-table">');

        # Generate the HTML For the settings form.
        $this->generate_settings_html();
        echo $this->add_next_line('</table>');
    }

    /**
     * 前端付款方式顯示
     * Display the form when chooses O'Pay payment
     */
    public function payment_fields()
    {
        if (!empty($this->description)) {
            echo $this->add_next_line($this->description . '<br /><br />');
        }
        echo $this->tran('Payment Method') . ' : ';
        echo $this->add_next_line('<select name="'. $this->id .'_choose_payment">');
        foreach ($this->payment_methods as $payment_method) {
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
        $get_choose_payment = $_POST[$this->id . '_choose_payment'];
        $payment_desc = $this->get_payment_desc($get_choose_payment);
        if ($_POST['payment_method'] == $this->id && !empty($payment_desc)) {
            $this->choose_payment = $get_choose_payment;
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
        $order->update_status('pending', $this->tran('Awaiting '. $this->context .' payment'));

        # Set the O'Pay payment type to the order note
        $order->add_order_note($this->choose_payment, true);

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
            $this->invoke_module();

            # Get the chosen payment and installment
            $notes = $order->get_customer_order_notes();
            $choose_payment = '';
            $choose_installment = '';
            if (isset($notes[0])) {
                $chooseParam = explode('_', $notes[0]->comment_content);
                $choose_payment =isset($chooseParam[0]) ? $chooseParam[0] : '';
                $choose_installment = isset($chooseParam[1]) ? $chooseParam[1] : '';
            }

            $data = array(
               'choosePayment' => $choose_payment,
               'hashKey' => $this->hash_key,
               'hashIv' => $this->hash_iv,
               'returnUrl' => add_query_arg('wc-api', 'WC_Gateway_Opay', home_url('/')),
               'clientBackUrl' => $this->get_return_url($order),
               'orderId' => $order->get_id(),
               'total' => $order->get_total(),
               'itemName' => '網路商品一批',
               'cartName' => 'woocommerce',
               'currency' => $order->get_currency(),
               'needExtraPaidInfo' => 'Y',
            );

            $this->paymentHelper->checkout($data);

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
            $this->invoke_module();

            $data = array(
                'hashKey' => $this->hash_key,
                'hashIv'=> $this->hash_iv,
            );
            $feedback = $this->paymentHelper->getFeedback($data);

            if(count($feedback) < 1) {
                throw new Exception('Get '. $this->context .' feedback failed.');
            } else {
                # Get the cart order id
                $cart_order_id = $feedback['MerchantTradeNo'];
                if ($this->paymentHelper->isTestMode($this->merchant_id)) {
                    $cart_order_id = substr($feedback['MerchantTradeNo'], 10);
                }

                # Get the cart order amount
                $order = new WC_Order($cart_order_id);
                $cart_amount = round($order->get_total(), 0);

                # Check the amounts
                $amount = $feedback['TradeAmt'];
                if ($cart_amount != $amount) {
                    throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                }
                else
                {
                    # Set the common comments
                    $comments = sprintf(
                        $this->tran('Payment Method : %s<br />Trade Time : %s<br />'),
                        $feedback['PaymentType'],
                        $feedback['TradeDate']
                    );

                    # Set the getting code comments
                    $return_code = $feedback['RtnCode'];
                    $return_message = $feedback['RtnMsg'];
                    $get_code_result_comments = sprintf(
                        $this->tran('Getting Code Result : (%s)%s'),
                        $return_code,
                        $return_message
                    );

                    # Set the payment result comments
                    $payment_result_comments = sprintf(
                        $this->tran($this->method_title . ' Payment Result : (%s)%s'),
                        $return_code,
                        $return_message
                    );

                    # Set the fail message
                    $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);

                    # Get O'Pay payment method
                    $payment_method = $this->get_payment_method($feedback['PaymentType']);

                    # Set the order comments
                    switch($payment_method) {

                        case PaymentMethod::Credit:
                            if ($return_code != 1 and $return_code != 800)
                            {
                                throw new Exception($fail_msg);
                            }
                            else
                            {
                                if (!$this->is_order_complete($order))
                                {
                                    $this->confirm_order($order, $payment_result_comments, $feedback);

                                    // 增加付款狀態
                                    add_post_meta( $order->id, $this->id.'_payment_tag', 1, true);
                                }
                                else
                                {
                                    # The order already paid or not in the standard procedure, do nothing
                                    $n_Payment_Tag = get_post_meta($order->id, $this->id.'_payment_tag', true);
                                    if($n_Payment_Tag == 0)
                                    {
                                        $order->add_order_note($payment_result_comments, 1);
                                        add_post_meta( $order->id, $this->id.'_payment_tag', 1, true);
                                    }
                                }
                            }
                            break;

                        case PaymentMethod::WebATM:
                        case PaymentMethod::TopUpUsed:
                        case PaymentMethod::WeiXinpay:
                            if ($return_code != 1 and $return_code != 800)
                            {
                                throw new Exception($fail_msg);
                            }
                            else
                            {
                                if (!$this->is_order_complete($order))
                                {

                                    $this->confirm_order($order, $payment_result_comments, $feedback);

                                    // 增加付款狀態
                                    add_post_meta( $order->id, $this->id . '_payment_tag', 1, true);
                                }
                                else
                                {
                                    $n_Payment_Tag = get_post_meta($order->id, $this->id . '_payment_tag', true);
                                    if($n_Payment_Tag == 0)
                                    {
                                        $order->add_order_note($payment_result_comments, 1);
                                        add_post_meta( $order->id, $this->id . '_payment_tag', 1, true);
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
                                    $comments .= $this->get_order_comments($feedback);
                                    $comments .= $get_code_result_comments;
                                    $order->add_order_note($comments);

                                    // 紀錄付款資訊提供感謝頁面使用
                                    add_post_meta( $order->id, 'payment_method', 'ATM', true);
                                    add_post_meta( $order->id, 'BankCode', $feedback['BankCode'], true);
                                    add_post_meta( $order->id, 'vAccount', $feedback['vAccount'], true);
                                    add_post_meta( $order->id, 'ExpireDate', $feedback['ExpireDate'], true);
                                }
                                else
                                {
                                    if (!$this->is_order_complete($order))
                                    {
                                        $this->confirm_order($order, $payment_result_comments, $feedback);

                                        // 增加付款狀態
                                        add_post_meta( $order->id, $this->id . '_payment_tag', 1, true);
                                    }
                                    else
                                    {
                                        $n_Payment_Tag = get_post_meta($order->id, $this->id . '_payment_tag', true);
                                        if($n_Payment_Tag == 0)
                                        {
                                            $order->add_order_note($payment_result_comments, 1);
                                            add_post_meta( $order->id, $this->id . '_payment_tag', 1, true);
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
                                    $comments .= $this->get_order_comments($feedback);
                                    $comments .= $get_code_result_comments;
                                    $order->add_order_note($comments);

                                    // 紀錄付款資訊提供感謝頁面使用
                                    add_post_meta( $order->id, 'payment_method', 'CVS', true);
                                    add_post_meta( $order->id, 'PaymentNo', $feedback['PaymentNo'], true);
                                    add_post_meta( $order->id, 'ExpireDate', $feedback['ExpireDate'], true);
                                }
                                else
                                {
                                    if (!$this->is_order_complete($order))
                                    {
                                        $this->confirm_order($order, $payment_result_comments, $feedback);

                                        // 增加付款狀態
                                        add_post_meta( $order->id, $this->id . '_payment_tag', 1, true);
                                    }
                                    else
                                    {
                                        $n_Payment_Tag = get_post_meta($order->id, $this->id . '_payment_tag', true);
                                        if($n_Payment_Tag == 0)
                                        {
                                            $order->add_order_note($payment_result_comments, 1);
                                            add_post_meta( $order->id, $this->id . '_payment_tag', 1, true);
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
            'TopUpUsed' => $this->tran('TopUpUsed'),
            'WeiXinpay' => $this->tran('WeiXinpay')
        );

        return $payment_desc[$payment_name];
    }

    /**
     * Translate the content
     * @param  string   translate target
     * @return string   translate result
     */
    private function tran($content)
    {
        return __($content, $this->domain);
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
    private function invoke_module()
    {
        if (!class_exists('AllInOne')) {
            if (!require(plugin_dir_path(__FILE__) . '/lib/AllPay.Payment.Integration.php')) {
                throw new Exception($this->tran($this->context . ' module missed.'));
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
    function get_order_comments($feedback)
    {
        $comments = array(
            'ATM' =>
                sprintf(
                    $this->tran('Bank Code : %s<br />Virtual Account : %s<br />Payment Deadline : %s<br />'),
                    $feedback['BankCode'],
                    $feedback['vAccount'],
                    $feedback['ExpireDate']
                ),
            'CVS' =>
                sprintf(
                    $this->tran('Trade Code : %s<br />Payment Deadline : %s<br />'),
                    $feedback['PaymentNo'],
                    $feedback['ExpireDate']
                )
        );

        $payment_method = $this->get_payment_method($feedback['PaymentType']);

        return $comments[$payment_method];
    }

    /**
     * Complete the order and add the comments
     * @param  object   order
     */

    function confirm_order($order, $comments, $feedback)
    {
        $order->add_order_note($comments, true);
        $order->payment_complete();

        // 加入信用卡後四碼，提供電子發票開立使用
        if(isset($feedback['card4no']) && !empty($feedback['card4no']))
        {
            add_post_meta( $order->get_id(), 'card4no', $feedback['card4no'], true);
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
                $nTotalSuccessTimes = ( isset($feedback['TotalSuccessTimes']) && ( empty($feedback['TotalSuccessTimes']) || $feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $feedback['TotalSuccessTimes'] ;
                update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_opay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_opay_invoice_auto'] == 'auto' ) {
                    do_action('opay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                }
            }
        } elseif ($invoice_active_ecpay == 1 && $invoice_active_opay == 0) { // ecpay
            if (is_file( get_home_path() . '/wp-content/plugins/ecpay_invoice/woocommerce-ecpayinvoice.php')) {
                $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

                // 記錄目前成功付款到第幾次
                $nTotalSuccessTimes = ( isset($feedback['TotalSuccessTimes']) && ( empty($feedback['TotalSuccessTimes']) || $feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $feedback['TotalSuccessTimes'] ;
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
                                'label' => $this->tran('PaymentNo'),
                                'value' => $PaymentNo
                            ),
                    'ExpireDate' => array(
                                'label' => $this->tran('ExpireDate'),
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
                                'label' => $this->tran('BankCode'),
                                'value' => $BankCode
                            ),
                    'vAccount' => array(
                                'label' => $this->tran('vAccount'),
                                'value' => $vAccount
                            ),
                    'ExpireDate' => array(
                                'label' => $this->tran('ExpireDate'),
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
            echo '<section><h2>' . $this->tran('Payment details') . '</h2>' . PHP_EOL . $account_html . '</section>';
        }
    }

    /**
    * 過濾重複付款
    */
    public function woocs_filter_gateways($gateway_list)
    {
       if(isset($_GET['pay_for_order']))
       {
            unset($gateway_list[$this->id]);
            unset($gateway_list[$this->id . '_dca']);
       }
       return $gateway_list;
    }
}

class WC_Gateway_Opay_DCA extends WC_Payment_Gateway
{
    public $merchant_id;
    public $hash_key;
    public $hash_iv;
    public $choose_payment;
    public $domain;
    public $dca_payment;
    public $paymentHelper;

    public function __construct()
    {
        # Load the translation
        $this->domain = 'opay';
        $this->context = 'O\'Pay';

        # Initialize construct properties
        $this->id = 'opay_dca';

        # If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image
        $this->icon = '';

        # Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration)
        $this->has_fields = true;

        # Title of the payment method shown on the admin page
        $this->method_title       = $this->tran($this->context . ' Paid Automatically');
        $this->method_description = $this->tran('Enable to use '. $this->context .' Paid Automatically');

        # Load the form fields
        $this->init_form_fields();

        # Load the administrator settings
        $this->init_settings();
        $this->title = $this->get_option( 'title' );
        $admin_options = get_option('woocommerce_'. $this->domain .'_settings');
        $this->merchant_id = $admin_options[$this->domain . '_merchant_id'];
        $this->hash_key = $admin_options[$this->domain . '_hash_key'];
        $this->hash_iv = $admin_options[$this->domain . '_hash_iv'];
        $this->dca_payment = $this->getDcaPayment();


        $this->opay_dca = get_option( 'woocommerce_'. $this->id,
            array(
                array(
                    'periodType' => $this->get_option( 'periodType' ),
                    'frequency' => $this->get_option( 'frequency' ),
                    'execTimes' => $this->get_option( 'execTimes' ),
                ),
            )
        );

        # Load the helper
        $this->paymentHelper = new paymentHelper();
        $this->paymentHelper->setMerchantId($this->merchant_id);

        # Register a action to save administrator settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_dca_details' ) );

        # Register a action to redirect to opay payment center
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        # Register a action to process the callback
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'receive_response'));

        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

        add_filter('woocommerce_available_payment_gateways', array( $this, 'woocs_filter_gateways' ) );
    }

    /**
     * 設定後端欄位
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'woocommerce' ),
                'type'    => 'checkbox',
                'label'   => $this->tran('Enable '. $this->context .' Paid Automatically'),
                'default' => 'no'
            ),
            'title' => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                'default'     => $this->tran($this->context . ' Paid Automatically'),
                'desc_tip'    => true,
            ),
            $this->id => array(
                'type'        => $this->id
            ),
        );
    }

    public function generate_opay_dca_html()
    {
        ob_start();

        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo $this->tran($this->context . ' Paid Automatically Details'); ?></th>
            <td class="forminp" id="opay_dca">
                <table class="widefat wc_input_table sortable" cellspacing="0" style="width: 600px;">
                    <thead>
                        <tr>
                            <th class="sort">&nbsp;</th>
                            <th><?php echo $this->tran('Peroid Type'); ?></th>
                            <th><?php echo $this->tran('Frequency'); ?></th>
                            <th><?php echo $this->tran('Execute Times'); ?></th>
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
                                <a href="#" class="add button"><?php echo $this->tran('add'); ?></a>
                                <a href="#" class="remove_rows button"><?php echo $this->tran('remove'); ?></a>
                            </th>
                        </tr>
                    </tfoot>
                </table>
                <p class="description"><?php echo $this->tran('Don\'t forget to save after make any changes.'); ?></p>
                <p id="fieldsNotification" style="display: none;"><?php echo $this->tran($this->context . ' paid automatically details has been repeatedly, please confirm again.'); ?></p>
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
        $dca = array();

        if ( isset( $_POST['periodType'] ) ) {

            $periodTypes = array_map( 'wc_clean', $_POST['periodType'] );
            $frequencys = array_map( 'wc_clean', $_POST['frequency'] );
            $execTimes = array_map( 'wc_clean', $_POST['execTimes'] );

            foreach ( $periodTypes as $i => $name ) {
                if ( ! isset( $periodTypes[ $i ] ) ) {
                    continue;
                }

                $dca[] = array(
                    'periodType' => $periodTypes[ $i ],
                    'frequency' => $frequencys[ $i ],
                    'execTimes' => $execTimes[ $i ],
                );
            }
        }

        update_option( 'woocommerce_'. $this->id, $dca );
    }

    /**
     * 前端付款方式顯示
     * Display the form when chooses opay payment
     */
    public function payment_fields()
    {
        global $woocommerce;
        $dca = get_option('woocommerce_'. $this->id);
        $periodTypeMethod = [
            'Y' => ' ' . $this->tran('year'),
            'M' => ' ' . $this->tran('month'),
            'D' => ' ' . $this->tran('day')
        ];
        $opay = '';
        foreach ($dca as $dca) {
            $option = sprintf(
                    $this->tran('NT$ %d / %s %s, up to a maximun of %s'),
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
            <select id="'. $this->id .'_payment" name="'. $this->id .'_payment">
                <option>------</option>
                ' . $opay . '
            </select>
            <div id="'. $this->id .'_show"></div>
            <hr style="margin: 12px 0px;background-color: #eeeeee;">
            <p style="font-size: 0.8em;color: #c9302c;">
                你將使用<strong>歐付寶定期定額信用卡付款</strong>，請留意你所購買的商品為<strong>非單次扣款</strong>商品。
            </p>
        ';
    }

    public function getDcaPayment()
    {
        global $woocommerce;
        $dca = get_option('woocommerce_'. $this->id);
        $result = [];
        if (is_array($dca)) {
            foreach ($dca as $row) {
                array_push($result, $row['periodType'] . '_' . $row['frequency'] . '_' . $row['execTimes']);
            }
        }

        return $result;
    }

    /**
     * Translate the content
     * @param  string   translate target
     * @return string   translate result
     */
    private function tran($content)
    {
        return __($content, $this->domain);
    }

    /**
     * Invoke O'Pay module
     */
    private function invoke_module()
    {
        if (!class_exists('AllInOne')) {
            if (!require(plugin_dir_path(__FILE__) . '/lib/AllPay.Payment.Integration.php')) {
                throw new Exception($this->tran($this->context . ' module missed.'));
            }
        }
    }

    /**
     * Check the payment method and the chosen payment
     */
    public function validate_fields()
    {
        $get_choose_payment = $_POST[$this->id.'_payment'];

        if ($_POST['payment_method'] == $this->id && in_array($get_choose_payment, $this->dca_payment)) {
            $this->choose_payment = $get_choose_payment;
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
        $order->add_order_note('Credit_' . $this->choose_payment, true);

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
            $this->invoke_module();
            $aio = new AllInOne();
            $aio->Send['MerchantTradeNo'] = '';
            $service_url = '';
            if ($this->paymentHelper->isTestMode($this->merchant_id)) {
                $service_url = 'https://payment-stage.'. $this->domain .'.tw/Cashier/AioCheckOut';
                $aio->Send['MerchantTradeNo'] = date('YmdHis');
            } else {
                $service_url = 'https://payment.'. $this->domain .'.tw/Cashier/AioCheckOut';
            }
            $aio->MerchantID = $this->merchant_id;
            $aio->HashKey = $this->hash_key;
            $aio->HashIV = $this->hash_iv;
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

            $aio->Send['TradeDesc'] = $this->domain . '_module_woocommerce';

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
            $this->invoke_module();
            $aio = new AllInOne();
            $aio->HashKey = $this->hash_key;
            $aio->HashIV = $this->hash_iv;
            $aio->MerchantID = $this->merchant_id;
            $feedback = $aio->CheckOutFeedback();
            unset($aio);
            if(count($feedback) < 1) {
                throw new Exception('Get '. $this->context .' feedback failed.');
            } else {
                # Get the cart order id
                $cart_order_id = $feedback['MerchantTradeNo'];
                if ($this->paymentHelper->isTestMode($this->merchant_id)) {
                    $cart_order_id = substr($feedback['MerchantTradeNo'], 14);
                }

                # Get the cart order amount
                $order = new WC_Order($cart_order_id);
                $cart_amount = round($order->get_total(), 0);

                # Check the amounts
                $amount = $feedback['Amount'];
                if ($cart_amount != $amount) {
                    throw new Exception('Order ' . $cart_order_id . ' amount are not identical.');
                }
                else
                {
                    # Set the common comments
                    $comments = sprintf(
                        $this->tran('Payment Method : %s<br />Trade Time : %s<br />'),
                        $feedback['PaymentType'],
                        $feedback['TradeDate']
                    );

                    # Set the getting code comments
                    $return_code = $feedback['RtnCode'];
                    $return_message = $feedback['RtnMsg'];
                    $get_code_result_comments = sprintf(
                        $this->tran('Getting Code Result : (%s)%s'),
                        $return_code,
                        $return_message
                    );

                    # Set the payment result comments
                    $payment_result_comments = sprintf(
                        $this->tran($this->method_title . ' Payment Result : (%s)%s'),
                        $return_code,
                        $return_message
                    );

                    # Set the fail message
                    $fail_message = sprintf('Order %s Exception.(%s: %s)', $cart_order_id, $return_code, $return_message);

                    # Set the order comments
                    if ($return_code != 1 and $return_code != 800) {
                        throw new Exception($fail_msg);
                    } else {
                        if (!$this->is_order_complete($order) || ( isset($feedback['TotalSuccessTimes']) && !empty($feedback['TotalSuccessTimes']) ) ) {
                            $this->confirm_order($order, $payment_result_comments, $feedback);
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
    function confirm_order($order, $comments, $feedback)
    {
        $order->add_order_note($comments, true);
        $order->payment_complete();

        // 加入信用卡後四碼，提供電子發票開立使用
        if(isset($feedback['card4no']) && !empty($feedback['card4no']))
        {
            add_post_meta( $order->get_id(), 'card4no', $feedback['card4no'], true);
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
                $nTotalSuccessTimes = ( isset($feedback['TotalSuccessTimes']) && ( empty($feedback['TotalSuccessTimes']) || $feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $feedback['TotalSuccessTimes'] ;
                update_post_meta($order->get_id(), '_total_success_times', $nTotalSuccessTimes );

                if (isset($aConfig_Invoice) && $aConfig_Invoice['wc_opay_invoice_enabled'] == 'enable' && $aConfig_Invoice['wc_opay_invoice_auto'] == 'auto' ) {

                    do_action('opay_auto_invoice', $order->get_id(), $ecpay_feedback['SimulatePaid']);
                }
            }
        } elseif ($invoice_active_ecpay == 1 && $invoice_active_opay == 0) { // ecpay
            if (is_file( get_home_path() . '/wp-content/plugins/ecpay_invoice/woocommerce-ecpayinvoice.php')) {
                $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model');

                // 記錄目前成功付款到第幾次
                $nTotalSuccessTimes = ( isset($feedback['TotalSuccessTimes']) && ( empty($feedback['TotalSuccessTimes']) || $feedback['TotalSuccessTimes'] == 1 ))  ? '' :  $feedback['TotalSuccessTimes'] ;
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
            unset($gateway_list[$this->domain]);
            unset($gateway_list[$this->id]);
       }
       return $gateway_list;
    }
}