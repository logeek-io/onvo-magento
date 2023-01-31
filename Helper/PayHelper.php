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
        ScopeConfigInterface                        $scopeConfig,
        Session                                     $checkoutSession,
        EncryptorInterface                          $_encryptor,
        ManagerInterface                            $eventManager,
        PaymentIntent                               $paymentIntentModel,
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
            "phone" => $this->intlPhone($billingAddress->getTelephone(), $billingAddress->getCountryId()),
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
                "phone" => $this->intlPhone($shippingAddress->getTelephone(), $shippingAddress->getCountryId())
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
        if ($data) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($data);
        }
        if ($bearerToken) {
            $headers[] = "Authorization: Bearer $bearerToken";;
        }

        //options
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($data) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        foreach ($extraOpts as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }

        $result = curl_exec($ch);
        if ($error = curl_error($ch) && $logger) {
            if ($logger instanceof Logger) {
                $logger->error("$url => $error");
            } else {
                if (is_string($logger)) {
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
                    if (is_string($entry)) {
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

    /**
     * @return \string[][]
     */
    private function getCountries()
    {
        return array(
            "AF" => array("name" => "Afghanistan", "phone" => "93"),
            "AX" => array("name" => "Aland Islands", "phone" => "358"),
            "AL" => array("name" => "Albania", "phone" => "355"),
            "DZ" => array("name" => "Algeria", "phone" => "213"),
            "AS" => array("name" => "American Samoa", "phone" => "1684"),
            "AD" => array("name" => "Andorra", "phone" => "376"),
            "AO" => array("name" => "Angola", "phone" => "244"),
            "AI" => array("name" => "Anguilla", "phone" => "1264"),
            "AQ" => array("name" => "Antarctica", "phone" => "672"),
            "AG" => array("name" => "Antigua and Barbuda", "phone" => "1268"),
            "AR" => array("name" => "Argentina", "phone" => "54"),
            "AM" => array("name" => "Armenia", "phone" => "374"),
            "AW" => array("name" => "Aruba", "phone" => "297"),
            "AU" => array("name" => "Australia", "phone" => "61"),
            "AT" => array("name" => "Austria", "phone" => "43"),
            "AZ" => array("name" => "Azerbaijan", "phone" => "994"),
            "BS" => array("name" => "Bahamas", "phone" => "1242"),
            "BH" => array("name" => "Bahrain", "phone" => "973"),
            "BD" => array("name" => "Bangladesh", "phone" => "880"),
            "BB" => array("name" => "Barbados", "phone" => "1246"),
            "BY" => array("name" => "Belarus", "phone" => "375"),
            "BE" => array("name" => "Belgium", "phone" => "32"),
            "BZ" => array("name" => "Belize", "phone" => "501"),
            "BJ" => array("name" => "Benin", "phone" => "229"),
            "BM" => array("name" => "Bermuda", "phone" => "1441"),
            "BT" => array("name" => "Bhutan", "phone" => "975"),
            "BO" => array("name" => "Bolivia", "phone" => "591"),
            "BQ" => array("name" => "Bonaire, Sint Eustatius and Saba", "phone" => "599"),
            "BA" => array("name" => "Bosnia and Herzegovina", "phone" => "387"),
            "BW" => array("name" => "Botswana", "phone" => "267"),
            "BV" => array("name" => "Bouvet Island", "phone" => "55"),
            "BR" => array("name" => "Brazil", "phone" => "55"),
            "IO" => array("name" => "British Indian Ocean Territory", "phone" => "246"),
            "BN" => array("name" => "Brunei Darussalam", "phone" => "673"),
            "BG" => array("name" => "Bulgaria", "phone" => "359"),
            "BF" => array("name" => "Burkina Faso", "phone" => "226"),
            "BI" => array("name" => "Burundi", "phone" => "257"),
            "KH" => array("name" => "Cambodia", "phone" => "855"),
            "CM" => array("name" => "Cameroon", "phone" => "237"),
            "CA" => array("name" => "Canada", "phone" => "1"),
            "CV" => array("name" => "Cape Verde", "phone" => "238"),
            "KY" => array("name" => "Cayman Islands", "phone" => "1345"),
            "CF" => array("name" => "Central African Republic", "phone" => "236"),
            "TD" => array("name" => "Chad", "phone" => "235"),
            "CL" => array("name" => "Chile", "phone" => "56"),
            "CN" => array("name" => "China", "phone" => "86"),
            "CX" => array("name" => "Christmas Island", "phone" => "61"),
            "CC" => array("name" => "Cocos (Keeling) Islands", "phone" => "672"),
            "CO" => array("name" => "Colombia", "phone" => "57"),
            "KM" => array("name" => "Comoros", "phone" => "269"),
            "CG" => array("name" => "Congo", "phone" => "242"),
            "CD" => array("name" => "Congo, Democratic Republic of the Congo", "phone" => "242"),
            "CK" => array("name" => "Cook Islands", "phone" => "682"),
            "CR" => array("name" => "Costa Rica", "phone" => "506"),
            "CI" => array("name" => "Cote D'Ivoire", "phone" => "225"),
            "HR" => array("name" => "Croatia", "phone" => "385"),
            "CU" => array("name" => "Cuba", "phone" => "53"),
            "CW" => array("name" => "Curacao", "phone" => "599"),
            "CY" => array("name" => "Cyprus", "phone" => "357"),
            "CZ" => array("name" => "Czech Republic", "phone" => "420"),
            "DK" => array("name" => "Denmark", "phone" => "45"),
            "DJ" => array("name" => "Djibouti", "phone" => "253"),
            "DM" => array("name" => "Dominica", "phone" => "1767"),
            "DO" => array("name" => "Dominican Republic", "phone" => "1809"),
            "EC" => array("name" => "Ecuador", "phone" => "593"),
            "EG" => array("name" => "Egypt", "phone" => "20"),
            "SV" => array("name" => "El Salvador", "phone" => "503"),
            "GQ" => array("name" => "Equatorial Guinea", "phone" => "240"),
            "ER" => array("name" => "Eritrea", "phone" => "291"),
            "EE" => array("name" => "Estonia", "phone" => "372"),
            "ET" => array("name" => "Ethiopia", "phone" => "251"),
            "FK" => array("name" => "Falkland Islands (Malvinas)", "phone" => "500"),
            "FO" => array("name" => "Faroe Islands", "phone" => "298"),
            "FJ" => array("name" => "Fiji", "phone" => "679"),
            "FI" => array("name" => "Finland", "phone" => "358"),
            "FR" => array("name" => "France", "phone" => "33"),
            "GF" => array("name" => "French Guiana", "phone" => "594"),
            "PF" => array("name" => "French Polynesia", "phone" => "689"),
            "TF" => array("name" => "French Southern Territories", "phone" => "262"),
            "GA" => array("name" => "Gabon", "phone" => "241"),
            "GM" => array("name" => "Gambia", "phone" => "220"),
            "GE" => array("name" => "Georgia", "phone" => "995"),
            "DE" => array("name" => "Germany", "phone" => "49"),
            "GH" => array("name" => "Ghana", "phone" => "233"),
            "GI" => array("name" => "Gibraltar", "phone" => "350"),
            "GR" => array("name" => "Greece", "phone" => "30"),
            "GL" => array("name" => "Greenland", "phone" => "299"),
            "GD" => array("name" => "Grenada", "phone" => "1473"),
            "GP" => array("name" => "Guadeloupe", "phone" => "590"),
            "GU" => array("name" => "Guam", "phone" => "1671"),
            "GT" => array("name" => "Guatemala", "phone" => "502"),
            "GG" => array("name" => "Guernsey", "phone" => "44"),
            "GN" => array("name" => "Guinea", "phone" => "224"),
            "GW" => array("name" => "Guinea-Bissau", "phone" => "245"),
            "GY" => array("name" => "Guyana", "phone" => "592"),
            "HT" => array("name" => "Haiti", "phone" => "509"),
            "HM" => array("name" => "Heard Island and Mcdonald Islands", "phone" => "0"),
            "VA" => array("name" => "Holy See (Vatican City State)", "phone" => "39"),
            "HN" => array("name" => "Honduras", "phone" => "504"),
            "HK" => array("name" => "Hong Kong", "phone" => "852"),
            "HU" => array("name" => "Hungary", "phone" => "36"),
            "IS" => array("name" => "Iceland", "phone" => "354"),
            "IN" => array("name" => "India", "phone" => "91"),
            "ID" => array("name" => "Indonesia", "phone" => "62"),
            "IR" => array("name" => "Iran, Islamic Republic of", "phone" => "98"),
            "IQ" => array("name" => "Iraq", "phone" => "964"),
            "IE" => array("name" => "Ireland", "phone" => "353"),
            "IM" => array("name" => "Isle of Man", "phone" => "44"),
            "IL" => array("name" => "Israel", "phone" => "972"),
            "IT" => array("name" => "Italy", "phone" => "39"),
            "JM" => array("name" => "Jamaica", "phone" => "1876"),
            "JP" => array("name" => "Japan", "phone" => "81"),
            "JE" => array("name" => "Jersey", "phone" => "44"),
            "JO" => array("name" => "Jordan", "phone" => "962"),
            "KZ" => array("name" => "Kazakhstan", "phone" => "7"),
            "KE" => array("name" => "Kenya", "phone" => "254"),
            "KI" => array("name" => "Kiribati", "phone" => "686"),
            "KP" => array("name" => "Korea, Democratic People's Republic of", "phone" => "850"),
            "KR" => array("name" => "Korea, Republic of", "phone" => "82"),
            "XK" => array("name" => "Kosovo", "phone" => "381"),
            "KW" => array("name" => "Kuwait", "phone" => "965"),
            "KG" => array("name" => "Kyrgyzstan", "phone" => "996"),
            "LA" => array("name" => "Lao People's Democratic Republic", "phone" => "856"),
            "LV" => array("name" => "Latvia", "phone" => "371"),
            "LB" => array("name" => "Lebanon", "phone" => "961"),
            "LS" => array("name" => "Lesotho", "phone" => "266"),
            "LR" => array("name" => "Liberia", "phone" => "231"),
            "LY" => array("name" => "Libyan Arab Jamahiriya", "phone" => "218"),
            "LI" => array("name" => "Liechtenstein", "phone" => "423"),
            "LT" => array("name" => "Lithuania", "phone" => "370"),
            "LU" => array("name" => "Luxembourg", "phone" => "352"),
            "MO" => array("name" => "Macao", "phone" => "853"),
            "MK" => array("name" => "Macedonia, the Former Yugoslav Republic of", "phone" => "389"),
            "MG" => array("name" => "Madagascar", "phone" => "261"),
            "MW" => array("name" => "Malawi", "phone" => "265"),
            "MY" => array("name" => "Malaysia", "phone" => "60"),
            "MV" => array("name" => "Maldives", "phone" => "960"),
            "ML" => array("name" => "Mali", "phone" => "223"),
            "MT" => array("name" => "Malta", "phone" => "356"),
            "MH" => array("name" => "Marshall Islands", "phone" => "692"),
            "MQ" => array("name" => "Martinique", "phone" => "596"),
            "MR" => array("name" => "Mauritania", "phone" => "222"),
            "MU" => array("name" => "Mauritius", "phone" => "230"),
            "YT" => array("name" => "Mayotte", "phone" => "262"),
            "MX" => array("name" => "Mexico", "phone" => "52"),
            "FM" => array("name" => "Micronesia, Federated States of", "phone" => "691"),
            "MD" => array("name" => "Moldova, Republic of", "phone" => "373"),
            "MC" => array("name" => "Monaco", "phone" => "377"),
            "MN" => array("name" => "Mongolia", "phone" => "976"),
            "ME" => array("name" => "Montenegro", "phone" => "382"),
            "MS" => array("name" => "Montserrat", "phone" => "1664"),
            "MA" => array("name" => "Morocco", "phone" => "212"),
            "MZ" => array("name" => "Mozambique", "phone" => "258"),
            "MM" => array("name" => "Myanmar", "phone" => "95"),
            "NA" => array("name" => "Namibia", "phone" => "264"),
            "NR" => array("name" => "Nauru", "phone" => "674"),
            "NP" => array("name" => "Nepal", "phone" => "977"),
            "NL" => array("name" => "Netherlands", "phone" => "31"),
            "AN" => array("name" => "Netherlands Antilles", "phone" => "599"),
            "NC" => array("name" => "New Caledonia", "phone" => "687"),
            "NZ" => array("name" => "New Zealand", "phone" => "64"),
            "NI" => array("name" => "Nicaragua", "phone" => "505"),
            "NE" => array("name" => "Niger", "phone" => "227"),
            "NG" => array("name" => "Nigeria", "phone" => "234"),
            "NU" => array("name" => "Niue", "phone" => "683"),
            "NF" => array("name" => "Norfolk Island", "phone" => "672"),
            "MP" => array("name" => "Northern Mariana Islands", "phone" => "1670"),
            "NO" => array("name" => "Norway", "phone" => "47"),
            "OM" => array("name" => "Oman", "phone" => "968"),
            "PK" => array("name" => "Pakistan", "phone" => "92"),
            "PW" => array("name" => "Palau", "phone" => "680"),
            "PS" => array("name" => "Palestinian Territory, Occupied", "phone" => "970"),
            "PA" => array("name" => "Panama", "phone" => "507"),
            "PG" => array("name" => "Papua New Guinea", "phone" => "675"),
            "PY" => array("name" => "Paraguay", "phone" => "595"),
            "PE" => array("name" => "Peru", "phone" => "51"),
            "PH" => array("name" => "Philippines", "phone" => "63"),
            "PN" => array("name" => "Pitcairn", "phone" => "64"),
            "PL" => array("name" => "Poland", "phone" => "48"),
            "PT" => array("name" => "Portugal", "phone" => "351"),
            "PR" => array("name" => "Puerto Rico", "phone" => "1787"),
            "QA" => array("name" => "Qatar", "phone" => "974"),
            "RE" => array("name" => "Reunion", "phone" => "262"),
            "RO" => array("name" => "Romania", "phone" => "40"),
            "RU" => array("name" => "Russian Federation", "phone" => "70"),
            "RW" => array("name" => "Rwanda", "phone" => "250"),
            "BL" => array("name" => "Saint Barthelemy", "phone" => "590"),
            "SH" => array("name" => "Saint Helena", "phone" => "290"),
            "KN" => array("name" => "Saint Kitts and Nevis", "phone" => "1869"),
            "LC" => array("name" => "Saint Lucia", "phone" => "1758"),
            "MF" => array("name" => "Saint Martin", "phone" => "590"),
            "PM" => array("name" => "Saint Pierre and Miquelon", "phone" => "508"),
            "VC" => array("name" => "Saint Vincent and the Grenadines", "phone" => "1784"),
            "WS" => array("name" => "Samoa", "phone" => "684"),
            "SM" => array("name" => "San Marino", "phone" => "378"),
            "ST" => array("name" => "Sao Tome and Principe", "phone" => "239"),
            "SA" => array("name" => "Saudi Arabia", "phone" => "966"),
            "SN" => array("name" => "Senegal", "phone" => "221"),
            "RS" => array("name" => "Serbia", "phone" => "381"),
            "CS" => array("name" => "Serbia and Montenegro", "phone" => "381"),
            "SC" => array("name" => "Seychelles", "phone" => "248"),
            "SL" => array("name" => "Sierra Leone", "phone" => "232"),
            "SG" => array("name" => "Singapore", "phone" => "65"),
            "SX" => array("name" => "Sint Maarten", "phone" => "1"),
            "SK" => array("name" => "Slovakia", "phone" => "421"),
            "SI" => array("name" => "Slovenia", "phone" => "386"),
            "SB" => array("name" => "Solomon Islands", "phone" => "677"),
            "SO" => array("name" => "Somalia", "phone" => "252"),
            "ZA" => array("name" => "South Africa", "phone" => "27"),
            "GS" => array("name" => "South Georgia and the South Sandwich Islands", "phone" => "500"),
            "SS" => array("name" => "South Sudan", "phone" => "211"),
            "ES" => array("name" => "Spain", "phone" => "34"),
            "LK" => array("name" => "Sri Lanka", "phone" => "94"),
            "SD" => array("name" => "Sudan", "phone" => "249"),
            "SR" => array("name" => "Suriname", "phone" => "597"),
            "SJ" => array("name" => "Svalbard and Jan Mayen", "phone" => "47"),
            "SZ" => array("name" => "Swaziland", "phone" => "268"),
            "SE" => array("name" => "Sweden", "phone" => "46"),
            "CH" => array("name" => "Switzerland", "phone" => "41"),
            "SY" => array("name" => "Syrian Arab Republic", "phone" => "963"),
            "TW" => array("name" => "Taiwan, Province of China", "phone" => "886"),
            "TJ" => array("name" => "Tajikistan", "phone" => "992"),
            "TZ" => array("name" => "Tanzania, United Republic of", "phone" => "255"),
            "TH" => array("name" => "Thailand", "phone" => "66"),
            "TL" => array("name" => "Timor-Leste", "phone" => "670"),
            "TG" => array("name" => "Togo", "phone" => "228"),
            "TK" => array("name" => "Tokelau", "phone" => "690"),
            "TO" => array("name" => "Tonga", "phone" => "676"),
            "TT" => array("name" => "Trinidad and Tobago", "phone" => "1868"),
            "TN" => array("name" => "Tunisia", "phone" => "216"),
            "TR" => array("name" => "Turkey", "phone" => "90"),
            "TM" => array("name" => "Turkmenistan", "phone" => "7370"),
            "TC" => array("name" => "Turks and Caicos Islands", "phone" => "1649"),
            "TV" => array("name" => "Tuvalu", "phone" => "688"),
            "UG" => array("name" => "Uganda", "phone" => "256"),
            "UA" => array("name" => "Ukraine", "phone" => "380"),
            "AE" => array("name" => "United Arab Emirates", "phone" => "971"),
            "GB" => array("name" => "United Kingdom", "phone" => "44"),
            "US" => array("name" => "United States", "phone" => "1"),
            "UM" => array("name" => "United States Minor Outlying Islands", "phone" => "1"),
            "UY" => array("name" => "Uruguay", "phone" => "598"),
            "UZ" => array("name" => "Uzbekistan", "phone" => "998"),
            "VU" => array("name" => "Vanuatu", "phone" => "678"),
            "VE" => array("name" => "Venezuela", "phone" => "58"),
            "VN" => array("name" => "Viet Nam", "phone" => "84"),
            "VG" => array("name" => "Virgin Islands, British", "phone" => "1284"),
            "VI" => array("name" => "Virgin Islands, U.s.", "phone" => "1340"),
            "WF" => array("name" => "Wallis and Futuna", "phone" => "681"),
            "EH" => array("name" => "Western Sahara", "phone" => "212"),
            "YE" => array("name" => "Yemen", "phone" => "967"),
            "ZM" => array("name" => "Zambia", "phone" => "260"),
            "ZW" => array("name" => "Zimbabwe", "phone" => "263")
        );
    }

    /**
     * @param $phone
     * @param $country
     * @return mixed|string
     */
    private function intlPhone($phone, $country)
    {
        $countries = $this->getCountries();
        if (isset($countries[$country])) {
            //sanitize
            $phone = str_replace('+', '', $phone);
            $phone = str_replace(' ', '', $phone);
            $phone = str_replace('-', '', $phone);
            $countryPhone = $countries[$country]['phone'];
            if (substr($phone, 0, strlen($countryPhone)) == $countryPhone) {
                $phone = substr($phone, strlen($countryPhone));
            }
            //add intl format
            $phone = "+{$countryPhone}{$phone}";
        }
        return $phone;
    }
}
