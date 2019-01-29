<?php

class Savvy_Payment_Model_Payment extends Mage_Core_Model_Abstract
{
    protected static $_currencies = null;
    protected static $_rates = null;

    protected function _construct()
    {
        $this->_init('savvy/payment');
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('savvy/payment');
    }

    public function getCurrencies()
    {
        if (self::$_currencies === null) {
            $url = Mage::helper('savvy')->getApiDomain() . sprintf('/v3/currencies?token=%s', Mage::getStoreConfig('payment/savvy/api_secret'));
            if (Mage::getStoreConfig('payment/savvy/testnet') == 1) {
                $url = Mage::helper('savvy')->getApiDomain() . sprintf('/v3/currencies?token=%s', Mage::getStoreConfig('payment/savvy/api_testnet'));
            }

            $response = file_get_contents($url);
            $data = json_decode($response, true);

            self::$_currencies = $data['data'];
        }

        return self::$_currencies;
    }

    public function checkSavvyResponse() {

        $url = Mage::helper('savvy')->getApiDomain() . sprintf('/v3/currencies?token=%s', Mage::getStoreConfig('payment/savvy/api_secret'));
        $response = file_get_contents($url);
        $data = json_decode($response, true);

        return $data;
    }

    public function getCurrency($token, $orderId, $getAddress = false)
    {
        $token = $this->sanitize_token($token);
        $order = new Mage_Sales_Model_Order();
        $order->loadByIncrementId($orderId);

        $rate = $this->getRate($token, $order->getOrderCurrencyCode());

        if ($rate) {


            $fiatValue = (float)$order->getGrandTotal();

            $coinsValue = round($fiatValue / $rate, 8);

            $currencies = $this->getCurrencies();
            $currency = (object) $currencies[strtolower($token)];

            $maximum = true;
            if ($currency->maximum > 0 && $coinsValue > $currency->maximum) $maximum = false;

            if (( $coinsValue >= $currency->minimum ) && $maximum ) {

                $currency->coinsValue = $coinsValue;

                $currency->rate = round($rate, 2);
                $coinsPaid = $this->getAlreadyPaidCoins($orderId);
                $currency->coinsPaid = round($coinsPaid, 8);

                if ($getAddress) {
                    $currency->address = $this->getTokenAddress($orderId, $token);
                } else {
                    $currency->currencyUrl = Mage::getUrl('savvy/payment/currencies', [
                        'token' => $token,
                        'order' => $orderId
                    ]);
                }

                return $currency;
            }

        }

        return null;
    }

    public function getRate($curCode, $order_currency_code)
    {
        $curCode = strtolower($curCode);
        $order_currency_code = strtolower($order_currency_code);
        if ($curCode == $order_currency_code)
            return 1;

        /**
         * Check, is order currency crypto ?
         */
        if (in_array($order_currency_code, $this->getCryptoCurrencies())) {

            $rateCryptoToCrypto = $this->getCryptoToCrypto($curCode, $order_currency_code);

            return $rateCryptoToCrypto;

        }

        $rates = $this->getRates($curCode);

        return isset($rates->$curCode) ? $rates->$curCode->mid : false;
    }

    /**
     * Get Cryptocurrency codes
     * @return array
     */
    public function getCryptoCurrencies() {
        return array_keys($this->getCurrencies());
    }

    /**
     * Get rate of one cryptocurrency to another cryptocurrency
     * @param $curCode
     * @param $order_currency_code
     * @return bool|float|int
     */
    public function getCryptoToCrypto($curCode, $order_currency_code) {

        // get USD rates
        $rates = $this->getRates('usd');

        $curCodeRate = ($rates[$curCode]) ? $rates[$curCode]['mid'] : null;
        $orderCurrencyRate = ($rates[$order_currency_code]) ? $rates[$order_currency_code]['mid'] : null;

        if (($curCodeRate > 0) && ($orderCurrencyRate > 0)) {
            return $curCodeRate/$orderCurrencyRate;
        }

        return false;
    }

    public function getRates()
    {
        if (self::$_rates === null) {
            $currency = strtolower(Mage::app()->getStore()->getCurrentCurrencyCode());
            $url = Mage::helper('savvy')->getApiDomain() . sprintf("/v3/exchange/%s/rate", $currency);

            if ($response = file_get_contents($url)) {
                $response = json_decode($response);
                if ($response->success) {
                    self::$_rates = $response->data;
                }
            }
        }

        return self::$_rates;
    }

