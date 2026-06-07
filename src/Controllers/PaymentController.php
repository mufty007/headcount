<?php

namespace Headcount\Controllers;

use Headcount\Services\PaymentService;
use Headcount\Services\PortalPaymentService;
use Headcount\Core\ErrorHandler;

/**
 * Payment Controller
 * Handles payment-related API requests
 */
class PaymentController
{
    private $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Create checkout session
     */
    public function createCheckout($event, $userId, $successUrl, $cancelUrl)
    {
        $result = $this->paymentService->createCheckoutSession($event, $userId, $successUrl, $cancelUrl);
        
        if ($result['success']) {
            return [
                'success' => true,
                'data' => $result['data'],
                'message' => 'Checkout session created successfully'
            ];
        } else {
            return ErrorHandler::formatValidationErrors($result['errors']);
        }
    }

    /**
     * Handle webhook (delegates to portal pipeline: payments table + RSVPs).
     */
    public function handleWebhook($payload, $signature)
    {
        try {
            $portal = new PortalPaymentService();
            return $portal->handleWebhook($payload, $signature);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund
     */
    public function processRefund($paymentId, $amount = null)
    {
        $result = $this->paymentService->processRefund($paymentId, $amount);
        
        if ($result['success']) {
            return [
                'success' => true,
                'data' => $result['data'],
                'message' => 'Refund processed successfully'
            ];
        } else {
            return ErrorHandler::formatValidationErrors($result['errors']);
        }
    }
}
