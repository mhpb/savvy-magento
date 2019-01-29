<?php

class Savvy_Payment_Model_Resource_Paymenttxn extends Mage_Core_Model_Resource_Db_Abstract
{

    protected function _construct()
    {
        $this->_init('savvy/paymenttxn', 'id_txn');
    }
}
