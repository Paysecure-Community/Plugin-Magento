<?php

namespace PaySecure\Payments\Controller\Checkout;

use InvalidArgumentException;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use PaySecure\Payments\Helper\Checkout;
use PaySecure\Payments\Helper\PaySecureApiFactory;
use PaySecure\Payments\Model\OrderFinder;
use PaySecure\Payments\Model\Transaction;

/**
 * Process customers redirect from PaySecure and Webhook update requests
 */
class Redirect extends Action implements CsrfAwareActionInterface
{
    const STATUS_PAID = 'PAID';
    const STATUS_CREATED = 'CREATED';

    /**
     * @var Transaction
     */
    protected $transaction;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var OrderFinder
     */
    protected $orderFinder;

    /**
     * @var Checkout
     */
    private $checkoutHelper;

    /**
     * @var PaySecureApiFactory
     */
    private $paySecureApiFactory;

    /**
     * @param Context $context
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param Session $checkoutSession
     * @param OrderFinder $orderFinder
     * @param Transaction $transaction
     * @param Checkout $checkoutHelper
     * @param PaySecureApiFactory $paySecureApiFactory
     */
    public function __construct(
        Context              $context,
        LoggerInterface      $logger,
        ScopeConfigInterface $scopeConfig,
        Session              $checkoutSession,
        OrderFinder          $orderFinder,
        Transaction          $transaction,
        Checkout             $checkoutHelper,
        PaySecureApiFactory  $paySecureApiFactory
    )
    {
        parent::__construct($context);
        $this->context = $context;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        $this->orderFinder = $orderFinder;
        $this->transaction = $transaction;
        $this->checkoutHelper = $checkoutHelper;
        $this->paySecureApiFactory = $paySecureApiFactory;
    }

    /**
     * Handle the result from the Payment Gateway
     * Receives two types of requests, one is customers 'redirect back to merchant' where we load data from session
     *  the other one is Webhook request from gateway. There we look up order information in database.
     *
     * @throws LocalizedException
     */
    public function execute()
    {
        $isWebhook = !$this->getRequest()->isPost();
        $data = $isWebhook ? $this->loadPostData() : $this->getRequest()->getParams();
        $action = $this->getRequest()->getParam('action');

        switch ($action) {
            case 'success':
                $this->processSuccess(json_decode($data['body'], true));
                break;
            case 'failure':
                $this->processFailure(json_decode($data['body'], true));
                break;
            case 'cancel':
                $this->processCancellation();
                break;
            case 'back':
                $this->processBack();
                break;
            default:
                $this->getResponse()->setHttpResponseCode(Exception::HTTP_NOT_FOUND);
                $this->getResponse()->setBody('Unknown redirect action - ' . $action);
        }
    }

    /**
     * This is incoming webhook request so CSRF doesn't exactly apply
     *
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Process order success
     *
     * @param $data
     *
     * @return mixed
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws \Exception
     */
    private function processSuccess($data)
    {
        $isWebhook = !$this->getRequest()->isPost();
        $purchase = $this->findPaySecureOrder($data);
        $order = $this->findMagentoOrder($data);

        $status = $purchase['status'];

        if ($status === self::STATUS_PAID
            && $this->canInvoice($order)
            && !$this->isInvoiced($order)
        ) {
            try {
                if ($isWebhook) {
                    $order->addCommentToStatusHistory($this->statusText($purchase));
                }
                $this->transaction->invoice($order);
            } catch (LocalizedException $exception) {
                $this->logger->critical($exception->getMessage());
                $this->logger->critical($exception->getTraceAsString());
                throw $exception;
            }
        }

        if ($status === self::STATUS_CREATED || ($isWebhook && $this->isInvoiced($order))) {
            return $this->getResponse();
        }

        $this
            ->checkoutSession
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastQuoteId($order->getQuoteId())
            ->clearHelperData();

        if ($order->getId())
        {
            $this
                ->checkoutSession
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());
        }

