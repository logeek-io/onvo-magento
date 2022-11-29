<?php
/**
 * Copyright © 2019 ONVO SA, All rights reserved.
 * See COPYING.txt for license details.
 */

namespace ONVO\Pay\Gateway\Request;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Helper\Formatter;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Monolog\Logger;

/**
 * Class CaptureRequest
 * @package ONVO\Pay\Gateway\Request
 */
class CaptureRequest implements BuilderInterface
{
    use Formatter;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    private Logger $_logger;

    /**
     * @param ConfigInterface $config
     * @param SubjectReader $subjectReader
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ConfigInterface $config,
        SubjectReader $subjectReader
    ) {
        $this->config = $config;
        $this->subjectReader = $subjectReader;
    }

    /**
     * Builds required request data
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];
        $payment = $paymentDO->getPayment();
        if (!$payment instanceof OrderPaymentInterface) {
            throw new \LogicException('Order payment should be provided.');
        }

        $additionalInfo = $payment->getAdditionalInformation();
        if(isset($additionalInfo['transaction_result'])) {
            return json_decode($additionalInfo['transaction_result'], true);
        } else {
            throw new \Exception('No onvo transaction result.');
        }
    }
}
