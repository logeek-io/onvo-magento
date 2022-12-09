<?php

namespace ONVO\Pay\Model;

use ONVO\Pay\Api\PayInterface;
use ONVO\Pay\Helper\PayHelper;

class Pay implements PayInterface
{
    /**
     * @var PayHelper
     */
    private $_onvoHelper;

    /**
     * @param PayHelper $onvoHelper
     */
    public function __construct(
        PayHelper $onvoHelper
    )
    {
        $this->_onvoHelper = $onvoHelper;
    }

    /**
     * @inheritDoc
     */
    public function paymentIntent()
    {
        $this->_onvoHelper->getPaymentIntent();
    }

    /**
     * @inheritDoc
     */
    public function paymentIntentReload($paymentIntentId)
    {
        $this->_onvoHelper->getPaymentIntent($paymentIntentId);
    }

    /**
     * @inheritDoc
     */
    public function errorReport()
    {
        $this->_onvoHelper->errorReport();
    }
}
