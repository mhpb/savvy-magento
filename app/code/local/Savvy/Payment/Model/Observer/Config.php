<?php

class Savvy_Payment_Model_Observer_Config
{
    public function adminSaveSettingSavvy($observer)
    {
        $savvy_payment = Mage::getModel('savvy/payment');
        $response = $savvy_payment->checkSavvyResponse();
        $message = '';

        if (empty($response)) {
            $message = "Unable to connect to Savvy. Please check your network or contact support.";
        }

        if ($response['success'] === false ) {
            $message = '<b> Savvy Payment: </b> Your API Key does not seem to be correct. Get your key at <a href="https://www.savvy.io/" target="_blank"><b>savvy.io</b></a>';
        }

        if ($response['success'] === true && empty($response['data'])) {
            $message = '<b> Savvy Payment: </b> You do not have any currencies enabled, please enable them to your Merchant Dashboard: <a href="https://www.savvy.io/" target="_blank"><b>savvy.io</b></a>';
        }

        if ($message) {
            Mage::getConfig()->saveConfig('payment/savvy/active', '0', 'default', 0);
            Mage::getSingleton('adminhtml/session')->addError($message);
        }
    }
}
