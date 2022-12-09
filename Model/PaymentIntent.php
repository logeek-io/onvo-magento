<?php

namespace ONVO\Pay\Model;

use Magento\Framework\Model\AbstractModel;

class PaymentIntent extends AbstractModel
{
    protected function _construct()
    {
        $this->_init('ONVO\Pay\Model\ResourceModel\PaymentIntent');
    }
}
