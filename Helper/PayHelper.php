<?php

namespace ONVO\Pay\Helper;

use ONVO\Pay\Model\PaymentIntent;
use Emarketa\AdvancedLogger\Logger\Handler\System\DatadogFile;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

class PayHelper
{
    const CODE = 'onvo';

    /**
     * @var ScopeConfigInterface
     */
    private $_scopeConfig;

    /**
     * @var Session
     */
    private $_checkoutSession;

    /**
     * @var Logger
     */
    private $_logger;

    /**
     * @var EncryptorInterface
     */
    public $_encryptor;

    /**
     * @var PaymentIntent
     */
    private $_paymentIntentModel;

    /**
     * @var \ONVO\Pay\Model\ResourceModel\PaymentIntent
     */
    private $_paymentIntentResourceModel;

    /**
     * @var ManagerInterface
     */
    private $_eventManager;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Session $checkoutSession
     * @param EncryptorInterface $_encryptor
     * @param ManagerInterface $eventManager
     * @param PaymentIntent $paymentIntentModel
     * @param \ONVO\Pay\Model\ResourceModel\PaymentIntent $paymentIntentResourceModel
     */
    public function __construct(
        ScopeConfigInterface                          $scopeConfig,
        Session                                       $checkoutSession,
        EncryptorInterface                            $_encryptor,
        ManagerInterface                              $eventManager,
        PaymentIntent                                 $paymentIntentModel,
        \ONVO\Pay\Model\ResourceModel\PaymentIntent $paymentIntentResourceModel
    )
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_checkoutSession = $checkoutSession;
        $this->_encryptor = $_encryptor;
        $this->_eventManager = $eventManager;
        $this->_paymentIntentModel = $paymentIntentModel;
        $this->_paymentIntentResourceModel = $paymentIntentResourceModel;

        $logHandler = new RotatingFileHandler(BP . '/var/log/onvo.log', 3);
        $this->_logger = new Logger('Onvo');
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
        if ($quote = $this->_checkoutSession->getQuote()) {
            $customerId = $this->createUpdateCustomer($quote);

            $paymentIntentId = $this->createUpdatePaymentIntent($quote, $paymentIntentId, $customerId);
        }
        $this->jsonResponse([
            'payment_intent_id' => $paymentIntentId
        ]);
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function errorReport()
    {
        if ($quote = $this->_checkoutSession->getQuote()) {
            $errorData = json_decode(file_get_contents('php://input'), true);
            if ($this->getDebugEnabled()) {
                $this->_logger->debug('errorReport:');
                $this->_logger->debug(json_encode($errorData));
            }
            $this->_eventManager->dispatch(
                'sales_model_service_quote_submit_failure',
                [
                    'order' => null,
                    'quote' => $quote,
                    'exception' => new \Exception(json_encode($errorData))
                ]
            );
        }
    }

    /**
     * @param Quote $quote
     */
    private function createUpdateCustomer(Quote $quote)
    {
        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = !$quote->isVirtual() ? $quote->getShippingAddress() : $billingAddress;
        $url = $this->getApiUrl() . '/customers';

        //get current email
        $query = urldecode($_SERVER['QUERY_STRING']);
        $query = explode('&', $query);
        $result = [];
        foreach ($query as $q) {
            $q = explode('=', $q);
            $result[$q[0]] = $q[1] ?? '';
        }
        if (isset($result['email'])) {
            $email = $result['email'];
        } else {
            $email = $billingAddress->getEmail() ?: $shippingAddress->getEmail();
            $email = $email ?: $quote->getCustomerEmail();
        }

        //search customer
        $customerId = '';
        $result = json_decode($this->curlCall(
            $url . "?email={$email}&limit=1", [], null,
            $this->getSecretKey()
        ), true);
        if (isset($result['data'][0]['id'])) {
            $customerId = $result['data'][0]['id'];
            $url .= "/{$customerId}";
        }
        if ($this->getDebugEnabled()) {
            $this->_logger->debug('searchCustomer:');
            $this->_logger->debug($email);
            $this->_logger->debug(json_encode($result));
        }

        //update/create customer
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
        if ($this->getDebugEnabled()) {
            $this->_logger->debug('createUpdateCustomer:');
            $this->_logger->debug(json_encode($customerData));
            $this->_logger->debug(json_encode($result));
        }

        if (isset($result['id'])) {
            $customerId = $result['id'];
        } else {
            $this->returnError($result);
        }

        return $customerId;
    }

