<?php
/**
 * Printable Facility Booking & Food Safety Responsibility Waiver
 */
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\FacilityService;
use Headcount\Services\FacilityBookingService;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = (int) AuthMiddleware::getOrganizationId();
$userId = (int) AuthMiddleware::getUserId();

$db = Database::getInstance();
$userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
$userRole = $userData['role'] ?? 'admin';
$isCoordinator = ($userRole === 'coordinator');

if (!isset($adminBase)) {
    require_once __DIR__ . '/includes/layout-vars.php';
}

$id = (int) get('id', 0);
$bookSvc = new FacilityBookingService();
$facSvc = new FacilityService();
$booking = $id > 0 ? $bookSvc->getByIdForOrg($id, $organizationId) : null;
if (!$booking) {
    http_response_code(404);
    echo 'Booking not found.';
    exit;
}
if ($isCoordinator && !$facSvc->userCanManageFacility($userId, $organizationId, (int) ($booking['facility_id'] ?? 0), $userRole)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$org = null;
try {
    $org = $db->queryOne(
        'SELECT name, facility_waiver_enabled, facility_waiver_checkbox_label, facility_waiver_full_text FROM organizations WHERE id = :id',
        ['id' => $organizationId]
    );
} catch (\Throwable $e) {
    $org = $db->queryOne('SELECT name FROM organizations WHERE id = :id', ['id' => $organizationId]);
}
$waiverSettings = headcount_org_facility_waiver_settings(is_array($org) ? $org : null);
$orgName = trim((string) ($org['name'] ?? 'Organization'));

$eventDate = !empty($booking['start_datetime']) ? date('F j, Y', strtotime($booking['start_datetime'])) : '';
$signedAt = !empty($booking['waiver_accepted_at']) ? date('F j, Y', strtotime($booking['waiver_accepted_at'])) : '';
$reviewerName = trim(($booking['reviewer_first_name'] ?? '') . ' ' . ($booking['reviewer_last_name'] ?? ''));
$approvedAt = !empty($booking['reviewed_at']) && ($booking['status'] ?? '') === 'approved'
    ? date('F j, Y', strtotime($booking['reviewed_at']))
    : '';
$setup = (string) ($booking['waiver_setup_location'] ?? '');
$hasWaiver = !empty($booking['waiver_accepted_at']);
$queueUrl = rtrim($adminBase, '/') . '/?page=facility-bookings';

$mark = static function (bool $on): string {
    return $on ? '[X]' : '[ ]';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facility Booking Waiver #<?= (int) $booking['id'] ?></title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: "Segoe UI", system-ui, sans-serif; color: #1a1a1a; background: #e8e8e8; }
        .toolbar { display: flex; gap: 0.75rem; justify-content: center; padding: 1rem; background: #fff; border-bottom: 1px solid #ddd; }
        .toolbar a, .toolbar button { font: inherit; padding: 0.5rem 1rem; border-radius: 0.5rem; border: 1px solid #ccc; background: #fff; cursor: pointer; text-decoration: none; color: #111; }
        .toolbar button.primary { background: #1e3a5f; color: #fff; border-color: #1e3a5f; }
        .sheet { max-width: 800px; margin: 1.5rem auto; background: #fff; padding: 2.25rem 2.5rem; box-shadow: 0 1px 8px rgba(0,0,0,.08); }
        h1 { margin: 0 0 0.75rem; text-align: center; font-size: 1.15rem; letter-spacing: .04em; color: #1e3a5f; }
        .rule { border: 0; border-top: 1px solid #1e3a5f; margin: 0 0 1.25rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem 1.5rem; }
        .field label { display: block; font-weight: 700; font-size: 0.92rem; }
        .line { border-bottom: 1px solid #333; min-height: 1.35rem; margin-top: 0.15rem; padding-bottom: 0.15rem; }
        .setup { margin: 1.25rem 0; }
        .setup p { font-weight: 700; margin: 0 0 0.4rem; }
        .setup div { margin: 0.25rem 0; font-family: ui-monospace, Consolas, monospace; }
        .legal h2 { font-size: 1rem; margin: 1.25rem 0 0.5rem; }
        .legal p { font-size: 0.92rem; line-height: 1.5; margin: 0 0 0.85rem; }
        .sigs { margin-top: 1.75rem; display: grid; gap: 1.1rem; }
        .sig-row { display: grid; grid-template-columns: 1fr 10rem; gap: 1.25rem; }
        .muted { color: #666; font-size: 0.85rem; text-align: center; margin-top: 1.5rem; }
        .notice { background: #fff7ed; border: 1px solid #fed7aa; padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; box-shadow: none; max-width: none; padding: 0; }
            .notice { display: none; }
        }
        @media (max-width: 640px) {
            .grid, .sig-row { grid-template-columns: 1fr; }
            .sheet { margin: 0; padding: 1.25rem; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="<?= e($queueUrl) ?>">Back to bookings</a>
        <button type="button" class="primary" onclick="window.print()">Print</button>
    </div>
    <article class="sheet">
        <?php if (!$hasWaiver): ?>
        <div class="notice">No signed waiver is on file for this booking (staff-created bookings skip the waiver).</div>
        <?php endif; ?>
        <h1>FACILITY BOOKING &amp; FOOD SAFETY RESPONSIBILITY WAIVER</h1>
        <hr class="rule">
        <div class="grid">
            <div class="field">
                <label>Vendor / Event Name:</label>
                <div class="line"><?= e($booking['title'] ?? '') ?></div>
            </div>
            <div class="field">
                <label>Date of Event:</label>
                <div class="line"><?= e($eventDate) ?></div>
            </div>
            <div class="field">
                <label>Contact Person:</label>
                <div class="line"><?= e($booking['waiver_contact_person'] ?? '') ?></div>
            </div>
            <div class="field">
                <label>Phone Number:</label>
                <div class="line"><?= e($booking['waiver_phone'] ?? '') ?></div>
            </div>
        </div>
        <div class="setup">
            <p>Setup Location:</p>
            <div><?= $mark($setup === 'indoor_foyer') ?> Indoor Entrance / Foyer</div>
            <div><?= $mark($setup === 'outdoor_canopy') ?> Outdoor Canopy / Entrance</div>
            <div><?= $mark($setup === 'other') ?> Other Space: <span style="border-bottom:1px solid #333; display:inline-block; min-width:14rem;"><?= e($setup === 'other' ? (string) ($booking['waiver_setup_other'] ?? '') : '') ?></span></div>
        </div>
        <div class="legal">
            <h2>Undertaking &amp; Release of Liability:</h2>
            <?php
            $paras = preg_split("/\n\s*\n/", trim($waiverSettings['full_text']));
            foreach ($paras as $para) {
                $para = trim($para);
                if ($para === '' || preg_match('/^undertaking/i', $para)) {
                    continue;
                }
                echo '<p>' . nl2br(e($para)) . '</p>';
            }
            ?>
        </div>
        <div class="sigs">
            <div class="sig-row">
                <div class="field">
                    <label>Applicant Signature:</label>
                    <div class="line"><?= e($booking['waiver_applicant_signature'] ?? '') ?></div>
                </div>
                <div class="field">
                    <label>Date:</label>
                    <div class="line"><?= e($signedAt) ?></div>
                </div>
            </div>
            <div class="sig-row">
                <div class="field">
                    <label>Management Approval:</label>
                    <div class="line"><?= e($reviewerName) ?></div>
                </div>
                <div class="field">
                    <label>Date:</label>
                    <div class="line"><?= e($approvedAt) ?></div>
                </div>
            </div>
        </div>
        <p class="muted"><?= e($orgName) ?> · Booking #<?= (int) $booking['id'] ?> · <?= e($booking['facility_name'] ?? '') ?></p>
    </article>
</body>
</html>
