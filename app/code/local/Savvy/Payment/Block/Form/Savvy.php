<?php

class Savvy_Payment_Block_Form_savvy extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('savvy/form/savvy.phtml');
    }
}
