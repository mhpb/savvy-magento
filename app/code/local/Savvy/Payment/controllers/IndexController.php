<?php

class Savvy_Payment_IndexController extends Mage_Core_Controller_Front_Action
{
    public function testModelAction()
    {
        $payment = Mage::getModel('savvy/payment');
        echo get_class($payment);
    }

    public function testAction() {
        echo Mage::helper('savvy')->getApiDomain();
    }
}
