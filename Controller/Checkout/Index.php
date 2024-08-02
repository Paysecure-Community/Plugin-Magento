<?php

namespace PaySecure\Payments\Controller\Checkout;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;

class Index extends Action
{
    private $_checkoutSession;
    private $_orderFactory;

    public function __construct(
        Context      $context,
        Session      $checkoutSession,
        OrderFactory $orderFactory
    )
    {
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
    }

    protected function getCheckoutSession()
    {
        return $this->_checkoutSession;
    }

    protected function getOrderFactory()
    {
        return $this->_orderFactory;
    }

    /**
     * Get an Instance of the current Checkout Order Object
     * @return Order
     */
    protected function getOrder()
    {
        $orderId = $this->getCheckoutSession()->getLastRealOrderId();

        if (!isset($orderId)) {
            return null;
        }

        $order = $this->getOrderFactory()->create()->loadByIncrementId(
            $orderId
        );

        if (!$order->getId()) {
            return null;
        }

        return $order;
    }

    protected function redirectToCheckoutFragmentPayment()
    {
        $this->_redirect('checkout', ['_fragment' => 'payment']);
    }

    public function execute()
    {
        $order = $this->getOrder();

        if (!$order) {
            throw new NotFoundException(__('No active order in session.'));
        }

        $redirectUrl = $this->getCheckoutSession()->getPaySecureCheckoutRedirectUrl();

        if (!$redirectUrl) {
            throw new LocalizedException(__('Failed to pass the payment gateway url.'));
        }

        $this->getResponse()->setRedirect($redirectUrl);
    }
}
