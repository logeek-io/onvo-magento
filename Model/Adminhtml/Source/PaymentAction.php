<?php
/**
 * Copyright © 2019 ONVO SA, All rights reserved.
 * See COPYING.txt for license details.
 */
namespace ONVO\Pay\Model\Adminhtml\Source;

use Magento\Payment\Model\MethodInterface;

/**
 * Class PaymentAction
 */
class PaymentAction implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => MethodInterface::ACTION_AUTHORIZE,
                'label' => __('Authorize')
            ],
            [
                'value' => MethodInterface::ACTION_AUTHORIZE_CAPTURE,
                'label' => __('Authorize & Capture')
            ]
        ];
    }
}
