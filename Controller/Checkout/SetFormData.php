<?php

namespace PaySecure\Payments\Controller\Checkout;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;

class SetFormData extends Action
{
    private $_checkoutSession;

    public function __construct(
        Context $context,
        Session $checkoutSession
    )
    {
        parent::__construct($context);
        $this->_checkoutSession = $checkoutSession;
    }

    public function execute()
    {
        $formDataJson = $this->getRequest()->getParam('json');
        $this->_checkoutSession
            ->setPaySecureFormDataJson($formDataJson);
        $this->getResponse()->setBody(json_encode([
            'status' => 'success',
        ]));
    }
}
