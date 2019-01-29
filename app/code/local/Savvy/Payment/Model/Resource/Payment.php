<?php

class Savvy_Payment_Model_Resource_Payment extends Mage_Core_Model_Resource_Db_Abstract
{

    protected function _construct()
    {
        $this->_init('savvy/payment', 'savvy_id');
    }
}
