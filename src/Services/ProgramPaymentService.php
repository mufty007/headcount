<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;
use Headcount\Integrations\StripeService;

/**
 * Stripe checkout and webhooks for program registrations.
 */
class ProgramPaymentService
{
    private const DESC = 'Headcount Program';
    private const STMT = 'Headcount Program';

    private $db;
    private $config;
    private $programService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->programService = new ProgramService();
        $configFile = __DIR__ . '/../../config/config.php';
        $this->config = file_exists($configFile) ? require $configFile : [];
    }

    private function getStripeServiceForOrganization($organizationId)
    {
        try {
            if (empty($organizationId) || !$this->config) {
                return null;
            }
            $org = null;
            try {
                $org = $this->db->queryOne(
                    "SELECT stripe_secret_key_encrypted, stripe_webhook_secret_encrypted FROM organizations WHERE id = :id",
                    ['id' => $organizationId]
                );
            } catch (\Throwable $e) {
                $org = $this->db->queryOne(
                    "SELECT stripe_secret_key_encrypted FROM organizations WHERE id = :id",
                    ['id' => $organizationId]
                );
            }
            if (!$org || empty($org['stripe_secret_key_encrypted'])) {
                try {
                    $legacy = $this->db->queryOne(
                        "SELECT stripe_secret_key FROM organizations WHERE id = :id",
                        ['id' => $organizationId]
                    );
                    if (!empty($legacy['stripe_secret_key'])) {
                        $decoded = base64_decode($legacy['stripe_secret_key'], true);
                        if ($decoded !== false && (strpos($decoded, 'sk_live_') === 0 || strpos($decoded, 'sk_test_') === 0)) {
                            return new StripeService($decoded, null);
                        }
                    }
                } catch (\Throwable $e) {
                }
                return null;
            }
            $key = $this->config['security']['encryption_key'] ?? null;
            if (empty($key)) {
                $dbName = $this->config['database']['name'] ?? 'headcount_dev';
                $key = hash('sha256', ($this->config['app']['name'] ?? '') . $dbName);
            }
            if (strlen($key) < 32) {
                $key = hash('sha256', $key . 'headcount_salt');
            }
            $encryptionKey = substr($key, 0, 32);
            $secretKey = Security::decrypt($org['stripe_secret_key_encrypted'], $encryptionKey);
            $webhookSecret = null;
            if (!empty($org['stripe_webhook_secret_encrypted'])) {
                try {
                    $webhookSecret = Security::decrypt($org['stripe_webhook_secret_encrypted'], $encryptionKey);
                } catch (\Throwable $e) {
                }
            }
            return new StripeService($secretKey, $webhookSecret);
        } catch (\Throwable $e) {
            error_log('ProgramPaymentService: getStripeServiceForOrganization: ' . $e->getMessage());
            return null;
        }
    }

    private function getBaseUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = dirname($scriptName);
        $basePath = str_replace('/public', '', $basePath);
        $basePath = rtrim($basePath, '/');

        return $protocol . '://' . $host . $basePath;
    }

    /**
     * @return array{program_title:string,line_name:string,line_description:string}
     */
    private function buildCheckoutPresentation(array $program, array $weekIds, array $allWeeks): array
    {
        $title = trim(preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            strip_tags(Utilities::decodeHtmlEntities($program['title'] ?? ''))
        ));
        if ($title === '') {
            $title = 'Program #' . (int) ($program['id'] ?? 0);
        }
        if (mb_strlen($title) > 100) {
            $title = mb_substr($title, 0, 97) . '...';
        }

        $lineName = $title;
        $lineDescription = $title;

        if (($program['registration_mode'] ?? '') === 'select_weeks' && !empty($weekIds)) {
            $weekLabels = [];
            foreach ($allWeeks as $wk) {
                if (in_array((int) ($wk['id'] ?? 0), $weekIds, true)) {
                    $weekTitle = trim(strip_tags(Utilities::decodeHtmlEntities($wk['title'] ?? '')));
                    if ($weekTitle !== '') {
                        $weekLabels[] = $weekTitle;
                    }
                }
            }
            if ($weekLabels !== []) {
                $weekSummary = implode(', ', array_slice($weekLabels, 0, 4));
                if (count($weekLabels) > 4) {
                    $weekSummary .= ' +' . (count($weekLabels) - 4) . ' more';
                }
                if (count($weekLabels) === 1) {
                    $lineName = $title . ' – ' . $weekLabels[0];
                } else {
                    $lineName = $title . ' – ' . count($weekLabels) . ' weeks';
                }
                $lineDescription = $title . ' (' . $weekSummary . ')';
            }
        }

        if (mb_strlen($lineDescription) > 500) {
            $lineDescription = mb_substr($lineDescription, 0, 497) . '...';
        }

        return [
            'program_title' => $title,
            'line_name' => $lineName,
            'line_description' => $lineDescription,
        ];
    }

    /**
     * Start checkout for pending program registration.
     */
    public function createCheckoutSession($programId, $userId, $registrationId, $couponCode = null)
    {
        $program = $this->db->queryOne(
            "SELECT * FROM programs WHERE id = :id AND status = 'published'",
            ['id' => $programId]
        );
        if (!$program) {
            return ['success' => false, 'message' => 'Program not found'];
        }
        $reg = $this->db->queryOne(
            "SELECT * FROM program_registrations WHERE id = :id AND program_id = :pid AND user_id = :uid AND status = 'pending'",
            ['id' => $registrationId, 'pid' => $programId, 'uid' => $userId]
        );
        if (!$reg) {
            return ['success' => false, 'message' => 'Registration not pending'];
        }
        $pricing = $program['pricing_type'] ?? 'free';
        if ($pricing === 'free') {
            return ['success' => false, 'message' => 'Program is free; use register-free'];
        }

        $orgId = (int) $program['organization_id'];
        $pricingSvc = new ProgramPricingService();
        $weekIds = $this->programService->getEnrolledWeekIds((int) $registrationId);
        $allWeeks = $this->programService->listWeeks((int) $programId);
        $quote = $pricingSvc->quote($program, $weekIds, $allWeeks);
        if (empty($quote['success'])) {
            return ['success' => false, 'message' => $quote['message'] ?? 'Invalid registration'];
        }
        $amount = (float) ($quote['total'] ?? 0);
        if ($amount <= 0 && ($program['registration_mode'] ?? 'whole_program') === 'whole_program') {
            $amount = (float) ($program['price_amount'] ?? 0);
        }
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Invalid price'];
        }

        $stripe = $this->getStripeServiceForOrganization($orgId);
        if (!$stripe && $orgId !== 1) {
            $stripe = $this->getStripeServiceForOrganization(1);
        }
        if (!$stripe) {
            return ['success' => false, 'message' => 'Stripe is not configured for this organization.'];
        }

        $user = $this->db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }

        $couponId = null;
        if ($couponCode) {
            $v = $this->programService->validateCoupon($orgId, $programId, $couponCode);
            if (empty($v['valid'])) {
                return ['success' => false, 'message' => $v['message'] ?? 'Invalid coupon'];
            }
            $c = $v['coupon'];
            $couponId = (int) $c['id'];
            $amount = $pricingSvc->applyCouponDiscount($amount, $c);
        }

        if (!$pricingSvc->meetsMinimumCharge($amount, (string) $pricing)) {
            return ['success' => false, 'message' => 'Amount too low after discount'];
        }

        $baseUrl = $this->getBaseUrl();
        $successUrl = $baseUrl . '/portal/payment-success.php?session_id={CHECKOUT_SESSION_ID}&type=program';
        $cancelUrl = $baseUrl . '/portal/program-details.php?id=' . (int) $programId;

        $presentation = $this->buildCheckoutPresentation($program, $weekIds, $allWeeks);
        $programTitle = $presentation['program_title'];
        $lineName = $presentation['line_name'];
        $lineDescription = $presentation['line_description'];

        $metadata = [
            'checkout_type' => 'program',
            'program_registration_id' => (string) $registrationId,
            'program_id' => (string) $programId,
            'program_title' => $programTitle,
            'user_id' => (string) $userId,
            'organization_id' => (string) $orgId,
            'week_ids' => json_encode(array_values(array_map('intval', $weekIds))),
        ];

        try {
            if (($program['pricing_type'] ?? '') === 'recurring') {
                $interval = 'week';
                $count = 1;
                $bi = $program['billing_interval'] ?? 'once';
                if ($bi === 'week') {
                    $interval = 'week';
                    $count = 1;
                } elseif ($bi === 'week_2') {
                    $interval = 'week';
                    $count = 2;
                } elseif ($bi === 'month') {
                    $interval = 'month';
                    $count = 1;
                }
                $cents = (int) round($amount * 100);
                if ($cents < 50) {
                    return ['success' => false, 'message' => 'Amount too low after discount'];
                }
                $session = $stripe->createSubscriptionCheckoutSession(
                    $programTitle,
                    $cents,
                    $interval,
                    $count,
                    $metadata,
                    $successUrl,
                    $cancelUrl,
                    $user['email'] ?? null
                );
            } else {
                $cents = (int) round($amount * 100);
                if ($cents < 50) {
                    return ['success' => false, 'message' => 'Amount too low after discount'];
                }
                $lineItems = [[
                    'name' => $lineName,
                    'description' => $lineDescription,
                    'unit_amount' => $cents,
                    'quantity' => 1,
                ]];
                $session = $stripe->createCheckoutSessionWithCustomMetadata(
                    $lineItems,
                    $metadata,
                    $successUrl,
                    $cancelUrl,
                    $user['email'] ?? null,
                    [
                        'description' => $lineDescription,
                        'statement_descriptor' => self::STMT,
                    ]
                );
            }

            $this->db->update('program_registrations', $registrationId, [
                'stripe_checkout_session_id' => $session->id,
            ]);

            return [
                'success' => true,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
                'registration_id' => $registrationId,
                'coupon_id' => $couponId,
            ];
        } catch (\Throwable $e) {
            error_log('ProgramPaymentService checkout: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Handle checkout.session.completed for programs (from PortalPaymentService).
     */
    public function handleCheckoutSessionCompleted($session)
    {
        $meta = $session->metadata ?? null;
        if (!$meta || ($meta->checkout_type ?? '') !== 'program') {
            return ['success' => false, 'skipped' => true];
        }
        $regId = (int) ($meta->program_registration_id ?? 0);
        $userId = (int) ($meta->user_id ?? 0);
        if ($regId <= 0 || $userId <= 0) {
            return ['success' => false, 'message' => 'Missing program metadata'];
        }

        $sessionId = $session->id ?? null;
        $paymentIntentId = $session->payment_intent ?? null;
        $subscriptionId = $session->subscription ?? null;
        $amount = $session->amount_total ? $session->amount_total / 100 : 0;

        $update = [
            'status' => 'active',
            'joined_at' => date('Y-m-d H:i:s'),
            'stripe_payment_intent_id' => $paymentIntentId ?: '',
            'stripe_subscription_id' => $subscriptionId ?: null,
        ];
        if ($this->db->hasColumn('program_registrations', 'amount_paid')) {
            $update['amount_paid'] = $amount > 0 ? round($amount, 2) : null;
        }
        if ($this->db->hasColumn('program_registrations', 'currency')) {
            $currency = is_string($session->currency ?? null) ? strtoupper($session->currency) : 'USD';
            $update['currency'] = $currency;
        }
        if ($sessionId) {
            $update['stripe_checkout_session_id'] = $sessionId;
        }

        $this->db->update('program_registrations', $regId, $update);

        $reg = $this->db->queryOne("SELECT * FROM program_registrations WHERE id = :id", ['id' => $regId]);
        if ($reg && !empty($reg['coupon_code'])) {
            $porg = $this->db->queryOne(
                "SELECT organization_id FROM programs WHERE id = :id",
                ['id' => $reg['program_id']]
            );
            if ($porg) {
                $c = $this->db->queryOne(
                    "SELECT id FROM program_coupons WHERE organization_id = :org AND UPPER(TRIM(code)) = :code",
                    ['org' => $porg['organization_id'], 'code' => strtoupper(trim($reg['coupon_code']))]
                );
                if ($c) {
                    $this->programService->incrementCouponRedemption((int) $c['id']);
                }
            }
        }

        return [
            'success' => true,
            'program_registration_id' => $regId,
            'user_id' => $userId,
            'amount' => $amount,
        ];
    }

    /**
     * customer.subscription.deleted — mark registration cancelled.
     */
    public function handleSubscriptionDeleted($subscription)
    {
        $subId = is_string($subscription) ? $subscription : ($subscription->id ?? null);
        if (!$subId) {
            return ['success' => false, 'message' => 'No subscription id'];
        }
        $reg = $this->db->queryOne(
            "SELECT * FROM program_registrations WHERE stripe_subscription_id = :sid",
            ['sid' => $subId]
        );
        if (!$reg) {
            return ['success' => true, 'status' => 'ignored'];
        }
        $this->db->update('program_registrations', $reg['id'], [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
        ]);
        return ['success' => true];
    }
}
