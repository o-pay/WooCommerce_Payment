<?php
/**
 * 設定後端管理欄位
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return apply_filters( 'wc_opay_payment_settings',
	array(
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
            'css' => 'height: 100%',
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
                'TopUpUsed' => $this->get_payment_desc('TopUpUsed'),
                'WeiXinpay' => $this->get_payment_desc('WeiXinpay')
            )
        )
	)
);
