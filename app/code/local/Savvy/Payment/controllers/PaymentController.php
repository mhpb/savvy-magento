<?php

class Savvy_Payment_PaymentController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        $order = new Mage_Sales_Model_Order();
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        
        if (!$orderId) {
            Mage::log('Order not found');
            $this->_redirect("/");
            return;
        }

        $order->loadByIncrementId($orderId);

        $this->loadLayout();

        //send new order email
        if ($order->getCanSendNewEmailFlag()) {
            try {
                $order->queueNewOrderEmail();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        /** @var Savvy_Payment_Model_Payment $model */
        $savvy_payment = Mage::getModel('savvy/payment');
        $currency_sign   = Mage::app()->getLocale()->currency($order->getOrderCurrencyCode())->getSymbol();
        $overpayment     = Mage::getStoreConfig('payment/savvy/minoverpaymentfiat');
        $overpayment     = !empty($overpayment) ? $overpayment : 1;

        $underpayment    = Mage::getStoreConfig('payment/savvy/maxunderpaymentfiat');
        $underpayment    = !empty($underpayment) ? $underpayment : 0.01;

        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template','savvy',array('template' => 'savvy/form.phtml'));
        $total_paid = $savvy_payment->getAlreadyPaid($orderId, $order->getOrderCurrencyCode());
        $fiat_value = round(max((float)$order->getGrandTotal() - $total_paid, 0), 2);

        $payment_status = 'Pending Payment';
        //$payment_status = Mage::getModel('sales/order_status')->load($order->getStatus(), 'status')->getLabel();
        $status = $order->getStatus();
        $button_html = '<a href="#" class="button" id="paybear-all">Pay with Crypto</a>';
        if ($status == 'complete' || $status == 'processing' ) {
            $payment_status = 'Paid';
            $button_html = '<a href="' . Mage::getUrl('checkout/onepage/success') .'" class="button" >Continue</a>';
        }

        if ($status == 'savvy_awaiting_confirmations' && ($fiat_value < $underpayment)) {
            $payment_status = 'Waiting for Confirmations';
            $button_html = '<a href="" class="button" >Refresh</a>';
        } elseif ($status == 'savvy_mispaid' || ($total_paid > 0 && ($fiat_value > $underpayment) )) {
            $payment_status = 'Partial Payment';
        }

        $order_overview  = '<div>Order overview #'.$orderId.'</div>';
        $order_overview .= '<div>Payment status - ' . $payment_status . '</div>';
        $is_overpaid = (($total_paid - $order->getGrandTotal()) > $overpayment) ? true : false;
        $token = null;

        if ($savvy_payment->load($orderId, 'order_increment_id')->getsavvyId()) {
            $token = $savvy_payment->getToken();
            if ($token && $total_paid > 0 && !$is_overpaid ) {
                $address = $savvy_payment->getSavedAddressByToken(strtolower($token), $savvy_payment->getAddress());
                $order_overview .= '<div>Selected token - '.strtoupper($token).'</div>';
                $order_overview .= '<div>Payment address - <a href="' . $savvy_payment->getBlockExplorerUrl($token,$address) .'" target="_blank" >'.$address.'</a></div>';
                $order_overview .= '<div>Total to pay - ' . $currency_sign . round($order->getGrandTotal(),2) . '</div>';
                $order_overview .= '<div>Paid - '. $currency_sign . round($total_paid,2) .'</div>';
                if (($fiat_value > 0) && $fiat_value > $underpayment && ($total_paid > 0)) {
                    $order_overview .= '<div>Left to pay - ' . $currency_sign . round($fiat_value, 2) . '</div>';
                }
            }
        }

        $order_overview .= '<br/>' . $button_html;

        $currency_url = Mage::getUrl('savvy/payment/currencies', [
            'order' => $order->getIncrementId()
        ]);

        if ($token && $total_paid>0) {
            $currency_url = Mage::getUrl('savvy/payment/currencies', [
                'order'      => $order->getIncrementId(),
                'token'      => $token,
                'total_paid' => true
            ]);
        }

        $exchange_locktime = Mage::getStoreConfig('payment/savvy/exchange_locktime') * 60;

        $block->addData([
            'currencies' => $currency_url,
            'status' => Mage::getUrl('savvy/payment/status', [
                'order' => $order->getIncrementId()
            ]),
            'redirect' => Mage::getUrl('checkout/onepage/success'),
            //'fiat_value' => (float)$order->getGrandTotal(),
            'fiat_value' => $fiat_value,
            'currency_iso' => $order->getOrderCurrencyCode(),
            'currency_sign' => $currency_sign,
            'overpayment' => $overpayment,
            'underpayment' => $underpayment,
            'order_overview' => $order_overview,
            'exchange_locktime' => !empty($exchange_locktime) ? $exchange_locktime : 15*60
        ]);

        $this->getLayout()->getBlock('head')->addCss('css/savvy.css');
        $this->getLayout()->getBlock('head')->addJs('savvy/savvy.js');
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    public function currenciesAction()
    {
        $orderId = $this->getRequest()->get('order');

        if (!$orderId) {
            Mage::throwException($this->__('Order not found'));
        }



        $order = new Mage_Sales_Model_Order();
        $order->loadByIncrementId($orderId);

        /** @var Savvy_Payment_Model_Payment $model */
        $model = Mage::getModel('savvy/payment');

        if ($this->getRequest()->get('token')) {
                if($this->getRequest()->get('total_paid')) {
                    $data[] = $model->getCurrency($this->getRequest()->get('token'), $orderId, true);
                }else{
                    $data   = $model->getCurrency($this->getRequest()->get('token'), $orderId, true);
                }
        } else {

            $currencies = $model->getCurrencies();
            foreach ($currencies as $token => $currency) {
                if (count($currencies) == 1) {
                    $currency = $model->getCurrency($token, $orderId, true);
                }else{
                    $currency = $model->getCurrency($token, $orderId);
                }

                if ($currency) {
                    $data[] = $currency;
                }
            }
        }

        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($data));
    }

    public function statusAction () {

        $orderId = $this->getRequest()->get('order');

        if (!$orderId) {
            Mage::throwException($this->__('Order not found'));
        }

        $order = new Mage_Sales_Model_Order();
        $order->loadByIncrementId($orderId);

        $savvy_payment = Mage::getModel('savvy/payment');
        $savvy_payment->load($order->getIncrementId(), 'order_increment_id');

        $payment_txn = Mage::getModel('savvy/paymenttxn');

        $maxConfirmations = $savvy_payment->getMaxConfirmations();
        $data = array();

        $totalConfirmations = $payment_txn->getTxnConfirmations($orderId);
        $totalConfirmed     = $payment_txn->getTotalConfirmed($orderId, $maxConfirmations);
        $maxDifference_fiat = Mage::getStoreConfig('payment/savvy/maxunderpaymentfiat');
        $maxDifference_fiat    = !empty($maxDifference_fiat) ? $maxDifference_fiat : 0.01;
        $maxDifference_coins = 0;

        if($maxDifference_fiat) {
            $maxDifference_coins = round($maxDifference_fiat/$savvy_payment->getRate($savvy_payment->getToken(), $order->getOrderCurrencyCode()) , 8);
            $maxDifference_coins = max($maxDifference_coins, 0.00000001);
        }

        if (($totalConfirmations >= $maxConfirmations) && ($totalConfirmed >= ($savvy_payment->amount - $maxDifference_coins) )) { //set max confirmations
            $data['success'] = true;
        } else {
            $data['success'] = false;
        }

        if (is_numeric($totalConfirmations)) {
            $data['confirmations'] = $totalConfirmations;
        }

        $coinsPaid = $payment_txn->getTotalPaid($orderId);

        if ($coinsPaid) {
            $data['coinsPaid'] = $coinsPaid;

            $underpayment = $savvy_payment->getAmount() - $coinsPaid;
            $email_flag   =  $savvy_payment->getEmailStatus();
            if (($underpayment > 0) && (empty($email_flag))) {

                if ($savvy_payment->sendEmail('underpayment', $underpayment, round($underpayment * $savvy_payment->getRate($savvy_payment->getToken(), $order->getOrderCurrencyCode()),2), $savvy_payment->getToken(), $order )) {
                    $savvy_payment->setEmailStatus(1);
                    $savvy_payment->save();
                }
            }
        }

        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($data));
    }

    public function callbackAction () {

        $params = $this->getRequest()->getParams();

        //$orderId = $this->getRequest()->getParam('order');

        $orderId = $params['order'];

        if (empty($orderId)) {
            Mage::helper('savvy')->log('Order id not found.', 'callback.log');
            return;
        }

        $order = new Mage_Sales_Model_Order();
        $order->loadByIncrementId($orderId);

        if (!$order->getIncrementId()) {
            Mage::throwException($this->__('Order not found.'));
        }

        $currency = $order->getOrderCurrency();

        $savvy_payment = Mage::getModel('savvy/payment');
        $savvy_payment = $savvy_payment->load($orderId, 'order_increment_id');

        if (!$savvy_payment->getToken()) {
            Mage::throwException($this->__('Order not found.'));
        }

        $payment_txn = Mage::getModel('savvy/paymenttxn');

        if (!in_array($order->getStatus(), array(
            Mage::getStoreConfig('payment/savvy/order_status'),
            Mage::getStoreConfig('payment/savvy/awaiting_confirmations_status'),
            Mage::getStoreConfig('payment/savvy/mispaid_status')
        ))) {
            return;
        }

        $data = file_get_contents('php://input');

        if ($data) {

            $params = json_decode($data);

            Mage::helper('savvy')->log('ORDER - '.$orderId, 'callback.log');
            Mage::helper('savvy')->log($data, 'callback.log');

            $maxConfirmations = $savvy_payment->getMaxConfirmations();


            $maxDifference_fiat = Mage::getStoreConfig('payment/savvy/maxunderpaymentfiat');
            $maxDifference_fiat = !empty($maxDifference_fiat) ? $maxDifference_fiat : 0.01;

            $maxDifference_coins = 0;

            if($maxDifference_fiat) {
                $maxDifference_coins = round($maxDifference_fiat/$savvy_payment->getRate($params->blockchain, $order->getOrderCurrencyCode()) , 8);
                $maxDifference_coins = max($maxDifference_coins, 0.00000001);
            }

            $invoice = $savvy_payment->getInvoiceByToken($savvy_payment->getToken(), $savvy_payment->getInvoice());

            if ($params->invoice == $invoice) {

                $isNewPayment = $payment_txn->isNewOrder($orderId);

                if ($isNewPayment) {
                    $savvy_payment->setPaidAt(date('Y-m-d H:i:s'));
                    $savvy_payment->save();
                }

                $savvy_payment->setTxn($params, $savvy_payment->getsavvyId());


                $hash = $params->inTransaction->hash;

                $confirmations = $savvy_payment->confirmations;

                if (!$confirmations) {
                    $confirmations = array();
                } else {
                    $confirmations = json_decode($confirmations, true);
                }

                $confirmations[$hash] = $params->confirmations;

                $savvy_payment->confirmations = json_encode($confirmations);
                $savvy_payment->save();

                $toPay = $savvy_payment->getAmount();

                $amountPaid = $params->inTransaction->amount / pow(10, $params->inTransaction->exp);

                $totalConfirmations = $payment_txn->getTxnConfirmations($orderId);

                if ($totalConfirmations >= $maxConfirmations) {

                    //avoid race conditions
                    $transactionIndex = array_search($hash, array_keys($confirmations));
                    if ($transactionIndex>0) sleep($transactionIndex*1);

                    $totalConfirmed =  $payment_txn->getTotalConfirmed($orderId, $maxConfirmations);


                    if (($toPay > 0 && ($toPay - $amountPaid) < $maxDifference_coins)  || (($toPay - $totalConfirmed) < $maxDifference_coins ) ) {

                        $orderTimestamp   = strtotime($order->getData('created_at'));
                        $paymentTimestamp = strtotime($savvy_payment->getPaidAt());
                        $deadline         = $orderTimestamp + Mage::getStoreConfig('payment/savvy/exchange_locktime') * 60;

                        if ($paymentTimestamp > $deadline) {
                            $orderStatus = Mage::getStoreConfig('payment/savvy/late_payment_status');

                            $fiatPaid = $totalConfirmed * $savvy_payment->getRate($params->blockchain, $order->getOrderCurrencyCode());
                            if ((float) $fiatPaid < $order->getData('grand_total')) {
                                $message = sprintf('Late Payment / Rate changed (%s %s paid, %s %s expected)', round($fiatPaid,2), $currency->getData('currency_code'), round($order->getData('grand_total'),2), $currency->getData('currency_code'));
                                $order->addStatusHistoryComment($message, $orderStatus);
                                $order->save();
                            }
                        }


                        if ($order->getStatus() != 'processing') {
                            $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
                            $message = sprintf('Amount Paid: %s, Blockchain: %s. ', $totalConfirmed, $params->blockchain);
                            $history = $order->addStatusHistoryComment($message, Mage_Sales_Model_Order::STATE_PROCESSING);

                            $history->setIsCustomerNotified(false);
                            $order->save();

                            $savvy_payment->createInvoice($orderId);
                        }

                        //check overpaid
                        $minoverpaid = Mage::getStoreConfig('payment/savvy/minoverpaymentfiat');
                        $minoverpaid = !empty($minoverpaid) ? $minoverpaid : 1;
                        $overpaid    =  (round(($totalConfirmed - $toPay)*$savvy_payment->getRate($params->blockchain, $order->getOrderCurrencyCode()), 2));
                        if ( ($minoverpaid > 0) && ($overpaid > $minoverpaid) ) {

                            if ($savvy_payment->sendEmail('overpayment', $totalConfirmed - $toPay, $overpaid, $savvy_payment->getToken(), $order )) {
                                $history = $order->addStatusHistoryComment('Looks as customer has overpaid an order');
                                $history->setIsCustomerNotified(true);
                                $savvy_payment->setEmailStatus(1);
                                $savvy_payment->save();
                            }

                            $order->save();
                        }

                        echo $params->invoice; //stop further callbacks
                        return;

                    } else {
                        $orderStatus = Mage::getStoreConfig('payment/savvy/mispaid_status');
                        if ($order->getStatus() != $orderStatus) {

                            $underpaid = round(($toPay - $totalConfirmed) * $savvy_payment->getRate($params->blockchain, $order->getOrderCurrencyCode()), 2);
                            $message = sprintf('Wrong Amount Paid (%s %s is received, %s %s is expected) - %s %s is underpaid', $amountPaid, $params->blockchain, $toPay, $params->blockchain, $currency->getData('currency_code'), $underpaid);
                            $order->addStatusHistoryComment($message, $orderStatus);
                            $order->save();
                        }
                    }

                } else {
                    $unconfirmedTotal = $payment_txn->getTotalUnconfirmed($orderId, $maxConfirmations);

                    if ($order->getStatus() != Mage::getStoreConfig('payment/savvy/awaiting_confirmations_status')) {
                        $massage = sprintf('%s Awaiting confirmation. Total Unconfirmed: %s %s', date('Y-m-d H:i:s'), $unconfirmedTotal, $params->blockchain );
                        $order->addStatusHistoryComment($massage, Mage::getStoreConfig('payment/savvy/awaiting_confirmations_status'));
                        $order->save();
                    }
                }
            }
        }

        return;
    }

}