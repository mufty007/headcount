<?php

namespace Headcount\Integrations;

use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Refund;
use Stripe\Webhook;
use Headcount\Core\Logger;

/**
 * Stripe Service
 * Handles Stripe API integration
 */
class StripeService
{
    private $secretKey;
    private $webhookSecret;

    public function __construct($secretKey, $webhookSecret = null)
    {
        $this->secretKey = $secretKey;
        $this->webhookSecret = $webhookSecret;
        Stripe::setApiKey($secretKey);
    }

    /**
     * Create checkout session from line items
     *
     * @param array $lineItems Array of ['name' => string, 'description' => string, 'unit_amount' => int (cents), 'quantity' => int]
     * @param array $metadata Metadata (event_id, user_id, organization_id, event_title, event_date)
     * @param string $successUrl
     * @param string $cancelUrl
     * @param string|null $customerEmail
     * @param array $paymentIntentData Optional keys for Checkout Session payment_intent_data (e.g. description, statement_descriptor)
     * @return \Stripe\Checkout\Session
     */
    public function createCheckoutSession($lineItems, $metadata, $successUrl, $cancelUrl, $customerEmail = null, array $paymentIntentData = [])
    {
        try {
            $stripeLineItems = [];
            foreach ($lineItems as $item) {
                $stripeLineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $item['name'],
                            'description' => $item['description'] ?? '',
                        ],
                        'unit_amount' => (int)($item['unit_amount']),
                    ],
                    'quantity' => (int)($item['quantity']),
                ];
            }
            $sessionMeta = [
                'event_id' => (string) ($metadata['event_id'] ?? ''),
                'user_id' => (string) ($metadata['user_id'] ?? ''),
                'organization_id' => (string) ($metadata['organization_id'] ?? ''),
                'event_title' => (string) ($metadata['event_title'] ?? ''),
                'event_date' => (string) ($metadata['event_date'] ?? ''),
            ];
            $sessionParams = [
                'payment_method_types' => ['card'],
                'line_items' => $stripeLineItems,
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $sessionMeta,
                'customer_email' => $customerEmail,
            ];
            $piData = array_filter($paymentIntentData, function ($v) {
                return $v !== null && $v !== '';
            });
            $sessionParams['payment_intent_data'] = array_merge(
                ['metadata' => $sessionMeta],
                $piData
            );
            $session = Session::create($sessionParams);

            return $session;
        } catch (\Exception $e) {
            Logger::error("Stripe checkout session creation failed: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Checkout Session with arbitrary string metadata (e.g. programs: program_registration_id).
     * Values are cast to strings per Stripe requirements.
     *
     * @param array $lineItems Same shape as createCheckoutSession
     * @param array $metadata Flat key => scalar
     */
    public function createCheckoutSessionWithCustomMetadata(
        $lineItems,
        array $metadata,
        $successUrl,
        $cancelUrl,
        $customerEmail = null,
        array $paymentIntentData = []
    ) {
        try {
            $stripeLineItems = [];
            foreach ($lineItems as $item) {
                $stripeLineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $item['name'],
                            'description' => $item['description'] ?? '',
                        ],
                        'unit_amount' => (int) ($item['unit_amount']),
                    ],
                    'quantity' => (int) ($item['quantity']),
                ];
            }
            $meta = [];
            foreach ($metadata as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $meta[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
            $sessionParams = [
                'payment_method_types' => ['card'],
                'line_items' => $stripeLineItems,
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $meta,
                'customer_email' => $customerEmail,
            ];
            $piData = array_filter($paymentIntentData, function ($v) {
                return $v !== null && $v !== '';
            });
            $sessionParams['payment_intent_data'] = array_merge(
                ['metadata' => $meta],
                $piData
            );
            return Session::create($sessionParams);
        } catch (\Exception $e) {
            Logger::error("Stripe program checkout failed: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Subscription Checkout for recurring program fees.
     *
     * @param string $productName
     * @param int $unitAmountCents
     * @param string $interval Stripe interval: day|week|month|year
     * @param int $intervalCount e.g. 2 for bi-weekly with interval week
     * @param array $metadata
     */
    public function createSubscriptionCheckoutSession(
        $productName,
        $unitAmountCents,
        $interval,
        $intervalCount,
        array $metadata,
        $successUrl,
        $cancelUrl,
        $customerEmail = null
    ) {
        try {
            $meta = [];
            foreach ($metadata as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $meta[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
            $recurring = ['interval' => $interval];
            if ($intervalCount > 1) {
                $recurring['interval_count'] = (int) $intervalCount;
            }
            return Session::create([
                'payment_method_types' => ['card'],
                'mode' => 'subscription',
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => ['name' => $productName],
                        'recurring' => $recurring,
                        'unit_amount' => (int) $unitAmountCents,
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $meta,
                'customer_email' => $customerEmail,
            ]);
        } catch (\Exception $e) {
            Logger::error("Stripe subscription checkout failed: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhook($payload, $signature)
    {
        if (!$this->webhookSecret) {
            throw new \Exception("Webhook secret not configured");
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );
            return $event;
        } catch (\Exception $e) {
            Logger::error("Stripe webhook verification failed: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Process refund
     */
    public function refundPayment($paymentIntentId, $amount = null)
    {
        try {
            $refundData = [
                'payment_intent' => $paymentIntentId,
            ];

            if ($amount !== null) {
                $refundData['amount'] = (int)($amount * 100); // Convert to cents
            }

            $refund = Refund::create($refundData);
            return $refund;
        } catch (\Exception $e) {
            Logger::error("Stripe refund failed: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Get payment intent details
     */
    public function getPaymentIntent($paymentIntentId)
    {
        try {
            return \Stripe\PaymentIntent::retrieve($paymentIntentId);
        } catch (\Exception $e) {
            Logger::error("Failed to retrieve payment intent: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Retrieve a Checkout Session (e.g. reconcile after redirect if webhooks were delayed).
     *
     * @param string $sessionId cs_...
     * @return \Stripe\Checkout\Session
     */
    public function retrieveCheckoutSession(string $sessionId, array $options = [])
    {
        $params = array_merge(['expand' => ['payment_intent']], $options);
        return Session::retrieve($sessionId, $params);
    }

    /**
     * Create transfer to connected account
     */
    public function createTransfer($amount, $destinationAccount, $metadata = [])
    {
        try {
            $transfer = \Stripe\Transfer::create([
                'amount' => (int)($amount * 100), // Convert to cents
                'currency' => 'usd',
                'destination' => $destinationAccount,
                'metadata' => $metadata
            ]);
            return $transfer;
        } catch (\Exception $e) {
            Logger::error("Stripe transfer failed: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Create payout
     */
    public function createPayout($amount, $metadata = [])
    {
        try {
            $payout = \Stripe\Payout::create([
                'amount' => (int)($amount * 100), // Convert to cents
                'currency' => 'usd',
                'metadata' => $metadata
            ]);
            return $payout;
        } catch (\Exception $e) {
            Logger::error("Stripe payout failed: " . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Capture an authorized PaymentIntent (manual capture / facility holds).
     */
    public function capturePaymentIntent(string $paymentIntentId, $amountCents = null)
    {
        try {
            $params = [];
            if ($amountCents !== null) {
                $params['amount_to_capture'] = (int) $amountCents;
            }
            return \Stripe\PaymentIntent::capture($paymentIntentId, $params);
        } catch (\Exception $e) {
            Logger::error('Stripe capture failed: ' . $e->getMessage(), $e);
            throw $e;
        }
    }

    /**
     * Cancel an uncaptured PaymentIntent (releases card hold).
     */
    public function cancelPaymentIntent(string $paymentIntentId, $reason = null)
    {
        try {
            $params = [];
            if ($reason) {
                $params['cancellation_reason'] = $reason;
            }
            return \Stripe\PaymentIntent::cancel($paymentIntentId, $params);
        } catch (\Exception $e) {
            Logger::error('Stripe cancel PI failed: ' . $e->getMessage(), $e);
            throw $e;
        }
    }
}
