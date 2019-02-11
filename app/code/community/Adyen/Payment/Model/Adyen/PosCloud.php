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
 * @package    Adyen_Payment
 * @copyright    Copyright (c) 2011 Adyen (http://www.adyen.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * @category   Payment Gateway
 * @package    Adyen_Payment
 * @author     Adyen
 * @property   Adyen B.V
 * @copyright  Copyright (c) 2014 Adyen BV (http://www.adyen.com)
 */
class Adyen_Payment_Model_Adyen_PosCloud extends Adyen_Payment_Model_Adyen_Abstract
{

    /**
     * @var bool
     */
    protected $_canUseInternal = false;
    /**
     * @var string
     */
    protected $_code = 'adyen_pos_cloud';
    /**
     * @var string
     */
    protected $_formBlockType = 'adyen/form_posCloud';
    /**
     * @var string
     */
    protected $_infoBlockType = 'adyen/info_posCloud';
    /**
     * @var string
     */
    protected $_paymentMethod = 'pos_cloud';
    /**
     * @var string
     */
    protected $_paymentMethodType = 'pos_cloud';

    /**
     * @var GUEST_ID , used when order is placed by guests
     */
    const GUEST_ID = 'customer_';

    /**
     * @desc Get payment method type
     *
     * @return string
     */
    public function getPaymentMethodType()
    {
        return $this->_paymentMethodType;
    }

    /**
     * @desc Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * @desc Get current quote
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }

    /**
     * @return mixed
     */
    public function getOrderPlaceRedirectUrl()
    {
        return parent::getOrderPlaceRedirectUrl();
    }

    /**
     * @return string
     */
    public function getFormName()
    {
        return "Adyen POS Cloud";
    }

    /**
     * Return redirect block type
     *
     * @return string
     */
    public function getRedirectBlockType()
    {
        return $this->_redirectBlockType;
    }

    /**
     * @param $paymentAction
     * @param $stateObject
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_NEW;
        $stateObject->setState($state);
        $stateObject->setStatus($this->_getConfigData('order_status'));
    }

    /*
     * Check if IP filter is active
     */
    public function isAvailable($quote = null)
    {
        $isAvailable = parent::isAvailable($quote);
        // check if ip range is enabled
        $ipFilter = $this->_getConfigData('ip_filter', 'adyen_pos_cloud');

        if ($isAvailable && $ipFilter != 0) {
            $ip = Mage::helper('adyen')->getClientIp();
            switch ($ipFilter) {
                case '1':
                    // check if ip in in list
                    $_list = $this->_getConfigData('ip_filter_ips', 'adyen_pos_cloud');
                    $_list = str_replace(' ', '', $_list);
                    $_list = explode(',', $_list);
                    $isAvailable = in_array($ip, $_list);
                    break;
                case '2':
                default:
                    // check if ip is in range
                    $from = $this->_getConfigData('ip_filter_from', 'adyen_pos_cloud');
                    $to = $this->_getConfigData('ip_filter_to', 'adyen_pos_cloud');
                    $isAvailable = Mage::helper('adyen')->ipInRange($ip, $from, $to);
                    break;
            }
        }

        return $isAvailable;
    }
}
