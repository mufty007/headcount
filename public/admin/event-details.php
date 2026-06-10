<?php

/**
 * Admin Single Event Details Page
 * Tabs: Event details, RSVP report, Email actions
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\EventSeriesHelper;
use Headcount\Services\EventPeopleService;
use Headcount\Services\EventInviteService;

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

$hasParentEventId = headcount_db_has_column($db, 'events', 'parent_event_id');
$hasRsvpsTable = headcount_db_table_exists($db, 'rsvps');
$hasAttendanceTable = headcount_db_table_exists($db, 'attendance');
$rsvpHasGuestCount = false;
if ($hasRsvpsTable) {
    try {
        $rsvpColsEv = $db->query('SHOW COLUMNS FROM rsvps');
        $rsvpHasGuestCount = in_array('guest_count', array_column($rsvpColsEv, 'Field'), true);
    } catch (\Exception $e) {
        $rsvpHasGuestCount = false;
    }
}
$hasCategoriesTable = headcount_db_table_exists($db, 'categories');
$hasEventCategoriesTable = headcount_db_table_exists($db, 'event_categories');

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
$adminBase = $basePath . '/admin';

$userId = AuthMiddleware::getUserId();
$userData = $db->queryOne("SELECT first_name, last_name, email, role FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin'
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local',
    'role' => $_SESSION['role'] ?? 'admin'
];

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$eventId) {
    Utilities::redirect($adminBase . '/?page=events');
    exit;
}

$event = $db->queryOne(
    "SELECT e.* FROM events e WHERE e.id = :id AND e.organization_id = :org_id",
    ['id' => $eventId, 'org_id' => $organizationId]
);

if (!$event) {
    Utilities::redirect($adminBase . '/?page=events');
    exit;
}

headcount_decode_html_entities_in_event_row($event);

// Opening the series root URL should land on the preferred upcoming session (share link, RSVP scope).
$skipSessionLanding = (isset($_GET['session']) && (string) $_GET['session'] === 'root')
    || (isset($_GET['no_session_redirect']) && (string) $_GET['no_session_redirect'] === '1');
if (!$skipSessionLanding && $hasParentEventId) {
    try {
        $rootForLanding = EventSeriesHelper::getSeriesRootId($db, $eventId);
    } catch (\Throwable $t) {
        $rootForLanding = null;
    }
    if ($rootForLanding !== null && $rootForLanding === $eventId) {
        try {
            $landRow = EventSeriesHelper::pickPreferredSeriesSessionForDetailsLanding(
                $db,
                $rootForLanding,
                (int) $organizationId
            );
        } catch (\Throwable $t) {
            $landRow = null;
        }
        if (is_array($landRow) && !empty($landRow['id']) && (int) $landRow['id'] !== $eventId) {
            Utilities::redirect($adminBase . '/?page=event-details&id=' . (int) $landRow['id']);
            exit;
        }
    }
}

// Resolve RSVP source event and parent IDs for attendance counting.
try {
    $rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
} catch (\Exception $e) {
    error_log('event-details: getRsvpSourceEventId failed for event ' . $eventId . ': ' . $e->getMessage());
    $rsvpSourceEventId = $eventId;
}
$parentId = $hasParentEventId ? (int) ($event['parent_event_id'] ?? 0) : 0;
$eventDateYmd = substr((string) ($event['event_date'] ?? ''), 0, 10);

// Fetch RSVP + check-in counts. Wrapped in try-catch so a schema mismatch
// (e.g. missing column) logs an error and degrades gracefully instead of 500-ing.
try {
    $rsvpRegistrantExpr = '0';
    $rsvpHeadExpr = '0';
    $checkinExpr = '0';
    if ($hasRsvpsTable) {
        $rsvpRegistrantExpr = '(SELECT COUNT(*) FROM rsvps WHERE event_id = :rsrc AND status = \'yes\')';
        $rsvpHeadExpr = $rsvpHasGuestCount
            ? '(SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) FROM rsvps WHERE event_id = :rsrc AND status = \'yes\')'
            : '(SELECT COUNT(*) FROM rsvps WHERE event_id = :rsrc AND status = \'yes\')';
    }
    if ($hasAttendanceTable) {
        $checkinExpr = '(SELECT COUNT(DISTINCT a.id) FROM attendance a
            WHERE a.checked_in_at IS NOT NULL
            AND DATE(a.checked_in_at) = :ed
            AND (a.event_id = :eid OR (:pid > 0 AND a.event_id = :pid)))';
    }
    $counts = $db->queryOne(
        "SELECT
            {$rsvpRegistrantExpr} AS rsvp_registrant_count,
            {$rsvpHeadExpr} AS rsvp_head_count,
            {$checkinExpr} AS checkin_count",
        ['rsrc' => $rsvpSourceEventId, 'ed' => $eventDateYmd, 'eid' => $eventId, 'pid' => $parentId]
    );
    if (!is_array($counts)) {
        $counts = [];
    }
} catch (\Exception $e) {
    error_log('event-details: counts query failed for event ' . $eventId . ': ' . $e->getMessage());
    $counts = [];
}
$event['rsvp_registrant_count'] = (int) ($counts['rsvp_registrant_count'] ?? 0);
$event['rsvp_head_count']        = (int) ($counts['rsvp_head_count'] ?? 0);
$event['rsvp_count']             = $event['rsvp_head_count'];
$event['checkin_count']          = (int) ($counts['checkin_count'] ?? 0);

// Event categories
$eventCategoriesMap = [];
if ($hasCategoriesTable && $hasEventCategoriesTable) {
    try {
        $eventCats = $db->query(
            "SELECT c.id, c.name, c.color FROM event_categories ec INNER JOIN categories c ON ec.category_id = c.id WHERE ec.event_id = :event_id",
            ['event_id' => $eventId]
        );
        $eventCategoriesMap = $eventCats;
        foreach ($eventCategoriesMap as &$ec) {
            if (!empty($ec['name'])) {
                $ec['name'] = Utilities::decodeHtmlEntities($ec['name']);
            }
        }
        unset($ec);
    } catch (\Exception $e) {
        // fallback
    }
}

$seriesRootId = EventSeriesHelper::getSeriesRootId($db, $eventId);
$seriesSessions = [];
if ($hasParentEventId && $seriesRootId !== null) {
    try {
        $seriesSessions = $db->query(
            "SELECT e.id, e.event_date, e.start_time, e.end_time, e.status, e.parent_event_id
             FROM events e
             WHERE e.organization_id = ?
               AND (e.id = ? OR e.parent_event_id = ?)
             ORDER BY e.event_date ASC, COALESCE(e.start_time, '00:00:00') ASC, e.id ASC",
            [$organizationId, $seriesRootId, $seriesRootId]
        );
    } catch (\Exception $e) {
        $seriesSessions = [];
    }
}


$adminSpeakers = [];
$adminOrganisers = [];
try {
    $eventPeopleSvcAdmin = new EventPeopleService();
    if ($eventPeopleSvcAdmin->tableExists()) {
        $srcPeople = EventPeopleService::peopleStorageEventId($event);
        foreach ($eventPeopleSvcAdmin->listForEventId($srcPeople) as $epr) {
            $ip = isset($epr['image_path']) ? trim((string) $epr['image_path']) : '';
            $iu = '';
            if ($ip !== '') {
                $iu = filter_var($ip, FILTER_VALIDATE_URL) ? $ip : hc_public_api_image_url($ip);
            }
            $item = [
                'name'      => (string) ($epr['display_name'] ?? ''),
                'title'     => isset($epr['title']) && $epr['title'] !== null ? (string) $epr['title'] : '',
                'image_url' => $iu,
            ];
            if (($epr['role'] ?? '') === 'organiser') {
                $adminOrganisers[] = $item;
            } else {
                $adminSpeakers[] = $item;
            }
        }
    }
} catch (\Exception $e) {
    error_log('event-details: EventPeopleService failed for event ' . $eventId . ': ' . $e->getMessage());
    $adminSpeakers   = [];
    $adminOrganisers = [];
}

$eventInviteSvcPage = new EventInviteService();
$hasEventInvitesTable = $eventInviteSvcPage->tableExists();
$hasVisibilityColumn = false;
try {
    $hasVisibilityColumn = headcount_db_has_column($db, 'events', 'visibility');
} catch (\Throwable $e) {
    $hasVisibilityColumn = false;
}
$initialInvitesForPage = [];
$inviteStorageIdDisplay = $eventId;
if ($hasEventInvitesTable) {
    $initialInvitesForPage = $eventInviteSvcPage->listInvitesForViewEvent($db, $organizationId, $eventId);
    foreach ($initialInvitesForPage as &$invRowForPage) {
        $invRowForPage['profile_incomplete'] = empty($invRowForPage['password_hash']);
        unset($invRowForPage['password_hash']);
    }
    unset($invRowForPage);
    $inviteStorageIdDisplay = EventInviteService::inviteStorageEventId($db, $eventId);
}
$eventVisibilityLabel = $hasVisibilityColumn ? strtolower(trim((string) ($event['visibility'] ?? 'public'))) : 'public';
if (!in_array($eventVisibilityLabel, ['public', 'internal', 'invite_only'], true)) {
    $eventVisibilityLabel = 'public';
}

$pageTitle = $event['title'] . ' - Event Details';
$currentPage = 'events';

$additionalCSS = [$basePath . '/public/css/modal.css'];

require __DIR__ . '/includes/header.php';

$apiBaseUrl = $basePath . '/public/api/events.php';
$apiBase = $basePath . '/public/api';
$isCoordinator = (isset($user['role']) && $user['role'] === 'coordinator');

$eventShareUrl = headcount_event_portal_url($config, (int)$eventId);
$eventShareQrSrc = $basePath . '/public/api/event-share-qr.php?id=' . (int)$eventId;
$eventShareQrDownloadHref = $basePath . '/public/api/event-share-qr.php?id=' . (int)$eventId . '&download=1';

$canManageInvites = !$isCoordinator;
$canCorrectCheckins = AuthMiddleware::canCorrectCheckins();
$eventDateYmdForUi = substr((string) ($event['event_date'] ?? ''), 0, 10);
$eventStartTimeForUi = !empty($event['start_time']) ? substr((string) $event['start_time'], 0, 8) : null;
$eventDetailsConfig = [
    'eventId' => (int)$eventId,
    'eventTitle' => $event['title'],
    'eventDate' => $eventDateYmdForUi,
    'eventStartTime' => $eventStartTimeForUi,
    'apiBaseUrl' => $apiBaseUrl,
    'apiBase' => $apiBase,
    'isCoordinator' => $isCoordinator,
    'canManageInvites' => $canManageInvites,
    'canCorrectCheckins' => $canCorrectCheckins,
    'eventShareUrl' => $eventShareUrl,
    'csrfToken' => CsrfMiddleware::getToken(),
    'hasEventInvitesTable' => $hasEventInvitesTable,
    'hasVisibilityColumn' => $hasVisibilityColumn,
    'eventVisibility' => $eventVisibilityLabel,
    'inviteStorageEventId' => (int) $inviteStorageIdDisplay,
    'initialInvites' => $initialInvitesForPage,
    'searchMembersUrl' => $basePath . '/public/api/search-members.php',
];
?>
<script type="application/json" id="event-details-config"><?= json_encode($eventDetailsConfig) ?></script>
<script>
function eventDetailsApp() {
    const el = document.getElementById('event-details-config');
    const config = el ? JSON.parse(el.textContent) : {};
    const eventId = config.eventId || 0;
    const eventTitle = config.eventTitle || '';
    const apiBaseUrl = config.apiBaseUrl || '';
    const apiBase = config.apiBase || '';
    const eventShareUrl = config.eventShareUrl || '';
    const csrfToken = config.csrfToken || '';
    const searchMembersUrl = config.searchMembersUrl || '';
    const canManageInvites = !!config.canManageInvites;
    const canCorrectCheckins = !!config.canCorrectCheckins;
    const eventDate = config.eventDate || '';
    const eventStartTime = config.eventStartTime || '';
    const hasEventInvitesTable = !!config.hasEventInvitesTable;
    const hasVisibilityColumn = !!config.hasVisibilityColumn;
    const eventVisibility = config.eventVisibility || 'public';
    const inviteStorageEventId = config.inviteStorageEventId || eventId;
    return {
        eventId,
        apiBase,
        eventShareUrl,
        canManageInvites,
        canCorrectCheckins,
        eventDate,
        eventStartTime,
        hasEventInvitesTable,
        hasVisibilityColumn,
        eventVisibility,
        inviteStorageEventId,
        invitesList: Array.isArray(config.initialInvites) ? config.initialInvites : [],
        inviteSearchQuery: '',
        inviteSearchResults: [],
        inviteSearchLoading: false,
        inviteSearchError: '',
        inviteSearchDone: false,
        inviteSaving: false,
        guestInviteForm: { email: '', first_name: '', last_name: '' },
        guestInviteSaving: false,
        guestInviteError: '',
        guestInviteSuccess: '',
        activeTab: 'details',
        rsvpReportSubTab: 'responses',
        rsvpList: [],
        eventQuestions: [],
        questionGroups: [],
        checkinList: [],
        loadingRsvps: false,
        loadingCheckins: false,
        rsvpSummary: null,
        showCorrectionModal: false,
        correctionSaving: false,
        correctionForm: {
            action: 'checkin',
            user_id: null,
            user_name: '',
            reason: '',
            checked_in_at_local: '',
            guests_checked_in: 0,
        },
        saving: false,
        emailLogs: [],
        emailLogsLoading: false,
        showEmailComposer: false,
        composerType: 'announcement',
        composerTemplates: [],
        composerTemplateId: '',
        composerLoadingTemplates: false,
        composerSending: false,
        composer: {
            subject: '',
            bodyHtml: ''
        },
        composeDefaults: {
            announcementSubject: 'Event Announcement: {event_name}',
            announcementBody: '<p>Hi {first_name},</p><p>We have an update for <strong>{event_name}</strong>.</p><p><strong>Date:</strong> {event_day}, {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {event_location}</p><p>See you there.</p>',
            reminderSubject: 'Reminder: {event_name} on {event_day}, {event_date}',
            reminderBody: '<p>Hi {first_name},</p><p>This is a reminder for <strong>{event_name}</strong>.</p><p><strong>Date:</strong> {event_day}, {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {event_location}</p><p>We look forward to seeing you.</p>'
        },
        get composerTitle() {
            return this.composerType === 'announcement' ? 'Compose announcement' : 'Compose reminder';
        },
        get composerAction() {
            return this.composerType === 'announcement' ? 'announce' : 'remind';
        },
        recordingCashFor: null,
        cashAmount: '',
        cashSaving: false,
        formatRsvpDate(iso) {
            if (!iso) return '\u2014';
            const d = new Date(iso);
            return isNaN(d.getTime()) ? iso : d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
        buildQuestionGroups() {
            const list = this.rsvpList || [];
            const configuredQuestions = this.eventQuestions || [];
            const groups = new Map();
            const order = [];
            for (const q of configuredQuestions) {
                const qid = q && q.id != null && q.id !== '' ? Number(q.id) : null;
                const key = (qid !== null && !Number.isNaN(qid)) ? ('id:' + qid) : ('t:' + String((q && q.question_text) || ''));
                if (!groups.has(key)) {
                    const sort = q && q.sort_order != null ? Number(q.sort_order) : 999999;
                    groups.set(key, {
                        key,
                        question_id: qid,
                        question_text: (q && q.question_text) || '',
                        question_sort_order: sort,
                        answers: []
                    });
                    order.push(key);
                }
            }
            for (const rsvp of list) {
                const name = ((rsvp.first_name || '') + ' ' + (rsvp.last_name || '')).trim() || '\u2014';
                for (const qa of (rsvp.question_answers || [])) {
                    const qid = qa.question_id != null && qa.question_id !== '' ? Number(qa.question_id) : null;
                    const key = (qid !== null && !Number.isNaN(qid)) ? ('id:' + qid) : ('t:' + String(qa.question_text || ''));
                    if (!groups.has(key)) {
                        const sort = qa.question_sort_order != null ? Number(qa.question_sort_order) : 999999;
                        groups.set(key, {
                            key,
                            question_id: qid,
                            question_text: qa.question_text || '',
                            question_sort_order: sort,
                            answers: []
                        });
                        order.push(key);
                    }
                    const g = groups.get(key);
                    if (qa.question_text && !g.question_text) {
                        g.question_text = qa.question_text;
                    }
                    const ans = (qa.answer_text || '').trim();
                    if (ans !== '') {
                        g.answers.push({ name, answer: qa.answer_text });
                    }
                }
            }
            const arr = order.map((k) => groups.get(k));
            arr.sort((a, b) => {
                if (a.question_sort_order !== b.question_sort_order) {
                    return a.question_sort_order - b.question_sort_order;
                }
                return (a.question_id || 0) - (b.question_id || 0);
            });
            return arr;
        },
        /** Per-question block for Alpine x-data (search + filter by answer) */
        questionAnswerBlock(q) {
            return {
                q,
                search: '',
                answerFilter: 'all',
                get uniqueAnswers() {
                    const seen = new Set();
                    (this.q.answers || []).forEach((r) => {
                        seen.add(String(r.answer ?? ''));
                    });
                    return Array.from(seen).sort((a, b) => a.localeCompare(b));
                },
                get filteredRows() {
                    let list = [...(this.q.answers || [])];
                    const term = (this.search || '').trim().toLowerCase();
                    if (term) {
                        list = list.filter((r) =>
                            ((r.name || '').toLowerCase().includes(term)) ||
                            String(r.answer ?? '').toLowerCase().includes(term)
                        );
                    }
                    if (this.answerFilter !== 'all') {
                        const want = this.answerFilter === '__EMPTY__' ? '' : this.answerFilter;
                        list = list.filter((r) => String(r.answer ?? '') === want);
                    }
                    return list;
                }
            };
        },
        async copyEventShareUrl() {
            const u = this.eventShareUrl;
            if (!u) {
                return;
            }
            try {
                await navigator.clipboard.writeText(u);
                alert('Link copied to clipboard.');
            } catch (e) {
                window.prompt('Copy this link:', u);
            }
        },
        async refreshInvites() {
            if (!hasEventInvitesTable) return;
            try {
                const r = await fetch(apiBaseUrl + '?action=event-invites&id=' + eventId, { credentials: 'same-origin' });
                const data = await r.json().catch(() => ({ success: false }));
                if (data.success && Array.isArray(data.invites)) {
                    this.invitesList = data.invites.map(inv => ({
                        ...inv,
                        profile_incomplete: !!inv.profile_incomplete || !inv.password_hash,
                        password_hash: undefined,
                    }));
                }
            } catch (e) { /* ignore */ }
        },
        async searchMembersForInvite() {
            if (!hasEventInvitesTable || !searchMembersUrl) return;
            const q = (this.inviteSearchQuery || '').trim();
            this.inviteSearchError = '';
            this.inviteSearchDone = false;
            if (q.length < 2) {
                this.inviteSearchResults = [];
                return;
            }
            this.inviteSearchLoading = true;
            try {
                const url = searchMembersUrl + '?q=' + encodeURIComponent(q) + '&event_id=' + eventId + '&purpose=invite';
                const r = await fetch(url, { credentials: 'same-origin' });
                const data = await r.json().catch(() => ({ success: false }));
                if (!data.success) {
                    this.inviteSearchError = data.message || 'Search failed.';
                    this.inviteSearchResults = [];
                } else {
                    this.inviteSearchResults = Array.isArray(data.members) ? data.members : [];
                }
                this.inviteSearchDone = true;
            } catch (e) {
                this.inviteSearchError = 'Search failed.';
                this.inviteSearchResults = [];
                this.inviteSearchDone = true;
            }
            this.inviteSearchLoading = false;
        },
        async addInviteForMember(m) {
            if (!this.canManageInvites || !m || !m.id) return;
            this.inviteSaving = true;
            try {
                const r = await fetch(apiBaseUrl + '?action=add-event-invites', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: eventId, user_ids: [m.id] })
                });
                const data = await r.json().catch(() => ({ success: false }));
                if (data.success) {
                    this.inviteSearchQuery = '';
                    this.inviteSearchResults = [];
                    if (Array.isArray(data.invites)) {
                        this.invitesList = data.invites;
                    } else {
                        await this.refreshInvites();
                    }
                } else {
                    alert(data.message || 'Could not add invite.');
                }
            } catch (e) {
                alert('Could not add invite.');
            }
            this.inviteSaving = false;
        },
        async inviteGuestByEmail() {
            if (!this.canManageInvites) return;
            const email = (this.guestInviteForm.email || '').trim();
            const firstName = (this.guestInviteForm.first_name || '').trim();
            const lastName = (this.guestInviteForm.last_name || '').trim();
            this.guestInviteError = '';
            this.guestInviteSuccess = '';
            if (!email || !firstName || !lastName) {
                this.guestInviteError = 'Email, first name, and last name are required.';
                return;
            }
            this.guestInviteSaving = true;
            try {
                const r = await fetch(apiBaseUrl + '?action=invite-guest-by-email', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: eventId,
                        email,
                        first_name: firstName,
                        last_name: lastName,
                    }),
                });
                const data = await r.json().catch(() => ({ success: false }));
                if (!data.success) {
                    this.guestInviteError = data.message || 'Could not send invite.';
                } else {
                    if (Array.isArray(data.invites)) {
                        this.invitesList = data.invites;
                    } else {
                        await this.refreshInvites();
                    }
                    if ((data.added || 0) > 0) {
                        this.guestInviteSuccess = data.email_sent
                            ? 'Invite sent. They will receive an email with next steps.'
                            : ('Added to invite list.' + (data.email_error ? ' Email could not be sent: ' + data.email_error : ''));
                        this.guestInviteForm = { email: '', first_name: '', last_name: '' };
                    } else {
                        this.guestInviteSuccess = data.message || 'This person is already on the invite list.';
                    }
                }
            } catch (e) {
                this.guestInviteError = 'Could not send invite.';
            }
            this.guestInviteSaving = false;
        },
        async removeInviteRow(inv) {
            if (!this.canManageInvites || !inv || !inv.id) return;
            if (!confirm('Remove this member from the invite list?')) return;
            this.inviteSaving = true;
            try {
                const r = await fetch(apiBaseUrl + '?action=remove-event-invite', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: eventId, invite_id: inv.id })
                });
                const data = await r.json().catch(() => ({ success: false }));
                if (data.success) {
                    await this.refreshInvites();
                } else {
                    alert(data.message || 'Could not remove invite.');
                }
            } catch (e) {
                alert('Could not remove invite.');
            }
            this.inviteSaving = false;
        },
        async loadRsvps() {
            this.loadingRsvps = true;
            try {
                const r = await fetch(apiBaseUrl + '?action=rsvps&id=' + eventId, { credentials: 'same-origin' });
                const data = await r.json().catch(() => ({ success: false }));
                this.rsvpList = (data.success && Array.isArray(data.rsvps)) ? data.rsvps : [];
                const s = (data.success && data.summary) ? data.summary : null;
                this.rsvpSummary = s ? {
                    counts: Object.assign({ yes: 0, no: 0, maybe: 0, total_rsvps: 0, total_head_count: 0, total_guests: 0 }, s.counts || {}),
                    attendance: Object.assign({ checked_in_yes: 0, not_checked_in_yes: 0, expected_head_count: 0 }, s.attendance || {}),
                    capacity: s.capacity ?? null,
                    available_spots: s.available_spots ?? null,
                    no_response_count: s.no_response_count ?? 0,
                } : null;
                this.eventQuestions = (data.success && Array.isArray(data.event_questions)) ? data.event_questions : [];
                this.questionGroups = this.buildQuestionGroups();
            } catch (e) {
                this.rsvpList = [];
                this.rsvpSummary = null;
                this.eventQuestions = [];
                this.questionGroups = [];
            }
            this.loadingRsvps = false;
        },
        async loadCheckins() {
            this.loadingCheckins = true;
            try {
                const url = apiBase.replace(/\/+$/, '') + '/event-checkins.php?event_id=' + eventId;
                const r = await fetch(url, { credentials: 'same-origin' });
                const data = await r.json().catch(() => ({ success: false }));
                this.checkinList = (data.success && Array.isArray(data.checkins)) ? data.checkins : [];
            } catch (e) {
                this.checkinList = [];
            }
            this.loadingCheckins = false;
        },
        async recordCash(rsvp) {
            const amount = parseFloat(this.cashAmount);
            if (!amount || amount <= 0) { alert('Enter a valid amount.'); return; }
            this.cashSaving = true;
            try {
                const r = await fetch(apiBase + '/cash-payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create', event_id: eventId, user_id: rsvp.user_id, amount: amount }),
                    credentials: 'same-origin'
                });
                const data = await r.json();
                if (data.success) {
                    rsvp.payment_id = data.payment_id;
                    rsvp.payment_amount = data.amount;
                    rsvp.payment_method = 'cash';
                    this.recordingCashFor = null;
                    this.cashAmount = '';
                } else {
                    alert(data.message || 'Failed to record cash payment.');
                }
            } catch (e) {
                alert('An error occurred.');
            }
            this.cashSaving = false;
        },
        async deleteCash(rsvp) {
            if (!rsvp.payment_id || (rsvp.payment_method || '').toLowerCase() !== 'cash') return;
            if (!confirm('Delete this cash payment? This cannot be undone.')) return;
            try {
                const r = await fetch(apiBase + '/cash-payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', payment_id: rsvp.payment_id }),
                    credentials: 'same-origin'
                });
                const data = await r.json();
                if (data.success) {
                    rsvp.payment_id = null;
                    rsvp.payment_amount = null;
                    rsvp.payment_method = null;
                } else {
                    alert(data.message || 'Failed to delete.');
                }
            } catch (e) {
                alert('An error occurred.');
            }
        },
        async deleteRsvp(rsvp) {
            if (!rsvp || !rsvp.id) return;
            if (!confirm('Remove this RSVP? This cannot be undone.')) return;
            try {
                const r = await fetch(apiBaseUrl + '?action=delete-rsvp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ rsvp_id: rsvp.id }),
                    credentials: 'same-origin'
                });
                const data = await r.json().catch(() => ({ success: false }));
                if (data.success) {
                    // Refresh list so counts and ordering stay accurate.
                    await this.loadRsvps();
                } else {
                    alert(data.message || 'Failed to remove RSVP.');
                }
            } catch (e) {
                alert('An error occurred.');
            }
        },
        defaultCorrectionDateTime(checkedInAt) {
            if (checkedInAt) {
                const normalized = String(checkedInAt).trim().replace(' ', 'T');
                const d = new Date(normalized);
                if (!isNaN(d.getTime())) {
                    const pad = (n) => String(n).padStart(2, '0');
                    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                        + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                }
            }
            const date = eventDate || '';
            let time = eventStartTime || '12:00:00';
            if (time.length === 5) {
                time += ':00';
            }
            return date + 'T' + time.slice(0, 5);
        },
        openCorrectionModal(action, userId, userName, checkedInAt, guestsCheckedIn) {
            this.correctionForm = {
                action: action,
                user_id: userId,
                user_name: userName || '',
                reason: '',
                checked_in_at_local: action === 'undo' ? '' : this.defaultCorrectionDateTime(checkedInAt || null),
                guests_checked_in: guestsCheckedIn != null ? parseInt(guestsCheckedIn, 10) || 0 : 0,
            };
            this.showCorrectionModal = true;
        },
        closeCorrectionModal() {
            this.showCorrectionModal = false;
            this.correctionSaving = false;
        },
        async submitCorrection() {
            if (!this.canCorrectCheckins || !this.correctionForm.user_id) return;
            const reason = (this.correctionForm.reason || '').trim();
            if (reason.length < 3) {
                alert('Please enter a reason (at least 3 characters).');
                return;
            }
            const payload = {
                event_id: eventId,
                user_id: this.correctionForm.user_id,
                action: this.correctionForm.action,
                reason: reason,
            };
            if (this.correctionForm.action !== 'undo') {
                const local = this.correctionForm.checked_in_at_local;
                if (!local) {
                    alert('Please set a check-in time.');
                    return;
                }
                payload.checked_in_at = local.length === 16 ? local.replace('T', ' ') + ':00' : local.replace('T', ' ');
                payload.guests_checked_in = this.correctionForm.guests_checked_in;
            }
            this.correctionSaving = true;
            try {
                const r = await fetch(apiBase.replace(/\/+$/, '') + '/checkin-override.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin',
                });
                const data = await r.json().catch(() => ({ success: false }));
                if (data.success) {
                    this.closeCorrectionModal();
                    await this.loadRsvps();
                    await this.loadCheckins();
                } else {
                    alert(data.message || 'Could not save correction.');
                }
            } catch (e) {
                alert('An error occurred while saving.');
            }
            this.correctionSaving = false;
        },
        initComposerWysiwyg() {
            const ta = document.getElementById('email-composer-body');
            if (!ta || typeof window.initWYSIWYG !== 'function') return;
            if (!ta.dataset.quillInitialized) {
                window.initWYSIWYG('#email-composer-body');
                const quill = window.__quillInstances && window.__quillInstances.get(ta);
                if (quill && typeof headcountInitQuillRichToolbar === 'function' && !ta.dataset.composerRichToolbar) {
                    ta.dataset.composerRichToolbar = '1';
                    headcountInitQuillRichToolbar(quill, {
                        uploadImageUrl: apiBase.replace(/\/+$/, '') + '/upload-email-image.php',
                        uploadVideoUrl: apiBase.replace(/\/+$/, '') + '/upload-email-video.php',
                        csrfToken: csrfToken
                    });
                }
                if (quill && !ta.dataset.composerSafePaste) {
                    ta.dataset.composerSafePaste = '1';
                    quill.root.addEventListener('paste', (event) => {
                        const clipboard = event.clipboardData || window.clipboardData;
                        if (!clipboard) return;
                        const text = clipboard.getData('text/plain');
                        if (typeof text !== 'string') return;
                        event.preventDefault();
                        const range = quill.getSelection(true);
                        const index = range ? range.index : quill.getLength();
                        quill.insertText(index, text, 'user');
                        quill.setSelection(index + text.length, 0, 'silent');
                    });
                }
            }
            const sanitized = this.sanitizeComposerHtml(this.composer.bodyHtml || '');
            this.composer.bodyHtml = sanitized;
            ta.value = sanitized;
            ta.dispatchEvent(new Event('sync-to-quill'));
        },
        sanitizeComposerHtml(html) {
            const raw = String(html || '');
            if (!raw) return '';
            try {
                const doc = new DOMParser().parseFromString(raw, 'text/html');
                doc.querySelectorAll('script,style,iframe,object,embed,link,meta').forEach((el) => el.remove());
                const nodes = doc.body ? doc.body.querySelectorAll('*') : [];
                nodes.forEach((el) => {
                    [...el.attributes].forEach((attr) => {
                        const n = String(attr.name || '').toLowerCase();
                        if (n.startsWith('on') || n === 'style' || n === 'class' || n === 'id') {
                            el.removeAttribute(attr.name);
                        }
                    });
                });
                return doc.body ? doc.body.innerHTML : '';
            } catch (e) {
                return raw
                    .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
                    .replace(/<style[\s\S]*?>[\s\S]*?<\/style>/gi, '')
                    .replace(/<(iframe|object|embed|link|meta)[^>]*>/gi, '');
            }
        },
        flushComposerHtmlFromEditor() {
            const ta = document.getElementById('email-composer-body');
            if (!ta || !window.__quillInstances) return;
            const quill = window.__quillInstances.get(ta);
            if (!quill) return;
            let html = quill.root.innerHTML;
            if (html === '<p><br></p>') html = '';
            html = this.sanitizeComposerHtml(html);
            this.composer.bodyHtml = html;
            ta.value = html;
        },
        async openEmailComposer(type) {
            this.composerType = type === 'reminder' ? 'reminder' : 'announcement';
            if (this.composerType === 'announcement') {
                this.composer.subject = this.composeDefaults.announcementSubject;
                this.composer.bodyHtml = this.composeDefaults.announcementBody;
            } else {
                this.composer.subject = this.composeDefaults.reminderSubject;
                this.composer.bodyHtml = this.composeDefaults.reminderBody;
            }
            this.composerTemplateId = '';
            this.showEmailComposer = true;
            await this.loadComposerTemplates();
            this.$nextTick(() => setTimeout(() => this.initComposerWysiwyg(), 80));
        },
        async loadComposerTemplates() {
            this.composerLoadingTemplates = true;
            try {
                const res = await fetch(apiBase + '/email-templates.php?action=list', { credentials: 'same-origin' });
                const data = await res.json().catch(() => ({ success: false }));
                if (data.success && Array.isArray(data.templates)) {
                    const targetType = this.composerType === 'announcement' ? 'announcement' : 'reminder_1day';
                    this.composerTemplates = data.templates.filter((t) => (t.template_type === targetType || t.template_type === 'custom'));
                } else {
                    this.composerTemplates = [];
                }
            } catch (e) {
                this.composerTemplates = [];
            }
            this.composerLoadingTemplates = false;
        },
        applyComposerTemplate() {
            if (!this.composerTemplateId) return;
            const tid = Number(this.composerTemplateId);
            const selected = (this.composerTemplates || []).find((t) => Number(t.id) === tid);
            if (!selected) return;
            this.composer.subject = selected.subject || this.composer.subject;
            this.composer.bodyHtml = selected.body_html || this.composer.bodyHtml;
            this.$nextTick(() => setTimeout(() => this.initComposerWysiwyg(), 30));
        },
        async sendComposedEmail() {
            this.flushComposerHtmlFromEditor();
            const subject = (this.composer.subject || '').trim();
            const bodyHtml = (this.composer.bodyHtml || '').trim();
            if (!subject) {
                alert('Subject is required.');
                return;
            }
            if (!bodyHtml) {
                alert('Email body is required.');
                return;
            }
            this.composerSending = true;
            try {
                const r = await fetch(apiBaseUrl + '?action=' + this.composerAction, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: eventId, subject: subject, body_html: bodyHtml })
                });
                const data = await r.json().catch(() => ({ success: false }));
                if (data.success) {
                    if (this.composerType === 'announcement') {
                        this.composeDefaults.announcementSubject = subject;
                        this.composeDefaults.announcementBody = bodyHtml;
                    } else {
                        this.composeDefaults.reminderSubject = subject;
                        this.composeDefaults.reminderBody = bodyHtml;
                    }
                    const details = data.details || {};
                    const sent = details.sent ?? 0;
                    const failed = details.failed ?? 0;
                    const total = details.total ?? sent + failed;
                    let msg = data.message || `Email sent to ${sent} recipients.`;
                    if (failed > 0) {
                        msg += ` (${failed} failed out of ${total}. See email log below for details.)`;
                    }
                    alert(msg);
                    this.showEmailComposer = false;
                    this.loadEmailLogs();
                } else {
                    alert(data.message || 'Failed to send email.');
                }
            } catch (e) {
                alert('An error occurred while sending email.');
            }
            this.composerSending = false;
        },
        async announceEvent() {
            await this.openEmailComposer('announcement');
        },
        async sendReminderEvent() {
            await this.openEmailComposer('reminder');
        },
        async loadEmailLogs() {
            this.emailLogsLoading = true;
            try {
                const url = apiBase.replace(/\/+$/, '') + '/email-logs.php?event_id=' + eventId + '&limit=50';
                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json().catch(() => ({ success: false }));
                this.emailLogs = (data.success && Array.isArray(data.logs)) ? data.logs : [];
            } catch (e) {
                this.emailLogs = [];
            }
            this.emailLogsLoading = false;
        }
    };
}
</script>
<style>
[x-cloak] { display: none !important; }
</style>
<div class="content-wrapper" x-data="eventDetailsApp()">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Dashboard', 'url' => $adminBase . '/?page=dashboard'],
        ['label' => 'Events', 'url' => $adminBase . '/?page=events'],
        ['label' => $event['title']],
    ];
    $pageHeaderTitle = $event['title'];
    $pageHeaderSubtitle = formatDate($event['event_date']);
    if (!empty($event['start_time'])) {
        $pageHeaderSubtitle .= ' | ' . formatTime($event['start_time']);
    }
    $pageHeaderSubtitle .= ' | ' . ucfirst((string) ($event['status'] ?? ''));
    if ($hasVisibilityColumn) {
        $visHuman = $eventVisibilityLabel === 'invite_only' ? 'Invite-only' : ($eventVisibilityLabel === 'internal' ? 'Internal' : 'Public');
        $pageHeaderSubtitle .= ' | ' . $visHuman;
    }
    if (!empty($event['is_virtual'])) {
        $pageHeaderSubtitle .= ' | Virtual';
    }
    ob_start();
    ?>
            <a href="<?= e($adminBase . '/index.php?page=events') ?>" class="btn-secondary flex items-center gap-2">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Back to Events
            </a>
            <?php if (!$isCoordinator): ?>
            <a href="<?= e($adminBase . '/index.php?page=event-edit&id=' . (int) $eventId) ?>" class="btn-primary flex items-center gap-2">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                Edit Event
            </a>
            <?php endif; ?>
            <?php if ($event['status'] === 'published' && strtotime($event['event_date']) >= strtotime('today')): ?>
            <a href="<?= e($adminBase . '/index.php?page=checkin&event_id=' . $eventId) ?>" class="btn-primary bg-success-600 hover:bg-success-700 flex items-center gap-2">
                Check-In
            </a>
            <?php elseif ($canCorrectCheckins && $event['status'] === 'published'): ?>
            <button type="button" @click="activeTab = 'rsvps'; rsvpReportSubTab = 'responses'; loadRsvps(); loadCheckins()" class="btn-primary bg-amber-600 hover:bg-amber-700 flex items-center gap-2">
                Correct attendance
            </button>
            <?php endif; ?>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
        <?php
        $statLabel = 'RSVP People';
        $statValue = number_format((int) $event['rsvp_head_count']);
        $statTrend = null;
        $statTrendLabel = number_format((int) $event['rsvp_registrant_count']) . ' registrants';
        $statAccent = 'brand';
        $statIcon = 'users';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Checked In';
        $statValue = number_format((int) $event['checkin_count']);
        $statTrend = null;
        $statTrendLabel = 'At this session';
        $statAccent = 'success';
        $statIcon = 'ticket';
        require __DIR__ . '/components/stat-card-trend.php';
        if (!empty($event['capacity'])) {
            $statLabel = 'Capacity';
            $statValue = number_format((int) $event['capacity']);
            $statTrend = null;
            $remaining = max(0, (int) $event['capacity'] - (int) $event['rsvp_head_count']);
            $statTrendLabel = $remaining . ' spots left';
            $statAccent = 'warning';
            $statIcon = 'layers';
            require __DIR__ . '/components/stat-card-trend.php';
        }
        $statLabel = 'Status';
        $statValue = ucfirst((string) ($event['status'] ?? 'draft'));
        $statTrend = null;
        $statTrendLabel = formatDate($event['event_date']);
        $statAccent = 'sky';
        $statIcon = 'calendar';
        require __DIR__ . '/components/stat-card-trend.php';
        ?>
    </div>

    <!-- Tabs -->
    <div class="mb-6">
        <?php
        $cardTabs = [
            ['id' => 'details', 'label' => 'Details', 'active' => true],
            ['id' => 'rsvps', 'label' => 'RSVP Report', 'click' => "rsvpReportSubTab = 'responses'; loadRsvps(); loadCheckins()"],
            ['id' => 'questions', 'label' => 'Questions', 'click' => 'if (!rsvpList.length) { loadRsvps(); }'],
        ];
        if (!$isCoordinator) {
            $cardTabs[] = ['id' => 'email', 'label' => 'Email', 'click' => 'loadEmailLogs()'];
        }
        $cardTabsVar = 'activeTab';
        $cardTabsParentScope = true;
        require __DIR__ . '/components/card-tabs.php';
        unset($cardTabs, $cardTabsVar, $cardTabsParentScope);
        ?>
    </div>

    <!-- Tab: Details (no x-cloak so content is visible on load before Alpine) -->
    <div x-show="activeTab === 'details'" class="space-y-6">
        <?php if (!empty($seriesSessions) && count($seriesSessions) > 1): ?>
        <div class="bento-card p-6 border border-brand-100 bg-brand-50/40">
            <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-1 dark:text-white">All sessions in this series</h3>
            <p class="text-xs text-gray-600 mb-3 dark:text-gray-300">Pick a session below, then use <strong>Open</strong> (the button shows <strong>This page</strong> when that session is already open here) to switch this page, or <strong>Check-In</strong> when that date is published and still upcoming.</p>
            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                <div class="flex-1 min-w-0">
                    <label for="series-session-select" class="block text-xs font-semibold text-gray-700 mb-1.5 dark:text-gray-200">Session</label>
                    <select id="series-session-select" name="series_session"
                            class="w-full max-w-xl border border-brand-200 rounded-xl px-3 py-2.5 text-sm bg-white text-gray-900 shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 dark:bg-gray-800 dark:text-white"
                            data-current-id="<?= (int) $eventId ?>"
                            data-details-base="<?= e($adminBase . '/?page=event-details&id=') ?>"
                            data-checkin-base="<?= e($adminBase . '/?page=checkin&event_id=') ?>">
                        <?php foreach ($seriesSessions as $s):
                            $sid = (int) $s['id'];
                            $isThis = ($sid === $eventId);
                            $sessPast = strtotime((string) $s['event_date']) < strtotime('today');
                            $canCheckin = ($s['status'] === 'published' && !$sessPast);
                            $label = formatDate($s['event_date']);
                            if (!empty($s['start_time'])) {
                                $label .= ' | ' . formatTime($s['start_time']);
                                if (!empty($s['end_time'])) {
                                    $label .= ' – ' . formatTime($s['end_time']);
                                }
                            }
                            $label .= ' | ' . ucfirst((string) $s['status']);
                            if ($isThis) {
                                $label .= ' (this page)';
                            }
                            ?>
                        <option value="<?= $sid ?>" data-can-checkin="<?= $canCheckin ? '1' : '0' ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-2 shrink-0 pb-0.5">
                    <a id="series-session-open" href="<?= e($adminBase . '/?page=event-details&id=' . (int) $eventId) ?>" class="btn-primary text-xs py-2 px-3 whitespace-nowrap">Open</a>
                    <a id="series-session-checkin" href="#" class="btn-primary bg-success-600 hover:bg-success-700 text-xs py-2 px-3 whitespace-nowrap hidden" hidden>Check-In</a>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var sel = document.getElementById('series-session-select');
            if (!sel) return;
            var cur = String(sel.getAttribute('data-current-id') || '');
            var detailsBase = sel.getAttribute('data-details-base') || '';
            var checkinBase = sel.getAttribute('data-checkin-base') || '';
            var btnIn = document.getElementById('series-session-checkin');
            var linkOpen = document.getElementById('series-session-open');

            function syncActions() {
                var id = sel.value;
                var opt = sel.options[sel.selectedIndex];
                var canIn = opt && opt.getAttribute('data-can-checkin') === '1';
                if (linkOpen) {
                    if (id === cur) {
                        linkOpen.textContent = 'This page';
                        linkOpen.setAttribute('aria-disabled', 'true');
                        linkOpen.setAttribute('tabindex', '-1');
                        linkOpen.classList.add('opacity-50', 'pointer-events-none', 'cursor-default');
                        linkOpen.href = detailsBase + encodeURIComponent(id);
                    } else {
                        linkOpen.textContent = 'Open';
                        linkOpen.removeAttribute('aria-disabled');
                        linkOpen.removeAttribute('tabindex');
                        linkOpen.classList.remove('opacity-50', 'pointer-events-none', 'cursor-default');
                        linkOpen.href = detailsBase + encodeURIComponent(id);
                    }
                }
                if (btnIn) {
                    if (canIn && id) {
                        btnIn.href = checkinBase + encodeURIComponent(id);
                        btnIn.classList.remove('hidden');
                        btnIn.removeAttribute('hidden');
                    } else {
                        btnIn.classList.add('hidden');
                        btnIn.setAttribute('hidden', 'hidden');
                    }
                }
            }

            sel.value = cur;
            sel.addEventListener('change', syncActions);
            syncActions();
        })();
        </script>
        <?php endif; ?>

        <div class="bento-card p-6">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Share event</h3>
            <?php if ($hasVisibilityColumn && $eventVisibilityLabel === 'internal'): ?>
            <p class="text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 mb-4">This event is <strong>internal</strong>. The portal link is for staff only; members will not see this event in the portal.</p>
            <?php elseif ($hasVisibilityColumn && $eventVisibilityLabel === 'invite_only'): ?>
            <p class="text-xs text-brand-900 bg-brand-50 border border-brand-100 rounded-lg px-3 py-2 mb-4">This event is <strong>invite-only</strong>. Only invited members can open this link and RSVP in the portal.</p>
            <?php endif; ?>
            <p class="text-xs text-gray-500 mb-4 dark:text-gray-400">Scan or download the QR code to share the public event page. Members open the same link as in announcements and social share.</p>
            <div class="flex flex-col sm:flex-row gap-6 items-start">
                <div class="shrink-0 rounded-xl border border-gray-200 bg-white p-2 shadow-card dark:bg-gray-800 dark:border-gray-700">
                    <img src="<?= e($eventShareQrSrc) ?>" width="200" height="200" alt="QR code linking to this event" class="w-[200px] h-[200px] object-contain">
                </div>
                <div class="flex-1 min-w-0 space-y-3 w-full">
                    <div>
                        <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Public link</div>
                        <div class="break-all rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700"><?= e($eventShareUrl) ?></div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="copyEventShareUrl()" class="btn-primary text-sm">Copy link</button>
                        <a href="<?= e($eventShareQrDownloadHref) ?>" class="btn-secondary text-sm inline-flex items-center gap-2">Download QR</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $showInvitedMembersCard = $hasEventInvitesTable
            && (!$hasVisibilityColumn || $eventVisibilityLabel === 'invite_only');
        ?>
        <?php if ($showInvitedMembersCard): ?>
        <div class="bento-card p-6 space-y-4">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-1">Invited members</h3>
            <?php if ($hasVisibilityColumn && $eventVisibilityLabel === 'invite_only'): ?>
                <p class="text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 mb-3">This event is <strong>invite-only</strong> in the member portal. Only people listed here can see it and RSVP (invite list is stored on event #<?= (int) $inviteStorageIdDisplay ?> for recurring series).</p>
            <?php endif; ?>
            <?php if (!$canManageInvites): ?>
                <p class="text-xs text-gray-500 dark:text-gray-400">Coordinators can view this list; only admins can change invites.</p>
            <?php endif; ?>
            <div class="flex flex-col sm:flex-row gap-2 max-w-xl items-stretch sm:items-center" x-show="canManageInvites">
                <input type="search" x-model="inviteSearchQuery" @keyup.enter="searchMembersForInvite()"
                       placeholder="Search members by name or email (min 2 chars)…"
                       class="flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700">
                <button type="button" class="btn-secondary text-sm whitespace-nowrap" :disabled="inviteSearchLoading" @click="searchMembersForInvite()">Search</button>
                <span x-show="inviteSearchLoading" class="text-xs text-gray-500 self-center dark:text-gray-400">Searching…</span>
            </div>
            <p x-show="inviteSearchError" class="text-xs text-rose-600 max-w-xl" x-text="inviteSearchError"></p>
            <p x-show="inviteSearchDone && !inviteSearchLoading && !inviteSearchError && inviteSearchResults.length === 0 && (inviteSearchQuery || '').trim().length >= 2"
               class="text-xs text-gray-500 max-w-xl dark:text-gray-400">No members found. Try a different search or invite someone new below.</p>
            <div x-show="inviteSearchResults.length > 0" class="rounded-xl border border-gray-200 divide-y divide-gray-100 max-w-xl overflow-hidden dark:border-gray-700 dark:divide-gray-800">
                <template x-for="m in inviteSearchResults" :key="m.id">
                    <div class="flex items-center justify-between gap-2 px-3 py-2 text-sm bg-white dark:bg-gray-800">
                        <div class="min-w-0">
                            <div class="font-medium text-gray-900 truncate dark:text-white" x-text="(m.first_name || '') + ' ' + (m.last_name || '')"></div>
                            <div class="text-xs text-gray-500 truncate dark:text-gray-400" x-text="m.email || ''"></div>
                        </div>
                        <button type="button" class="shrink-0 text-xs font-bold text-brand-600 hover:underline disabled:opacity-50"
                                :disabled="inviteSaving" @click="addInviteForMember(m)">Add</button>
                    </div>
                </template>
            </div>
            <div x-show="canManageInvites" class="max-w-xl rounded-xl border border-dashed border-gray-200 bg-gray-50/80 p-4 space-y-3 dark:bg-gray-800 dark:border-gray-700">
                <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Invite someone new</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400">Send an invite by email if they are not in the member list yet. They will receive an email to complete their profile before RSVPing.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <input type="text" x-model="guestInviteForm.first_name" placeholder="First name"
                           class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700">
                    <input type="text" x-model="guestInviteForm.last_name" placeholder="Last name"
                           class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700">
                </div>
                <input type="email" x-model="guestInviteForm.email" placeholder="Email address"
                       class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-primary text-sm" :disabled="guestInviteSaving" @click="inviteGuestByEmail()">
                        <span x-show="!guestInviteSaving">Send invite</span>
                        <span x-show="guestInviteSaving">Sending…</span>
                    </button>
                    <span x-show="guestInviteError" class="text-xs text-rose-600" x-text="guestInviteError"></span>
                    <span x-show="guestInviteSuccess" class="text-xs text-emerald-700" x-text="guestInviteSuccess"></span>
                </div>
            </div>
            <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <div x-show="!invitesList.length" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No invited members yet.</div>
                <table x-show="invitesList.length > 0" class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700">
                        <tr>
                            <th class="px-4 py-2">Name</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2 w-24"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="inv in invitesList" :key="inv.id">
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white" x-text="(inv.first_name || '') + ' ' + (inv.last_name || '')"></td>
                                <td class="px-4 py-2 text-gray-600 truncate max-w-[200px] dark:text-gray-300" x-text="inv.email || '—'"></td>
                                <td class="px-4 py-2">
                                    <span x-show="inv.profile_incomplete" class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 border border-amber-100">Profile incomplete</span>
                                    <span x-show="!inv.profile_incomplete" class="text-xs text-gray-400">Ready</span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" x-show="canManageInvites" class="text-xs font-bold text-rose-600 hover:underline disabled:opacity-50"
                                            :disabled="inviteSaving" @click="removeInviteRow(inv)">Remove</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bento-card p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider">When &amp; Where</h3>
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-400">Date</div>
                    <div class="font-medium text-gray-900 dark:text-white"><?= formatDate($event['event_date']) ?></div>
                </div>
                <?php if (!empty($event['start_time'])): ?>
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-400">Time</div>
                    <div class="font-medium text-gray-900 dark:text-white"><?= formatTime($event['start_time']) ?><?= !empty($event['end_time']) ? ' - ' . formatTime($event['end_time']) : '' ?></div>
                </div>
                <?php endif; ?>
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-400"><?= !empty($event['is_virtual']) ? 'Join link' : 'Location' ?></div>
                    <?php if (!empty($event['is_virtual']) && !empty($event['location']) && (strpos($event['location'], 'http') === 0 || strpos($event['location'], '//') !== false)): ?>
                    <a href="<?= e(strpos($event['location'], 'http') === 0 ? $event['location'] : 'https://' . ltrim($event['location'], '/')) ?>" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-600 hover:underline break-all"><?= e($event['location']) ?></a>
                    <?php else: ?>
                    <div class="font-medium text-gray-900 dark:text-white"><?= e($event['location'] ?: '-') ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($event['capacity'])): ?>
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-400">Capacity</div>
                    <div class="font-medium text-gray-900 dark:text-white"><?= (int)$event['capacity'] ?> cap | <?= (int)$event['rsvp_head_count'] ?> people
                        <?php if ((int)$event['rsvp_registrant_count'] > 0 && (int)$event['rsvp_head_count'] !== (int)$event['rsvp_registrant_count']): ?>
                            <span class="text-gray-500 font-normal dark:text-gray-400">(<?= (int)$event['rsvp_registrant_count'] ?> registrants)</span>
                        <?php endif; ?>
                        | <?= (int)$event['checkin_count'] ?> checked in</div>
                </div>
                <?php endif; ?>
                <?php if (!empty($eventCategoriesMap)): ?>
                <div>
                    <div class="text-xs text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-400">Categories</div>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($eventCategoriesMap as $ec): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider" style="background-color: <?= e($ec['color'] ?? '#3B82F6') ?>15; color: <?= e($ec['color'] ?? '#3B82F6') ?>;"><?= e($ec['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($event['banner_image'])): ?>
            <div class="bento-card p-0 overflow-hidden">
                <img src="<?= e($basePath . '/public/api/image.php?path=' . urlencode($event['banner_image'])) ?>" alt="<?= e($event['title']) ?>" class="w-full h-48 object-cover">
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($adminSpeakers) || !empty($adminOrganisers)): ?>
        <div class="bento-card p-6 space-y-6">
            <?php if (!empty($adminSpeakers)): ?>
            <div>
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">Speakers</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach ($adminSpeakers as $sp): ?>
                    <div class="flex gap-3 rounded-xl border border-gray-200 bg-gray-50/80 p-3 dark:bg-gray-800 dark:border-gray-700">
                        <?php if (!empty($sp['image_url'])): ?>
                        <img src="<?= e($sp['image_url']) ?>" alt="" class="w-14 h-14 rounded-lg object-cover shrink-0" width="56" height="56">
                        <?php else: ?>
                        <div class="w-14 h-14 rounded-lg bg-brand-100 shrink-0 flex items-center justify-center text-brand-700 font-bold" aria-hidden="true"><?= e($sp['name'] !== '' ? strtoupper(substr($sp['name'], 0, 1)) : '?') ?></div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <div class="font-bold text-gray-900 dark:text-white"><?= e($sp['name']) ?></div>
                            <?php if (!empty($sp['title'])): ?><div class="text-sm text-gray-600 mt-0.5 dark:text-gray-300"><?= e($sp['title']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($adminOrganisers)): ?>
            <div>
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">Organisers</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach ($adminOrganisers as $sp): ?>
                    <div class="flex gap-3 rounded-xl border border-gray-200 bg-gray-50/80 p-3 dark:bg-gray-800 dark:border-gray-700">
                        <?php if (!empty($sp['image_url'])): ?>
                        <img src="<?= e($sp['image_url']) ?>" alt="" class="w-14 h-14 rounded-lg object-cover shrink-0" width="56" height="56">
                        <?php else: ?>
                        <div class="w-14 h-14 rounded-lg bg-brand-100 shrink-0 flex items-center justify-center text-brand-700 font-bold" aria-hidden="true"><?= e($sp['name'] !== '' ? strtoupper(substr($sp['name'], 0, 1)) : '?') ?></div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <div class="font-bold text-gray-900 dark:text-white"><?= e($sp['name']) ?></div>
                            <?php if (!empty($sp['title'])): ?><div class="text-sm text-gray-600 mt-0.5 dark:text-gray-300"><?= e($sp['title']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($event['description'])): ?>
        <div class="bento-card p-6">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Description</h3>
            <?php
            // Allow safe HTML from Quill; strip script and other dangerous content to prevent XSS and broken inline scripts
            $safeDesc = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $event['description']);
            $safeDesc = strip_tags($safeDesc, '<p><br><strong><em><b><i><u><a><ul><ol><li><h1><h2><h3><h4><span><div><blockquote>');
            ?>
            <div class="prose max-w-none text-gray-700 dark:text-gray-200"><?= $safeDesc ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($event['extra_details'])): ?>
        <div class="bento-card p-6">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Extra details</h3>
            <div class="prose max-w-none text-gray-700 dark:text-gray-200"><?= nl2br(e($event['extra_details'])) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Questions (grouped by custom question) -->
    <div x-show="activeTab === 'questions'" x-cloak class="space-y-4">
        <div x-show="loadingRsvps" class="py-12 text-center">
            <div class="inline-block animate-spin w-8 h-8 border-4 border-brand-500 border-t-transparent rounded-full"></div>
            <p class="mt-4 text-gray-500 font-bold uppercase tracking-widest text-xs dark:text-gray-400">Loading...</p>
        </div>
        <div x-show="!loadingRsvps && questionGroups.length === 0" class="py-12 text-center text-gray-500 bento-card dark:text-gray-400">
            <p>No custom question responses yet for this event.</p>
        </div>
        <div x-show="!loadingRsvps && questionGroups.length > 0" class="space-y-3">
            <template x-for="q in questionGroups" :key="q.key">
                <details class="bento-card overflow-hidden group" x-data="questionAnswerBlock(q)">
                    <summary class="px-5 py-4 cursor-pointer list-none flex items-start justify-between gap-4 hover:bg-gray-50/80 transition-colors marker:content-none [&::-webkit-details-marker]:hidden dark:bg-gray-800">
                        <span class="font-bold text-gray-900 text-sm leading-snug pr-2 dark:text-white" x-text="q.question_text"></span>
                        <span class="shrink-0 text-[10px] font-black uppercase tracking-wider px-2.5 py-1 rounded-full bg-brand-50 text-brand-700" x-text="q.answers.length + ' answers'"></span>
                    </summary>
                    <div class="border-t border-gray-200 px-5 pb-4 pt-3 dark:border-gray-700">
                        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between mb-3">
                            <div class="flex flex-col sm:flex-row gap-2 flex-1 min-w-0">
                                <label class="sr-only">Search responses</label>
                                <input type="search"
                                       x-model="search"
                                       placeholder="Search name or answer..."
                                       class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 sm:max-w-md dark:text-white dark:border-gray-700">
                                <label class="sr-only">Filter by answer</label>
                                <select x-model="answerFilter"
                                        class="min-w-[10rem] w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 sm:w-auto dark:bg-gray-800 dark:text-white dark:border-gray-700">
                                    <option value="all">All answers</option>
                                    <template x-for="opt in uniqueAnswers" :key="opt === '' ? '__blank__' : opt">
                                        <option :value="opt === '' ? '__EMPTY__' : opt" x-text="opt === '' ? '(blank)' : opt"></option>
                                    </template>
                                </select>
                            </div>
                            <p class="text-xs text-gray-500 shrink-0 dark:text-gray-400">
                                Showing <span class="font-bold text-gray-700 dark:text-gray-200" x-text="filteredRows.length"></span>
                                of <span x-text="q.answers.length"></span>
                            </p>
                        </div>
                        <div x-show="filteredRows.length > 0" class="overflow-hidden rounded-xl border border-gray-200 shadow-card dark:border-gray-700">
                            <div class="overflow-x-auto">
                            <table class="w-full text-sm table-fixed">
                                <thead class="bg-gray-50 border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-[38%] dark:text-gray-400">Name</th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Answer</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <template x-for="(row, idx) in filteredRows" :key="idx + '-' + (row.name || '') + '-' + (row.answer || '')">
                                        <tr class="hover:bg-gray-50/80 dark:bg-gray-800">
                                            <td class="px-4 py-3 font-semibold text-gray-900 align-top break-words min-w-0 dark:text-white">
                                                <span class="block pr-3" x-text="row.name"></span>
                                            </td>
                                            <td class="px-4 py-3 text-gray-800 align-top break-words min-w-0 dark:text-gray-100">
                                                <span class="block pl-0.5" x-text="row.answer"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            </div>
                        </div>
                        <div x-show="filteredRows.length === 0" class="rounded-xl border border-dashed border-gray-200 py-8 text-center text-sm text-gray-500 dark:text-gray-400 dark:border-gray-700">
                            No rows match your search or filter.
                        </div>
                    </div>
                </details>
            </template>
        </div>
    </div>

    <!-- Tab: RSVP Report -->
    <div x-show="activeTab === 'rsvps'" x-cloak>
        <template x-if="rsvpSummary">
        <div class="mb-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </div>
                    <div class="mt-5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">People</span>
                        <h4 class="mt-2 text-title-xl font-bold leading-none tracking-tight text-gray-800 dark:text-white/90" x-text="(rsvpSummary.counts.total_head_count ?? rsvpSummary.counts.total_rsvps ?? 0) + ''"></h4>
                        <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500" x-text="((rsvpSummary.counts.total_rsvps || 0) === 1 ? '1 registrant' : ((rsvpSummary.counts.total_rsvps || 0) + ' registrants')) + ' responded'"></p>
                    </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg>
                    </div>
                    <div class="mt-5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">Attendance</span>
                        <h4 class="mt-2 text-title-xl font-bold leading-none tracking-tight text-gray-800 dark:text-white/90" x-text="(rsvpSummary.attendance.checked_in_yes ?? 0) + ' / ' + (rsvpSummary.attendance.expected_head_count ?? rsvpSummary.counts.total_head_count ?? rsvpSummary.counts.total_rsvps ?? 0)"></h4>
                        <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500" x-text="'Not checked in: ' + (rsvpSummary.attendance.not_checked_in_yes ?? 0)"></p>
                    </div>
                </div>
                <template x-if="rsvpSummary.capacity">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7l8-4 8 4M4 7v10l8 4 8-4V7M4 7l8 4 8-4M12 21V11"></path></svg>
                        </div>
                        <div class="mt-5">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Capacity</span>
                            <h4 class="mt-2 text-title-xl font-bold leading-none tracking-tight text-gray-800 dark:text-white/90" x-text="(rsvpSummary.counts.total_head_count ?? rsvpSummary.counts.total_rsvps ?? 0) + ' / ' + (rsvpSummary.capacity || 0)"></h4>
                            <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500" x-text="'Available: ' + (rsvpSummary.available_spots || 0)"></p>
                        </div>
                    </div>
                </template>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div class="mt-5">
                        <span class="text-sm text-gray-500 dark:text-gray-400">No response</span>
                        <h4 class="mt-2 text-title-xl font-bold leading-none tracking-tight text-gray-800 dark:text-white/90" x-text="(rsvpSummary.no_response_count || 0) + ''"></h4>
                        <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">Members who haven't RSVP'd yet</p>
                    </div>
                </div>
            </div>
        </div>
        </template>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between border-b border-gray-200 pb-3 mb-4 mt-2 dark:border-gray-700">
            <nav class="flex gap-1" aria-label="RSVP report sections">
                <button type="button"
                        @click="rsvpReportSubTab = 'responses'"
                        :class="rsvpReportSubTab === 'responses' ? 'border-brand-200 bg-brand-50 text-brand-700 shadow-card' : 'border-transparent bg-gray-50 text-gray-600 hover:bg-gray-100'"
                        class="px-4 py-2.5 rounded-xl text-sm font-bold border transition-colors flex items-center gap-2">
                    <span>RSVP responses</span>
                    <span x-show="!loadingRsvps" class="text-[10px] font-black tabular-nums px-2 py-0.5 rounded-full"
                          :class="rsvpReportSubTab === 'responses' ? 'bg-white/80 text-brand-800' : 'bg-gray-200/80 text-gray-700'"
                          x-text="rsvpList.length"></span>
                </button>
                <button type="button"
                        @click="rsvpReportSubTab = 'checkins'; loadCheckins()"
                        :class="rsvpReportSubTab === 'checkins' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 shadow-card' : 'border-transparent bg-gray-50 text-gray-600 hover:bg-gray-100'"
                        class="px-4 py-2.5 rounded-xl text-sm font-bold border transition-colors flex items-center gap-2">
                    <span>Checked in</span>
                    <span x-show="!loadingCheckins" class="text-[10px] font-black tabular-nums px-2 py-0.5 rounded-full"
                          :class="rsvpReportSubTab === 'checkins' ? 'bg-white/80 text-emerald-900' : 'bg-gray-200/80 text-gray-700'"
                          x-text="checkinList.length"></span>
                </button>
            </nav>
            <div class="flex flex-wrap gap-2 shrink-0">
                <a x-show="rsvpReportSubTab === 'responses'"
                   :href="apiBase + '/event-rsvp-export.php?event_id=' + eventId"
                   class="btn-secondary flex items-center gap-2 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Export RSVPs (CSV)
                </a>
                <a x-show="rsvpReportSubTab === 'checkins'"
                   :href="apiBase + '/event-checkin-export.php?event_id=' + eventId"
                   class="btn-secondary bg-emerald-50 text-emerald-900 border-emerald-100 hover:bg-emerald-100 flex items-center gap-2 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Export check-ins (CSV)
                </a>
            </div>
        </div>

        <div x-show="canCorrectCheckins" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            <p class="font-semibold">Correct attendance</p>
            <p class="mt-1 text-amber-900/90">You can add, remove, or edit check-ins for this event after it has ended. Each change is logged and requires a short reason. Live check-in at the door is unchanged.</p>
        </div>
        <div x-show="rsvpReportSubTab === 'responses'">
        <div x-show="loadingRsvps" class="py-12 text-center">
            <div class="inline-block animate-spin w-8 h-8 border-4 border-brand-500 border-t-transparent rounded-full"></div>
            <p class="mt-4 text-gray-500 font-bold uppercase tracking-widest text-xs dark:text-gray-400">Loading...</p>
        </div>
        <div x-show="!loadingRsvps && rsvpList.length === 0" class="py-12 text-center text-gray-500 dark:text-gray-400">
            <p>No RSVPs yet for this event.</p>
        </div>
        <div x-show="!loadingRsvps && rsvpList.length > 0" class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">RSVP responses</h3>
            </div>
            <div class="w-full overflow-x-auto custom-scrollbar">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Type</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Guests</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Potluck</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Payment</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Response date</p></th>
                            <template x-if="canCorrectCheckins">
                                <th class="py-3 pr-4 text-left min-w-[140px]"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Attendance</p></th>
                            </template>
                        </tr>
                    </thead>
                    <template x-for="rsvp in rsvpList" :key="rsvp.id">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-3">
                                            <span class="ta-avatar ta-avatar-sm bg-brand-100 text-brand-700" x-text="((rsvp.first_name || '').charAt(0) + (rsvp.last_name || '').charAt(0)).toUpperCase() || '?'"></span>
                                            <div class="min-w-0">
                                                <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="(rsvp.first_name || '') + ' ' + (rsvp.last_name || '')"></span>
                                                <span class="block text-theme-xs text-gray-500 dark:text-gray-400 truncate max-w-[180px]" x-text="rsvp.email || '\u2014'"></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium"
                                              :class="rsvp.user_type === 'Member' ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' : 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400'"
                                              x-text="rsvp.user_type || '\u2014'"></span>
                                    </td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 text-center">
                                        <span class="font-bold text-gray-700 dark:text-gray-200" x-text="rsvp.guest_count !== undefined ? rsvp.guest_count : (rsvp.notes && rsvp.notes.includes('Guests:') ? rsvp.notes.replace(/[^0-9]/g, '') : 0)"></span>
                                    </td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 text-xs max-w-[260px]">
                                        <template x-if="rsvp.potluck_category_label || rsvp.potluck_item_note || (rsvp.potluck_quantity != null && rsvp.potluck_quantity !== '')">
                                            <div>
                                                <div class="font-semibold text-gray-800 dark:text-gray-100" x-text="rsvp.potluck_category_label || '\u2014'"></div>
                                                <div class="text-gray-500 mt-0.5 break-words dark:text-gray-400" x-show="rsvp.potluck_item_note" x-text="rsvp.potluck_item_note"></div>
                                                <div class="text-gray-500 mt-1 space-y-0.5 dark:text-gray-400" x-show="rsvp.potluck_quantity != null || rsvp.potluck_serving_side_label || rsvp.potluck_party_adults != null">
                                                    <div x-show="rsvp.potluck_quantity != null && rsvp.potluck_quantity !== ''"><span class="font-medium text-gray-600 dark:text-gray-300">Qty:</span> <span x-text="rsvp.potluck_quantity"></span></div>
                                                    <div x-show="rsvp.potluck_serving_side_label"><span class="font-medium text-gray-600 dark:text-gray-300">Side:</span> <span x-text="rsvp.potluck_serving_side_label"></span></div>
                                                    <div x-show="rsvp.potluck_party_adults != null"><span class="font-medium text-gray-600 dark:text-gray-300">Attending:</span> <span x-text="(rsvp.potluck_party_adults || 0) + ' adults'"></span>, <span x-text="(rsvp.potluck_party_children || 0) + ' children'"></span></div>
                                                </div>
                                            </div>
                                        </template>
                                        <span x-show="!rsvp.potluck_category_label && !rsvp.potluck_item_note && (rsvp.potluck_quantity == null || rsvp.potluck_quantity === '')">&mdash;</span>
                                    </td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300">
                                        <template x-if="rsvp.payment_id && (rsvp.payment_method || '').toLowerCase() === 'cash'">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-xs text-gray-600 dark:text-gray-300">Cash $<span x-text="(rsvp.payment_amount != null) ? parseFloat(rsvp.payment_amount).toFixed(2) : '0.00'"></span></span>
                                                <button type="button" @click="deleteCash(rsvp)" class="text-[10px] font-bold text-rose-600 hover:underline">Delete</button>
                                            </div>
                                        </template>
                                        <template x-if="rsvp.payment_id && (rsvp.payment_status || 'paid') === 'pending' && (rsvp.payment_method || '').toLowerCase() !== 'cash'">
                                            <div class="text-xs text-amber-700 font-medium">Stripe checkout pending</div>
                                            <div class="text-[10px] text-gray-500 mt-0.5 dark:text-gray-400">Use Payments &rarr; Sync Stripe if payment succeeded in Stripe.</div>
                                        </template>
                                        <template x-if="rsvp.payment_id || !rsvp.payment_id">
                                            <div class="mt-2">
                                                <button type="button" @click="deleteRsvp(rsvp)" class="text-[10px] font-bold text-rose-700 hover:underline">
                                                    Remove RSVP
                                                </button>
                                            </div>
                                        </template>
                                        <template x-if="rsvp.payment_id && (rsvp.payment_status || 'paid') === 'paid' && (rsvp.payment_method || '').toLowerCase() !== 'cash'">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">$<span x-text="(rsvp.payment_amount != null) ? parseFloat(rsvp.payment_amount).toFixed(2) : '0.00'"></span> (card)<span x-show="rsvp.is_refunded" class="ml-1 text-rose-600 font-bold">Refunded</span></span>
                                        </template>
                                        <template x-if="rsvp.payment_id && rsvp.is_refunded && (rsvp.payment_method || '').toLowerCase() === 'cash'">
                                            <span class="text-xs text-rose-600 font-bold">Refunded</span>
                                        </template>
                                        <template x-if="!rsvp.payment_id">
                                            <div class="flex items-center gap-2">
                                                <template x-if="recordingCashFor === rsvp.user_id">
                                                    <span class="flex items-center gap-2">
                                                        <input type="number" step="0.01" min="0.01" x-model="cashAmount" placeholder="0.00" class="w-20 text-xs border border-gray-200 rounded px-2 py-1 dark:border-gray-700">
                                                        <button type="button" @click="recordCash(rsvp)" :disabled="cashSaving" class="text-[10px] font-bold text-emerald-600 hover:underline disabled:opacity-50">Save</button>
                                                        <button type="button" @click="recordingCashFor = null; cashAmount = ''" class="text-[10px] text-gray-500 hover:underline dark:text-gray-400">Cancel</button>
                                                    </span>
                                                </template>
                                                <template x-if="recordingCashFor !== rsvp.user_id">
                                                    <button type="button" @click="recordingCashFor = rsvp.user_id; cashAmount = ''" class="text-[10px] font-bold text-emerald-600 hover:underline">Record cash</button>
                                                </template>
                                            </div>
                                        </template>
                                    </td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-500 dark:text-gray-400" x-text="formatRsvpDate(rsvp.created_at)"></td>
                                    <template x-if="canCorrectCheckins">
                                        <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 min-w-[140px]">
                                            <template x-if="String(rsvp.status || '').toLowerCase() === 'yes'">
                                                <div class="space-y-1.5">
                                                    <span class="font-semibold block"
                                                          :class="rsvp.checked_in ? 'text-emerald-700' : 'text-amber-700'"
                                                          x-text="rsvp.checked_in ? 'Checked in' : 'Not checked in'"></span>
                                                    <button type="button" x-show="!rsvp.checked_in"
                                                            @click="openCorrectionModal('checkin', rsvp.user_id, (rsvp.first_name || '') + ' ' + (rsvp.last_name || ''))"
                                                            class="text-xs font-bold text-brand-600 hover:underline">Mark checked in</button>
                                                    <button type="button" x-show="rsvp.checked_in"
                                                            @click="openCorrectionModal('undo', rsvp.user_id, (rsvp.first_name || '') + ' ' + (rsvp.last_name || ''))"
                                                            class="text-xs font-bold text-rose-600 hover:underline">Remove check-in</button>
                                                </div>
                                            </template>
                                            <span x-show="String(rsvp.status || '').toLowerCase() !== 'yes'" class="text-gray-400">\u2014</span>
                                        </td>
                                    </template>
                                </tr>
                        </tbody>
                        </template>
                </table>
            </div>
        </div>
        </div>

        <div x-show="rsvpReportSubTab === 'checkins'">
        <div x-show="loadingCheckins" class="py-8 text-center">
            <div class="inline-block animate-spin w-8 h-8 border-4 border-emerald-500 border-t-transparent rounded-full"></div>
            <p class="mt-4 text-gray-500 font-bold uppercase tracking-widest text-xs dark:text-gray-400">Loading check-ins...</p>
        </div>
        <div x-show="!loadingCheckins && checkinList.length === 0" class="py-8 text-center text-gray-500 bento-card dark:text-gray-400">
            <p>No one has checked in yet for this event.</p>
        </div>
        <div x-show="!loadingCheckins && checkinList.length > 0" class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Checked in</h3>
            </div>
            <div class="w-full overflow-x-auto custom-scrollbar">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Phone</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Type</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">RSVP</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Checked in</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Recorded by</p></th>
                            <th class="py-3 pr-4 text-center"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Guests</p></th>
                            <template x-if="canCorrectCheckins">
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</p></th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="c in checkinList" :key="c.user_id + '-' + (c.checked_in_at || '')">
                            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-3">
                                        <span class="ta-avatar ta-avatar-sm bg-success-100 text-success-700" x-text="((c.first_name || '').charAt(0) + (c.last_name || '').charAt(0)).toUpperCase() || '?'"></span>
                                        <div class="min-w-0">
                                            <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="(c.first_name || '') + ' ' + (c.last_name || '')"></span>
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400 truncate max-w-[160px]" x-text="c.email || '\u2014'"></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 whitespace-nowrap" x-text="c.phone || '\u2014'"></td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium"
                                          :class="c.user_type === 'Member' ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' : 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400'"
                                          x-text="c.user_type || '\u2014'"></span>
                                </td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium"
                                          :class="c.rsvp_label === 'Walk-in' ? 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'"
                                          x-text="c.rsvp_label || '\u2014'"></span>
                                </td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 whitespace-nowrap" x-text="formatRsvpDate(c.checked_in_at)"></td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-600 dark:text-gray-400" x-text="c.checked_in_by || '\u2014'"></td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 text-center" x-text="c.guests_checked_in !== undefined ? c.guests_checked_in : '\u2014'"></td>
                                <template x-if="canCorrectCheckins">
                                    <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                        <button type="button" @click="openCorrectionModal('update', c.user_id, (c.first_name || '') + ' ' + (c.last_name || ''), c.checked_in_at, c.guests_checked_in)" class="text-[10px] font-bold text-brand-600 hover:underline mr-2">Edit time</button>
                                        <button type="button" @click="openCorrectionModal('undo', c.user_id, (c.first_name || '') + ' ' + (c.last_name || ''))" class="text-[10px] font-bold text-rose-600 hover:underline">Remove</button>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
    </div>


        <div x-show="showCorrectionModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-black/40" @click="closeCorrectionModal()"></div>
            <div class="relative bg-white rounded-2xl shadow-xl max-w-md w-full p-6 z-10 dark:bg-gray-800">
                <h3 class="text-lg font-bold text-gray-900 mb-1 dark:text-white" x-text="correctionForm.action === 'undo' ? 'Remove check-in' : (correctionForm.action === 'update' ? 'Edit check-in time' : 'Mark checked in')"></h3>
                <p class="text-sm text-gray-600 mb-4 dark:text-gray-300" x-text="correctionForm.user_name"></p>
                <div class="space-y-4">
                    <div x-show="correctionForm.action !== 'undo'">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-400">Check-in time</label>
                        <input type="datetime-local" x-model="correctionForm.checked_in_at_local" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-400">Reason (required)</label>
                        <textarea x-model="correctionForm.reason" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700" placeholder="e.g. Verified in person after event"></textarea>
                    </div>
                </div>
                <div class="flex gap-2 mt-6 justify-end">
                    <button type="button" @click="closeCorrectionModal()" class="btn-secondary text-sm py-2 px-4">Cancel</button>
                    <button type="button" @click="submitCorrection()" :disabled="correctionSaving" class="btn-primary text-sm py-2 px-4 disabled:opacity-50" x-text="correctionSaving ? 'Saving...' : 'Confirm'"></button>
                </div>
            </div>
        </div>

    <!-- Tab: Email -->    <!-- Tab: Email -->
    <?php if (!$isCoordinator): ?>
    <div x-show="activeTab === 'email'" x-cloak class="space-y-6">
        <div class="bento-card p-6">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Send email</h3>
            <div class="flex flex-wrap gap-4">
                <button type="button" @click.prevent="announceEvent()" :disabled="composerSending" class="btn-primary flex items-center gap-2 disabled:opacity-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    <span>Announce to all members</span>
                </button>
                <button type="button" @click.prevent="sendReminderEvent()" :disabled="composerSending" class="btn-secondary flex items-center gap-2 border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 disabled:opacity-50">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    <span>Send reminder to registered attendees</span>
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-4 dark:text-gray-400">Announcement goes to all members. Reminder goes only to people who registered for this event and RSVP'd Yes and have event reminders enabled.</p>
        </div>
        <div class="bento-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider">Email activity for this event</h3>
                <button type="button" @click="loadEmailLogs()" class="text-xs font-bold text-brand-600 hover:underline">Refresh</button>
            </div>
            <div x-show="emailLogsLoading" class="py-6 text-center text-gray-500 text-sm dark:text-gray-400">
                Loading email activity...
            </div>
            <div x-show="!emailLogsLoading && emailLogs.length === 0" class="py-2 text-sm text-gray-500 dark:text-gray-400">
                No email activity logged yet for this event.
            </div>
            <div x-show="!emailLogsLoading && emailLogs.length > 0" class="-mx-4 overflow-hidden rounded-xl border border-gray-200 shadow-card sm:mx-0 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                        <tr>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">When</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Subject</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Recipient</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Type</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="log in emailLogs" :key="log.id">
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400" x-text="log.sent_at || log.created_at || '\u2014'"></td>
                                <td class="px-4 py-2 max-w-[220px] truncate" x-text="log.subject || '\u2014'"></td>
                                <td class="px-4 py-2 max-w-[200px] truncate">
                                    <span x-text="(log.recipient_first_name || log.recipient_last_name) ? ((log.recipient_first_name || '') + ' ' + (log.recipient_last_name || '') + ' | ' + (log.recipient_email || '')) : (log.recipient_email || '\u2014')"></span>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-300" x-text="log.email_type || 'custom'"></td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                          :class="log.status === 'sent' ? 'bg-emerald-50 text-emerald-700' : (log.status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-gray-50 text-gray-600')"
                                          x-text="log.status || 'queued'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <div x-show="showEmailComposer" x-cloak class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-3 sm:p-4">
            <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showEmailComposer = false"></div>
            <div class="relative z-10 mx-auto my-auto flex min-w-0 max-h-[calc(100vh-2rem)] w-[calc(100%-1.5rem)] flex-col overflow-y-hidden overflow-x-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg sm:max-h-[calc(100vh-4rem)] md:w-[860px] md:max-w-[860px] dark:bg-gray-800 dark:border-gray-700">
                <div class="flex shrink-0 items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="composerTitle"></h3>
                    <button type="button" class="rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:bg-gray-800 dark:text-white" @click="showEmailComposer = false" aria-label="Close">Close</button>
                </div>
                <div class="p-6 space-y-4 overflow-y-auto min-h-0 flex-1">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 dark:text-gray-300">Use template</label>
                        <select x-model="composerTemplateId" @change="applyComposerTemplate()" class="ta-select w-full">
                            <option value="">Start from current draft</option>
                            <template x-for="t in composerTemplates" :key="t.id">
                                <option :value="String(t.id)" x-text="(t.name || t.subject || 'Template') + ' [' + (t.template_type || 'custom') + ']'"></option>
                            </template>
                        </select>
                        <p x-show="composerLoadingTemplates" class="text-xs text-gray-500 mt-1 dark:text-gray-400">Loading templates...</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 dark:text-gray-300">Subject</label>
                        <input type="text" x-model="composer.subject" class="ta-input w-full" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2 dark:text-gray-300">Message</label>
                        <div id="email-composer-body-wrap" class="rounded-xl border border-gray-200 overflow-hidden bg-white dark:bg-gray-800 dark:border-gray-700">
                            <textarea id="email-composer-body" class="wysiwyg-editor w-full text-sm" rows="6" x-model="composer.bodyHtml"></textarea>
                        </div>
                        <p class="text-xs text-gray-500 mt-2 dark:text-gray-400">Placeholders: {first_name}, {name}, {event_name}, {event_day}, {event_date}, {event_time}, {event_location}</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-3 border-t border-gray-200 bg-white px-6 py-4 dark:bg-gray-800 dark:border-gray-700">
                    <button type="button" @click="showEmailComposer = false" class="page-header-btn-secondary text-sm">Cancel</button>
                    <button type="button" @click="sendComposedEmail()" :disabled="composerSending" class="page-header-btn-primary text-sm disabled:opacity-50">
                        <span x-text="composerSending ? 'Sending...' : 'Send now'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="<?= e($basePath) ?>/public/admin/js/quill-rich-toolbar.js"></script>
<style>
#email-composer-body-wrap { max-width: 100%; }
#email-composer-body-wrap .ql-toolbar.ql-snow { border-radius: 0.75rem 0.75rem 0 0; }
#email-composer-body-wrap .ql-container.ql-snow {
    border-radius: 0 0 0.75rem 0.75rem;
    max-width: 100%;
    min-width: 0;
}
#email-composer-body-wrap .ql-editor {
    min-height: 200px;
    font-size: 14px;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
}
#email-composer-body-wrap .ql-editor * {
    max-width: 100%;
}
#email-composer-body-wrap .ql-editor p,
#email-composer-body-wrap .ql-editor li,
#email-composer-body-wrap .ql-editor a,
#email-composer-body-wrap .ql-editor span {
    overflow-wrap: anywhere;
    word-break: break-word;
}
#email-composer-body-wrap .ql-editor img,
#email-composer-body-wrap .ql-editor video,
#email-composer-body-wrap .ql-editor iframe,
#email-composer-body-wrap .ql-editor table,
#email-composer-body-wrap .ql-editor pre,
#email-composer-body-wrap .ql-editor blockquote {
    max-width: 100%;
}
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
