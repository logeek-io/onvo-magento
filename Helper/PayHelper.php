<?php

namespace ONVO\Pay\Helper;

use Emarketa\AdvancedLogger\Logger\Handler\System\DatadogFile;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Encryption\EncryptorInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

class PayHelper
{
    const CODE = 'onvo';

    const ONVO_URL = 'https://api.onvopay.com/v1';

    private ScopeConfigInterface $_scopeConfig;

    private Session $_checkoutSession;

    private Logger $_logger;

    /**
     * @var EncryptorInterface
     */
    public $_encryptor;

    /**
     *
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Session              $checkoutSession,
        EncryptorInterface   $_encryptor
    )
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_checkoutSession = $checkoutSession;
        $this->_encryptor = $_encryptor;

        $logHandler = new RotatingFileHandler(BP . '/var/log/onvo.log', 3);
        $this->_logger = new Logger('ONVO');
        $this->_logger->pushHandler($logHandler);
        if (class_exists('\Emarketa\AdvancedLogger\Logger\Handler\System\DatadogFile')) {
            $om = ObjectManager::getInstance();
            $this->_logger->pushHandler($om->create(DatadogFile::class));
        }
    }

    /**
     * @param null $paymentIntentId
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getPaymentIntent($paymentIntentId = null)
    {
        $error = function($result) {
            $error = 'Unknown error';
            if(isset($result['message'])) {
                if(is_array($result['message'])) {
                    $error = $result['message'][0];
                } else {
                    $error = $result['message'];
                }
            }
            jsonResponse([
                'error' => $error
            ]);
        };

        $id = '';
        if ($quote = $this->_checkoutSession->getQuote()) {
            //create/update customer
            $billingAddress = $quote->getBillingAddress();
            $shippingAddress = !$quote->isVirtual() ? $quote->getShippingAddress() : $billingAddress;
            $url = self::ONVO_URL . '/customers';

            $query = urldecode($_SERVER['QUERY_STRING']);
            $query = explode('&', $query);
            $result = [];
            foreach ($query as $q) {
                $q = explode('=', $q);
                $result[$q[0]] = $q[1] ?? '';
            }
            if(isset($result['email'])) {
                $email = $result['email'];
            } else {
                $email = $billingAddress->getEmail() ?: $shippingAddress->getEmail();
                $email = $email ?: $quote->getCustomerEmail();
            }
            $this->_logger->debug($email);

            $customerId = '';
            /*$result = json_decode($this->curlCall(
                $url . "?email={$email}&limit=1", [], null,
                $this->getSecretKey()
            ), true);
            if(isset($result['data'][0]['id'])) {
                $customerId = $result['data'][0]['id'];
                $url .= "/{$customerId}";
            }
            $customerData = [
                "address" => [
                    "city" => $billingAddress->getCity(),
                    "country" => $billingAddress->getCountryId(),
                    "line1" => $billingAddress->getStreet()[0],
                    "line2" => null,
                    "postalCode" => $billingAddress->getPostcode(),
                    "state" => $billingAddress->getRegion()
                ],
                "description" => "",
                "email" => $email,
                "name" => "{$billingAddress->getFirstname()} {$billingAddress->getLastname()}",
                "phone" => $billingAddress->getTelephone(),
                "shipping" => [
                    "address" => [
                        "city" => $shippingAddress->getCity(),
                        "country" => $shippingAddress->getCountryId(),
                        "line1" => $shippingAddress->getStreet()[0],
                        "line2" => null,
                        "postalCode" => $shippingAddress->getPostcode(),
                        "state" => $shippingAddress->getRegion()
                    ],
                    "name" => "{$shippingAddress->getFirstname()} {$shippingAddress->getLastname()}",
                    "phone" => $shippingAddress->getTelephone()
                ]
            ];
            $result = json_decode($this->curlCall(
                $url, [],
                json_encode($customerData),
                $this->getSecretKey()
            ), true);
            $this->_logger->debug(json_encode($customerData));
            $this->_logger->debug(json_encode($result));

            if (isset($result['id'])) {
                $customerId = $result['id'];
            } else {
                $error($result);
            }*/

            //create/update payment intent
            $url = self::ONVO_URL . '/payment-intents';
            if($paymentIntentId) {
                $url .= "/" . $paymentIntentId;
            }
            $this->_logger->debug($quote->getGrandTotal() * 100);
            $result = json_decode($this->curlCall(
                $url, [],
                json_encode([
                    "amount" => $quote->getGrandTotal() * 100,
                    "currency" => $quote->getQuoteCurrencyCode(),
                    "customerId" => $customerId
                ]),
                $this->getSecretKey()
            ), true);
            $this->_logger->debug(json_encode($result));

            if (isset($result['id'])) {
                $id = $result['id'];
            } else {
                $error($result);
            }
        }
        jsonResponse([
            'payment_intent_id' => $id
        ]);
    }

    /**
     * @return mixed
     */
    public function getSecretKey()
    {
        if ($this->_scopeConfig->getValue("payment/" . self::CODE . "/use_sandbox")) {
            $secret = $this->_scopeConfig->getValue("payment/" . self::CODE . "/sandbox_secret_key");
        } else {
            $secret = $this->_scopeConfig->getValue("payment/" . self::CODE . "/secret_key");
        }
        return $this->_encryptor->decrypt($secret);
    }

    /**
     * @return mixed
     */
    public function getPublicKey()
    {
        if ($this->_scopeConfig->getValue("payment/" . self::CODE . "/use_sandbox")) {
            return $this->_scopeConfig->getValue("payment/" . self::CODE . "/sandbox_public_key");
        } else {
            return $this->_scopeConfig->getValue("payment/" . self::CODE . "/public_key");
        }
    }

    /**
     * @param $url
     * @param array $extraOpts
     * @param null $data
     * @param null $bearerToken
     * @param null $logger
     * @param array $extraHeaders
     * @return bool|string
     */
    private function curlCall($url, $extraOpts = [], $data = null, $bearerToken = null, &$logger = null, $extraHeaders = [])
    {
        $ch = curl_init($url);

        //headers
        $headers = array_merge($extraHeaders, [
            'User-Agent: Avify',
            'Accept: */*',
        ]);
        if($data) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($data);
        }
        if($bearerToken) {
            $headers[] = "Authorization: Bearer $bearerToken";;
        }

        //options
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if($data) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        foreach ($extraOpts as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }

        $result = curl_exec($ch);
        if ($error = curl_error($ch) && $logger) {
            if($logger instanceof Logger) {
                $logger->error("$url => $error");
            } else {
                if(is_string($logger)) {
                    $logger .= (time() . ":ERROR: " . json_encode(curl_getinfo($ch)) . ".\ln");
                    $logger .= (time() . ":ERROR: $url => " . curl_error($ch) . ".\ln");
                }
            }
        }
        curl_close($ch);

        return $result;
    }
}
