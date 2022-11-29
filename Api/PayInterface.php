<?php

namespace ONVO\Pay\Api;

interface PayInterface
{
    /**
     * Returns session quote payment intent
     *
     * @return mixed
     */
    public function paymentIntent();

    /**
     * Reload session quote payment intent
     *
     * @param string $paymentIntentId
     * @return mixed
     */
    public function paymentIntentReload($paymentIntentId);
}
