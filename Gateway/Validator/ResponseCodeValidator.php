<?php
/**
 * Copyright © 2019 ONVO SA, All rights reserved.
 * See COPYING.txt for license details.
 */

namespace ONVO\Pay\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * Class ResponseCodeValidator
 * @package ONVO\Pay\Gateway\Validator
 */
class ResponseCodeValidator extends AbstractValidator
{
    const RESULT_CODE = 'status';

    /**
     * Performs validation of result code
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        if (!isset($validationSubject['response']) || !is_array($validationSubject['response'])) {
            throw new \InvalidArgumentException('Response does not exist');
        }

        $response = $validationSubject['response'];
        if ($this->isSuccessfulTransaction($response)) {
            return $this->createResult(
                true,
                []
            );
        } else {
            return $this->createResult(
                false,
                [
                    'Unknown error'
                ],
                [
                    'Unknown error'
                ]
            );
        }
    }

    /**
     * @param array $response
     * @return bool
     */
    private function isSuccessfulTransaction(array $response)
    {
        if(isset($response[self::RESULT_CODE])) {
            return $response[self::RESULT_CODE] == 'succeeded';
        }
        return false;
    }
}
