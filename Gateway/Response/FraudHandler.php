<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace ONVO\Pay\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order\Payment;

/**
 * Class FraudHandler
 * @package ONVO\Pay\Gateway\Response
 */
class FraudHandler implements HandlerInterface
{
    const FRAUD_MSG_LIST = 'FRAUD_MSG_LIST';

    /**
     * Handles fraud messages
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {

    }
}
