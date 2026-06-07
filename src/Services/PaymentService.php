<?php

namespace Headcount\Services;

use Headcount\Models\Attendance;
use Headcount\Models\Event;
use Headcount\Models\User;
use Headcount\Core\SecurityLogger;

/**
 * Payment Service
 * Handles Stripe payment processing
 */
class PaymentService
{
    private $stripe;
    private $secretKey;
    private $webhookSecret;
    private $testMode;

    public function __construct($config)
    {
        $this->secretKey = $config['secret_key'] ?? '';
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        $this->testMode = $config['test_mode'] ?? true;

        if (!empty($this->secretKey)) {
            \Stripe\Stripe::setApiKey($this->secretKey);
        }
    }

    /**
     * Create Stripe checkout session
     */
    public function createCheckoutSession($eventId, $userId, $successUrl, $cancelUrl)
    {
        $eventModel = new Event();
        $userModel = new User();

        $event = $eventModel->find($eventId);
        if (!$event) {
            throw new \Exception('Event not found', 404);
        }

        if (!$event['is_paid'] || empty($event['price'])) {
            throw new \Exception('Event is not a paid event', 400);
        }

        $user = $userModel->find($userId);
        if (!$user) {
            throw new \Exception('User not found', 404);
        }

        try {
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $event['title'],
                            'description' => trim(strip_tags(html_entity_decode($event['description'] ?? '', ENT_QUOTES, 'UTF-8'))),
                        ],
                        'unit_amount' => (int)($event['price'] * 100), // Convert to cents
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $user['email'],
                'metadata' => [
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'organization_id' => $event['organization_id'],
                ],
            ]);

            return $session;
        } catch (\Exception $e) {
            error_log("Stripe checkout creation failed: " . $e->getMessage());
            throw new \Exception('Failed to create checkout session', 500);
        }
    }

    /**
     * Verify and process webhook
     */
    public function processWebhook($payload, $signature)
    {
        SecurityLogger::init();
        
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );
            
            // Log successful webhook verification
            SecurityLogger::log('stripe_webhook_verified', [
                'event_type' => $event->type,
                'event_id' => $event->id ?? null
            ]);
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            SecurityLogger::log('stripe_webhook_invalid_payload', [
                'error' => $e->getMessage()
            ]);
            error_log("Webhook payload invalid: " . $e->getMessage());
            throw new \Exception('Invalid webhook payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            SecurityLogger::log('stripe_webhook_invalid_signature', [
                'error' => $e->getMessage()
            ]);
            error_log("Webhook signature verification failed: " . $e->getMessage());
            throw new \Exception('Invalid webhook signature', 400);
        } catch (\Exception $e) {
            SecurityLogger::log('stripe_webhook_error', [
                'error' => $e->getMessage()
            ]);
            error_log("Webhook processing error: " . $e->getMessage());
            throw new \Exception('Webhook processing failed', 400);
        }

        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                return $this->handleCheckoutCompleted($event->data->object);
            
            case 'payment_intent.succeeded':
                return $this->handlePaymentSucceeded($event->data->object);
            
            case 'charge.refunded':
                return $this->handleRefund($event->data->object);
            
            default:
                SecurityLogger::log('stripe_webhook_ignored', [
                    'event_type' => $event->type,
                    'event_id' => $event->id ?? null
                ]);
                return ['status' => 'ignored', 'type' => $event->type];
        }
    }

    /**
     * Handle checkout session completed
     */
    private function handleCheckoutCompleted($session)
    {
        $eventId = $session->metadata->event_id ?? null;
        $userId = $session->metadata->user_id ?? null;

        if (!$eventId || !$userId) {
            SecurityLogger::logPaymentAnomaly(null, [
                'reason' => 'missing_metadata',
                'session_id' => $session->id ?? null
            ]);
            throw new \Exception('Missing metadata in checkout session', 400);
        }
        
        // Validate payment amount
        $amount = $session->amount_total ? $session->amount_total / 100 : 0;
        if ($amount <= 0 || $amount > 10000) {
            SecurityLogger::logPaymentAnomaly(null, [
                'reason' => 'invalid_amount',
                'amount' => $amount,
                'event_id' => $eventId,
                'user_id' => $userId
            ]);
        }

        $attendanceModel = new Attendance();
        $eventModel = new Event();

        $event = $eventModel->find($eventId);
        if (!$event) {
            throw new \Exception('Event not found', 404);
        }

        // Check if already checked in
        if ($attendanceModel->isCheckedIn($eventId, $userId)) {
            // Update payment status
            $attendanceModel->updatePaymentStatus(
                $eventId,
                $userId,
                'paid',
                $session->payment_intent ?? null,
                $session->amount_total ? $session->amount_total / 100 : null
            );
        } else {
            // Create attendance record with payment
            $attendanceModel->create([
                'event_id' => $eventId,
                'user_id' => $userId,
                'checked_in_by' => $userId, // Self check-in via payment
                'payment_status' => 'paid',
                'payment_intent_id' => $session->payment_intent ?? null,
                'amount_paid' => $session->amount_total ? $session->amount_total / 100 : null,
            ]);
        }

        // TODO: Send payment receipt email

        return ['status' => 'success', 'event_id' => $eventId, 'user_id' => $userId];
    }

    /**
     * Handle payment succeeded
     */
    private function handlePaymentSucceeded($paymentIntent)
    {
        // Additional processing if needed
        return ['status' => 'success', 'payment_intent' => $paymentIntent->id];
    }

    /**
     * Handle refund
     */
    private function handleRefund($charge)
    {
        // Find attendance record by payment intent
        // Update payment status to refunded
        // TODO: Implement refund handling
        
        return ['status' => 'success', 'refund_id' => $charge->id];
    }

    /**
     * Process refund
     */
    public function processRefund($paymentIntentId, $amount = null)
    {
        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $paymentIntentId,
                'amount' => $amount ? (int)($amount * 100) : null, // Convert to cents
            ]);

            // Update attendance record
            // TODO: Find and update attendance record

            return $refund;
        } catch (\Exception $e) {
            error_log("Refund failed: " . $e->getMessage());
            throw new \Exception('Failed to process refund', 500);
        }
    }
}
