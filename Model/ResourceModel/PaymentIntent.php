<?php

namespace ONVO\Pay\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PaymentIntent extends AbstractDb
{
    protected $_isPkAutoIncrement = false;

    protected function _construct()
    {
        $this->_init('onvo_pay_intents', 'id');
    }
}
