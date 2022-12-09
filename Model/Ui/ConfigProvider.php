<?php
/**
 * Copyright © 2019 ONVO SA, All rights reserved.
 * See COPYING.txt for license details.
 */
namespace ONVO\Pay\Model\Ui;

use ONVO\Pay\Helper\PayHelper;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Gateway\Config\Config;
use Magento\Store\Model\StoreManagerInterface;

final class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'onvo';

    /**
     * @var \Magento\Payment\Gateway\ConfigInterface
     */
    protected $config;

    /**
     * @var PayHelper
     */
    private $_onvoHelper;

    /**
     * @var StoreManagerInterface
     */
    private $_storeManager;

    /**
     * @param Config $config
     * @param PayHelper $onvoHelper
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Config $config,
        PayHelper $onvoHelper,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->_onvoHelper = $onvoHelper;
        $this->_storeManager = $storeManager;
        $this->config->setMethodCode(self::CODE);
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        $this->config->setMethodCode(self::CODE);
        return [
            'payment' => [
                self::CODE => [
                    'publicKey' => $this->_onvoHelper->getPublicKey(),
                    'paymentIntentUrl' => "/rest/{$this->_storeManager->getStore()->getCode()}/V1/onvo/pay/payment-intent",
                    'errorReportUrl' => "/rest/{$this->_storeManager->getStore()->getCode()}/V1/onvo/pay/error"
                ]
            ]
        ];
    }
}
