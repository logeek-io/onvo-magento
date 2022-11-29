<?php
/**
 * Copyright © 2019 ONVO SA, All rights reserved.
 * See COPYING.txt for license details.
 */
namespace ONVO\Pay\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;

/**
 * Class TxnIdHandler
 * @package ONVO\Pay\Gateway\Response
 */
class TxnIdHandler implements HandlerInterface
{
    /**
     * Store response data on payment
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $handlingSubject['payment'];
        $payment = $paymentDO->getPayment();
        $payment->setAdditionalInformation('response', json_encode($response));
    }
}
