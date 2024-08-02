<?php

namespace PaySecure\Payments\Model\Method;

use Magento\Catalog\Model\Product\Type;
use Magento\Checkout\Model\Session;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\ScopeInterface;
use PaySecure\Payments\Helper\PaySecureApiFactory;
use Psr\Log\LoggerInterface;

class Checkout extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CODE = 'paysecure';
    const PAYSECURE_MODULE_VERSION = 'v1.2.0';

    /**
     * Checkout Method Code
     */
    protected $_code = self::CODE;

    protected $_canOrder = true;
    protected $_isGateway = false;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = true;
    protected $_canCancelInvoice = true;
    protected $_canVoid = true;
    protected $_canRefundInvoicePartial = false;
    protected $_canFetchTransactionInfo = true;
    protected $_canAuthorize = true;
    protected $_isInitializeNeeded = false;
    protected Session $_checkoutSession;
    protected \PaySecure\Payments\Helper\Data $_moduleHelper;

    private $request;

    /**
     * @var ResolverInterface
     */
    private $localeResolver;
    /**
     * @var PaySecureApiFactory
     */
    private $paySecureApiFactory;

    /**
     * Get Instance of the Magento Code Logger
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        return $this->_logger;
    }

    /**
     * Get a string of concatenated product names up to 256 chars
     *
     * @param $order
     * @return string
     */
    private function getProductNames($order)
    {
        $ignoredTypes = [Configurable::TYPE_CODE, Type::TYPE_BUNDLE];
        $names = [];
        foreach ($order->getAllItems() as $item) {
            if (\in_array($item->getProductType(), $ignoredTypes)) {
                continue;
            }
            $names[] = $item->getName();
        }
        $nameString = implode(';', $names);
        return substr($nameString, 0, 10000);
    }

    public function __construct(
        Context                                                 $context,
        Registry                                                $registry,
        ExtensionAttributesFactory                              $extensionFactory,
        AttributeValueFactory                                   $customAttributeFactory,
        Data                                                    $paymentData,
        ScopeConfigInterface                                    $scopeConfig,
        Logger                                                  $logger,
        Session                                                 $checkoutSession,
        \PaySecure\Payments\Helper\Data                         $moduleHelper,
        ResolverInterface                                       $localeResolver,
        PaySecureApiFactory                                     $paySecureApiFactory,
        RequestInterface                                        $request,
        AbstractResource                                        $resource = null,
        AbstractDb                                              $resourceCollection = null,
        array                                                   $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->request = $request;
        $this->_checkoutSession = $checkoutSession;
        $this->_moduleHelper = $moduleHelper;
        $this->localeResolver = $localeResolver;
        $this->paySecureApiFactory = $paySecureApiFactory;
    }

    private function getModuleHelper()
    {
        return $this->_moduleHelper;
    }

    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    private function makePaymentParams(InfoInterface $payment, $amount): array
    {
        $order = $payment->getOrder();
        $isShippingSet = (bool)$order->getShippingAddress();

        $orderId = ltrim(
            $order->getIncrementId(),
            '0'
        );

        // ignoring Yen, Rubles, Dinars, etc - can't find API to get decimal
        // places in Magento, and it was done same way in other modules anyway
        $amountInCents = $amount * 100;

        return [
            'success_callback' => $this->getModuleHelper()->getReturnUrl(
                $this->getCode(),
                'success'
            ),
            'success_redirect' => $this->getModuleHelper()->getReturnUrl(
                $this->getCode(),
                'success'
            ),
            'failure_redirect' => $this->getModuleHelper()->getReturnUrl(
                $this->getCode(),
                'failure'
            ),
            'cancel_redirect' => $this->getModuleHelper()->getReturnUrl(
                $this->getCode(),
                'cancel'
            ),
            'creator_agent' => 'PaySecure Payments for Magento: ' . self::PAYSECURE_MODULE_VERSION,
            'platform' => 'Magento',
            'reference' => (string) $orderId,
            'purchase' => [
                "currency" => $order->getBaseCurrencyCode(),
                "language" => strstr($this->localeResolver->getLocale(), '_', true),
                "products" => [
                    [
                        'name' => 'Payment',
                        'price' => round($amountInCents / 100, 2),
                        'quantity' => 1,
                    ]
                ],
                "notes" => $this->getProductNames($order)
            ],
            'brand_id' => $this->_scopeConfig->getValue(
                'payment/paysecure/brand_id',
                ScopeInterface::SCOPE_STORE
            ),
            'client' => [
                'email' => $this->_checkoutSession->getQuote()->getCustomerEmail(),
                'phone' => $isShippingSet ?
                    $order->getShippingAddress()->getTelephone() : $order->getBillingAddress()->getTelephone(),
                'full_name' => $order->getBillingAddress()->getFirstName() . ' '
                    . $order->getBillingAddress()->getLastName(),

                'street_address' => implode(' ', $order->getBillingAddress()->getStreet() ?? []),
                'country' => $order->getBillingAddress()->getCountryId(),
                'city' => $order->getBillingAddress()->getCity(),
                'zip_code' => $order->getBillingAddress()->getPostcode(),
                'stateCode' => $order->getBillingAddress()->getRegion(),

                'shipping_street_address' => $isShippingSet ? implode(
                    ' ',
                    $order->getShippingAddress()->getStreet()
                ) : '',
                'shipping_country' => $isShippingSet ? $order->getShippingAddress()->getCountryId() : '',
                'shipping_city' => $isShippingSet ? $order->getShippingAddress()->getCity() : '',
                'shipping_zip_code' => $isShippingSet ? $order->getShippingAddress()->getPostcode() : '',
                'shipping_stateCode' => $isShippingSet ? $order->getShippingAddress()->getRegion() : ''
            ],
        ];
    }

    /**
     * Order Payment
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \RuntimeException|LocalizedException
     */
    public function order(InfoInterface $payment, $amount)
    {
        $paySecureApi = $this->paySecureApiFactory->create();
        $paymentParams = $this->makePaymentParams($payment, $amount);
        $paymentResponse = $paySecureApi->createPayment($paymentParams);

        $checkout_url = $paymentResponse['checkout_url'] ?? null;
        $id = $paymentResponse['purchaseId'] ?? null;

        if (!$id) {
            $this->logger->debug($paymentResponse);
            $msg = 'Could not init payment in service - ' . json_encode($paymentResponse);
            throw new \RuntimeException($msg);
        }

        $formDataJson = $this->_checkoutSession->getPaySecureFormDataJson() ?: 'null';

        $formData = json_decode($formDataJson, true);
        $chosenMethod = $formData['paysecure_payment_method'] ?? null;

        if ($chosenMethod) {
            $checkout_url .= '?preferred=' . $chosenMethod;
        }

        $this->_checkoutSession->setPaySecureCheckoutRedirectUrl($checkout_url);
        $this->_checkoutSession->setPaySecurePaymentId($id);

        $payment->setTransactionId($paymentResponse['purchaseId']);

        $payment->setAdditionalInformation([
            \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => $paymentResponse['transaction_data']
        ]);

        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);

        return $this;
    }

    /**
     * Refunds specified amount
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();

        $creditmemo = $this->request->getPost('creditmemo');

        $reason = (!empty($creditmemo['comment_text'])) ? $creditmemo['comment_text'] : 'Refunded by site admin';

        $refundId = $payment->getTransactionId();

        $this->_logger->info('PaySecure Refund - Transaction ID:' . $refundId);

        $paymentId = substr($refundId, 0, -7);

        try
        {
            $data = [
                'amount'    =>  $amount,
                'receipt'   =>  $order->getIncrementId(),
                'notes'     =>  [
                    'reason'                =>  $reason,
                    'order_id'              =>  $order->getIncrementId(),
                    'refund_from_website'   =>  true,
                    'source'                =>  'Magento',
                ]
            ];

            $paySecureApi = $this->paySecureApiFactory->create();

            if ($paySecureApi->wasPaymentSuccessful($order->getPaySecureId())) {

                $refund = $paySecureApi->refundPayment($order->getPaySecureId(), $data);

                $payment->setAmountPaid($amount)
                    ->setLastTransId($refund->id)
                    ->setTransactionId($refund->id)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);
            }

        }
        catch (\Exception $e)
        {
            $this->_logger->critical($e);

            throw new LocalizedException(__('PaySecure Refund Error: %1.', $e->getMessage()));
        }

        return $this;
    }

    private function getPlainTransactionInfo($transactionDetails) {

        $transactionInfo = [
//            'is_test' => 'N/A',
//            'status' => 'N/A',
//            'platform' => 'N/A',
//            'amount' => 'N/A',
//            'refundable_amount' => 'N/A',
//            'payment_type' => 'N/A',
//            'payment_method' => 'N/A',
//            'card_issuer' => 'N/A',
//            'card_issuer_country' => 'N/A',
//            'card_brand' => 'N/A',
//            'masked_pan' => 'N/A',
//            'expiry_month' => 'N/A',
//            'expiry_year' => 'N/A',
//            'cardholder_name' => 'N/A',
            'Is Test' => 'N/A',
            'Status' => 'N/A',
            'Platform' => 'N/A',
            "Country"  => 'N/A',
            'Amount' => 'N/A',
            'Refundable Amount' => 'N/A',
            'Payment Type' => 'N/A',
            'Payment Method' => 'N/A',
            'Card Issuer' => 'N/A',
            'Card Issuer Country' => 'N/A',
            'Card Brand' => 'N/A',
            'Masked Card Number' => 'N/A',
            'Expiry Month' => 'N/A',
            'Expiry Year' => 'N/A',
            'Cardholder Name' => 'N/A',
        ];

        foreach ($transactionDetails as $key => $value) {

            if (is_array($value) && !in_array($key, ['status_history'])) {
                $transactionInfo = [...$transactionInfo, ...$this->getPlainTransactionInfo($value)];
            } else if (in_array($key, ['country','is_test', 'refundable_amount', 'platform', 'status', 'payment_type', 'payment_method','card_issuer', 'card_issuer_country', 'card_brand', 'amount', 'cardholder_name','expiry_year','expiry_month','masked_pan'])) {
                $key = $key == 'masked_pan' ? 'masked_card_number' : $key;

                $newKey = ucwords(str_replace('_', ' ', $key));
                $transactionInfo[$newKey] = $value;
            }
        }

        return  $transactionInfo;
    }

    public function fetchTransactionInfo(InfoInterface $payment, $transactionId): array
    {
        $paySecureApi = $this->paySecureApiFactory->create();

        $transactionDetails = $paySecureApi->purchases($transactionId);

        $transactionInfo = $this->getPlainTransactionInfo($transactionDetails);

        $this->logger->debug($transactionDetails);

        return $transactionInfo;
    }

    /**
     * Determines method's availability based on config data and quote amount
     *
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null): bool
    {
        return parent::isAvailable($quote)
            && $this->_scopeConfig->getValue('payment/paysecure/active', ScopeInterface::SCOPE_STORE);
    }

    /**
     * Checks base currency against the allowed currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode): bool
    {
        return true;
    }
}
