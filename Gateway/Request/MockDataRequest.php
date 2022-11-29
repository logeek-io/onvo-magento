<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace ONVO\Pay\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use ONVO\Pay\Gateway\Http\Client\ClientMock;

/**
 * Class MockDataRequest
 * @package ONVO\Pay\Gateway\Request
 */
class MockDataRequest implements BuilderInterface
{
    const FORCE_RESULT = 'FORCE_RESULT';

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        return [];
    }
}
