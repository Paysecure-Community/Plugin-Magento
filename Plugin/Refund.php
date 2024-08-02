<?php

declare(strict_types=1);

namespace PaySecure\Payments\Plugin;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Service\CreditmemoService;
use Psr\Log\LoggerInterface;
use PaySecure\Payments\Helper\PaySecureApiFactory;
use PaySecure\Payments\Model\Method\Checkout;
use function Safe\ingres_result_seek;

/**
 * Process online refunds
 */
class Refund
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var PaySecureApiFactory
     */
    private $paySecureApiFactory;

    /**
     * @var TransactionRepositoryInterface
     */
    private $transactionRepositoryInterface;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * Refund constructor.
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param PaySecureApiFactory $paySecureApiFactory
     * @param TransactionRepositoryInterface $transactionRepositoryInterface
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        PaySecureApiFactory $paySecureApiFactory,
        TransactionRepositoryInterface $transactionRepositoryInterface,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->paySecureApiFactory = $paySecureApiFactory;
        $this->transactionRepositoryInterface = $transactionRepositoryInterface;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * @param CreditmemoService $subject
     * @param \Closure $proceed
     * @param CreditmemoInterface $creditmemo
     * @param $offlineRequested
     * @return CreditmemoInterface
     * @throws \Exception
     * @throws \RuntimeException
     */
    public function aroundRefund(
        CreditmemoService $subject,
        \Closure $proceed,
        CreditmemoInterface $creditmemo,
        $offlineRequested
    ) {
        $order = $creditmemo->getOrder();
        $payment = $order->getPayment();
        $methodCode = $payment->getMethod();

        if (!$offlineRequested && $methodCode == Checkout::CODE) {
            $transactionId = $this->getTransactionId($payment);
            $amount = $creditmemo->getGrandTotal();

            $params = [
                'amount' => round($amount * 100),
            ];

            $paySecureApi = $this->paySecureApiFactory->create();
            $refundResponse = $paySecureApi->refundPayment($transactionId, $params);
            $paySecureApi->logInfo(sprintf(
                "Refund response: %s",
                var_export($refundResponse, true)
            ));

            $refundResponseDetails = $refundResponse['__all__'][0] ?? null;
            if (isset($refundResponseDetails['code']) && $refundResponseDetails['code'] === 'not_found') {
                throw new LocalizedException(
                    __($refundResponseDetails['message'])
                );
            }
        }

        return $proceed($creditmemo, $offlineRequested);
    }

    /**
     * Get the transaction id. Magento 2.0, 2.1, 2.2 truncates the transaction id stored in sales_order_payment table.
     *
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @return string
     * @throws \RuntimeException
     */
    private function getTransactionId($payment)
    {
        $this->searchCriteriaBuilder->addFilter('payment_id', $payment->getEntityId());
        $this->searchCriteriaBuilder->addFilter('method', Checkout::CODE);
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $transactionItems = $this->transactionRepositoryInterface->getList($searchCriteria)->getItems();

        if (!is_array($transactionItems) || count($transactionItems) < 1) {
            throw new \RuntimeException('Could not retrieve the full Savannah transaction ID during order refund.');
        }
        return array_values(array_reverse($transactionItems))[0]->getTxnId();
    }
}