    public function getTokenAddress($orderId, $token)
    {
        $data = Mage::getModel('savvy/payment')->load($orderId, 'order_increment_id');
        $order = new Mage_Sales_Model_Order();
        $order->loadByIncrementId($orderId);

        $apiSecret = Mage::getStoreConfig('payment/savvy/api_secret');
        $apiTestnet = Mage::getStoreConfig('payment/savvy/api_testnet');
        $currencies = $this->getCurrencies();
        $token = $this->sanitize_token($token);
        $rate = $this->getRate($token, $order->getOrderCurrencyCode());
        $fiatAmount = $order->getGrandTotal();
        $coinsAmount = round($fiatAmount / $rate, 8);

        $address = $this->getSavedAddressByToken(strtolower($token), $data->getAddress());

        if ($data->getOrderIncrementId() && $address) {
            $data->setConfirmations(null);
            $data->setToken(strtolower($token));
            $data->setAmount($coinsAmount);
            $data->setMaxConfirmations($currencies[strtolower($token)]['maxConfirmations']);
            $data->setUpdatedAt(date('Y-m-d H:i:s'));
            $data->save();

            return $address;
        } elseif (!$data->getOrderIncrementId()) {
            $data->setOrderIncrementId($orderId);
            $data->setCreatedAt(date('Y-m-d H:i:s'));
        }

        $callbackUrl = Mage::getUrl('savvy/payment/callback', [
            'order' => $orderId
        ]);

        $lock_address_timeout = Mage::getStoreConfig('payment/savvy/lock_address_timeout');
        $lock_address_timeout = $lock_address_timeout = (empty($lock_address_timeout)) ? 86400 : $lock_address_timeout;

        $url = Mage::helper('savvy')->getApiDomain() . sprintf('/v3/%s/payment/%s?token=%s&lock_address_timeout=%s', strtolower($token), urlencode($callbackUrl), $apiSecret, $lock_address_timeout);
        if (Mage::getStoreConfig('payment/savvy/testnet') == 1) {
            $url = Mage::helper('savvy')->getApiDomain() . sprintf('/v3/%s/payment/%s?token=%s&lock_address_timeout=%s', strtolower($token), urlencode($callbackUrl), $apiTestnet, $lock_address_timeout);
        }

        if ($response = file_get_contents($url)) {
            $response = json_decode($response);

            if (isset($response->data->address)) {
                $address = (is_array(json_decode($data->getAddress(), true))) ? json_decode($data->getAddress(), true) : array() ;
                $data->setConfirmations(null);
                $data->setToken(strtolower($token));
                $address = array_merge($address, array(strtolower($token)=>$response->data->address));
                $data->setAddress(json_encode($address));

                $invoice = (is_array(json_decode($data->getInvoice(), true))) ? json_decode($data->getInvoice(), true) : array();
                $invoice = array_merge($invoice, array(strtolower($token)=>$response->data->invoice));
                //$data->setInvoice($response->data->invoice);
                $data->setInvoice(json_encode($invoice));

                $data->setAmount($coinsAmount);
                $data->setMaxConfirmations($currencies[strtolower($token)]['maxConfirmations']);
                $data->setUpdatedAt(date('Y-m-d H:i:s'));
                $data->save();

                return $response->data->address;
            }
        }

        return null;
    }

    public function getSavedAddressByToken($token, $address) {
        try {
            if ($address) {
                $address = json_decode($address, true);

                if (array_key_exists($token, $address))
                    return  $address[$token];
            }

        }  catch (Exception $e) {
            Mage::logException($e);
        }

        return null;
    }

    public function getInvoiceByToken($token, $invoice) {
        try {
            if ($invoice) {
                $invoice = json_decode($invoice, true);

                if (array_key_exists($token, $invoice))
                    return  $invoice[$token];
            }

        }  catch (Exception $e) {
            Mage::logException($e);
        }

        return null;
    }

    public function getBlockExplorerUrl($token, $address) {
        $currencies = $this->getCurrencies();
        foreach ($currencies as $token_code => $currency) {
            if ($token_code == $token) {
                return sprintf($currency['blockExplorer'], $address);
            }
        }
    }

    public function getAlreadyPaid($orderId, $order_currency_code) {
        try {
            $savvy_payment = Mage::getModel('savvy/payment')->load($orderId, 'order_increment_id');
            if ($savvy_payment->getSavvyId()) {
                $token = $savvy_payment->getToken();
                $rate = $this->getRate($token, $order_currency_code);
                $already_paid = Mage::getModel('savvy/paymenttxn')->getTotalPaid($orderId);
                return round($already_paid*$rate, 2);
            }

        } catch (Exception $e) {

        }

        return 0;
    }

    public function getAlreadyPaidCoins ($orderId) {
        try {
            $savvy_payment = Mage::getModel('savvy/payment')->load($orderId, 'order_increment_id');
            if ($savvy_payment->getSavvyId()) {

                $already_paid = Mage::getModel('savvy/paymenttxn')->getTotalPaid($orderId);
                return $already_paid;
            }

        } catch (Exception $e) {

        }

        return 0;

    }

