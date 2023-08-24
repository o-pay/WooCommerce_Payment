<?php
// SDK外殼，用來處理WooCommerce相容性問題

include_once('Opay.Payment.Integration.php');

final class OpayWooAllInOne extends OpayAllInOne
{
	//訂單查詢作業
    function QueryTradeInfo() {
        return $arFeedback = OpayWooQueryTradeInfo::CheckOut(array_merge($this->Query,array("MerchantID" => $this->MerchantID, 'EncryptType' => $this->EncryptType)) ,$this->HashKey ,$this->HashIV ,$this->ServiceURL) ;
	}

	//信用卡定期定額訂單查詢的方法
    function QueryPeriodCreditCardTradeInfo() {
        return $arFeedback = OpayWooQueryPeriodCreditCardTradeInfo::CheckOut(array_merge($this->Query,array("MerchantID" => $this->MerchantID, 'EncryptType' => $this->EncryptType)) ,$this->HashKey ,$this->HashIV ,$this->ServiceURL);
	}

	//信用卡關帳/退刷/取消/放棄的方法
    function DoAction() {
        return $arFeedback = OpayWooDoAction::CheckOut(array_merge($this->Action,array("MerchantID" => $this->MerchantID, 'EncryptType' => $this->EncryptType)) ,$this->HashKey ,$this->HashIV ,$this->ServiceURL);
	}

    //廠商通知退款
    function AioChargeback() {
        return $arFeedback = OpayWooAioChargeback::CheckOut(array_merge($this->ChargeBack,array("MerchantID" => $this->MerchantID, 'EncryptType' => $this->EncryptType)) ,$this->HashKey ,$this->HashIV ,$this->ServiceURL);
    }

	//會員申請撥款／退款
    function AioCapture(){
        return $arFeedback = OpayWooAioCapture::Capture(array_merge($this->Capture,array("MerchantID" => $this->MerchantID, 'EncryptType' => $this->EncryptType)) ,$this->HashKey ,$this->HashIV ,$this->ServiceURL);
	}

	//查詢信用卡單筆明細紀錄
    function QueryTrade(){
        return $arFeedback = OpayWooQueryTrade::CheckOut(array_merge($this->Trade,array("MerchantID" => $this->MerchantID, 'EncryptType' => $this->EncryptType)) ,$this->HashKey ,$this->HashIV ,$this->ServiceURL);
    }
}

/**
* 抽象類
*/
final class OpayWooAio extends OpayAio
{
    protected static function ServerPost($parameters ,$ServiceURL) {

		$fields_string = http_build_query($parameters);
		$rs = wp_remote_post($ServiceURL, array(
			'method'      => 'POST',
			'headers'     => array(),
            'httpversion' => '1.0',
			'sslverify'   => true,
            'body'        => $fields_string
		));

		if ( is_wp_error($rs) ) {
			throw new Exception($rs->get_error_message());
		}

		return $rs['body'];
    }
}

final class OpayWooQueryTradeInfo extends OpayQueryTradeInfo
{
    protected static function ServerPost($parameters ,$ServiceURL)
    {
        return OpayWooAio::ServerPost($parameters ,$ServiceURL);
    }
}

final class OpayWooQueryPeriodCreditCardTradeInfo extends OpayQueryPeriodCreditCardTradeInfo
{
    protected static function ServerPost($parameters ,$ServiceURL)
    {
        return OpayWooAio::ServerPost($parameters ,$ServiceURL);
    }
}

final class OpayWooDoAction extends OpayDoAction
{
    protected static function ServerPost($parameters ,$ServiceURL)
    {
        return OpayWooAio::ServerPost($parameters ,$ServiceURL);
    }
}

final class OpayWooAioChargeback extends OpayAioChargeback
{
    protected static function ServerPost($parameters ,$ServiceURL)
    {
        return OpayWooAio::ServerPost($parameters ,$ServiceURL);
    }
}

final class OpayWooAioCapture extends OpayAioCapture
{
    protected static function ServerPost($parameters ,$ServiceURL)
    {
        return OpayWooAio::ServerPost($parameters ,$ServiceURL);
    }
}

final class OpayWooQueryTrade extends OpayQueryTrade
{
    protected static function ServerPost($parameters ,$ServiceURL)
    {
        return OpayWooAio::ServerPost($parameters ,$ServiceURL);
    }
}
?>