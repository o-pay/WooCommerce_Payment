<?php
/**
 * 設定後端管理欄位
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return apply_filters( 'wc_opay_dca_payment_settings',
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
        'opay_dca_merchant_id' => array(
            'title' => $this->tran('Merchant ID'),
            'type' => 'text',
            'default' => '2000132'
        ),
        'opay_dca_hash_key' => array(
            'title' => $this->tran('Hash Key'),
            'type' => 'text',
            'default' => '5294y06JbISpM5x9'
        ),
        'opay_dca_hash_iv' => array(
            'title' => $this->tran('Hash IV'),
            'type' => 'text',
            'default' => 'v77hoKGq4kWxNNIS'
        )
	)
);

