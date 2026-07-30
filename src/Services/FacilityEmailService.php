<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/**
 * Facility booking email notifications.
 */
class FacilityEmailService extends PortalEmailService
{
    private $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        parent::__construct($this->resolveOrgSmtp($config));
    }

    private function resolveOrgSmtp(array $config)
    {
        if (!empty($config['smtp2go'])) {
            return $config['smtp2go'];
        }
        return [];
    }

    public function forOrganization($organizationId)
    {
        $db = Database::getInstance();
        $org = $db->queryOne(
            "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
            [(int) $organizationId]
        );
        if (!$org || empty($org['smtp_from_email'])) {
            return !empty($this->config['smtp2go']) ? $this : null;
        }
        $apiKey = null;
        if (!empty($org['smtp_api_key'])) {
            $apiKey = base64_decode($org['smtp_api_key'], true);
        }
        if (($apiKey === false || $apiKey === '') && !empty($org['smtp_api_key_encrypted']) && !empty($this->config['security']['encryption_key'])) {
            $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $this->config['security']['encryption_key']);
        }
        if (empty($apiKey) && !empty($this->config['smtp2go'])) {
            return $this;
        }
        if (empty($apiKey)) {
            return null;
        }
        return new self(array_merge($this->config, [
            'smtp2go' => [
                'api_key' => $apiKey,
                'from_email' => $org['smtp_from_email'],
                'from_name' => $org['smtp_from_name'] ?? null,
                'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
            ],
        ]));
    }

    private function memberUpgradeBlock($email, $needsAccount)
    {
        if (!$needsAccount) {
            return '<p style="margin-top:16px;color:#64748b;font-size:14px;">Log in to your member portal to view and manage your bookings.</p>';
        }
        $url = $this->registerUrl($email);
        return '
            <p style="margin-top:24px;padding:16px;background:#f0f9ff;border-radius:8px;">
                <strong>Want to manage this booking online?</strong><br>
                Complete your profile and become a member to view, change, or cancel bookings yourself:<br>
                <a href="' . htmlspecialchars($url) . '" style="display:inline-block;margin-top:8px;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Complete your profile</a>
            </p>';
    }

    private function loadAppConfig(): array
    {
        if (!empty($this->config['app']) || !empty($this->config['portal'])) {
            return $this->config;
        }
        $configFile = __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        return is_array($this->config) ? $this->config : [];
    }

    private function registerUrl($email)
    {
        $base = headcount_portal_base_url($this->loadAppConfig());
        return $base . '/portal/register.php?email=' . urlencode($email);
    }

    private function portalFacilitiesUrl(): string
    {
        return headcount_portal_base_url($this->loadAppConfig()) . '/portal/facilities.php';
    }

    private function adminBookingQueueUrl(int $facilityId): string
    {
        $qs = 'page=facility-bookings&status=pending';
        if ($facilityId > 0) {
            $qs .= '&facility_id=' . $facilityId;
        }

        return headcount_app_base_url($this->loadAppConfig()) . '/admin/index.php?' . $qs;
    }

    private function portalBookingsBlock(): string
    {
        $url = $this->portalFacilitiesUrl();

        return '<p style="margin-top:16px;"><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">View your bookings</a></p>';
    }

    private function paymentNote(array $booking): string
    {
        $ps = $booking['payment_status'] ?? 'not_required';
        if ($ps === 'not_required') {
            return '';
        }
        if ($ps === 'authorized') {
            $amt = !empty($booking['total_amount']) ? ' ($' . number_format((float) $booking['total_amount'], 2) . ' authorized)' : '';
            return '<p style="margin-top:12px;padding:12px;background:#f0f9ff;border-radius:8px;font-size:14px;"><strong>Payment:</strong> A temporary card authorization is on file' . htmlspecialchars($amt) . '. You are only charged if this request is approved. If declined, the hold is released automatically.</p>';
        }
        if ($ps === 'captured') {
            $amt = !empty($booking['total_amount']) ? '$' . number_format((float) $booking['total_amount'], 2) : '';
            return '<p style="margin-top:12px;padding:12px;background:#ecfdf5;border-radius:8px;font-size:14px;"><strong>Payment:</strong> ' . htmlspecialchars($amt) . ' has been charged.</p>';
        }
        if ($ps === 'released') {
            return '<p style="margin-top:12px;padding:12px;background:#f8fafc;border-radius:8px;font-size:14px;"><strong>Payment:</strong> Any card authorization for this request has been released. You will not be charged.</p>';
        }
        return '';
    }

    private function formatBookingDetails(array $booking)
    {
        $start = date('F j, Y g:i A', strtotime($booking['start_datetime']));
        $end = date('g:i A', strtotime($booking['end_datetime']));
        $purpose = trim((string) ($booking['purpose'] ?? ''));
        $purposeBlock = $purpose !== ''
            ? '<p><strong>Event / purpose:</strong> ' . nl2br(htmlspecialchars($purpose)) . '</p>'
            : '';
        return '
            <h3>' . htmlspecialchars($booking['title'] ?? 'Facility booking') . '</h3>
            ' . $purposeBlock . '
            <p><strong>Facility:</strong> ' . htmlspecialchars($booking['facility_name'] ?? '') . '</p>
            <p><strong>When:</strong> ' . htmlspecialchars($start) . ' – ' . htmlspecialchars($end) . '</p>
            <p><strong>Location:</strong> ' . htmlspecialchars($booking['facility_location'] ?? '') . '</p>'
            . $this->paymentNote($booking);
    }

    public function sendPendingConfirmation(array $booking, $organizationId)
    {
        $svc = $this->forOrganization($organizationId);
        if (!$svc) {
            return ['success' => false];
        }
        $needsAccount = empty($booking['password_hash']);
        $body = '
            <h2>Booking request received</h2>
            <p>Hello ' . htmlspecialchars($booking['first_name'] ?? '') . ',</p>
            <p>Your facility booking request has been submitted and is <strong>pending approval</strong>.</p>
            ' . $this->formatBookingDetails($booking) . '
            <p>We will email you once your request has been reviewed.</p>
            ' . $this->memberUpgradeBlock($booking['email'] ?? '', $needsAccount);

        return $svc->sendEmail(
            $booking['email'],
            'Facility booking request received',
            $body,
            $organizationId,
            ['email_type' => 'facility_booking_pending', 'user_id' => $booking['booked_by_user_id'] ?? null]
        );
    }

    public function sendGuestPendingConfirmation(array $booking, $organizationId, $isNewUser = false)
    {
        $svc = $this->forOrganization($organizationId);
        if (!$svc) {
            return ['success' => false];
        }
        $needsAccount = $isNewUser || empty($booking['password_hash']);
        $body = '
            <h2>Booking request received</h2>
            <p>Hello ' . htmlspecialchars($booking['first_name'] ?? '') . ',</p>
            <p>Your facility booking request has been submitted and is <strong>pending approval</strong>.</p>
            ' . $this->formatBookingDetails($booking) . '
            <p>You can book without an account, but to manage this booking online you will need to become a member.</p>
            ' . $this->memberUpgradeBlock($booking['email'] ?? '', $needsAccount);

        return $svc->sendEmail(
            $booking['email'],
            'Facility booking request received',
            $body,
            $organizationId,
            ['email_type' => 'facility_booking_guest_pending', 'user_id' => $booking['booked_by_user_id'] ?? null]
        );
    }

    public function sendApproved(array $booking, $organizationId)
    {
        $svc = $this->forOrganization($organizationId);
        if (!$svc) {
            return ['success' => false];
        }
        $needsAccount = empty($booking['password_hash']);
        $body = '
            <h2>Booking approved</h2>
            <p>Hello ' . htmlspecialchars($booking['first_name'] ?? '') . ',</p>
            <p>Your facility booking has been <strong>approved</strong>.</p>
            ' . $this->formatBookingDetails($booking) . '
            ' . $this->portalBookingsBlock() . '
            ' . $this->memberUpgradeBlock($booking['email'] ?? '', $needsAccount);

        return $svc->sendEmail(
            $booking['email'],
            'Facility booking approved',
            $body,
            $organizationId,
            ['email_type' => 'facility_booking_approved', 'user_id' => $booking['booked_by_user_id'] ?? null]
        );
    }

    public function sendRejected(array $booking, $organizationId, $reason = '')
    {
        $svc = $this->forOrganization($organizationId);
        if (!$svc) {
            return ['success' => false];
        }
        $needsAccount = empty($booking['password_hash']);
        $body = '
            <h2>Booking not approved</h2>
            <p>Hello ' . htmlspecialchars($booking['first_name'] ?? '') . ',</p>
            <p>Unfortunately your facility booking request was not approved.</p>
            ' . $this->formatBookingDetails($booking) . '
            ' . ($reason ? '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>' : '') . '
            ' . $this->portalBookingsBlock() . '
            ' . $this->memberUpgradeBlock($booking['email'] ?? '', $needsAccount);

        return $svc->sendEmail(
            $booking['email'],
            'Facility booking update',
            $body,
            $organizationId,
            ['email_type' => 'facility_booking_rejected', 'user_id' => $booking['booked_by_user_id'] ?? null]
        );
    }

    public function notifyAdminsPending(array $booking, $organizationId)
    {
        $svc = $this->forOrganization($organizationId);
        if (!$svc) {
            return ['success' => false];
        }

        $facilityId = (int) ($booking['facility_id'] ?? 0);
        $facSvc = new FacilityService();
        $recipients = [];
        if ($facSvc->managersTableExists() && $facilityId > 0) {
            $managers = $facSvc->getManagers($facilityId, (int) $organizationId);
            foreach ($managers as $mgr) {
                if (!empty($mgr['email'])) {
                    $recipients[] = ['email' => $mgr['email'], 'first_name' => $mgr['first_name'] ?? ''];
                }
            }
        }
        if ($recipients === []) {
            $db = Database::getInstance();
            $recipients = $db->query(
                "SELECT email, first_name FROM users WHERE organization_id = :org AND role IN ('admin','coordinator') AND status = 'active' AND email IS NOT NULL",
                ['org' => (int) $organizationId]
            ) ?: [];
        }
        if ($recipients === []) {
            return ['success' => false];
        }

        $reviewUrl = $this->adminBookingQueueUrl($facilityId);
        $body = '
            <h2>New facility booking request</h2>
            <p>A new booking is pending your approval.</p>
            ' . $this->formatBookingDetails($booking) . '
            <p><strong>Requested by:</strong> ' . htmlspecialchars(trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))) . ' (' . htmlspecialchars($booking['email'] ?? '') . ')</p>
            <p><strong>Via:</strong> ' . htmlspecialchars($booking['booked_via'] ?? 'portal') . '</p>
            <p style="margin-top:20px;"><a href="' . htmlspecialchars($reviewUrl) . '" style="display:inline-block;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Review booking</a></p>';

        foreach ($recipients as $admin) {
            $svc->sendEmail(
                $admin['email'],
                'Pending facility booking: ' . ($booking['facility_name'] ?? ''),
                $body,
                $organizationId,
                ['email_type' => 'facility_booking_admin_notify']
            );
        }

        return ['success' => true];
    }

    public function sendHoldExpired(array $booking, $organizationId)
    {
        $svc = $this->forOrganization($organizationId);
        if (!$svc) {
            return ['success' => false];
        }
        $body = '
            <h2>Booking request expired</h2>
            <p>Hello ' . htmlspecialchars($booking['first_name'] ?? '') . ',</p>
            <p>Your facility booking request was automatically cancelled because the payment authorization expired before staff could review it (authorizations typically last about 7 days).</p>
            ' . $this->formatBookingDetails($booking) . '
            <p>You may submit a new request if you still need the space.</p>
            ' . $this->memberUpgradeBlock($booking['email'] ?? '', empty($booking['password_hash']));

        return $svc->sendEmail(
            $booking['email'],
            'Facility booking authorization expired',
            $body,
            $organizationId,
            ['email_type' => 'facility_booking_hold_expired', 'user_id' => $booking['booked_by_user_id'] ?? null]
        );
    }
}
