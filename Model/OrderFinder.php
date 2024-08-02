<?php

namespace PaySecure\Payments\Model;

use InvalidArgumentException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\OrderRepository;

/**
 * Find order by certain parameters
 */
class OrderFinder
{
    /**
     * @var OrderPaymentRepositoryInterface
     */
    private $paymentRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param SearchCriteriaBuilder           $searchCriteriaBuilder
     * @param OrderRepository                 $orderRepository
     */
    public function __construct(
        OrderPaymentRepositoryInterface $paymentRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepository $orderRepository
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param string $paySecureId
     *
     * @return OrderInterface
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function findOrderByPaySecureId(string $paySecureId): OrderInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder->addFilter('last_trans_id', $paySecureId)->create();
        $items = $this->paymentRepository->getList($searchCriteria)->getItems();

        if (!count($items)) {
            throw new InvalidArgumentException(__('Payment for %1 not found', $paySecureId));
        }

        $orderId = end($items)->getParentId();

        return $this->orderRepository->get($orderId);
    }
}
