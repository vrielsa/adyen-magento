<?php

class Adyen_Payment_Model_Order_Pos
{
    /** @var Mage_Checkout_Helper_Data */
    private $checkoutHelper;

    public function __construct()
    {
        $this->checkoutHelper = Mage::helper('checkout');
    }

    /**
     * Create order from quote and create customer when needed
     *
     * @param Mage_Sales_Model_Quote $quote
     *
     * @return $this
     * @throws \Exception
     */
    public function saveOrder($quote)
    {
        $customerSession = Mage::getSingleton('customer/session');
        $customer = null;

        if (!$this->checkoutHelper->isAllowedGuestCheckout($quote) && !$customerSession->isLoggedIn()) {
            $customer = $this->prepareNewCustomerQuote($quote);
        }

        /** @var Mage_Sales_Model_Service_Quote $service */
        $service = Mage::getModel('sales/service_quote', $quote);
        $service->submitAll();
        $order = $service->getOrder();
        $order->save();

        if ($customer) {
            $this->sendCustomerEmail($customer, $quote, $customerSession);
        }

        $this->updateCheckoutSession($order);

        return $this;
    }

    /**
     * Either send out the confirmation email or send the new account email
     *
     * @param Mage_Customer_Model_Customer $customer
     * @param Mage_Sales_Model_Quote $quote
     * @param Mage_Customer_Model_Session $customerSession
     *
     * @return $this
     * @throws Mage_Core_Exception
     */
    private function sendCustomerEmail($customer, $quote, $customerSession)
    {
        if ($customer->isConfirmationRequired()) {
            $customer->sendNewAccountEmail('confirmation', '', $quote->getStoreId());
            $url = Mage::helper('customer')->getEmailConfirmationUrl($customer->getEmail());
            $customerSession->addSuccess(
                Mage::helper('customer')
                    ->__('Account confirmation is required. Please, check your e-mail for confirmation link. To resend confirmation email please <a href="%s">click here</a>.', $url)
            );

            return $this;
        }

        $customer->sendNewAccountEmail('registered', '', $quote->getStoreId());
        $customerSession->loginById($customer->getId());

        return $this;
    }

    /**
     * Copy the customer data from the quote to the customer object
     *
     * @param Mage_Sales_Model_Quote $quote
     *
     * @return Mage_Customer_Model_Customer
     */
    private function prepareNewCustomerQuote($quote)
    {
        $customer = $this->getCustomerFromQuote($quote);
        Mage::helper('core')
            ->copyFieldset('checkout_onepage_quote', 'to_customer', $quote, $customer);

        $customer->setPassword(
            $customer->decryptPassword($quote->getPasswordHash())
        );

        $passwordCreatedTime = Mage::getSingleton('checkout/session')
                ->getData('_session_validator_data')['session_expire_timestamp'] - Mage::getSingleton('core/cookie')->getLifetime();

        $customer->setPasswordCreatedAt($passwordCreatedTime);
        $quote->setCustomer($customer)
            ->setCustomerId(true);
        $quote->setPasswordHash('');

        return $customer;
    }

    /**
     * Fetch customer object from the quote and set address data
     *
     * @param Mage_Sales_Model_Quote $quote
     *
     * @return Mage_Customer_Model_Customer
     */
    private function getCustomerFromQuote($quote)
    {
        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->isVirtual() ?: $quote->getShippingAddress();

        $customer = $quote->getCustomer();
        $customerBillingAddress = $billingAddress->exportCustomerAddress();
        $customer->addAddress($customerBillingAddress);
        $billingAddress->setCustomerAddress($customerBillingAddress);

        if ($shippingAddress && !$shippingAddress->getSameAsBilling()) {
            $customerShippingAddress = $shippingAddress->exportCustomerAddress();
            $customer->addAddress($customerBillingAddress);
            $shippingAddress->setCustomerAddress($customerShippingAddress);
            $customerShippingAddress->setIsDefaultShipping(true);

            return $customer;
        }

        $customerBillingAddress->setIsDefaultShipping(true);

        return $customer;
    }

    /**
     * Adds order information to the checkout session
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return $this
     * @throws \Exception
     */
    private function updateCheckoutSession($order)
    {
        /** @var Mage_Checkout_Model_Session $session */
        $session = Mage::getSingleton('checkout/session');
        $session->setLastOrderId($order->getId());
        $session->setLastRealOrderId($order->getIncrementId());
        $session->setLastSuccessQuoteId($order->getQuoteId());
        $session->setLastQuoteId($order->getQuoteId());
        $session->unsAdyenRealOrderId();
        $session->setQuoteId($session->getAdyenQuoteId(true));
        $session->getQuote()->setIsActive(false)->save();

        return $this;
    }
}
