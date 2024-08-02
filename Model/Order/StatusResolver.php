<?php

namespace PaySecure\Payments\Model\Order;

use Magento\Sales\Api\Data\OrderInterface;

class StatusResolver
{
    /**
     * @param OrderInterface $order
     * @param string $state
     * @return string
     */
    public function getOrderStatusByState(OrderInterface $order, string $state): string
    {
        $paymentMethodOrderStatus = $order->getPayment()->getMethodInstance()
            ->getConfigData('order_status');

        return array_key_exists($paymentMethodOrderStatus, $order->getConfig()->getStateStatuses($state))
            ? $paymentMethodOrderStatus
            : $order->getConfig()->getStateDefaultStatus($state);
    }
}
