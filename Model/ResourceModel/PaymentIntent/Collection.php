<?php

namespace ONVO\Pay\Model\ResourceModel\PaymentIntent;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('ONVO\Pay\Model\PaymentIntent', 'ONVO\Pay\Model\ResourceModel\PaymentIntent');
    }
}
