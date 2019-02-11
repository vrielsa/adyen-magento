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
class Adyen_Payment_Block_Redirect extends Mage_Core_Block_Abstract
{

    /**
     * Collected debug information
     *
     * @var array
     */
    protected $_debugData = array();

    /**
     * Zend_Log debug level
     * @var unknown_type
     */
    const DEBUG_LEVEL = 7;

    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    protected function _getOrder()
    {
        if ($this->getOrder()) {
            return $this->getOrder();
        } else {
            // log the exception
            Mage::log("Redirect exception could not load the order:", Zend_Log::DEBUG, "adyen_notification.log", true);
            return null;
        }
    }

    protected function _toHtml()
    {
        $order = $this->_getOrder();
        $payment = $order->getPayment()->getMethodInstance();
        $html = '<html><head><link rel="stylesheet" type="text/css" href="' . Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN) . '/frontend/base/default/css/adyenstyle.css"><script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js" ></script>';
        $html .= '</head><body class="redirect-body-adyen">';

        // do not use Magento form because this generate a form_key input field
        $html .= '<form name="adyenForm" id="' . $payment->getCode() . '" action="' . $payment->getFormUrl() . '" method="post">';

        foreach ($payment->getFormFields() as $field => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($field, ENT_COMPAT | ENT_HTML401, 'UTF-8') .
                '" value="' . htmlspecialchars($value, ENT_COMPAT | ENT_HTML401, 'UTF-8') . '" />';
        }

        $html .= '</form>';
        $html .= '<script type="text/javascript">document.getElementById("' . $payment->getCode() . '").submit();</script>';
        $html .= '</body></html>';

        // log the actual HTML
        Mage::log($html, self::DEBUG_LEVEL, 'adyen_http-request-form.log');

        return $html;
    }


    /**
     * Log debug data to file
     *
     * @param mixed $debugData
     */
    protected function _debug(
        $storeId
    ) {
        if ($this->_getConfigData('debug', 'adyen_abstract', $storeId)) {
            $file = 'adyen_request_pos.log';
            Mage::getModel('core/log_adapter', $file)->log($this->_debugData);
        }
    }

    private function getReceiptOrderLines(
        $order
    ) {

        $myReceiptOrderLines = "";

        // temp
        $currency = $order->getOrderCurrencyCode();
        $formattedAmountValue = Mage::helper('core')->formatPrice($order->getGrandTotal(), false);

        $formattedAmountValue = Mage::getModel('directory/currency')->format(
            $order->getGrandTotal(),
            array('display' => Zend_Currency::NO_SYMBOL),
            false
        );

        $taxAmount = Mage::helper('checkout')->getQuote()->getShippingAddress()->getData('tax_amount');
        $formattedTaxAmount = Mage::getModel('directory/currency')->format(
            $taxAmount,
            array('display' => Zend_Currency::NO_SYMBOL),
            false
        );

        $paymentAmount = "1000";

        $myReceiptOrderLines .= "---||C\n" .
            "====== YOUR ORDER DETAILS ======||CB\n" .
            "---||C\n" .
            " No. Description |Piece  Subtotal|\n";

        foreach ($order->getItemsCollection() as $item) {
            //skip dummies
            if ($item->isDummy()) {
                continue;
            }

            $singlePriceFormat = Mage::getModel('directory/currency')->format(
                $item->getPriceInclTax(),
                array('display' => Zend_Currency::NO_SYMBOL),
                false
            );

            $itemAmount = $item->getPriceInclTax() * (int)$item->getQtyOrdered();
            $itemAmountFormat = Mage::getModel('directory/currency')->format(
                $itemAmount,
                array('display' => Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . (int)$item->getQtyOrdered() . "  " . trim(
                    substr(
                        $item->getName(), 0,
                        25
                    )
                ) . "| " . $currency . " " . $singlePriceFormat . "  " . $currency . " " . $itemAmountFormat . "|\n";
        }

        //discount cost
        if ($order->getDiscountAmount() > 0 || $order->getDiscountAmount() < 0) {
            $discountAmountFormat = Mage::getModel('directory/currency')->format(
                $order->getDiscountAmount(),
                array('display' => Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . 1 . " " . $this->__('Total Discount') . "| " . $currency . " " . $discountAmountFormat . "|\n";
        }

        //shipping cost
        if ($order->getShippingAmount() > 0 || $order->getShippingTaxAmount() > 0) {
            $shippingAmountFormat = Mage::getModel('directory/currency')->format(
                $order->getShippingAmount(),
                array('display' => Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . 1 . " " . $order->getShippingDescription() . "| " . $currency . " " . $shippingAmountFormat . "|\n";
        }

        if ($order->getPaymentFeeAmount() > 0) {
            $paymentFeeAmount = Mage::getModel('directory/currency')->format(
                $order->getPaymentFeeAmount(),
                array('display' => Zend_Currency::NO_SYMBOL),
                false
            );
            $myReceiptOrderLines .= "  " . 1 . " " . $this->__('Payment Fee') . "| " . $currency . " " . $paymentFeeAmount . "|\n";
        }

        $myReceiptOrderLines .= "|--------|\n" .
            "|Order Total:  " . $currency . " " . $formattedAmountValue . "|B\n" .
            "|Tax:  " . $currency . " " . $formattedTaxAmount . "|B\n" .
            "||C\n";

        //Cool new header for card details section! Default location is After Header so simply add to Order Details as separator
        $myReceiptOrderLines .= "---||C\n" .
            "====== YOUR PAYMENT DETAILS ======||CB\n" .
            "---||C\n";


        return $myReceiptOrderLines;

    }

    /**
     * @param $code
     * @param null $paymentMethodCode
     * @param int|null $storeId
     * @return mixed
     */
    protected
    function _getConfigData(
        $code,
        $paymentMethodCode = null,
        $storeId = null
    ) {
        return Mage::helper('adyen')->getConfigData($code, $paymentMethodCode, $storeId);
    }

}
