<?php
/**
 * Copyright © 2019 ONVO SA, All rights reserved.
 * See COPYING.txt for license details.
 */
namespace ONVO\Pay\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

/**
 * Class ClientMock
 * @package ONVO\Pay\Gateway\Http\Client
 */
class ClientMock implements ClientInterface
{
    /**
     * @param TransferInterface $transferObject
     * @return array|bool|string
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        return $transferObject->getBody();
    }
}
