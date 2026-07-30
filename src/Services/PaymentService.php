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

        // Receipt email is sent by PortalPaymentService on the live checkout path.
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
     * Handle refund — update payments table to match portal webhook path.
     */
    private function handleRefund($charge)
    {
        $paymentIntentId = $charge->payment_intent ?? null;
        if (!$paymentIntentId) {
            return ['status' => 'ignored', 'reason' => 'no_payment_intent'];
        }

        $db = \Headcount\Helpers\Database::getInstance();
        $payment = $db->queryOne(
            "SELECT * FROM payments WHERE stripe_payment_intent_id = :pi",
            ['pi' => $paymentIntentId]
        );

        if ($payment) {
            $refundAmount = $charge->amount_refunded ? $charge->amount_refunded / 100 : 0;
            $isFullRefund = $refundAmount >= (float) $payment['amount'];
            $db->update('payments', $payment['id'], [
                'status' => $isFullRefund ? 'refunded' : 'paid',
                'refund_amount' => $refundAmount,
                'refunded_at' => date('Y-m-d H:i:s'),
                'refund_reason' => 'Refunded via Stripe'
            ]);

            if ($isFullRefund && method_exists($db, 'hasColumn') && $db->hasColumn('attendance', 'payment_status')) {
                try {
                    $db->execute(
                        "UPDATE attendance SET payment_status = 'refunded'
                         WHERE event_id = :event_id AND user_id = :user_id",
                        [
                            'event_id' => (int) $payment['event_id'],
                            'user_id' => (int) $payment['user_id'],
                        ]
                    );
                } catch (\Throwable $e) {
                    error_log('PaymentService: attendance refund sync failed: ' . $e->getMessage());
                }
            }
        }

        return ['status' => 'success', 'refund_id' => $charge->id ?? null];
    }

    /**
     * Process refund for a payment row id (or Stripe payment intent id).
     *
     * @param int|string $paymentIdOrIntent Payment table id or payment_intent id
     * @param float|null $amount Optional partial refund amount in dollars
     * @return array{success:bool,data?:mixed,errors?:array,message?:string}
     */
    public function processRefund($paymentIdOrIntent, $amount = null)
    {
        try {
            $db = \Headcount\Helpers\Database::getInstance();
            $payment = null;

            if (is_numeric($paymentIdOrIntent)) {
                $payment = $db->queryOne(
                    "SELECT * FROM payments WHERE id = :id",
                    ['id' => (int) $paymentIdOrIntent]
                );
            }
            if (!$payment) {
                $payment = $db->queryOne(
                    "SELECT * FROM payments WHERE stripe_payment_intent_id = :pi",
                    ['pi' => (string) $paymentIdOrIntent]
                );
            }
            if (!$payment || empty($payment['stripe_payment_intent_id'])) {
                return [
                    'success' => false,
                    'errors' => [['field' => 'payment', 'message' => 'Payment not found or not refundable via Stripe']],
                ];
            }

            $refundParams = [
                'payment_intent' => $payment['stripe_payment_intent_id'],
            ];
            if ($amount !== null && (float) $amount > 0) {
                $refundParams['amount'] = (int) round((float) $amount * 100);
            }

            $refund = \Stripe\Refund::create($refundParams);

            $refundAmount = isset($refund->amount) ? ($refund->amount / 100) : ((float) ($amount ?? $payment['amount']));
            $totalRefunded = (float) ($payment['refund_amount'] ?? 0) + $refundAmount;
            $isFullRefund = $totalRefunded >= (float) $payment['amount'];

            $db->update('payments', $payment['id'], [
                'status' => $isFullRefund ? 'refunded' : 'paid',
                'refund_amount' => $totalRefunded,
                'refunded_at' => date('Y-m-d H:i:s'),
                'refund_reason' => 'Admin refund'
            ]);

            if ($isFullRefund && method_exists($db, 'hasColumn') && $db->hasColumn('attendance', 'payment_status')) {
                try {
                    $db->execute(
                        "UPDATE attendance SET payment_status = 'refunded'
                         WHERE event_id = :event_id AND user_id = :user_id",
                        [
                            'event_id' => (int) $payment['event_id'],
                            'user_id' => (int) $payment['user_id'],
                        ]
                    );
                } catch (\Throwable $e) {
                    error_log('PaymentService: attendance refund sync failed: ' . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'data' => [
                    'refund_id' => $refund->id ?? null,
                    'payment_id' => $payment['id'],
                    'refund_amount' => $refundAmount,
                ],
            ];
        } catch (\Exception $e) {
            error_log("Refund failed: " . $e->getMessage());
            return [
                'success' => false,
                'errors' => [['field' => 'refund', 'message' => 'Failed to process refund']],
                'message' => 'Failed to process refund',
            ];
        }
    }
}
