<?php
/**
 * Adyen Payment Module
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category    Adyen
 * @package        Adyen_Payment
 * @copyright    Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2015 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Model_Api extends Mage_Core_Model_Abstract
{
    const RECURRING_TYPE_ONECLICK = 'ONECLICK';
    const RECURRING_TYPE_RECURRING = 'RECURRING';
    const RECURRING_TYPE_ONECLICK_RECURRING = 'ONECLICK,RECURRING';
    const ENDPOINT_TEST = "https://pal-test.adyen.com/pal/adapter/httppost";
    const ENDPOINT_LIVE = "https://pal-live.adyen.com/pal/adapter/httppost";
    const ENDPOINT_TERMINAL_CLOUD_TEST = "https://terminal-api-test.adyen.com/sync";
    const ENDPOINT_TERMINAL_CLOUD_LIVE = "https://terminal-api-live.adyen.com/sync";
    const ENDPOINT_PROTOCOL = "https://";
    const CHECKOUT_ENDPOINT_LIVE_SUFFIX = "-checkout-live.adyenpayments.com/checkout";
    const ENDPOINT_CONNECTED_TERMINALS_TEST = "https://terminal-api-test.adyen.com/connectedTerminals";
    const ENDPOINT_CONNECTED_TERMINALS_LIVE = "https://terminal-api-live.adyen.com/connectedTerminals";

    protected $_recurringTypes = array(
        self::RECURRING_TYPE_ONECLICK,
        self::RECURRING_TYPE_RECURRING
    );

    protected $_paymentMethodMap;


    /**
     * @param string $shopperReference
     * @param string $recurringDetailReference
     * @param int|Mage_Core_model_Store|null $store
     * @return bool
     */
    public function getRecurringContractDetail($shopperReference, $recurringDetailReference, $store = null)
    {
        $recurringContracts = $this->listRecurringContracts($shopperReference, $store);
        foreach ($recurringContracts as $rc) {
            if (isset($rc['recurringDetailReference']) && $rc['recurringDetailReference'] == $recurringDetailReference) {
                return $rc;
            }
        }

        return false;
    }


    /**
     * Get all the stored Credit Cards and other billing agreements stored with Adyen.
     *
     * @param string $shopperReference
     * @param int|Mage_Core_model_Store|null $store
     * @return array
     */
    public function listRecurringContracts($shopperReference, $store = null)
    {

        $recurringContracts = array();
        foreach ($this->_recurringTypes as $recurringType) {
            try {
                // merge ONECLICK and RECURRING into one record with recurringType ONECLICK,RECURRING
                $listRecurringContractByType = $this->listRecurringContractByType(
                    $shopperReference, $store,
                    $recurringType
                );

                foreach ($listRecurringContractByType as $recurringContract) {
                    if (isset($recurringContract['recurringDetailReference'])) {
                        $recurringDetailReference = $recurringContract['recurringDetailReference'];
                        // check if recurring reference is already in array
                        if (isset($recurringContracts[$recurringDetailReference])) {
                            // recurring reference already exists so recurringType is possible for ONECLICK and RECURRING
                            $recurringContracts[$recurringDetailReference]['recurring_type'] = self::RECURRING_TYPE_ONECLICK_RECURRING;
                        } else {
                            $recurringContracts[$recurringDetailReference] = $recurringContract;
                        }
                    }
                }
            } catch (Adyen_Payment_Exception $e) {
                Adyen_Payment_Exception::throwException(
                    Mage::helper('adyen')->__(
                        "Error retrieving the Billing Agreement for shopperReference %s with recurringType #%s Error: %s",
                        $shopperReference, $recurringType, $e->getMessage()
                    )
                );
            }
        }

        return $recurringContracts;
    }


    /**
     * @param $shopperReference
     * @param $store
     * @param $recurringType
     *
     * @return array
     */
    public function listRecurringContractByType($shopperReference, $store, $recurringType)
    {
        // rest call to get list of recurring details
        $request = array(
            "action" => "Recurring.listRecurringDetails",
            "recurringDetailsRequest.merchantAccount" => $this->_helper()->getConfigData(
                'merchantAccount', null,
                $store
            ),
            "recurringDetailsRequest.shopperReference" => $shopperReference,
            "recurringDetailsRequest.recurring.contract" => $recurringType,
        );

        $result = $this->_doRequest($request, $store);

        // convert result to utf8 characters
        $result = utf8_encode(urldecode($result));

        // The $result contains a JSON array containing the available payment methods for the merchant account.
        parse_str($result, $resultArr);

        $recurringContracts = array();
        $recurringContractExtra = array();
        foreach ($resultArr as $key => $value) {
            // strip the key
            $key = str_replace("recurringDetailsResult_details_", "", $key);
            $key2 = strstr($key, '_');
            $keyNumber = str_replace($key2, "", $key);
            $keyAttribute = substr($key2, 1);

            // set ideal to sepadirectdebit because it is and we want to show sepadirectdebit logo
            if ($keyAttribute == "variant" && $value == "ideal") {
                $value = 'sepadirectdebit';
            }

            if ($keyAttribute == 'variant') {
                $recurringContracts[$keyNumber]['recurring_type'] = $recurringType;
                $recurringContracts[$keyNumber]['payment_method'] = $this->_mapToPaymentMethod($value);
            }

            $recurringContracts[$keyNumber][$keyAttribute] = $value;

            if ($keyNumber == 'recurringDetailsResult') {
                $recurringContractExtra[$keyAttribute] = $value;
            }
        }

        // unset the recurringDetailsResult because this is not a card
        unset($recurringContracts["recurringDetailsResult"]);

        foreach ($recurringContracts as $key => $recurringContract) {
            $recurringContracts[$key] = $recurringContracts[$key] + $recurringContractExtra;
        }

        return $recurringContracts;
    }

    /**
     * Map the recurring variant to a Magento payment method.
     * @param $variant
     * @return mixed
     */
    protected function _mapToPaymentMethod($variant)
    {
        if (is_null($this->_paymentMethodMap)) {
            //@todo abstract this away to some config?
            $this->_paymentMethodMap = array(
                'sepadirectdebit' => 'adyen_sepa'
            );


            $ccTypes = Mage::helper('adyen')->getCcTypes();
            $ccTypes = array_keys(array_change_key_case($ccTypes, CASE_LOWER));
            foreach ($ccTypes as $ccType) {
                $this->_paymentMethodMap[$ccType] = 'adyen_cc';
            }
        }

        return isset($this->_paymentMethodMap[$variant]) ? $this->_paymentMethodMap[$variant] : $variant;
    }


    /**
     * Disable a recurring contract
     *
     * @param string $recurringDetailReference
     * @param string $shopperReference
     * @param int|Mage_Core_model_Store|null $store
     *
     * @throws Adyen_Payment_Exception
     * @return bool
     */
    public function disableRecurringContract($recurringDetailReference, $shopperReference, $store = null)
    {
        $merchantAccount = $this->_helper()->getConfigData('merchantAccount', null, $store);

        $request = array(
            "action" => "Recurring.disable",
            "disableRequest.merchantAccount" => $merchantAccount,
            "disableRequest.shopperReference" => $shopperReference,
            "disableRequest.recurringDetailReference" => $recurringDetailReference
        );

        $result = $this->_doRequest($request, $store);

        // convert result to utf8 characters
        $result = utf8_encode(urldecode($result));

        if ($result != "disableResult.response=[detail-successfully-disabled]") {
            Adyen_Payment_Exception::throwException(Mage::helper('adyen')->__($result));
        }

        return true;
    }

    public function originKeys($store)
    {
        $cacheId = "adyen_origin_keys_" . $store;

        $originUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
        $parsed = parse_url($originUrl);
        $domain = $parsed['scheme'] . "://" . $parsed['host'];

        $request = array(
            "originDomains" => array($domain)
        );

        if ($cacheData = Mage::app()->getCache()->load($cacheId)) {
            $originKey = $cacheData;
        } else {
            try {
                $resultJson = $this->doRequestOriginKey($request, $store);
                $result = json_decode($resultJson, true);
                if (!empty($originKey = $result['originKeys'][$domain])) {
                    Mage::app()->getCache()->save(
                        $originKey, $cacheId,
                        array(Mage_Core_Model_Config::CACHE_TAG), 60 * 60 * 24
                    );
                }
            } catch (Exception $e) {
                return '';
            }
        }

        return $originKey;
    }


    /**
     * Do the actual API request
     *
     * @param array $request
     * @param int|Mage_Core_model_Store $storeId
     *
     * @throws Adyen_Payment_Exception
     * @return mixed
     */
    protected function _doRequest(array $request, $storeId)
    {
        if ($storeId instanceof Mage_Core_model_Store) {
            $storeId = $storeId->getId();
        }

        $requestUrl = self::ENDPOINT_LIVE;
        if ($this->_helper()->getConfigDataDemoMode($storeId)) {
            $requestUrl = self::ENDPOINT_TEST;
        }

        $username = $this->_helper()->getConfigDataWsUserName($storeId);
        $password = $this->_helper()->getConfigDataWsPassword($storeId);

        Mage::log($request, null, 'adyen_api.log');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POST, count($request));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $error = curl_error($ch);

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($result === false) {
            Adyen_Payment_Exception::throwException($error);
        }

        if ($httpStatus != 200) {
            Adyen_Payment_Exception::throwException(
                Mage::helper('adyen')->__(
                    'HTTP Status code %s received, data %s',
                    $httpStatus, $result
                )
            );
        }

        return $result;
    }

    protected function doRequestOriginKey(array $request, $storeId)
    {
        if ($storeId instanceof Mage_Core_model_Store) {
            $storeId = $storeId->getId();
        }

        if ($this->_helper()->getConfigDataDemoMode()) {
            $requestUrl = "https://checkout-test.adyen.com/v1/originKeys";
        } else {
            $requestUrl = self::ENDPOINT_PROTOCOL . $this->_helper()->getConfigData("live_endpoint_url_prefix") . self::CHECKOUT_ENDPOINT_LIVE_SUFFIX . "/v1/originKeys";
        }

        $apiKey = $this->_helper()->getConfigDataApiKey($storeId);

        return $this->doRequestJson($request, $requestUrl, $apiKey, $storeId);
    }

    /**
     * @return Adyen_Payment_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('adyen');
    }

    /**
     * Do the API request in json format
     *
     * @param array $request
     * @param $requestUrl
     * @param $apiKey
     * @param $storeId
     * @param null $timeout
     * @return mixed
     */
    protected function doRequestJson(array $request, $requestUrl, $apiKey, $storeId, $timeout = null)
    {
        $ch = curl_init();
        $headers = array(
            'Content-Type: application/json'
        );

        if (empty($apiKey)) {
            $username = $this->_helper()->getConfigDataWsUserName($storeId);
            $password = $this->_helper()->getConfigDataWsPassword($storeId);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        } else {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        if (!empty($timeout)) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }

        Mage::log($request, null, 'adyen_api.log');

        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCode = curl_errno($ch);
        curl_close($ch);
        if ($result === false) {
            Adyen_Payment_Exception::throwCurlException($error, $errorCode);
        }

        if ($httpStatus == 401 || $httpStatus == 403) {
            Adyen_Payment_Exception::throwException(
                Mage::helper('adyen')->__(
                    'Received Status code %s, please make sure your Checkout API key is correct.',
                    $httpStatus
                )
            );
        } elseif ($httpStatus != 200) {
            Adyen_Payment_Exception::throwException(
                Mage::helper('adyen')->__(
                    'HTTP Status code %s received, data %s',
                    $httpStatus, $result
                )
            );
        }

        return $result;
    }

    /**
     * Set the timeout and do a sync request to the Terminal API endpoint
     *
     * @param array $request
     * @param int $storeId
     * @return mixed
     */
    public function doRequestSync(array $request, $storeId)
    {
        $requestUrl = self::ENDPOINT_TERMINAL_CLOUD_LIVE;
        if ($this->_helper()->getConfigDataDemoMode($storeId)) {
            $requestUrl = self::ENDPOINT_TERMINAL_CLOUD_TEST;
        }

        $apiKey = $this->_helper()->getPosApiKey($storeId);
        $timeout = $this->_helper()->getConfigData('timeout', 'adyen_pos_cloud', $storeId);
        $response = $this->doRequestJson($request, $requestUrl, $apiKey, $storeId, $timeout);
        return json_decode($response, true);
    }

    /**
     * Do a synchronous request to retrieve the connected terminals
     *
     * @param $storeId
     * @return mixed
     */
    public function retrieveConnectedTerminals($storeId)
    {
        $requestUrl = self::ENDPOINT_CONNECTED_TERMINALS_LIVE;
        if ($this->_helper()->getConfigDataDemoMode($storeId)) {
            $requestUrl = self::ENDPOINT_CONNECTED_TERMINALS_TEST;
        }

        $apiKey = $this->_helper()->getPosApiKey($storeId);
        $merchantAccount = $this->_helper()->getAdyenMerchantAccount("pos_cloud", $storeId);
        $request = array("merchantAccount" => $merchantAccount);

        //If store_code is configured, retrieve only terminals connected to that store
        $storeCode = $this->_helper()->getConfigData('store_code', 'adyen_pos_cloud', $storeId);
        if ($storeCode) {
            $request["store"] = $storeCode;
        }
        $response = $this->doRequestJson($request, $requestUrl, $apiKey, $storeId);
        return $response;
    }
}
