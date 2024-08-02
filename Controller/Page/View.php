<?php

namespace PaySecure\Payments\Controller\Page;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

class View extends Action
{
    protected $resultJsonFactory;

    public function __construct(
        Context     $context,
        JsonFactory $resultJsonFactory
    )
    {
        $this->resultJsonFactory = $resultJsonFactory;
        parent::__construct($context);
    }

    /** @return ResultInterface */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $data = ['message' => 'Hello world!'];

        return $result->setData($data);
    }
}
