<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Integrations\StripeService;
use Headcount\Services\RSVPService;
use Headcount\Services\PortalEmailService;
use Headcount\Services\EventHeadcountPricingService;
use Headcount\Services\PotluckCategoryService;
use Headcount\Services\EventTicketTypeRulesService;

/**
 * Portal Payment Service
 * Handles payment processing for member portal
 */
class PortalPaymentService
{
    private const STRIPE_CHECKOUT_PRODUCT_DESCRIPTION = 'Headcount Event Payment';
    private const STRIPE_STATEMENT_DESCRIPTOR = 'Headcount Event';

    private $db;
    private $stripeService;
    private $rsvpService;
    private $emailService;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        
        // Load config
        $configFile = __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
            
            // Initialize Stripe if configured
            if (!empty($this->config['stripe']['secret_key'])) {
                $this->stripeService = new StripeService(
                    $this->config['stripe']['secret_key'],
                    $this->config['stripe']['webhook_secret'] ?? null
                );
            }
            
            // Initialize email service if configured
            if (!empty($this->config['smtp2go']['api_key'])) {
                $this->emailService = new PortalEmailService($this->config['smtp2go']);
            }
        }
        
        $this->rsvpService = new RSVPService();
    }

    /**
     * Get StripeService for an organization (from DB). Returns null if org has no Stripe configured.
     * Never throws - returns null on any error so checkout can return a proper JSON message.
     */
    private function getStripeServiceForOrganization($organizationId)
    {
        try {
            if (empty($organizationId) || !$this->config) {
                return null;
            }
            // Try with webhook column first (after migration 023); fall back to secret only if column is missing
            $org = null;
            try {
                $org = $this->db->queryOne(
                    "SELECT stripe_secret_key_encrypted, stripe_webhook_secret_encrypted FROM organizations WHERE id = :id",
                    ['id' => $organizationId]
                );
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'stripe_webhook_secret_encrypted') !== false || strpos($e->getMessage(), '1054') !== false) {
                    $org = $this->db->queryOne(
                        "SELECT stripe_secret_key_encrypted FROM organizations WHERE id = :id",
                        ['id' => $organizationId]
                    );
                } else {
                    error_log('PortalPaymentService: getStripeServiceForOrganization query failed: ' . $e->getMessage());
                    return null;
                }
            }
        // Fallback: old API stored secret in stripe_secret_key (base64) instead of stripe_secret_key_encrypted
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
                // Column may not exist; ignore
            }
            if (!$org || empty($org['stripe_secret_key_encrypted'])) {
                return null;
            }
        }
        // Must match SettingsController::getEncryptionKey() exactly for decryption to work
        $key = $this->config['security']['encryption_key'] ?? null;
        if (empty($key)) {
            $dbName = $this->config['database']['name'] ?? 'headcount_dev';
            $key = hash('sha256', ($this->config['app']['name'] ?? '') . $dbName);
        }
        if (strlen($key) < 32) {
            $key = hash('sha256', $key . 'headcount_salt');
        }
        $encryptionKey = substr($key, 0, 32);
        try {
            $secretKey = Security::decrypt($org['stripe_secret_key_encrypted'], $encryptionKey);
        } catch (\Throwable $e) {
            error_log('PortalPaymentService: Stripe secret decryption failed for org ' . $organizationId . ': ' . $e->getMessage());
            return null;
        }
        $webhookSecret = null;
        if (!empty($org['stripe_webhook_secret_encrypted'])) {
            try {
                $webhookSecret = Security::decrypt($org['stripe_webhook_secret_encrypted'], $encryptionKey);
            } catch (\Throwable $e) {
                // optional; leave null
            }
        }
        if (empty($secretKey) || !is_string($secretKey)) {
            return null;
        }
        return new StripeService($secretKey, $webhookSecret);
        } catch (\Throwable $e) {
            error_log('PortalPaymentService: getStripeServiceForOrganization failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * AES key for decrypting organization SMTP (same derivation as SettingsController::getEncryptionKey).
     */
    private function getSmtpEncryptionKey(): string
    {
        $key = $this->config['security']['encryption_key'] ?? null;
        if (empty($key)) {
            $dbName = $this->config['database']['name'] ?? 'headcount_dev';
            $key = hash('sha256', ($this->config['app']['name'] ?? '') . $dbName);
        }
        if (strlen($key) < 32) {
            $key = hash('sha256', $key . 'headcount_salt');
        }

        return substr($key, 0, 32);
    }

    /**
     * Organization SMTP from DB (same rules as public/api/portal/rsvps.php getOrgEmailConfig).
     *
     * @return array{api_key: string, from_email: string, from_name: ?string, reply_to: string}|null
     */
    private function resolveOrganizationSmtpConfig(?int $organizationId): ?array
    {
        if (!$this->config || $organizationId === null || $organizationId <= 0) {
            return null;
        }
        try {
            $org = $this->db->queryOne(
                'SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?',
                [$organizationId]
            );
        } catch (\Throwable $e) {
            return null;
        }
        if (!$org || empty($org['smtp_from_email'])) {
            return null;
        }
        $apiKey = null;
        if (!empty($org['smtp_api_key'])) {
            $decoded = base64_decode($org['smtp_api_key'], true);
            $apiKey = ($decoded !== false && $decoded !== '') ? $decoded : null;
        }
        if (($apiKey === null || $apiKey === '') && !empty($org['smtp_api_key_encrypted'])) {
            try {
                $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $this->getSmtpEncryptionKey());
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (empty($apiKey)) {
            return null;
        }

        return [
            'api_key' => $apiKey,
            'from_email' => $org['smtp_from_email'],
            'from_name' => $org['smtp_from_name'] ?? null,
            'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
        ];
    }

    private function getPortalEmailServiceForOrganization(?int $organizationId): ?PortalEmailService
    {
        $orgCfg = $this->resolveOrganizationSmtpConfig($organizationId);
        if ($orgCfg !== null) {
            return new PortalEmailService($orgCfg);
        }

        return $this->emailService;
    }

    /**
     * Create Stripe checkout session for event
     *
     * @param int $eventId Event ID
     * @param int $userId User ID
     * @param int $guests Number of guests (default 0) - used when no ticket types
     * @param array $tickets Optional array of [ticket_type_id => quantity] for multiple ticket types
     * @param array $pendingCheckout Optional JSON-serializable payload applied after payment (e.g. guest RSVP)
     * @return array Result with checkout session URL
     */
    public function createCheckoutSession($eventId, $userId, $guests = 0, $tickets = [], array $pendingCheckout = [])
    {
        // Get event first so we can use organization's Stripe if configured
        $event = $this->db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            return [
                'success' => false,
                'message' => 'Event not found'
            ];
        }

        $rsvpCheck = new \Headcount\Services\RSVPService();
        if ($rsvpCheck->isRegistrationDeadlinePassed($event)) {
            return [
                'success' => false,
                'message' => 'Online RSVP is closed for this event.'
            ];
        }

        $organizationId = $event['organization_id'] ?? null;
        $orgTzName = OrgTimeZone::FALLBACK_IANA;
        if (!empty($organizationId)) {
            $ot = $this->db->queryOne('SELECT timezone FROM organizations WHERE id = ?', [(int) $organizationId]);
            $orgTzName = OrgTimeZone::resolve(is_array($ot) ? ($ot['timezone'] ?? null) : null);
        }
        $stripeService = $this->getStripeServiceForOrganization($organizationId);
        // Fallback: try org 1 for single-org setups (event's org may be null or different)
        if (!$stripeService && $organizationId != 1) {
            $stripeService = $this->getStripeServiceForOrganization(1);
        }
        $stripeService = $stripeService ?? $this->stripeService;
        if (!$stripeService) {
            return [
                'success' => false,
                'message' => 'Stripe is not configured. In Admin go to Settings → Payments (Stripe) → Configure, enter your Secret Key (starts with sk_live_ or sk_test_), and click Save Configuration.'
            ];
        }

        // Get user
        $user = $this->db->queryOne(
            "SELECT * FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        $baseUrl = $this->getBaseUrl();
        $successUrl = $baseUrl . '/portal/payment-success.php?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $baseUrl . '/portal/payment-cancel.php?event_id=' . $eventId;

        $eventTitleForDisplay = trim(preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            Utilities::decodeHtmlEntities($event['title'] ?? '')
        ));
        $eventTitleSafe = $eventTitleForDisplay;
        if (mb_strlen($eventTitleSafe) > 100) {
            $eventTitleSafe = mb_substr($eventTitleSafe, 0, 97) . '...';
        }
        $checkoutDescription = $eventTitleSafe !== ''
            ? $eventTitleSafe
            : self::STRIPE_CHECKOUT_PRODUCT_DESCRIPTION;
        $eventDateStr = !empty($event['event_date'])
            ? date('M j, Y', strtotime($event['event_date']))
            : '';
        if (!empty($event['start_time']) && $eventDateStr !== '') {
            $eventDateStr .= ' ' . date('g:i A', strtotime($event['start_time']));
        }

        $metadata = [
            'event_id' => $event['id'],
            'user_id' => $userId,
            'organization_id' => $event['organization_id'] ?? '',
            'event_title' => $eventTitleSafe,
            'event_date' => $eventDateStr
        ];

        $lineItems = [];
        $totalAmount = 0;

        if (!empty($tickets)) {
            // Multiple ticket types: load and validate
            $ttExtra = $this->db->hasColumn('event_ticket_types', 'sale_starts_at')
                ? ', sale_starts_at, sale_ends_at, package_group'
                : '';
            $ticketTypeRows = $this->db->query(
                "SELECT id, event_id, name, price, quantity_limit{$ttExtra} FROM event_ticket_types WHERE event_id = :event_id",
                ['event_id' => $eventId]
            );
            $typeMap = [];
            foreach ($ticketTypeRows as $row) {
                $typeMap[(int)$row['id']] = $row;
            }
            $rulesCheck = EventTicketTypeRulesService::validateSelection($tickets, $typeMap, null, $orgTzName);
            if (!$rulesCheck['ok']) {
                return [
                    'success' => false,
                    'message' => $rulesCheck['message'] ?? 'Invalid ticket selection.',
                ];
            }
            foreach ($tickets as $t) {
                $typeId = (int)($t['ticket_type_id'] ?? $t['ticket_type_id'] ?? 0);
                $qty = (int)($t['quantity'] ?? 0);
                if ($qty <= 0 || !isset($typeMap[$typeId])) {
                    continue;
                }
                $tt = $typeMap[$typeId];
                $price = (float)($tt['price'] ?? 0);
                $ticketName = Utilities::decodeHtmlEntities(trim((string) ($tt['name'] ?? '')));
                $ticketName = $ticketName !== '' ? $ticketName : 'Ticket';
                $lineItems[] = [
                    'name' => $eventTitleForDisplay . ' – ' . $ticketName,
                    'description' => $checkoutDescription,
                    'unit_amount' => (int)round($price * 100),
                    'quantity' => $qty
                ];
                $totalAmount += $price * $qty;
            }
            if (empty($lineItems) || $totalAmount <= 0) {
                return [
                    'success' => false,
                    'message' => 'Please select at least one ticket with a positive amount.'
                ];
            }
        } else {
            // Legacy: per-person ticket_price and/or headcount tier packages
            $heads = 1 + max(0, (int) $guests);
            $headcountPricing = new EventHeadcountPricingService();
            $usesTiers = $headcountPricing->usesHeadcountTiers($event);
            $ticketPrice = (float) ($event['ticket_price'] ?? 0);
            if (!$usesTiers && $ticketPrice <= 0) {
                return [
                    'success' => false,
                    'message' => 'Event is free'
                ];
            }
            $resolved = $headcountPricing->resolveLegacyCheckoutAmount($event, $heads, $eventTitleSafe);
            if (!$resolved['success']) {
                return [
                    'success' => false,
                    'message' => $resolved['message'] ?? 'Could not calculate price for this group size.',
                ];
            }
            $totalAmount = $resolved['amount'];
            $lineName = $resolved['line_label'] ?? $eventTitleSafe;
            $lineItems[] = [
                'name' => $lineName,
                'description' => $checkoutDescription,
                'unit_amount' => (int) round($totalAmount * 100),
                'quantity' => 1
            ];
        }

        try {
            $session = $stripeService->createCheckoutSession(
                $lineItems,
                $metadata,
                $successUrl,
                $cancelUrl,
                $user['email'] ?? null,
                [
                    'description' => $checkoutDescription,
                    'statement_descriptor' => self::STRIPE_STATEMENT_DESCRIPTOR,
                ]
            );

            $paymentId = $this->db->insert('payments', [
                'event_id' => $eventId,
                'user_id' => $userId,
                'stripe_payment_intent_id' => '',
                'stripe_checkout_session_id' => $session->id,
                'amount' => $totalAmount,
                'currency' => 'USD',
                'status' => 'pending'
            ]);

            if (!empty($pendingCheckout)) {
                $this->saveCheckoutPendingJson((int) $paymentId, $pendingCheckout);
            }

            return [
                'success' => true,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
                'payment_id' => $paymentId
            ];
        } catch (\Exception $e) {
            error_log("Checkout session creation failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create checkout session: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Store JSON for webhook to apply guest_count, series targets, question answers (column from migration 047).
     */
    private function saveCheckoutPendingJson(int $paymentId, array $data): void
    {
        try {
            $this->db->execute(
                'UPDATE payments SET checkout_pending_json = :j WHERE id = :id',
                ['j' => json_encode($data), 'id' => $paymentId]
            );
        } catch (\Throwable $e) {
            error_log('PortalPaymentService: checkout_pending_json update failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle Stripe webhook
     * 
     * @param string $payload Raw webhook payload
     * @param string $signature Webhook signature
     * @return array Result
     */
    public function handleWebhook($payload, $signature)
    {
        $stripeService = null;
        $payloadData = json_decode($payload, true);
        if (is_array($payloadData)) {
            $obj = $payloadData['data']['object'] ?? [];
            $metadata = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
            $orgId = $metadata['organization_id'] ?? null;
            if ($orgId !== null && $orgId !== '' && (int) $orgId > 0) {
                $stripeService = $this->getStripeServiceForOrganization((int) $orgId);
            }
            if (!$stripeService) {
                $eventIdHint = (int) ($metadata['event_id'] ?? 0);
                if ($eventIdHint > 0) {
                    $ev = $this->db->queryOne('SELECT organization_id FROM events WHERE id = ?', [$eventIdHint]);
                    if ($ev && !empty($ev['organization_id'])) {
                        $stripeService = $this->getStripeServiceForOrganization((int) $ev['organization_id']);
                    }
                }
            }
            if (!$stripeService && ($metadata['checkout_type'] ?? '') === 'facility_booking') {
                $fbId = (int) ($metadata['facility_booking_id'] ?? 0);
                if ($fbId > 0) {
                    $fb = $this->db->queryOne('SELECT organization_id FROM facility_bookings WHERE id = ?', [$fbId]);
                    if ($fb && !empty($fb['organization_id'])) {
                        $stripeService = $this->getStripeServiceForOrganization((int) $fb['organization_id']);
                    }
                }
            }
        }
        $stripeService = $stripeService ?? $this->stripeService;
        if (!$stripeService) {
            return [
                'success' => false,
                'message' => 'Stripe is not configured'
            ];
        }

        try {
            $event = $stripeService->verifyWebhook($payload, $signature);

            switch ($event->type) {
                case 'checkout.session.completed':
                    return $this->handleCheckoutCompleted($event->data->object);

                case 'customer.subscription.deleted':
                    $pp = new \Headcount\Services\ProgramPaymentService();
                    return $pp->handleSubscriptionDeleted($event->data->object);
                
                case 'payment_intent.succeeded':
                    return $this->handlePaymentSucceeded($event->data->object);
                
                case 'charge.refunded':
                    return $this->handleRefund($event->data->object);
                
                default:
                    return [
                        'success' => true,
                        'status' => 'ignored',
                        'type' => $event->type
                    ];
            }
        } catch (\Exception $e) {
            error_log("Webhook handling failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Normalize Stripe object id fields to string id.
     *
     * @param mixed $value
     */
    private static function normalizeStripeId($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_object($value) && isset($value->id)) {
            return (string) $value->id;
        }
        return is_string($value) ? $value : null;
    }

    /**
     * Whether a Checkout Session should be treated as successfully collected for reconcile/finalize.
     * Covers payment_status paid / no_payment_required, and sessions where payment_status lags unpaid
     * but the PaymentIntent is already succeeded (expand or retrieve).
     *
     * @param \Stripe\Checkout\Session $session
     */
    private function isCheckoutSessionEffectivelyPaid($session, ?StripeService $stripe = null): bool
    {
        $paymentStatus = (string) ($session->payment_status ?? '');
        if ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required') {
            return true;
        }

        $sessionStatus = (string) ($session->status ?? '');
        if ($sessionStatus !== 'complete') {
            return false;
        }

        $pi = $session->payment_intent ?? null;
        if (is_object($pi)) {
            $st = (string) ($pi->status ?? '');
            if ($st === 'succeeded') {
                return true;
            }
        } elseif (is_string($pi) && $pi !== '' && $stripe instanceof StripeService) {
            try {
                $piObj = $stripe->getPaymentIntent($pi);
                if ($piObj && (string) ($piObj->status ?? '') === 'succeeded') {
                    return true;
                }
            } catch (\Throwable $e) {
                error_log('isCheckoutSessionEffectivelyPaid: ' . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * After redirect: if DB row still pending but Stripe session is paid, finalize (covers missed webhooks).
     */
    public function reconcileMemberCheckoutSession(string $sessionId, int $userId): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return ['success' => false, 'message' => 'Missing session'];
        }
        $payment = $this->db->queryOne(
            "SELECT p.*, e.organization_id AS org_id FROM payments p
             INNER JOIN events e ON e.id = p.event_id
             WHERE p.stripe_checkout_session_id = :sid AND p.user_id = :uid",
            ['sid' => $sessionId, 'uid' => $userId]
        );
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found'];
        }
        if (($payment['status'] ?? '') === 'paid') {
            return ['success' => true, 'already_finalized' => true];
        }
        $orgId = (int) ($payment['org_id'] ?? 0);
        $stripe = $this->getStripeServiceForOrganization($orgId);
        if (!$stripe && $orgId !== 1) {
            $stripe = $this->getStripeServiceForOrganization(1);
        }
        $stripe = $stripe ?? $this->stripeService;
        if (!$stripe) {
            return ['success' => false, 'message' => 'Stripe is not configured'];
        }
        try {
            $session = $stripe->retrieveCheckoutSession($sessionId);
        } catch (\Throwable $e) {
            error_log('reconcileMemberCheckoutSession: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not verify payment with Stripe'];
        }
        if (!$this->isCheckoutSessionEffectivelyPaid($session, $stripe)) {
            return ['success' => true, 'stripe_pending' => true];
        }
        return $this->finalizeFromStripeCheckoutSession($session);
    }

    /**
     * For each pending checkout row with a Stripe session id, ask Stripe if payment completed; if so, finalize.
     * Use when webhooks were missed (e.g. wrong signing secret) but Checkout succeeded in Stripe.
     */
    public function reconcilePendingPaymentsForEvent(int $eventId): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return ['success' => false, 'message' => 'Invalid event', 'updated' => 0, 'skipped' => 0, 'errors' => []];
        }
        $rows = $this->db->query(
            "SELECT p.id, p.stripe_checkout_session_id, e.organization_id
             FROM payments p
             INNER JOIN events e ON e.id = p.event_id
             WHERE p.event_id = ? AND p.status = 'pending'
               AND p.stripe_checkout_session_id IS NOT NULL
               AND TRIM(p.stripe_checkout_session_id) <> ''",
            [$eventId]
        );
        $updated = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $row) {
            $sid = trim((string) ($row['stripe_checkout_session_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $orgId = (int) ($row['organization_id'] ?? 0);
            $stripe = $this->getStripeServiceForOrganization($orgId);
            if (!$stripe && $orgId !== 1) {
                $stripe = $this->getStripeServiceForOrganization(1);
            }
            $stripe = $stripe ?? $this->stripeService;
            if (!$stripe) {
                $errors[] = 'Stripe is not configured for this organization';
                break;
            }
            try {
                $session = $stripe->retrieveCheckoutSession($sid);
            } catch (\Throwable $e) {
                $errors[] = $sid . ': ' . $e->getMessage();
                continue;
            }
            if (!$this->isCheckoutSessionEffectivelyPaid($session, $stripe)) {
                $skipped++;
                continue;
            }
            $res = $this->finalizeFromStripeCheckoutSession($session);
            if (!empty($res['success'])) {
                $updated++;
            } else {
                $errors[] = $sid . ': ' . ($res['message'] ?? 'finalize failed');
            }
        }
        return [
            'success' => true,
            'updated' => $updated,
            'skipped_unpaid_session' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Reconcile pending Stripe checkouts for every event in an organization that has such rows.
     * Used by admin background sync and optional cron scoped to one org.
     */
    public function reconcilePendingPaymentsForOrganization(int $organizationId): array
    {
        $organizationId = (int) $organizationId;
        if ($organizationId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid organization',
                'events_processed' => 0,
                'programs_processed' => 0,
                'facilities_processed' => 0,
                'updated' => 0,
                'skipped_unpaid_session' => 0,
                'errors' => [],
            ];
        }

        return $this->mergeReconcileResults([
            'events' => $this->reconcileEventsPendingForOrganization($organizationId),
            'programs' => (new \Headcount\Services\ProgramPaymentService())->reconcilePendingRegistrationsForOrganization($organizationId),
            'facilities' => (new \Headcount\Services\FacilityPaymentService())->reconcilePendingBookingsForOrganization($organizationId),
        ]);
    }

    /**
     * Reconcile pending Stripe checkouts across all organizations (CLI / secured HTTP cron).
     */
    public function reconcilePendingPaymentsGlobally(): array
    {
        return $this->mergeReconcileResults([
            'events' => $this->reconcileEventsPendingGlobally(),
            'programs' => (new \Headcount\Services\ProgramPaymentService())->reconcilePendingRegistrationsGlobally(),
            'facilities' => (new \Headcount\Services\FacilityPaymentService())->reconcilePendingBookingsGlobally(),
        ]);
    }

    private function reconcileEventsPendingForOrganization(int $organizationId): array
    {
        $eventRows = $this->db->query(
            'SELECT DISTINCT p.event_id
             FROM payments p
             INNER JOIN events e ON e.id = p.event_id AND e.organization_id = ?
             WHERE p.status = ?
               AND p.stripe_checkout_session_id IS NOT NULL
               AND TRIM(p.stripe_checkout_session_id) <> ?',
            [$organizationId, 'pending', '']
        );
        return $this->aggregateReconcileAcrossEventIds($eventRows);
    }

    private function reconcileEventsPendingGlobally(): array
    {
        $eventRows = $this->db->query(
            "SELECT DISTINCT p.event_id
             FROM payments p
             INNER JOIN events e ON e.id = p.event_id
             WHERE p.status = 'pending'
               AND p.stripe_checkout_session_id IS NOT NULL
               AND TRIM(p.stripe_checkout_session_id) <> ''"
        );
        return $this->aggregateReconcileAcrossEventIds($eventRows);
    }

    /**
     * @param array<string, array<string, mixed>> $byDomain
     */
    private function mergeReconcileResults(array $byDomain): array
    {
        $updated = 0;
        $skipped = 0;
        $errors = [];
        foreach ($byDomain as $domain => $result) {
            $updated += (int) ($result['updated'] ?? 0);
            $skipped += (int) ($result['skipped_unpaid_session'] ?? 0);
            if (!empty($result['errors']) && is_array($result['errors'])) {
                foreach ($result['errors'] as $err) {
                    $errors[] = $domain . ': ' . $err;
                }
            }
        }

        return [
            'success' => true,
            'updated' => $updated,
            'skipped_unpaid_session' => $skipped,
            'errors' => $errors,
            'events_processed' => (int) ($byDomain['events']['events_processed'] ?? 0),
            'programs_processed' => (int) ($byDomain['programs']['programs_processed'] ?? 0),
            'facilities_processed' => (int) ($byDomain['facilities']['facilities_processed'] ?? 0),
            'by_domain' => $byDomain,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $eventRows rows with event_id
     */
    private function aggregateReconcileAcrossEventIds(array $eventRows): array
    {
        $eventsProcessed = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $allErrors = [];
        foreach ($eventRows as $er) {
            $eid = (int) ($er['event_id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }
            $eventsProcessed++;
            $r = $this->reconcilePendingPaymentsForEvent($eid);
            $totalUpdated += (int) ($r['updated'] ?? 0);
            $totalSkipped += (int) ($r['skipped_unpaid_session'] ?? 0);
            if (!empty($r['errors']) && is_array($r['errors'])) {
                foreach ($r['errors'] as $err) {
                    $allErrors[] = 'event ' . $eid . ': ' . $err;
                }
            }
        }
        return [
            'success' => true,
            'events_processed' => $eventsProcessed,
            'updated' => $totalUpdated,
            'skipped_unpaid_session' => $totalSkipped,
            'errors' => $allErrors,
        ];
    }

    /**
     * Idempotent: mark event payment paid, sync RSVPs, questions, potluck, confirmation email (once).
     */
    private function finalizePaidEventCheckoutCore(
        int $eventId,
        int $userId,
        ?string $sessionId,
        ?string $paymentIntentId,
        float $amount
    ): array {
        $payment = null;
        if ($sessionId) {
            $payment = $this->db->queryOne(
                "SELECT * FROM payments WHERE stripe_checkout_session_id = :session_id",
                ['session_id' => $sessionId]
            );
        }
        if (!$payment && $paymentIntentId) {
            $payment = $this->db->queryOne(
                "SELECT * FROM payments WHERE stripe_payment_intent_id = :pi",
                ['pi' => $paymentIntentId]
            );
        }
        if (!$payment && $paymentIntentId && $eventId > 0 && $userId > 0) {
            $payment = $this->db->queryOne(
                "SELECT * FROM payments WHERE event_id = :eid AND user_id = :uid AND status = 'pending'
                 AND (stripe_payment_intent_id IS NULL OR stripe_payment_intent_id = '')
                 ORDER BY id DESC LIMIT 1",
                ['eid' => $eventId, 'uid' => $userId]
            );
        }
        if (!$payment && $eventId > 0 && $userId > 0) {
            $payment = $this->db->queryOne(
                "SELECT * FROM payments WHERE event_id = :eid AND user_id = :uid AND status = 'pending' ORDER BY id DESC LIMIT 1",
                ['eid' => $eventId, 'uid' => $userId]
            );
        }

        if ($payment) {
            $eventId = (int) $payment['event_id'];
            $userId = (int) $payment['user_id'];
        }

        $wasPaid = $payment && (string) ($payment['status'] ?? '') === 'paid';
        if ($wasPaid) {
            error_log('PortalPaymentService: duplicate finalize ignored for already-paid payment id=' . (int) ($payment['id'] ?? 0));
        }

        $pending = [];
        if ($payment && !empty($payment['checkout_pending_json'])) {
            $decoded = json_decode($payment['checkout_pending_json'], true);
            if (is_array($decoded)) {
                $pending = $decoded;
            }
        }

        $resolvedPi = $paymentIntentId;
        if ($resolvedPi === null && $payment) {
            $resolvedPi = self::normalizeStripeId($payment['stripe_payment_intent_id'] ?? null);
        }
        $resolvedSession = $sessionId;
        if ($resolvedSession === null && $payment) {
            $resolvedSession = (string) ($payment['stripe_checkout_session_id'] ?? '');
        }
        $resolvedSession = $resolvedSession ?? '';
        $resolvedAmount = $amount > 0 ? $amount : ($payment ? (float) ($payment['amount'] ?? 0) : 0.0);

        if ($payment) {
            $this->db->update('payments', $payment['id'], [
                'stripe_payment_intent_id' => $resolvedPi ?? '',
                'stripe_checkout_session_id' => $resolvedSession !== '' ? $resolvedSession : $payment['stripe_checkout_session_id'],
                'amount' => $resolvedAmount,
                'status' => 'paid',
            ]);
        } else {
            $this->db->insert('payments', [
                'event_id' => $eventId,
                'user_id' => $userId,
                'stripe_payment_intent_id' => $resolvedPi ?? '',
                'stripe_checkout_session_id' => $resolvedSession,
                'amount' => $resolvedAmount,
                'currency' => 'USD',
                'status' => 'paid',
            ]);
        }

        if ($wasPaid) {
            return [
                'success' => true,
                'event_id' => $eventId,
                'user_id' => $userId,
                'payment_intent_id' => $resolvedPi,
                'already_finalized' => true,
            ];
        }

        $guests = (int) ($pending['guest_count'] ?? 0);
        $targetIds = [];
        if (!empty($pending['target_event_ids']) && is_array($pending['target_event_ids'])) {
            foreach ($pending['target_event_ids'] as $tid) {
                $tid = (int) $tid;
                if ($tid > 0) {
                    $targetIds[$tid] = $tid;
                }
            }
            $targetIds = array_values($targetIds);
        }
        if ($targetIds === []) {
            $targetIds = [$eventId];
        }

        foreach ($targetIds as $tid) {
            $this->rsvpService->createRSVP((int) $tid, $userId, $guests, [], ['from_payment_success' => true]);
        }

        if (!empty($pending['waiver_accepted'])) {
            try {
                $rsvpPrimaryWaiver = $this->db->queryOne(
                    'SELECT id FROM rsvps WHERE event_id = :eid AND user_id = :uid',
                    ['eid' => $eventId, 'uid' => $userId]
                );
                if ($rsvpPrimaryWaiver) {
                    headcount_mark_waiver_accepted($this->db, 'rsvps', (int) $rsvpPrimaryWaiver['id']);
                }
            } catch (\Throwable $e) {
                error_log('Payment waiver mark: ' . $e->getMessage());
            }
        }

        if (!empty($pending['question_answers']) && is_array($pending['question_answers'])) {
            try {
                $rsvpPrimary = $this->db->queryOne(
                    "SELECT id FROM rsvps WHERE event_id = :eid AND user_id = :uid",
                    ['eid' => $eventId, 'uid' => $userId]
                );
                if ($rsvpPrimary) {
                    $rid = (int) $rsvpPrimary['id'];
                    foreach ($pending['question_answers'] as $qId => $answerText) {
                        $qId = (int) $qId;
                        if ($qId <= 0) {
                            continue;
                        }
                        $stored = is_array($answerText)
                            ? json_encode(array_values($answerText))
                            : (is_scalar($answerText) ? trim((string) $answerText) : '');
                        $this->db->execute(
                            "INSERT INTO rsvp_question_answers (rsvp_id, question_id, answer_text) VALUES (:rsvp_id, :question_id, :answer_text)
                             ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text)",
                            ['rsvp_id' => $rid, 'question_id' => $qId, 'answer_text' => $stored]
                        );
                    }
                }
            } catch (\Throwable $e) {
                error_log('Payment checkout pending question_answers: ' . $e->getMessage());
            }
        }

        $hasPotluckReplay = !empty($pending['potluck_category'])
            || array_key_exists('potluck_party_adults', $pending)
            || array_key_exists('potluck_party_children', $pending);
        if ($hasPotluckReplay) {
            try {
                $hasExtReplay = $this->db->hasColumn('rsvps', 'potluck_quantity')
                    && $this->db->hasColumn('rsvps', 'potluck_serving_side')
                    && $this->db->hasColumn('rsvps', 'potluck_party_adults')
                    && $this->db->hasColumn('rsvps', 'potluck_party_children');
                $replayInput = [
                    'potluck_category' => $pending['potluck_category'] ?? null,
                    'potluck_item_note' => $pending['potluck_item_note'] ?? null,
                    'potluck_quantity' => $pending['potluck_quantity'] ?? null,
                    'potluck_serving_side' => $pending['potluck_serving_side'] ?? null,
                    'potluck_party_adults' => $pending['potluck_party_adults'] ?? null,
                    'potluck_party_children' => $pending['potluck_party_children'] ?? null,
                    'potluck_bringing_food' => !empty($pending['potluck_category']),
                ];
                if ($hasExtReplay) {
                    if (!empty($pending['potluck_category'])) {
                        if ($replayInput['potluck_quantity'] === null || $replayInput['potluck_quantity'] === '') {
                            $replayInput['potluck_quantity'] = 1;
                        }
                        if ($replayInput['potluck_serving_side'] === null || $replayInput['potluck_serving_side'] === '') {
                            $replayInput['potluck_serving_side'] = 'both';
                        }
                    }
                    if ($replayInput['potluck_party_adults'] === null || $replayInput['potluck_party_adults'] === '') {
                        $replayInput['potluck_party_adults'] = 1 + (int) ($pending['guest_count'] ?? $guests);
                    }
                    if ($replayInput['potluck_party_children'] === null || $replayInput['potluck_party_children'] === '') {
                        $replayInput['potluck_party_children'] = 0;
                    }
                }
                $requireReplayDish = PotluckCategoryService::requiresPotluckDishCategoryFromRequest($replayInput);
                $evReplayForAllow = $this->db->queryOne('SELECT * FROM events WHERE id = ?', [(int) $eventId]);
                if (is_array($evReplayForAllow)) {
                    $evReplayForAllow = EventSeriesHelper::mergeSeriesParentPolicyFields($this->db, $evReplayForAllow);
                }
                $potluckAllowedReplay = is_array($evReplayForAllow)
                    ? PotluckCategoryService::parsePotluckAllowedSlugsFromEvent($evReplayForAllow)
                    : null;
                $normReplay = PotluckCategoryService::normalizePotluckSignup($replayInput, $hasExtReplay, $requireReplayDish, $potluckAllowedReplay);
                if (!$normReplay['ok']) {
                    error_log('Payment checkout pending potluck normalize failed: ' . (string) ($normReplay['error'] ?? ''));
                } else {
                    foreach ($targetIds as $tid) {
                        $evP = $this->db->queryOne('SELECT * FROM events WHERE id = ?', [(int) $tid]);
                        $rsvP = $this->db->queryOne(
                            "SELECT id FROM rsvps WHERE event_id = ? AND user_id = ? AND status = 'yes'",
                            [(int) $tid, $userId]
                        );
                        if (!$evP || !$rsvP || empty($evP['is_potluck'])) {
                            continue;
                        }
                        $replayApply = PotluckCategoryService::applyPayloadFromNormalization($normReplay);
                        PotluckCategoryService::applyPotluckState(
                            $this->db,
                            $evP,
                            (int) $rsvP['id'],
                            'yes',
                            $replayApply,
                            $replayApply !== null
                        );
                    }
                }
            } catch (\Throwable $e) {
                error_log('Payment checkout pending potluck: ' . $e->getMessage());
            }
        }

        try {
            $eventRow = $this->db->queryOne(
                "SELECT * FROM events WHERE id = :id",
                ['id' => $eventId]
            );
            $userRow = $this->db->queryOne(
                "SELECT * FROM users WHERE id = :id",
                ['id' => $userId]
            );
            if ($eventRow && $userRow) {
                $orgIdForMail = (int) ($eventRow['organization_id'] ?? 0);
                $mailer = $this->getPortalEmailServiceForOrganization($orgIdForMail > 0 ? $orgIdForMail : null);
                if ($mailer) {
                    $paymentAmount = $resolvedAmount > 0 ? $resolvedAmount : (float) ($payment['amount'] ?? 0);
                    $alreadySentReceipt = $this->db->queryOne(
                        "SELECT id FROM email_logs
                         WHERE organization_id = :org
                           AND event_id = :eid
                           AND recipient_user_id = :uid
                           AND email_type = 'receipt'
                           AND status = 'sent'
                         ORDER BY id DESC LIMIT 1",
                        [
                            'org' => (int) ($eventRow['organization_id'] ?? 0),
                            'eid' => (int) $eventId,
                            'uid' => (int) $userId,
                        ]
                    );
                    if (!$alreadySentReceipt) {
                        $mailer->sendPaymentReceipt($eventRow, $userRow, (float) $paymentAmount);
                    } else {
                        error_log('PortalPaymentService: skipped duplicate payment receipt for event=' . (int) $eventId . ' user=' . (int) $userId);
                    }

                    $rsvp = $this->db->queryOne(
                        "SELECT * FROM rsvps WHERE event_id = :event_id AND user_id = :user_id",
                        ['event_id' => $eventId, 'user_id' => $userId]
                    );

                    if ($rsvp) {
                        $guestNew = !empty($pending['is_new_user']) && empty($userRow['password_hash']);
                        if ($guestNew) {
                            $registerUrl = $this->getBaseUrl() . '/portal/register.php?email=' . rawurlencode((string) ($userRow['email'] ?? ''));
                            $mailer->sendGuestRSVPConfirmation($rsvp, $eventRow, $userRow, $registerUrl);
                        } else {
                            $mailer->sendRSVPConfirmation($rsvp, $eventRow, $userRow);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to send payment confirmation email: " . $e->getMessage());
        }

        return [
            'success' => true,
            'event_id' => $eventId,
            'user_id' => $userId,
            'payment_intent_id' => $resolvedPi,
        ];
    }

    private function finalizeFromStripeCheckoutSession($session): array
    {
        $eventId = (int) ($session->metadata->event_id ?? 0);
        $userId = (int) ($session->metadata->user_id ?? 0);
        $sessionId = $session->id ?? null;
        $paymentIntentId = self::normalizeStripeId($session->payment_intent ?? null);
        $amount = $session->amount_total ? $session->amount_total / 100 : 0;

        if (!$eventId || !$userId) {
            error_log('Missing metadata in checkout session: ' . ($sessionId ?? ''));
            return [
                'success' => false,
                'message' => 'Missing metadata',
            ];
        }

        return $this->finalizePaidEventCheckoutCore(
            $eventId,
            $userId,
            $sessionId,
            $paymentIntentId,
            (float) $amount
        );
    }

    /**
     * Handle checkout session completed
     */
    private function handleCheckoutCompleted($session)
    {
        $meta = $session->metadata ?? null;
        if ($meta && (($meta->checkout_type ?? '') === 'facility_booking')) {
            $fp = new \Headcount\Services\FacilityPaymentService();
            return $fp->handleCheckoutSessionCompleted($session);
        }
        if ($meta && (($meta->checkout_type ?? '') === 'program' || !empty($meta->program_registration_id))) {
            $pp = new \Headcount\Services\ProgramPaymentService();
            return $pp->handleCheckoutSessionCompleted($session);
        }

        return $this->finalizeFromStripeCheckoutSession($session);
    }

    /**
     * Handle payment succeeded (Stripe often delivers this alongside or before checkout.session.completed).
     */
    private function handlePaymentSucceeded($paymentIntent)
    {
        $piId = self::normalizeStripeId($paymentIntent->id ?? null);
        if (!$piId) {
            return ['success' => false, 'message' => 'Missing payment intent id'];
        }

        $meta = $paymentIntent->metadata ?? null;
        if ($meta && (($meta->checkout_type ?? '') === 'facility_booking')) {
            return [
                'success' => true,
                'status' => 'ignored',
                'type' => 'facility_booking_authorization',
            ];
        }
        $eventId = $meta ? (int) ($meta->event_id ?? 0) : 0;
        $userId = $meta ? (int) ($meta->user_id ?? 0) : 0;
        $amountCents = $paymentIntent->amount_received ?? $paymentIntent->amount ?? 0;
        $amount = is_numeric($amountCents) ? ((float) $amountCents) / 100.0 : 0.0;

        $paymentByPi = $this->db->queryOne(
            "SELECT * FROM payments WHERE stripe_payment_intent_id = :pi",
            ['pi' => $piId]
        );
        $sessionId = null;
        if ($paymentByPi) {
            $eventId = (int) $paymentByPi['event_id'];
            $userId = (int) $paymentByPi['user_id'];
            if ($amount <= 0) {
                $amount = (float) ($paymentByPi['amount'] ?? 0);
            }
            $sid = (string) ($paymentByPi['stripe_checkout_session_id'] ?? '');
            $sessionId = $sid !== '' ? $sid : null;
        }

        if ($eventId <= 0 || $userId <= 0) {
            return [
                'success' => true,
                'status' => 'ignored_no_event_user',
                'payment_intent_id' => $piId,
            ];
        }

        return $this->finalizePaidEventCheckoutCore($eventId, $userId, $sessionId, $piId, $amount);
    }

    /**
     * Handle refund
     */
    private function handleRefund($charge)
    {
        $paymentIntentId = $charge->payment_intent ?? null;
        
        if (!$paymentIntentId) {
            return [
                'success' => false,
                'message' => 'No payment intent in refund'
            ];
        }

        // Find payment record
        $payment = $this->db->queryOne(
            "SELECT * FROM payments WHERE stripe_payment_intent_id = :payment_intent_id",
            ['payment_intent_id' => $paymentIntentId]
        );

        if ($payment) {
            $refundAmount = $charge->amount_refunded ? $charge->amount_refunded / 100 : 0;
            
            $this->db->update('payments', $payment['id'], [
                'status' => $refundAmount >= $payment['amount'] ? 'refunded' : 'paid',
                'refund_amount' => $refundAmount,
                'refunded_at' => date('Y-m-d H:i:s'),
                'refund_reason' => 'Refunded via Stripe'
            ]);
        }

        return [
            'success' => true,
            'refund_id' => $charge->id ?? null
        ];
    }

    /**
     * Get payment history for a user
     * 
     * @param int $userId User ID
     * @return array List of payments
     */
    public function getPaymentHistory($userId)
    {
        $payments = $this->db->query(
            "SELECT p.*, e.title as event_title, e.event_date, e.start_time, e.location
             FROM payments p
             JOIN events e ON p.event_id = e.id
             WHERE p.user_id = :user_id
             ORDER BY p.created_at DESC",
            ['user_id' => $userId]
        );

        return $payments;
    }

    /**
     * Get payment by ID
     * 
     * @param int $paymentId Payment ID
     * @param int $userId User ID (for verification)
     * @return array|null Payment data or null
     */
    public function getPayment($paymentId, $userId = null)
    {
        $sql = "SELECT p.*, e.title as event_title, e.event_date, e.start_time, e.location
                FROM payments p
                JOIN events e ON p.event_id = e.id
                WHERE p.id = :id";
        
        $params = ['id' => $paymentId];
        
        if ($userId) {
            $sql .= " AND p.user_id = :user_id";
            $params['user_id'] = $userId;
        }
        
        return $this->db->queryOne($sql, $params);
    }

    /**
     * Generate receipt PDF (placeholder - would need PDF library)
     * 
     * @param int $paymentId Payment ID
     * @return string|null PDF content or null
     */
    public function generateReceiptPDF($paymentId)
    {
        // TODO: Implement PDF generation using a library like TCPDF or DomPDF
        // For now, return null
        return null;
    }

    /**
     * Get base URL
     */
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
}
