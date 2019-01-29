<?php

class Savvy_Payment_Model_Payment_Method extends Mage_Payment_Model_Method_Abstract
{

    protected $_isGateway = true;

    protected $_code  = 'savvy';
    protected $_formBlockType = 'savvy/form_savvy';

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('savvy/payment');
    }
}
