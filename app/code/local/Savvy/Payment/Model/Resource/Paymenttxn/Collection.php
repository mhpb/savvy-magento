<?php
class Savvy_Payment_Model_Resource_Paymenttxn_Collection extends Mage_Core_Model_Mysql4_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('savvy/paymenttxn');
    }
}
