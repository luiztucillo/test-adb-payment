<?php
/**
 * Copyright © MercadoPago. All rights reserved.
 *
 * @author      Mercado Pago
 * @license     See LICENSE for license details.
 */

namespace MercadoPago\AdbPayment\Controller\Notification;

use Exception;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\ZendClient;
use MercadoPago\AdbPayment\Controller\MpIndex;

/**
 * Controler Notification Checkout Custom - Notification of receivers for Checkout Custom Methods.
 */
class CheckoutCustom extends MpIndex implements CsrfAwareActionInterface
{
    /**
     * Create Csrf Validation Exception.
     *
     * @param RequestInterface $request
     *
     * @return null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        if ($request) {
            return null;
        }
    }

    /**
     * Validate For Csrf.
     *
     * @param RequestInterface $request
     *
     * @return bool true
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        if ($request) {
            return true;
        }
    }

    /**
     * Execute.
     *
     * @return ResultInterface
     */
    public function execute()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->createResult(
                404,
                [
                    'error'   => 404,
                    'message' => __('You should not be here...'),
                ]
            );
        }

        $mpAmountRefund = null;
        $response = $this->getRequest()->getContent();
        $mercadopagoData = $this->json->unserialize($response);
        $mpTransactionId = $mercadopagoData['transaction_id'];
        $mpStatus = $mercadopagoData['status'];
        $notificationId = $mercadopagoData['notification_id'];
        $paymentsDetails = $mercadopagoData['payments_details'];
        $respData = null;
        
        if ($mpStatus === 'refunded') {
            try {
                /** @var ZendClient $client */
                $client = $this->httpClientFactory->create();
                $storeId = $mercadopagoData["payments_metadata"]["store_id"];
                $url = $this->config->getApiUrl();
                $clientConfigs = $this->config->getClientConfigs();
                $clientHeaders = $this->config->getClientHeaders($storeId);
                
                $client->setUri($url.'/v1/asgard/notification/'.$notificationId);
                $client->setConfig($clientConfigs);
                $client->setHeaders($clientHeaders);
                $client->setMethod(ZendClient::GET);
                $responseBody = $client->request()->getBody();
                $respData = $this->json->unserialize($responseBody);
                if (
                    !empty($respData["multiple_payment_transaction_id"])
                ) {
                    $mpTransactionId = $respData["multiple_payment_transaction_id"];
                }

            } catch (Exception $e) {
                    $this->logger->debug(['exception' => $e->getMessage()]);
            }
        }

        $this->logger->debug([
            'action'    => 'checkout_custom',
            'payload'   => $response
        ]);

        return $this->initProcess($mpTransactionId, $mpStatus, $notificationId, $paymentsDetails, $respData);
    }

    
    /**
     * Init Process.
     *
     * @param string $mpTransactionId
     * @param string $mpStatus
     * @param string $notificationId
     * @param $respData
     *
     * @return ResultInterface
     */
    public function initProcess(
        $mpTransactionId,
        $mpStatus,
        $notificationId,
        $paymentsDetails,
        $respData = null
    ) {
        $searchCriteria = $this->searchCriteria->addFilter('txn_id', $mpTransactionId)
            ->create();

        try {
            /** @var TransactionRepositoryInterface $transactions */
            $transactions = $this->transaction->getList($searchCriteria)->getItems();
        } catch (Exception $exc) {
            /** @var ResultInterface $result */
            return $this->createResult(
                500,
                [
                    'error'   => 500,
                    'message' => $exc->getMessage(),
                ]
            );
        }

        $origin = '';
        $results = [];
        $mpAmountRefund = null;
        $process = [];
        $resultData = [];
        $refundId = null;

        foreach ($transactions as $transaction) {
            if ($mpStatus == 'approved' && $transaction->getTxnType() == 'capture') {
                continue;
            }

            $order = $this->getOrderData($transaction->getOrderId());
            
            if ($mpStatus === 'refunded') {
                foreach ($paymentsDetails as $paymentsDetail) {
                    $refunds = $paymentsDetail['refunds'];

                    foreach ($respData['refunds_notifying'] as $refundNotifying) {
                        if (
                            isset($refunds[$refundNotifying['id']])
                            && $refundNotifying['notifying']
                        ) {
                            if (isset($refunds[$refundNotifying['id']]['metadata']['origem'])) {
                                $origin = $refunds[$refundNotifying['id']]['metadata']['origem'];
                            }
                            $mpAmountRefund = $refundNotifying['amount'];
                            $refundId = $refundNotifying['id'];

                            $process = $this->processNotification($mpStatus, $order, $notificationId, $mpAmountRefund, $origin, $refundId);
                            
                            array_push($resultData, $process['msg']);
                            
                            if ($process['code'] !== 200) {
                                /** @var ResultInterface $result */
                                return $this->createResult(
                                    $process['code'],
                                    $resultData
                                );
                            }
                        }
                    }
                }
            } else {
                $process = $this->processNotification($mpStatus, $order, $notificationId, $mpAmountRefund, $origin, $refundId);
                
                array_push($resultData, $process['msg']);
                            
                if ($process['code'] !== 200) {
                    /** @var ResultInterface $result */
                    return $this->createResult(
                        $process['code'],
                        $resultData
                    );
                }
            }

            if (sizeof($resultData) === 0) {
                /** @var ResultInterface $result */
                $result = $this->createResult(
                    422,
                    'Nothing to proccess'
                );
                return $result;
            }
        }

        /** @var ResultInterface $result */
        $result = $this->createResult(200, ['empty' => null]);

        return $result;
    }

    /**
     * Process Notification.
     *
     * @param string          $mpStatus
     * @param OrderRepository $order
     * @param string|null     $mpAmountRefund
     * @param string $notificationId
     *
     * @return array
     */
    public function processNotification(
        $mpStatus,
        $order,
        $notificationId,
        $mpAmountRefund = null,
        $origin = null,
        $refundId = null
    ) {
        $result = [];

        $isNotApplicable = $this->filterInvalidNotification($mpStatus, $order, $mpAmountRefund, $origin, $refundId);

        if ($isNotApplicable['isInvalid']) {
            if (
                strcmp($isNotApplicable['msg'], 'Refund notification for order refunded directly in Mercado Pago.') !== 0
                && strcmp($isNotApplicable['msg'], 'Refund notification for order already closed.') !== 0
                && strcmp($isNotApplicable['msg'], 'Notification response for online refund created in magento') !== 0
            ) {
                return $isNotApplicable;
            }
        }
        $order = $this->fetchStatus->fetch($order->getEntityId(), $notificationId);

        $result = [
            'code'  => 200,
            'msg'   => [
                'order'     => $order->getIncrementId(),
                'state'     => $order->getState(),
                'status'    => $order->getStatus(),
            ],
        ];

        return $result;
    }
}
