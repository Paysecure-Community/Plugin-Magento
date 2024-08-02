<?php

namespace PaySecure\Payments\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\NoSuchEntityException;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $_storeManager;

    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Context                                    $context
    )
    {
        $this->_storeManager = $storeManager;
        parent::__construct($context);
    }

    /**
     * Get an Instance of the Magento UrlBuilder
     * @return \Magento\Framework\UrlInterface
     */
    public function getUrlBuilder()
    {
        return $this->_urlBuilder;
    }

    /**
     * Get an Instance of the Magento Store Manager
     * @return \Magento\Store\Model\StoreManagerInterface
     */
    protected function getStoreManager()
    {
        return $this->_storeManager;
    }

    /**
     * Checks if the store is secure
     * @param $storeId
     * @return bool
     * @throws NoSuchEntityException
     */
    public function isStoreSecure($storeId = null)
    {
        $store = $this->getStoreManager()->getStore($storeId);
        return $store->isCurrentlySecure();
    }

    /**
     * Build URL for store
     *
     * @param string $moduleCode
     * @param string $controller
     * @param string|null $queryParams
     * @param bool|null $secure
     * @param int|null $storeId
     * @return string
     * @throws NoSuchEntityException
     */
    public function getUrl($moduleCode, $controller, $queryParams = null, $secure = null, $storeId = null): string
    {
        $moduleCode = 'paysecure_checkout';

        list($route, $module) = explode('_', $moduleCode);

        $path = sprintf("%s/%s/%s", $route, $module, $controller);

        $store = $this->getStoreManager()->getStore($storeId);
        $params = [
            "_store" => $store,
            "_secure" =>
                ($secure === null
                    ? $this->isStoreSecure($storeId)
                    : $secure
                )
        ];

        if (isset($queryParams) && is_array($queryParams)) {
            foreach ($queryParams as $queryKey => $queryValue) {
                $params[$queryKey] = $queryValue;
            }
        }

        return $this->getUrlBuilder()->getUrl(
            $path,
            $params
        );
    }

    /**
     * Build Return Url from Payment Gateway
     * @param string $moduleCode
     * @param string $returnAction
     * @return string
     * @throws NoSuchEntityException
     */
    public function getReturnUrl($moduleCode, $returnAction)
    {
        return $this->getUrl(
            $moduleCode,
            "redirect",
            [
                "action" => $returnAction
            ]
        );
    }
}