        $this->_redirect('checkout/onepage/success');
    }

    /**
     * process order failure
     */
    private function processFailure($data)
    {
        /**
         * A custom handler for the "Back" -> "Forward" browser button case
         */
        $message = isset($data['purchaseId']) ? __('PaySecure order cannot be found in request') : __('Unrecognized error');
        $purchase = isset($data['purchaseId']) ? $this->findPaySecureOrder($data) : [];

        if (isset($purchase['transaction_data']['attempts'])) {
            $attempts = count($purchase['transaction_data']['attempts']);
            $message = $purchase['transaction_data']['attempts'][$attempts - 1]['error']['message'] ?? '';
        }

        $comment = __('PaySecure system failed to process payment - %1', $message);
        $this->checkoutHelper->cancelCurrentOrderAndRestoreQuote($comment);
        $this->context->getMessageManager()->addErrorMessage($comment);

        $this->_redirect('checkout/cart');
    }

    /**
     * process order cancellation
     */
    private function processCancellation()
    {
        $comment = __('Customer cancelled transaction');
        $this->checkoutHelper->cancelCurrentOrderAndRestoreQuote($comment);

        $this->_redirect('checkout/cart');
    }

    /**
     * process browser "Back" button action
     */
    private function processBack()
    {
        $comment = __('Customer clicked browser "back" button and cancelled transaction');
        $this->checkoutHelper->cancelCurrentOrderAndRestoreQuote($comment);

        $this->_redirect('checkout/cart');
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws AuthenticationException
     */
    protected function findPaySecureOrder(array $data): array
    {
        $paySecurePaymentId = $this->checkoutSession->getPaySecurePaymentId();

        if ($paySecurePaymentId) {
            $paySecureApi = $this->paySecureApiFactory->create();
            $purchase = $paySecureApi->purchases($paySecurePaymentId);
        } else {
            $purchase = $this->readRequestData($data);
        }

        return $purchase;
    }

    /**
     * @param array $data
     *
     * @return OrderInterface|Order
     * @throws InputException
     * @throws NoSuchEntityException
     */
    protected function findMagentoOrder(array $data): OrderInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            $purchase = $this->readRequestData($data);
            $order = $this->orderFinder->findOrderByPaySecureId($purchase['purchaseId']);
        }

        return $order;
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws AuthenticationException
     */
    protected function readRequestData(array $data): array
    {
        if (!isset($data['purchaseId'])) {
            $message = __('PaySecure order cannot be found in request');
            $this->logger->critical($message, $data);
            throw new InvalidArgumentException($message);
        }

        return $this->validatePurchaseId($data['purchaseId']);
    }

    /**
     * @param $purchaseId
     * @return array
     * @throws AuthenticationException
     */
    protected function validatePurchaseId($purchaseId): array
    {
        $paySecureApi = $this->paySecureApiFactory->create();
        $purchase = $paySecureApi->purchases($purchaseId);

        if (!isset($purchase['purchaseId'])) {
            $message = __('PaySecure order %1 cannot be found in request', $purchaseId);
            $this->logger->critical($message);
            throw new InvalidArgumentException($message);
        }

        return $purchase;
    }

    /**
     * @return array
     */
    protected function loadPostData()
    {
        return \json_decode($this->getRequest()->getContent(), true);
    }

    /**
     * @param Order $order
     *
     * @return bool
     */
    protected function canInvoice(Order $order): bool
    {
        $state = $order->getState();
        if ($state === Order::STATE_CANCELED || $state === Order::STATE_COMPLETE || $state === Order::STATE_CLOSED) {
            return false;
        }

        if ($order->getActionFlag(Order::ACTION_FLAG_INVOICE) === false) {
            return false;
        }

        return true;
    }

    /**
     * @param Order $order
     *
     * @return bool
     */
    protected function isInvoiced(Order $order): bool
    {
        return $order->getInvoiceCollection()->count() > 0;
    }

    /**
     * @param array $purchase
     *
     * @return Phrase
     */
    protected function statusText(array $purchase): Phrase
    {
        return __('Order was invoiced due to Webhook HTTP request with status %1', $purchase['status']);
    }
}
