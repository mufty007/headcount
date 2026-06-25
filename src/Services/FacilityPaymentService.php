<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;
use Headcount\Integrations\StripeService;

/**
 * Stripe Checkout with manual capture for facility bookings (authorize on request, capture on approve).
 */
class FacilityPaymentService
{
    private const STMT = 'IMCA Facility booking';
    private const STMT_DESCRIPTION = 'IMCA Facility booking fee';
    private const HOLD_DAYS = 7;

    private $db;
    private $config;
    private $bookingService;
    private $facilityService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->bookingService = new FacilityBookingService();
        $this->facilityService = new FacilityService();
        $configFile = __DIR__ . '/../../config/config.php';
        $this->config = file_exists($configFile) ? require $configFile : [];
    }

    public function facilityPaymentsEnabled(): bool
    {
        return $this->facilityService->columnExistsPublic('facility_bookings', 'payment_status');
    }

    /**
     * @return StripeService|null
     */
    public function getStripeServiceForOrganization($organizationId)
    {
        try {
            if (empty($organizationId) || !$this->config) {
                return null;
            }
            $org = null;
            try {
                $org = $this->db->queryOne(
                    "SELECT stripe_secret_key_encrypted, stripe_webhook_secret_encrypted FROM organizations WHERE id = :id",
                    ['id' => (int) $organizationId]
                );
            } catch (\Throwable $e) {
                $org = $this->db->queryOne(
                    "SELECT stripe_secret_key_encrypted FROM organizations WHERE id = :id",
                    ['id' => (int) $organizationId]
                );
            }
            if (!$org || empty($org['stripe_secret_key_encrypted'])) {
                try {
                    $legacy = $this->db->queryOne(
                        "SELECT stripe_secret_key FROM organizations WHERE id = :id",
                        ['id' => (int) $organizationId]
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
            error_log('FacilityPaymentService: getStripeServiceForOrganization: ' . $e->getMessage());
            return null;
        }
    }

    private function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = dirname($scriptName);
        $basePath = str_replace('/public', '', $basePath);
        return rtrim($protocol . '://' . $host . rtrim($basePath, '/'), '/');
    }

    /**
     * Whether facility requires Stripe checkout for this booking.
     */
    public function requiresCheckout(array $facility, array $pricing): bool
    {
        if (!$this->facilityPaymentsEnabled()) {
            return false;
        }
        if (empty($facility['is_paid'])) {
            return false;
        }
        return (float) ($pricing['total_amount'] ?? 0) >= 0.50;
    }

    /**
     * Create pending booking + Stripe Checkout (manual capture).
     *
     * @return array{success:bool,message?:string,checkout_url?:string,booking_id?:int}
     */
    public function startCheckout(int $organizationId, int $userId, array $bookingData, string $role = 'member', string $bookedVia = 'portal', ?string $customerEmail = null)
    {
        if (!$this->facilityPaymentsEnabled()) {
            return ['success' => false, 'message' => 'Facility payments are not configured. Run migration 062.'];
        }

        $facilityId = (int) ($bookingData['facility_id'] ?? 0);
        $facility = $this->facilityService->getByIdForOrg($facilityId, $organizationId);
        if (!$facility) {
            return ['success' => false, 'message' => 'Facility not found.'];
        }

        $start = $this->normalizeDatetime($bookingData['start_datetime'] ?? '');
        $end = $this->normalizeDatetime($bookingData['end_datetime'] ?? '');
        $title = trim((string) ($bookingData['title'] ?? ''));
        if ($title === '') {
            return ['success' => false, 'message' => 'Event title is required.'];
        }
        $purpose = trim(strip_tags((string) ($bookingData['purpose'] ?? '')));
        $purposeError = headcount_validate_booking_purpose($purpose, 200);
        if ($purposeError !== null) {
            return ['success' => false, 'message' => $purposeError];
        }

        $validation = $this->facilityService->validateBookingRequest($facility, $start, $end, $role);
        if (!$validation['ok']) {
            return ['success' => false, 'message' => $validation['message']];
        }
        $blockMsg = $this->facilityService->getSlotBlockMessage($facility, $start, $end, $role);
        if ($blockMsg !== null) {
            return ['success' => false, 'message' => $blockMsg];
        }
        if ($this->bookingService->hasOverlap($facilityId, $start, $end)) {
            return ['success' => false, 'message' => 'This time slot overlaps with an existing booking.', 'code' => 409];
        }

        $pricing = $this->facilityService->calculateBookingPrice($facility, $start, $end);
        if (!$this->requiresCheckout($facility, $pricing)) {
            return ['success' => false, 'message' => 'This facility does not require online payment.'];
        }

        $stripe = $this->getStripeServiceForOrganization($organizationId);
        if (!$stripe) {
            return [
                'success' => false,
                'message' => 'Stripe is not configured. In Admin go to Settings → Payments (Stripe) and save your keys.',
            ];
        }

        $insert = [
            'organization_id' => $organizationId,
            'facility_id' => $facilityId,
            'booked_by_user_id' => $userId,
            'title' => $title,
            'purpose' => $purpose,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => 'pending',
            'booked_via' => $bookedVia,
            'payment_status' => 'awaiting_checkout',
            'hours_booked' => $pricing['hours_booked'],
            'hourly_rate' => $pricing['hourly_rate'] ?: null,
            'discount_percent' => $pricing['discount_percent'] ?: null,
            'subtotal_amount' => $pricing['subtotal_amount'] ?: null,
            'total_amount' => $pricing['total_amount'] ?: null,
        ];

        $bookingId = (int) $this->db->insert('facility_bookings', $insert);

        $cents = (int) round((float) $pricing['total_amount'] * 100);
        if ($cents < 50) {
            $this->db->update('facility_bookings', $bookingId, ['payment_status' => 'failed', 'status' => 'cancelled']);
            return ['success' => false, 'message' => 'Amount is below the minimum charge.'];
        }

        $facilityName = Utilities::decodeHtmlEntities($facility['name'] ?? 'Facility');
        $lineDesc = $title . ' — ' . date('M j, Y g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
        $baseUrl = $this->getBaseUrl();
        $successUrl = $baseUrl . '/portal/facility-booking-success.php?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $baseUrl . '/portal/facility-booking-cancel.php?booking_id=' . $bookingId;

        $metadata = [
            'checkout_type' => 'facility_booking',
            'facility_booking_id' => (string) $bookingId,
            'facility_id' => (string) $facilityId,
            'organization_id' => (string) $organizationId,
            'user_id' => (string) $userId,
            'event_title' => mb_substr($title, 0, 100),
        ];

        $user = $this->db->queryOne('SELECT email FROM users WHERE id = :id', ['id' => $userId]);
        $email = $customerEmail ?: ($user['email'] ?? null);

        try {
            $session = $stripe->createCheckoutSessionWithCustomMetadata(
                [[
                    'name' => mb_substr($facilityName, 0, 120),
                    'description' => mb_substr($lineDesc, 0, 500),
                    'unit_amount' => $cents,
                    'quantity' => 1,
                ]],
                $metadata,
                $successUrl,
                $cancelUrl,
                $email,
                [
                    'capture_method' => 'manual',
                    'description' => mb_substr(self::STMT_DESCRIPTION . ' — ' . $lineDesc, 0, 200),
                    'statement_descriptor' => self::STMT,
                ]
            );

            $this->db->update('facility_bookings', $bookingId, [
                'stripe_checkout_session_id' => $session->id,
            ]);

            return [
                'success' => true,
                'checkout_url' => $session->url,
                'booking_id' => $bookingId,
            ];
        } catch (\Throwable $e) {
            error_log('FacilityPaymentService startCheckout: ' . $e->getMessage());
            $this->db->update('facility_bookings', $bookingId, [
                'payment_status' => 'failed',
                'status' => 'cancelled',
            ]);
            return ['success' => false, 'message' => 'Could not start payment. Please try again.'];
        }
    }

    /**
     * After redirect or webhook: mark authorization hold active.
     */
    public function finalizeCheckoutFromSession(string $sessionId): array
    {
        if (!$this->facilityPaymentsEnabled()) {
            return ['success' => false, 'message' => 'Payments not configured'];
        }

        $booking = $this->db->queryOne(
            "SELECT * FROM facility_bookings WHERE stripe_checkout_session_id = :sid",
            ['sid' => $sessionId]
        );
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found for this session'];
        }

        if (($booking['payment_status'] ?? '') === 'authorized') {
            return [
                'success' => true,
                'booking' => $this->bookingService->getByIdForOrg((int) $booking['id'], (int) $booking['organization_id']),
                'already_finalized' => true,
            ];
        }

        $orgId = (int) $booking['organization_id'];
        $stripe = $this->getStripeServiceForOrganization($orgId);
        if (!$stripe) {
            return ['success' => false, 'message' => 'Stripe not configured'];
        }

        try {
            $session = $stripe->retrieveCheckoutSession($sessionId);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not verify payment session'];
        }

        $piId = self::normalizeStripeId($session->payment_intent ?? null);
        if (!$piId) {
            return ['success' => false, 'message' => 'No payment on session'];
        }

        $pi = is_object($session->payment_intent) && isset($session->payment_intent->status)
            ? $session->payment_intent
            : $stripe->getPaymentIntent($piId);

        $piStatus = (string) ($pi->status ?? '');
        if (!in_array($piStatus, ['requires_capture', 'succeeded'], true)) {
            if (($booking['payment_status'] ?? '') === 'awaiting_checkout') {
                $this->db->update('facility_bookings', (int) $booking['id'], [
                    'payment_status' => 'failed',
                    'stripe_payment_intent_id' => $piId,
                ]);
            }
            return ['success' => false, 'message' => 'Payment was not authorized', 'pi_status' => $piStatus];
        }

        $paymentStatus = $piStatus === 'succeeded' ? 'captured' : 'authorized';
        $update = [
            'stripe_payment_intent_id' => $piId,
            'payment_status' => $paymentStatus,
            'status' => 'pending',
        ];
        if ($paymentStatus === 'authorized') {
            $update['payment_authorized_at'] = date('Y-m-d H:i:s');
        } else {
            $update['payment_captured_at'] = date('Y-m-d H:i:s');
        }
        $this->db->update('facility_bookings', (int) $booking['id'], $update);

        $full = $this->bookingService->getByIdForOrg((int) $booking['id'], $orgId);
        $this->sendPostAuthorizationEmails($full, $orgId, ($booking['booked_via'] ?? '') === 'guest');

        return ['success' => true, 'booking' => $full];
    }

    /**
     * @param object|\Stripe\Checkout\Session $session
     */
    public function handleCheckoutSessionCompleted($session): array
    {
        $meta = $session->metadata ?? null;
        if (!$meta || (($meta->checkout_type ?? '') !== 'facility_booking')) {
            return ['success' => false, 'message' => 'Not a facility booking session'];
        }
        $sessionId = $session->id ?? '';
        if ($sessionId === '') {
            return ['success' => false, 'message' => 'Missing session id'];
        }
        return $this->finalizeCheckoutFromSession($sessionId);
    }

    public function captureForBooking(int $bookingId, int $organizationId): array
    {
        $booking = $this->bookingService->getByIdForOrg($bookingId, $organizationId);
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }
        $ps = $booking['payment_status'] ?? 'not_required';
        if ($ps === 'captured' || $ps === 'not_required') {
            return ['success' => true, 'booking' => $booking, 'skipped' => true];
        }
        if ($ps !== 'authorized') {
            return ['success' => false, 'message' => 'No authorized payment to capture.'];
        }
        $piId = trim((string) ($booking['stripe_payment_intent_id'] ?? ''));
        if ($piId === '') {
            return ['success' => false, 'message' => 'Missing payment reference.'];
        }

        $stripe = $this->getStripeServiceForOrganization($organizationId);
        if (!$stripe) {
            return ['success' => false, 'message' => 'Stripe not configured'];
        }

        try {
            $stripe->capturePaymentIntent($piId);
            $this->db->update('facility_bookings', $bookingId, [
                'payment_status' => 'captured',
                'payment_captured_at' => date('Y-m-d H:i:s'),
            ]);
            return ['success' => true, 'booking' => $this->bookingService->getByIdForOrg($bookingId, $organizationId)];
        } catch (\Throwable $e) {
            error_log('FacilityPaymentService capture: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Payment capture failed: ' . $e->getMessage()];
        }
    }

    public function releaseForBooking(int $bookingId, int $organizationId, string $reason = 'requested'): array
    {
        $booking = $this->bookingService->getByIdForOrg($bookingId, $organizationId);
        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }
        $ps = $booking['payment_status'] ?? 'not_required';
        if (in_array($ps, ['not_required', 'released', 'failed', 'awaiting_checkout'], true)) {
            if ($ps === 'awaiting_checkout') {
                $this->db->update('facility_bookings', $bookingId, [
                    'payment_status' => 'released',
                    'payment_released_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return ['success' => true, 'booking' => $this->bookingService->getByIdForOrg($bookingId, $organizationId), 'skipped' => true];
        }

        $piId = trim((string) ($booking['stripe_payment_intent_id'] ?? ''));
        $stripe = $this->getStripeServiceForOrganization($organizationId);

        if ($piId !== '' && $stripe) {
            try {
                $pi = $stripe->getPaymentIntent($piId);
                $status = (string) ($pi->status ?? '');
                if ($status === 'requires_capture') {
                    $stripe->cancelPaymentIntent($piId, 'requested_by_customer');
                } elseif ($status === 'succeeded') {
                    $stripe->refundPayment($piId);
                }
            } catch (\Throwable $e) {
                error_log('FacilityPaymentService release: ' . $e->getMessage());
            }
        }

        $this->db->update('facility_bookings', $bookingId, [
            'payment_status' => 'released',
            'payment_released_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'booking' => $this->bookingService->getByIdForOrg($bookingId, $organizationId)];
    }

    /**
     * Expire holds older than HOLD_DAYS; cancel booking and email requester.
     */
    public function processExpiredHolds(int $organizationId = 0): array
    {
        if (!$this->facilityPaymentsEnabled()) {
            return ['success' => true, 'processed' => 0];
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::HOLD_DAYS . ' days'));
        $sql = "SELECT b.* FROM facility_bookings b
                WHERE b.status = 'pending'
                  AND b.payment_status = 'authorized'
                  AND b.payment_authorized_at IS NOT NULL
                  AND b.payment_authorized_at < :cutoff";
        $params = ['cutoff' => $cutoff];
        if ($organizationId > 0) {
            $sql .= ' AND b.organization_id = :org';
            $params['org'] = $organizationId;
        }
        $rows = $this->db->query($sql, $params);
        $count = 0;
        $emailSvc = new FacilityEmailService($this->config);

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $orgId = (int) $row['organization_id'];
            $this->releaseForBooking($id, $orgId, 'abandoned');
            $this->db->update('facility_bookings', $id, ['status' => 'cancelled']);
            $full = $this->bookingService->getByIdForOrg($id, $orgId);
            if ($full) {
                $emailSvc->sendHoldExpired($full, $orgId);
            }
            $count++;
        }

        return ['success' => true, 'processed' => $count];
    }

    /**
     * Reconcile awaiting_checkout facility bookings against Stripe (missed webhooks).
     */
    public function reconcilePendingBookingsForFacility(int $facilityId): array
    {
        $facilityId = (int) $facilityId;
        if ($facilityId <= 0) {
            return ['success' => false, 'message' => 'Invalid facility', 'updated' => 0, 'skipped_unpaid_session' => 0, 'errors' => []];
        }
        if (!$this->facilityPaymentsEnabled()) {
            return ['success' => true, 'updated' => 0, 'skipped_unpaid_session' => 0, 'errors' => []];
        }

        $rows = $this->db->query(
            "SELECT b.id, b.stripe_checkout_session_id
             FROM facility_bookings b
             WHERE b.facility_id = ?
               AND b.payment_status = 'awaiting_checkout'
               AND b.stripe_checkout_session_id IS NOT NULL
               AND TRIM(b.stripe_checkout_session_id) <> ''",
            [$facilityId]
        );

        return $this->reconcilePendingBookingRows($rows);
    }

    public function reconcilePendingBookingsForOrganization(int $organizationId): array
    {
        $organizationId = (int) $organizationId;
        if ($organizationId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid organization',
                'facilities_processed' => 0,
                'updated' => 0,
                'skipped_unpaid_session' => 0,
                'errors' => [],
            ];
        }
        if (!$this->facilityPaymentsEnabled()) {
            return ['success' => true, 'facilities_processed' => 0, 'updated' => 0, 'skipped_unpaid_session' => 0, 'errors' => []];
        }

        $facilityRows = $this->db->query(
            'SELECT DISTINCT b.facility_id
             FROM facility_bookings b
             WHERE b.organization_id = ?
               AND b.payment_status = ?
               AND b.stripe_checkout_session_id IS NOT NULL
               AND TRIM(b.stripe_checkout_session_id) <> ?',
            [$organizationId, 'awaiting_checkout', '']
        );

        return $this->aggregateReconcileAcrossFacilityIds($facilityRows);
    }

    public function reconcilePendingBookingsGlobally(): array
    {
        if (!$this->facilityPaymentsEnabled()) {
            return ['success' => true, 'facilities_processed' => 0, 'updated' => 0, 'skipped_unpaid_session' => 0, 'errors' => []];
        }

        $facilityRows = $this->db->query(
            "SELECT DISTINCT b.facility_id
             FROM facility_bookings b
             WHERE b.payment_status = 'awaiting_checkout'
               AND b.stripe_checkout_session_id IS NOT NULL
               AND TRIM(b.stripe_checkout_session_id) <> ''"
        );

        return $this->aggregateReconcileAcrossFacilityIds($facilityRows);
    }

    /**
     * @param array<int, array<string, mixed>> $facilityRows
     */
    private function aggregateReconcileAcrossFacilityIds(array $facilityRows): array
    {
        $facilitiesProcessed = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $allErrors = [];
        foreach ($facilityRows as $row) {
            $fid = (int) ($row['facility_id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            $facilitiesProcessed++;
            $r = $this->reconcilePendingBookingsForFacility($fid);
            $totalUpdated += (int) ($r['updated'] ?? 0);
            $totalSkipped += (int) ($r['skipped_unpaid_session'] ?? 0);
            if (!empty($r['errors']) && is_array($r['errors'])) {
                foreach ($r['errors'] as $err) {
                    $allErrors[] = 'facility ' . $fid . ': ' . $err;
                }
            }
        }

        return [
            'success' => true,
            'facilities_processed' => $facilitiesProcessed,
            'updated' => $totalUpdated,
            'skipped_unpaid_session' => $totalSkipped,
            'errors' => $allErrors,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function reconcilePendingBookingRows(array $rows): array
    {
        $updated = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $row) {
            $sid = trim((string) ($row['stripe_checkout_session_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $res = $this->finalizeCheckoutFromSession($sid);
            if (!empty($res['success'])) {
                if (!empty($res['already_finalized'])) {
                    continue;
                }
                $updated++;
            } elseif (($res['pi_status'] ?? '') !== '' || str_contains((string) ($res['message'] ?? ''), 'not authorized')) {
                $skipped++;
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

    private function sendPostAuthorizationEmails(array $booking, int $organizationId, bool $isGuest): void
    {
        $emailSvc = new FacilityEmailService($this->config);
        $emailSvc->notifyAdminsPending($booking, $organizationId);
        if ($isGuest) {
            $emailSvc->sendGuestPendingConfirmation($booking, $organizationId, empty($booking['password_hash']));
        } else {
            $emailSvc->sendPendingConfirmation($booking, $organizationId);
        }
    }

    private function normalizeDatetime($value): string
    {
        $ts = strtotime((string) $value);
        return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
    }

    /**
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
}
