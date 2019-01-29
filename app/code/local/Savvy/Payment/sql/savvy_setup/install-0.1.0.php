<?php
/** @var Savvy_Payment_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

try {
    $table = $installer->getConnection()->newTable($installer->getTable('savvy/payment'))
        ->addColumn('savvy_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true,
            'nullable' => false,
            'primary' => true,
            'identity' => true,
        ), 'savvy Payment ID')
        ->addColumn('order_increment_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, '20', array())
        ->addColumn('token', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, array())
        ->addColumn('address', Varien_Db_Ddl_Table::TYPE_TEXT, array())
        ->addColumn('invoice', Varien_Db_Ddl_Table::TYPE_TEXT, array())
        ->addColumn('amount', Varien_Db_Ddl_Table::TYPE_DECIMAL, '20,8', array())
        ->addColumn('confirmations', Varien_Db_Ddl_Table::TYPE_TEXT, null, array())
        ->addColumn('max_confirmations', Varien_Db_Ddl_Table::TYPE_TINYINT, null, array())
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array())
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array())
        ->addColumn('paid_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array())
        ->addIndex(strtoupper(uniqid('FK_')), 'order_increment_id')
        ->addIndex(strtoupper(uniqid('FK_')), 'token')
    ;

    $installer->getConnection()->createTable($table);

    $installer->getConnection()->insert($installer->getTable('core/config_data'), [
        'scope' => 'default',
        'scope_id' => 0,
        'path' => 'payment/savvy/exchange_locktime',
        'value' => 15
    ]);

    $installer->getConnection()->insert($installer->getTable('core/config_data'), [
        'scope' => 'default',
        'scope_id' => 0,
        'path' => 'payment/savvy/title',
        'value' => 'Crypto Payments (BTC/ETH/LTC and others)'
    ]);

    $installer->getConnection()->insert($installer->getTable('core/config_data'), [
        'scope' => 'default',
        'scope_id' => 0,
        'path' => 'payment/savvy/mispaid_status',
        'value' => 'savvy_mispaid'
    ]);

    $installer->getConnection()->insert($installer->getTable('core/config_data'), [
        'scope' => 'default',
        'scope_id' => 0,
        'path' => 'payment/savvy/late_payment_status',
        'value' => 'savvy_late_payment'
    ]);

    $installer->getConnection()->insert($installer->getTable('core/config_data'), [
        'scope' => 'default',
        'scope_id' => 0,
        'path' => 'payment/savvy/order_status',
        'value' => 'pending'
    ]);

    $installer->getConnection()->insert($installer->getTable('core/config_data'), [
        'scope' => 'default',
        'scope_id' => 0,
        'path' => 'payment/savvy/awaiting_confirmations_status',
        'value' => 'savvy_awaiting_confirmations'
    ]);

    $installer->getConnection()->insert($installer->getTable('sales/order_status'), [
        'status' => 'savvy_mispaid',
        'label' => 'Mispaid'
    ]);

    $installer->getConnection()->insert($installer->getTable('sales/order_status'), [
        'status' => 'savvy_late_payment',
        'label' => 'Late Payment'
    ]);

    $installer->getConnection()->insert($installer->getTable('sales/order_status'), [
        'status' => 'savvy_awaiting_confirmations',
        'label' => 'Awaiting Confirmations'
    ]);

    $statusModel = Mage::getModel('sales/order_status');
    $statusModel->load('savvy_mispaid', 'status');
    $statusModel->assignState(Mage_Sales_Model_Order::STATE_CANCELED);

    $statusModel->load('savvy_late_payment', 'status');
    $statusModel->assignState(Mage_Sales_Model_Order::STATE_CANCELED);

    $statusModel->load('savvy_awaiting_confirmations', 'status');
    $statusModel->assignState(Mage_Sales_Model_Order::STATE_HOLDED);

} catch (Exception $e) {
    var_dump($e);
    die('omg');
    // nothing
}


$installer->endSetup();