    public function createInvoice($order_id) {
        $order = Mage::getModel("sales/order")->loadByIncrementId($order_id);
        try {
            if(!$order->canInvoice())
            {
                Mage::throwException(Mage::helper('core')->__('Cannot create an invoice.'));
            }

            $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

            if (!$invoice->getTotalQty()) {
                Mage::throwException(Mage::helper('core')->__('Cannot create an invoice without products.'));
            }

            $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $transactionSave = Mage::getModel('core/resource_transaction')
                ->addObject($invoice)
                ->addObject($invoice->getOrder());

            $transactionSave->save();

            $invoice->setRequestedCaptureCase(
                Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE
            );
        }
        catch (Mage_Core_Exception $e) {

        }



    }

    public function sendEmail($subject = 'underpayment', $paidCrypto, $fiat_paid, $token, $order)
    {
        $senderName     = Mage::getStoreConfig('trans_email/ident_general/name');
        $senderEmail    = Mage::getStoreConfig('trans_email/ident_general/email');

        $customerName   = $order->getCustomerName();
        $customerEmail  = $order->getCustomerEmail();

        $token = $this->sanitize_token($token);

        $fiat_currency = strtoupper($order->getOrderCurrencyCode());

        $vars = array(
            'order'         => $order,
            'store'         => Mage::app()->getStore(),
            'order_id'      => $order->getIncrementId(),
            'cryptopaid'    => $paidCrypto,
            'fiat_paid'     => $fiat_paid,
            'token'         => strtoupper( $token ),
            'fiat_currency' => $fiat_currency
        );

        $templateId = Savvy_Payment_Helper_Data::EMAIL_TEMPLATE_UNDERPAIMENT;
        if ($subject == 'overpayment') {
            $templateId = Savvy_Payment_Helper_Data::EMAIL_TEMPLATE_OVERPAIMENT;
        }

        try {

            $emailTemplate = Mage::getModel('core/email_template')->loadByCode($templateId);
            $emailTemplate->getProcessedTemplate($vars);
            $emailTemplate->setSenderEmail($senderEmail);
            $emailTemplate->setSenderName($senderName);
            $emailTemplate->send($customerEmail, $customerName, $vars);

            return 1;
        } catch(Exception $e) {
            Mage::logException($e);
        }

        return 0;
    }

    public function setTxn ($params, $savvy_payment_id) {

        /** @var Savvy_Payment_Model_Paymenttxn $model */
        $savvy_txn = Mage::getModel('savvy/paymenttxn');

        /** @var Savvy_Payment_Model_Payment $model */
        $savvy_payment = $this->load($savvy_payment_id, 'savvy_id');

        $txn_hash       = $params->inTransaction->hash;

        $token = $this->sanitize_token($params->blockchain);
        $address        = $this->getSavedAddressByToken($token, $savvy_payment->getAddress());

        $txn_amount     = $params->inTransaction->amount / pow(10, $params->inTransaction->exp);

        $order_amount   = $savvy_payment->getAmount();
        $invoice        = $params->invoice;
        $order_id       = $savvy_payment->getOrderIncrementId();

        $confirmations  = $params->confirmations;

        $savvy_txn->load($txn_hash, 'txn');

        if ($savvy_txn->getIdTxn()) {
            $savvy_txn->setConfirmations($confirmations);
            $savvy_txn->setUpdatedAt(date('Y-m-d H:i:s'));
        }else{
            $savvy_txn->setTxn($txn_hash);
            $savvy_txn->setToken($token);
            $savvy_txn->setAddress($address);
            $savvy_txn->setTxnAmount($txn_amount);
            $savvy_txn->setOrderAmount($order_amount);
            $savvy_txn->setInvoice($invoice);
            $savvy_txn->setOrderId($order_id);
            $savvy_txn->setConfirmations($confirmations);
            $savvy_txn->setCreatedAt(date('Y-m-d H:i:s'));
        }

        $savvy_txn->save();

    }

    public function sanitize_token( $token ) {
        $token = strtolower($token);
        $token = preg_replace('/[^a-z0-9:]/', '', $token);
        return $token;
    }

    public function getCurrenciesIconsHtml($size = 5) {

        $currencies = array_slice($this->getCurrencies(), 0, $size);
        $_html = '';

        if (count($currencies) > 0) {
            $_html .= '<span class="payment_icons" />';
            foreach ($currencies as $code =>$currency) {
                $_html .= sprintf('<img src="%s" alt="%s" width="%spx" height="%spx" />', $currency['icon'], $currency['code'], 30, 30 );
            }
            $_html .= '</span>';
        }

        return $_html;
    }

}