    /**
     * @param Quote $quote
     * @param $paymentIntentId
     * @param $customerId
     * @return mixed|string
     */
    private function createUpdatePaymentIntent(Quote $quote, $paymentIntentId, $customerId)
    {
        $paymentIntentModel = $this->getPaymentIntentModel($quote->getId());
        if ($this->getDebugEnabled()) {
            $this->_logger->debug($paymentIntentModel->getPiId());
            $this->_logger->debug(json_encode($paymentIntentModel->getData()));
        }

        if ($paymentIntentModel->getPiId() && $paymentIntentId) {
            if ($paymentIntentId != $paymentIntentModel->getPiId()) {
                throw new \Exception('Payment intent id mismatch');
            }
        }

        if ($paymentIntentModel->getPiId() && !$paymentIntentId) {
            $paymentIntentId = $paymentIntentModel->getPiId();
        }

        $id = '';
        $url = $this->getApiUrl() . '/payment-intents';
        if ($paymentIntentId) {
            $url .= "/" . $paymentIntentId;
        }
        $paymentIntentData = [
            "amount" => $quote->getGrandTotal() * 100,
            "currency" => $quote->getQuoteCurrencyCode(),
            "customerId" => $customerId
        ];
        $result = json_decode($this->curlCall(
            $url, [],
            json_encode($paymentIntentData),
            $this->getSecretKey()
        ), true);
        if ($this->getDebugEnabled()) {
            $this->_logger->debug('createUpdatePaymentIntent:');
            $this->_logger->debug(json_encode($paymentIntentData));
            $this->_logger->debug(json_encode($result));
        }

        if (isset($result['id'])) {
            $this->savePaymentIntentModel($quote->getId(), [
                "pi_id" => $result['id']
            ]);
            $id = $result['id'];
        } else {
            $this->returnError($result);
        }
        return $id;
    }

    /**
     * @param $quoteId
     * @return PaymentIntent
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function getPaymentIntentModel($quoteId)
    {
        $this->_paymentIntentModel->setId(null);
        $model = clone $this->_paymentIntentModel;
        $this->_paymentIntentModel = $model;
        $this->_paymentIntentResourceModel->load(
            $model, $quoteId, 'quote_id'
        );
        if ($model->getId()) {
            return $model;
        }
        return $this->savePaymentIntentModel($quoteId, []);
    }

    /**
     * @param $quoteId
     * @param $data
     * @return PaymentIntent
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function savePaymentIntentModel($quoteId, $data)
    {
        $this->_paymentIntentResourceModel->load(
            $this->_paymentIntentModel,
            $quoteId,
            'quote_id'
        );
        if ($this->_paymentIntentModel->getId()) {
            $this->_paymentIntentModel->addData($data);
            $this->_paymentIntentModel->isObjectNew(false);
        } else {
            $data['quote_id'] = $quoteId;
            $this->_paymentIntentModel->addData($data);
            $this->_paymentIntentModel->isObjectNew(true);
        }
        $this->_paymentIntentModel->setDataChanges(true);
        $this->_paymentIntentResourceModel->save($this->_paymentIntentModel);
        return $this->_paymentIntentModel;
    }

    /**
     * @param $result
     */
    private function returnError($result)
    {
        $error = 'Unknown error';
        if (isset($result['message'])) {
            if (is_array($result['message'])) {
                $error = $result['message'][0];
            } else {
                $error = $result['message'];
            }
        }
        $this->jsonResponse([
            'error' => $error
        ]);
    }

    /**
     * @return mixed
     */
    public function getSecretKey()
    {
        if ($this->getUseSandbox()) {
            $secret = $this->_scopeConfig->getValue(
                "payment/" . self::CODE . "/sandbox_secret_key",
                ScopeInterface::SCOPE_STORE
            );
        } else {
            $secret = $this->_scopeConfig->getValue(
                "payment/" . self::CODE . "/secret_key",
                ScopeInterface::SCOPE_STORE
            );
        }
        return $this->_encryptor->decrypt($secret);
    }

    /**
     * @return mixed
     */
    public function getPublicKey()
    {
        if ($this->getUseSandbox()) {
            return $this->_scopeConfig->getValue(
                "payment/" . self::CODE . "/sandbox_public_key",
                ScopeInterface::SCOPE_STORE
            );
        } else {
            return $this->_scopeConfig->getValue(
                "payment/" . self::CODE . "/public_key",
                ScopeInterface::SCOPE_STORE
            );
        }
    }

    /**
     * @return mixed
     */
    public function getApiUrl()
    {
        return $this->_scopeConfig->getValue(
            "payment/" . self::CODE . "/api_url",
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return mixed
     */
    public function getUseSandbox()
    {
        return $this->_scopeConfig->getValue(
            "payment/" . self::CODE . "/use_sandbox",
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return mixed
     */
    public function getDebugEnabled()
    {
        return $this->_scopeConfig->getValue(
            "payment/" . self::CODE . "/debug_enabled",
            ScopeInterface::SCOPE_STORE
        );
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

    /**
     * @param $response
     * @param bool $rest
     * @return false|string|void
     */
    private function jsonResponse($response, $rest = true)
    {
        if (is_array($response)) {
            array_walk_recursive(
                $response,
                function (&$entry) {
                    if(is_string($entry)) {
                        $entry = mb_convert_encoding($entry, 'UTF-8');
                    }
                }
            );
        }

        if ($rest) {
            try {
                header('Content-Type: application/json');
            } catch (\Exception $e) {
            }
            if (is_array($response)) {
                echo json_encode($response);
            } else {
                if (is_string($response)) {
                    echo $response;
                }
            }
            exit;
        } else {
            if (is_array($response)) {
                return json_encode($response);
            } else {
                if (is_string($response)) {
                    return $response;
                }
            }
        }
    }
}
