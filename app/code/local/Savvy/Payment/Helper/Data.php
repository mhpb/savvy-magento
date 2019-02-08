<?php

class Savvy_Payment_Helper_Data extends Mage_Core_Helper_Abstract {

    const API_DOMAIN                    = 'https://api.savvy.io';
    const API_DOMAIN_TEST               = 'http://api.test.savvy.io';
    const EMAIL_TEMPLATE_UNDERPAIMENT   = 'savvy_underpayment_email';
    const EMAIL_TEMPLATE_OVERPAIMENT    = 'savvy_overpayment_email';

    public function getApiDomain() {
        $testnet = Mage::getStoreConfig('payment/savvy/testnet');

        if ($testnet) {
            return self::API_DOMAIN_TEST;
        }else {
            return self::API_DOMAIN;
        }
    }

    public function log($data, $file = 'savvy.log') {

        if (Mage::getStoreConfig('payment/savvy/debug')) {
            Mage::log($data, null, $file);
        }
    }
}
