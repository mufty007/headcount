<?php

/**
 * Events Management Page
 * Server-side rendered events listing with filtering
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
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\EventSeriesHelper;

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$requestValue = static function (string $key, $default = null) {
    if (!isset($_GET[$key])) {
        return $default;
    }
    $value = $_GET[$key];
    if (is_array($value)) {
        $last = end($value);
        return $last === false ? $default : $last;
    }
    return $value;
};

// Get the current user for the header (include role for coordinator vs admin nav)
$userId = AuthMiddleware::getUserId();
$userData = null;
try {
    $userData = $db->queryOne("SELECT first_name, last_name, email, role, organization_id FROM users WHERE id = :id", ['id' => $userId]);
} catch (Exception $e) {
    error_log('events.php: failed to load current user: ' . $e->getMessage());
}
if ((empty($organizationId) || $organizationId === '0') && !empty($userData['organization_id'])) {
    $organizationId = (int) $userData['organization_id'];
    $_SESSION['organization_id'] = $organizationId;
}
$hasOrganizationFilter = !empty($organizationId);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin'
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local',
    'role' => $_SESSION['role'] ?? 'admin'
];

// Get filter parameters
$status = (string) $requestValue('status', 'all');
$category = (string) $requestValue('category', 'all');
$search = (string) $requestValue('search', '');

// Pagination (10 per page; use ?p=2 for next page)
$perPage        = 10;
$currentPageNum = max(1, (int) ($_GET['p'] ?? 1));
$offset         = ($currentPageNum - 1) * $perPage;

// Build query with organization filter
$hasParentEventId = headcount_db_has_column($db, 'events', 'parent_event_id');
$hasRecurringInstanceFlag = headcount_db_has_column($db, 'events', 'is_recurring_instance');

// One row per series by default; expand_sessions=1 lists every session as its own card.
$expandRaw = $_GET['expand_sessions'] ?? null;
$expandSessions = is_array($expandRaw)
    ? ((string) end($expandRaw) === '1')
    : ($expandRaw === '1' || $expandRaw === 1);
$collapseSeries = $hasParentEventId && !$expandSessions;

$hasSessionRegMode = headcount_db_has_column($db, 'events', 'session_registration_mode');

$hasRsvpsTable = headcount_db_table_exists($db, 'rsvps');
$hasAttendanceTable = headcount_db_table_exists($db, 'attendance');
$hasCategoriesTable = headcount_db_table_exists($db, 'categories');
$hasEventCategoriesTable = headcount_db_table_exists($db, 'event_categories');

// RSVP head/registrant counts are filled after the list query (see enrich block below). Heavy correlated
// subqueries in the main SELECT caused query failures and SELECT * fallbacks with missing columns → zeros.

$rsvpHasGuestCount = false;
if ($hasRsvpsTable) {
    try {
        $rsvpCols = $db->query('SHOW COLUMNS FROM rsvps');
        $rsvpHasGuestCount = in_array('guest_count', array_column($rsvpCols, 'Field'), true);
    } catch (Exception $e) {
        $rsvpHasGuestCount = false;
    }
}

$sql = "SELECT e.*, 
        0 as rsvp_registrant_count,
        0 as rsvp_head_count,
        0 as checkin_count";
        
if ($hasParentEventId) {
    $sql .= ",
        (SELECT COUNT(*) FROM events WHERE parent_event_id = e.id) as recurring_instance_count,
        COALESCE(NULLIF(TRIM(e.banner_image), ''), parent_banner.banner_image) AS effective_banner_image";
} else {
    $sql .= ",
        e.banner_image AS effective_banner_image";
}

$sql .= " FROM events e ";
if ($hasParentEventId) {
    $sql .= "LEFT JOIN events parent_banner ON e.parent_event_id = parent_banner.id ";
}
$sql .= "WHERE 1=1";
$params = [];
if ($hasOrganizationFilter) {
    $sql .= " AND e.organization_id = :org_id";
    $params['org_id'] = $organizationId;
}

if ($status !== 'all') {
    $sql .= " AND e.status = :status";
    $params['status'] = $status;
}

if ($category !== 'all' && $hasCategoriesTable && $hasEventCategoriesTable) {
    // Support both legacy single category and new multiple categories
    $sql .= " AND (e.category = :category OR EXISTS (
        SELECT 1 FROM event_categories ec 
        INNER JOIN categories c ON ec.category_id = c.id 
        WHERE ec.event_id = e.id AND (c.id = :category OR c.name = :category)
    ))";
    $params['category'] = $category;
} elseif ($category !== 'all') {
    $sql .= " AND e.category = :category";
    $params['category'] = $category;
}

if ($search) {
    $sql .= " AND (e.title LIKE :search1 OR e.location LIKE :search2)";
    $params['search1'] = "%$search%";
    $params['search2'] = "%$search%";
}

if ($hasParentEventId && $collapseSeries) {
    $sql .= ' AND (e.parent_event_id IS NULL OR e.parent_event_id = 0)';
    if ($hasRecurringInstanceFlag) {
        $sql .= ' AND COALESCE(e.is_recurring_instance, 0) = 0';
    }
}

$sql .= " ORDER BY e.event_date DESC, e.start_time DESC";

// --- COUNT query for pagination (same WHERE, no LIMIT) ---
$countSql = "SELECT COUNT(*) AS total FROM events e ";
if ($hasParentEventId) {
    $countSql .= "LEFT JOIN events parent_banner ON e.parent_event_id = parent_banner.id ";
}
$countSql .= "WHERE 1=1";
if ($hasOrganizationFilter) {
    $countSql .= " AND e.organization_id = :org_id";
}
// Re-apply the same WHERE conditions
if ($status !== 'all') {
    $countSql .= " AND e.status = :status";
}
if ($category !== 'all' && $hasCategoriesTable && $hasEventCategoriesTable) {
    $countSql .= " AND (e.category = :category OR EXISTS (
        SELECT 1 FROM event_categories ec
        INNER JOIN categories c ON ec.category_id = c.id
        WHERE ec.event_id = e.id AND (c.id = :category OR c.name = :category)
    ))";
} elseif ($category !== 'all') {
    $countSql .= " AND e.category = :category";
}
if ($search) {
    $countSql .= " AND (e.title LIKE :search1 OR e.location LIKE :search2)";
}
if ($hasParentEventId && $collapseSeries) {
    $countSql .= ' AND (e.parent_event_id IS NULL OR e.parent_event_id = 0)';
    if ($hasRecurringInstanceFlag) {
        $countSql .= ' AND COALESCE(e.is_recurring_instance, 0) = 0';
    }
}
try {
    $countRow   = $db->queryOne($countSql, $params);
    $totalCount = (int) ($countRow['total'] ?? 0);
} catch (\Exception $e) {
    $totalCount = 0;
}
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$currentPageNum = min($currentPageNum, $totalPages);
$offset = ($currentPageNum - 1) * $perPage;

// Apply LIMIT/OFFSET
$sql .= " LIMIT :limit OFFSET :offset";
$params['limit']  = $perPage;
$params['offset'] = $offset;

// Minimal SELECT used when the main query fails (must respect grouped filters or PHP merge collapses many sessions into few cards).
$fallbackLimitInt  = max(1, (int) $perPage);
$fallbackOffsetInt = max(0, (int) $offset);
$fallbackGroupedExtra = '';
if ($hasParentEventId && $collapseSeries) {
    $fallbackGroupedExtra .= ' AND (parent_event_id IS NULL OR parent_event_id = 0)';
    if ($hasRecurringInstanceFlag) {
        $fallbackGroupedExtra .= ' AND COALESCE(is_recurring_instance, 0) = 0';
    }
}

$events = [];
$eventsFromPrimarySelect = false;
try {
    $events = $db->query($sql, $params);
    $eventsFromPrimarySelect = true;
} catch (Exception $e) {
    error_log('events.php: main events query failed, rendering empty list fallback: ' . $e->getMessage());
    $events = [];
    // Hard fallback: same org + grouped root filter as the main list, same sort as primary query.
    try {
        if ($hasOrganizationFilter) {
            $events = $db->query(
                "SELECT * FROM events WHERE organization_id = :org_id{$fallbackGroupedExtra} ORDER BY event_date DESC, start_time DESC LIMIT {$fallbackLimitInt} OFFSET {$fallbackOffsetInt}",
                ['org_id' => $organizationId]
            );
        } else {
            $events = $db->query(
                "SELECT * FROM events WHERE 1=1{$fallbackGroupedExtra} ORDER BY event_date DESC, start_time DESC LIMIT {$fallbackLimitInt} OFFSET {$fallbackOffsetInt}"
            );
        }
    } catch (Exception $e2) {
        error_log('events.php: hard fallback events query failed: ' . $e2->getMessage());
        $events = [];
    }
}

// Fallback: some legacy datasets have only child session rows and no visible parent/root rows.
// If grouped mode yields no events, retry once without the grouped-only parent filter.
if (empty($events) && $hasParentEventId && $collapseSeries) {
    $fallbackSql = str_replace(' AND (e.parent_event_id IS NULL OR e.parent_event_id = 0)', '', $sql);
    try {
        $events = $db->query($fallbackSql, $params);
        if (!empty($events)) {
            $collapseSeries = false;
        }
    } catch (Exception $e) {
        error_log('events.php: fallback events query failed: ' . $e->getMessage());
    }
}
if (empty($events)) {
    try {
        if ($hasOrganizationFilter) {
            $events = $db->query(
                "SELECT * FROM events WHERE organization_id = :org_id{$fallbackGroupedExtra} ORDER BY event_date DESC, start_time DESC LIMIT {$fallbackLimitInt} OFFSET {$fallbackOffsetInt}",
                ['org_id' => $organizationId]
            );
        } else {
            $events = $db->query(
                "SELECT * FROM events WHERE 1=1{$fallbackGroupedExtra} ORDER BY event_date DESC, start_time DESC LIMIT {$fallbackLimitInt} OFFSET {$fallbackOffsetInt}"
            );
        }
    } catch (Exception $e) {
        error_log('events.php: final fallback events query failed: ' . $e->getMessage());
    }
}

// Enforce grouped mode in PHP too (defensive against inconsistent legacy flags/data).
// Only merge rows that came from the primary SELECT: fallback mixes child rows without the SQL parent filter and would collapse the whole page into one card per series.
if ($collapseSeries && !empty($events) && $eventsFromPrimarySelect) {
    $grouped = [];
    $groupMeta = [];
    $todayYmd = date('Y-m-d');
    foreach ($events as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $pid = (int) ($row['parent_event_id'] ?? 0);
        $groupKey = $pid > 0 ? $pid : $id;
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = $row;
            $groupMeta[$groupKey] = ['count' => 1];
            continue;
        }
        $groupMeta[$groupKey]['count']++;
        $existing = $grouped[$groupKey];
        $existingId = (int) ($existing['id'] ?? 0);

        // Prefer the root/parent row whenever available in grouped mode.
        if ($id === $groupKey && $existingId !== $groupKey) {
            $grouped[$groupKey] = $row;
            continue;
        }
        if ($existingId === $groupKey) {
            continue;
        }

        // If neither row is the root, keep the nearest upcoming session; otherwise latest past.
        $existingDate = substr((string) ($existing['event_date'] ?? ''), 0, 10);
        $rowDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
        $existingUpcoming = ($existingDate !== '' && $existingDate >= $todayYmd);
        $rowUpcoming = ($rowDate !== '' && $rowDate >= $todayYmd);
        if ($rowUpcoming && !$existingUpcoming) {
            $grouped[$groupKey] = $row;
            continue;
        }
        if ($rowUpcoming && $existingUpcoming && $rowDate < $existingDate) {
            $grouped[$groupKey] = $row;
            continue;
        }
        if (!$rowUpcoming && !$existingUpcoming && $rowDate > $existingDate) {
            $grouped[$groupKey] = $row;
            continue;
        }
    }
    $events = array_values($grouped);
    foreach ($events as &$ev) {
        $gk = (int) (($ev['parent_event_id'] ?? 0) > 0 ? $ev['parent_event_id'] : ($ev['id'] ?? 0));
        $cnt = (int) ($groupMeta[$gk]['count'] ?? 1);
        if ($cnt > 1) {
            $ev['recurring_instance_count'] = max(1, $cnt - 1);
            $ev['_grouped_recurring'] = 1;
        } else {
            $ev['_grouped_recurring'] = 0;
        }
    }
    unset($ev);
}
headcount_decode_html_entities_in_event_rows($events);

// Grouped recurring list: surface next list-visible session (same status filter as the list) for date, links, and per-session counts.
if ($collapseSeries && $hasParentEventId && !empty($events)) {
    $seriesRoots = [];
    foreach ($events as $ev) {
        if (!is_array($ev)) {
            continue;
        }
        $eid = (int) ($ev['id'] ?? 0);
        $pp = (int) ($ev['parent_event_id'] ?? 0);
        if ($eid <= 0 || $pp !== 0) {
            continue;
        }
        $seriesRoots[] = $eid;
    }
    $seriesRoots = array_values(array_unique(array_filter($seriesRoots)));
    if ($seriesRoots !== []) {
        $todayYmdSurface = date('Y-m-d');
        $nowHiSurface = date('H:i:s');
        $byRootRows = EventSeriesHelper::fetchSeriesSessionsGroupedForRoots(
            $db,
            $seriesRoots,
            $hasOrganizationFilter ? (int) $organizationId : null,
            $hasOrganizationFilter,
            $status
        );
        foreach ($events as &$evSurf) {
            if (!is_array($evSurf)) {
                continue;
            }
            $eid = (int) ($evSurf['id'] ?? 0);
            if ($eid <= 0 || (int) ($evSurf['parent_event_id'] ?? 0) !== 0) {
                continue;
            }
            $rows = $byRootRows[$eid] ?? [];
            $picked = EventSeriesHelper::pickPreferredSeriesSessionRow($rows, $todayYmdSurface, $nowHiSurface);
            if ($picked !== null && !empty($picked['event_date'])) {
                $evSurf['_list_surface_event_date'] = $picked['event_date'];
                $evSurf['_list_surface_start_time'] = $picked['start_time'] ?? null;
                $evSurf['_list_surface_event_id'] = (int) ($picked['id'] ?? 0);
                if (isset($picked['status'])) {
                    $evSurf['_list_surface_status'] = $picked['status'];
                }
            }
        }
        unset($evSurf);
    }
}

// RSVP + check-in counts per card (PHP): avoids fragile correlated SQL; matches RSVP source + attendance scope used on event details.
if (!empty($events)) {
    $pageMetaIds = [];
    foreach ($events as $evMetaInit) {
        $pageMetaIds[] = (int) ($evMetaInit['id'] ?? 0);
        $surfInit = (int) ($evMetaInit['_list_surface_event_id'] ?? 0);
        if ($surfInit > 0) {
            $pageMetaIds[] = $surfInit;
        }
    }
    $pageMetaIds = array_values(array_unique(array_filter($pageMetaIds)));
    $eventMetaById = [];
    if ($pageMetaIds !== []) {
        $metaPhInit = implode(',', array_map('intval', $pageMetaIds));
        $metaColsInit = 'id, parent_event_id';
        if ($hasSessionRegMode) {
            $metaColsInit .= ', session_registration_mode';
        }
        try {
            foreach ($db->query("SELECT {$metaColsInit} FROM events WHERE id IN ({$metaPhInit})") as $mr) {
                $eventMetaById[(int) $mr['id']] = $mr;
            }
        } catch (\Throwable $t) {
            error_log('events.php: batch event meta failed: ' . $t->getMessage());
        }
    }
    $sessionRegByRoot = [];
    if ($hasSessionRegMode && $hasParentEventId) {
        $rootMetaIds = [];
        foreach ($events as $evRoot) {
            $rid = (int) ($evRoot['id'] ?? 0);
            if ($rid > 0 && (int) ($evRoot['parent_event_id'] ?? 0) === 0) {
                $rootMetaIds[$rid] = true;
            }
        }
        if ($rootMetaIds !== []) {
            $rootPhInit = implode(',', array_map('intval', array_keys($rootMetaIds)));
            try {
                foreach ($db->query("SELECT id, session_registration_mode FROM events WHERE id IN ({$rootPhInit})") as $mr) {
                    $sessionRegByRoot[(int) $mr['id']] = strtolower(trim((string) ($mr['session_registration_mode'] ?? 'independent')));
                }
            } catch (\Throwable $t) {
                error_log('events.php: batch session_registration_mode failed: ' . $t->getMessage());
            }
        }
    }
    $resolveRsvpSource = static function (int $eventId) use ($eventMetaById, $sessionRegByRoot, $hasSessionRegMode): int {
        $row = $eventMetaById[$eventId] ?? null;
        if (!$row) {
            return $eventId;
        }
        $pid = (int) ($row['parent_event_id'] ?? 0);
        if ($pid > 0 && $hasSessionRegMode) {
            $mode = $sessionRegByRoot[$pid] ?? strtolower(trim((string) ($eventMetaById[$pid]['session_registration_mode'] ?? 'independent')));
            if ($mode === 'all_sessions') {
                return $pid;
            }
        }
        return (int) ($row['id'] ?? $eventId);
    };

    $rsvpBySource = [];
    if ($hasRsvpsTable) {
        $sourceIdsSet = [];
        foreach ($events as $ev) {
            $eid = (int) ($ev['id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }
            $sid = $resolveRsvpSource($eid);
            $sourceIdsSet[$sid] = true;
            $surf = (int) ($ev['_list_surface_event_id'] ?? 0);
            if ($surf > 0) {
                $sourceIdsSet[$resolveRsvpSource($surf)] = true;
            }
        }
        $srcList = array_keys($sourceIdsSet);
        sort($srcList, SORT_NUMERIC);
        if ($srcList !== []) {
            $ph = implode(',', array_map('intval', $srcList));
            try {
                $headSql = $rsvpHasGuestCount
                    ? 'COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0)'
                    : 'COUNT(*)';
                $rag = $db->query(
                    "SELECT event_id, COUNT(*) AS reg, {$headSql} AS heads
                     FROM rsvps WHERE status = 'yes' AND event_id IN ({$ph}) GROUP BY event_id"
                );
                foreach ($rag as $r) {
                    $rid = (int) ($r['event_id'] ?? 0);
                    if ($rid > 0) {
                        $rsvpBySource[$rid] = [
                            'reg' => (int) ($r['reg'] ?? 0),
                            'head' => (int) ($r['heads'] ?? 0),
                        ];
                    }
                }
            } catch (\Throwable $t) {
                error_log('events.php: batch RSVP counts failed: ' . $t->getMessage());
            }
        }
    }

    $childCountsByParent = [];
    if ($hasParentEventId) {
        $pageRootCandidates = [];
        foreach ($events as $ev) {
            $eid = (int) ($ev['id'] ?? 0);
            $pp = (int) ($ev['parent_event_id'] ?? 0);
            if ($eid > 0 && $pp === 0) {
                $pageRootCandidates[$eid] = true;
            }
        }
        $roots = array_keys($pageRootCandidates);
        if ($roots !== []) {
            $phr = implode(',', array_map('intval', $roots));
            try {
                $crow = $db->query(
                    "SELECT parent_event_id AS pid, COUNT(*) AS c FROM events WHERE parent_event_id IN ({$phr}) GROUP BY parent_event_id"
                );
                foreach ($crow as $cr) {
                    $childCountsByParent[(int) ($cr['pid'] ?? 0)] = (int) ($cr['c'] ?? 0);
                }
            } catch (\Throwable $t) {
                error_log('events.php: child session counts for list failed: ' . $t->getMessage());
            }
        }
    }

    $headExprSeries = $rsvpHasGuestCount
        ? 'COALESCE(SUM(1 + COALESCE(r.guest_count, 0)), 0)'
        : 'COUNT(*)';

    $metaIds = $pageMetaIds;
    $seriesRsvpByRoot = [];
    if ($hasRsvpsTable && $hasParentEventId && $childCountsByParent !== []) {
        $rootsNeedingAgg = [];
        foreach ($events as $evAgg) {
            $rid = (int) ($evAgg['id'] ?? 0);
            $pp = (int) ($evAgg['parent_event_id'] ?? 0);
            $surf = (int) ($evAgg['_list_surface_event_id'] ?? 0);
            if ($rid > 0 && $pp === 0 && ($childCountsByParent[$rid] ?? 0) > 0 && $surf <= 0) {
                $mode = $sessionRegByRoot[$rid] ?? 'independent';
                if ($mode !== 'all_sessions') {
                    $rootsNeedingAgg[$rid] = true;
                }
            }
        }
        if ($rootsNeedingAgg !== []) {
            $rootPh = implode(',', array_map('intval', array_keys($rootsNeedingAgg)));
            try {
                $aggRows = $db->query(
                    "SELECT COALESCE(NULLIF(ev.parent_event_id, 0), ev.id) AS root_id,
                            COUNT(*) AS reg, {$headExprSeries} AS heads
                     FROM rsvps r
                     INNER JOIN events ev ON ev.id = r.event_id
                     WHERE r.status = 'yes'
                     AND (ev.id IN ({$rootPh}) OR ev.parent_event_id IN ({$rootPh}))
                     GROUP BY root_id"
                );
                foreach ($aggRows as $ar) {
                    $seriesRsvpByRoot[(int) ($ar['root_id'] ?? 0)] = [
                        'reg' => (int) ($ar['reg'] ?? 0),
                        'head' => (int) ($ar['heads'] ?? 0),
                    ];
                }
            } catch (\Throwable $t) {
                error_log('events.php: batch series RSVP aggregate failed: ' . $t->getMessage());
            }
        }
    }

    $checkinByEventDate = [];
    if ($hasAttendanceTable && $metaIds !== []) {
        $attPh = implode(',', array_map('intval', $metaIds));
        try {
            foreach ($db->query(
                "SELECT a.event_id, DATE(a.checked_in_at) AS chk_date, COUNT(DISTINCT a.id) AS c
                 FROM attendance a
                 WHERE a.checked_in_at IS NOT NULL AND a.event_id IN ({$attPh})
                 GROUP BY a.event_id, DATE(a.checked_in_at)"
            ) as $cr) {
                $ek = (int) ($cr['event_id'] ?? 0) . '|' . ($cr['chk_date'] ?? '');
                $checkinByEventDate[$ek] = (int) ($cr['c'] ?? 0);
            }
        } catch (\Throwable $t) {
            error_log('events.php: batch check-in counts failed: ' . $t->getMessage());
        }
    }

    foreach ($events as &$ev) {
        $eid = (int) ($ev['id'] ?? 0);
        if ($eid <= 0) {
            $ev['rsvp_registrant_count'] = 0;
            $ev['rsvp_head_count'] = 0;
            $ev['checkin_count'] = 0;
            continue;
        }

        $ev['rsvp_registrant_count'] = 0;
        $ev['rsvp_head_count'] = 0;

        $parentRowPid = (int) ($ev['parent_event_id'] ?? 0);
        $listSurfaceId = (int) ($ev['_list_surface_event_id'] ?? 0);
        $hasSeriesChildren = $hasParentEventId && $parentRowPid === 0 && ($childCountsByParent[$eid] ?? 0) > 0;
        $seriesRegMode = $sessionRegByRoot[$eid] ?? 'independent';

        if ($hasRsvpsTable) {
            $usedSeriesAgg = false;
            if ($hasSeriesChildren && $seriesRegMode !== 'all_sessions') {
                if ($listSurfaceId > 0) {
                    $srcS = $resolveRsvpSource($listSurfaceId);
                    $packS = $rsvpBySource[$srcS] ?? null;
                    if ($packS !== null) {
                        $ev['rsvp_registrant_count'] = $packS['reg'];
                        $ev['rsvp_head_count'] = $packS['head'];
                    }
                    $usedSeriesAgg = true;
                } else {
                    $packRoot = $seriesRsvpByRoot[$eid] ?? null;
                    if ($packRoot !== null) {
                        $ev['rsvp_registrant_count'] = $packRoot['reg'];
                        $ev['rsvp_head_count'] = $packRoot['head'];
                        $usedSeriesAgg = true;
                    }
                }
            }
            if (!$usedSeriesAgg) {
                $src = $resolveRsvpSource($eid);
                $pack = $rsvpBySource[$src] ?? null;
                if ($pack !== null) {
                    $ev['rsvp_registrant_count'] = $pack['reg'];
                    $ev['rsvp_head_count'] = $pack['head'];
                }
            }
        }

        $ev['checkin_count'] = 0;
        if ($hasAttendanceTable) {
            $chkEid = $eid;
            $chkPid = $hasParentEventId ? (int) ($ev['parent_event_id'] ?? 0) : 0;
            $chkEd = substr((string) ($ev['event_date'] ?? ''), 0, 10);
            if ($hasSeriesChildren && $seriesRegMode !== 'all_sessions' && $listSurfaceId > 0) {
                $chkEid = $listSurfaceId;
                $chkPid = 0;
                $surfDate = $ev['_list_surface_event_date'] ?? ($ev['event_date'] ?? '');
                $chkEd = substr((string) $surfDate, 0, 10);
            }
            $chkKey = $chkEid . '|' . $chkEd;
            $ev['checkin_count'] = $checkinByEventDate[$chkKey] ?? 0;
            if ($chkPid > 0) {
                $parentKey = $chkPid . '|' . $chkEd;
                $ev['checkin_count'] += $checkinByEventDate[$parentKey] ?? 0;
            }
        }
    }
    unset($ev);
}

// Get categories for filter and form (from categories table)
try {
    if ($hasCategoriesTable) {
        if ($hasOrganizationFilter) {
            $categories = $db->query("SELECT id, name, color FROM categories WHERE organization_id = :org_id AND is_active = 1 ORDER BY sort_order, name", ['org_id' => $organizationId]);
        } else {
            $categories = $db->query("SELECT id, name, color FROM categories WHERE is_active = 1 ORDER BY sort_order, name");
        }
    } else {
        $categories = [];
    }
    if (empty($categories)) {
        // Fallback to distinct categories from events (legacy support)
        if ($hasOrganizationFilter) {
            $categories = $db->query("SELECT DISTINCT category as name FROM events WHERE organization_id = :org_id AND category IS NOT NULL AND category != '' ORDER BY category", ['org_id' => $organizationId]);
        } else {
            $categories = $db->query("SELECT DISTINCT category as name FROM events WHERE category IS NOT NULL AND category != '' ORDER BY category");
        }
    }
} catch (Exception $e) {
    // Final fallback: empty list (do not break events page on old schemas)
    $categories = [];
}

// Get event categories for each event (for display)
$eventIds = array_column($events, 'id');
$eventCategoriesMap = [];
if (!empty($eventIds) && $hasCategoriesTable && $hasEventCategoriesTable) {
    try {
        $eventCategories = $db->query("
            SELECT ec.event_id, c.id, c.name, c.color 
            FROM event_categories ec
            INNER JOIN categories c ON ec.category_id = c.id
            WHERE ec.event_id IN (" . implode(',', array_map('intval', $eventIds)) . ")
        ");
        foreach ($eventCategories as $ec) {
            if (!isset($eventCategoriesMap[$ec['event_id']])) {
                $eventCategoriesMap[$ec['event_id']] = [];
            }
            $eventCategoriesMap[$ec['event_id']][] = $ec;
        }
    } catch (Exception $e) {
        // event_categories table might not exist yet
    }
}

foreach ($categories as &$catRow) {
    if (!empty($catRow['name'])) {
        $catRow['name'] = Utilities::decodeHtmlEntities($catRow['name']);
    }
}
unset($catRow);
foreach ($eventCategoriesMap as &$ecGroup) {
    if (!is_array($ecGroup)) {
        continue;
    }
    foreach ($ecGroup as &$ecRow) {
        if (!empty($ecRow['name'])) {
            $ecRow['name'] = Utilities::decodeHtmlEntities($ecRow['name']);
        }
    }
    unset($ecRow);
}
unset($ecGroup);

// Calculate base path for assets (use from index.php if available)
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}
$assetsBase = $basePath . '/public/assets/';

// Load helper functions

$csrfToken = CsrfMiddleware::getToken();

$eventsNavQuery = ['page' => 'events'];
if ($status !== 'all') {
    $eventsNavQuery['status'] = $status;
}
if ($category !== 'all') {
    $eventsNavQuery['category'] = $category;
}
if ($search !== '') {
    $eventsNavQuery['search'] = $search;
}
$eventsListGroupedHref = $adminBase . '/index.php?' . http_build_query(array_merge($eventsNavQuery, ['expand_sessions' => '0']));
$eventsListExpandedHref = $adminBase . '/index.php?' . http_build_query(array_merge($eventsNavQuery, ['expand_sessions' => '1']));

$pageTitle = 'Events';
$currentPage = 'events';

// Add modal.css for confirm dialogs
$additionalCSS = [
    $basePath . '/public/css/modal.css'
];

require __DIR__ . '/includes/header.php';
?>

<!-- Define eventsApp function before Alpine initializes -->
<script>
const API_BASE_URL = '<?= e($basePath . '/public/api/events.php') ?>';
const API_PUBLIC = '<?= e($basePath) ?>/public/api';
const csrfToken = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
const categories = <?= json_encode($categories) ?>;

function eventsApp() {
    return {
        showEventModal: false,
        saving: false,
        formErrors: [],
        editorInstance: null,
        viewMode: 'card', // 'card' or 'list'
        eventForm: {
            id: null,
            title: '',
            description: '',
            banner_image: null,
            event_date: '',
            start_time: '',
            end_time: '',
            location: '',
            is_virtual: false,
            extra_details: '',
            categories: [],
            capacity: '',
            ticket_price: '0.00',
            registration_required: false,
            registration_deadline: '',
            status: 'draft',
            checkin_window_start: '',
            checkin_window_end: '',
            is_recurring: false,
            recurrence_type: 'weekly',
            recurrence_interval: 1,
            recurrence_days: [],
            recurrence_week_of_month: 5,
            recurrence_day_of_week: 5,
            recurrence_end_type: 'never',
            recurrence_end_after_count: null,
            recurrence_end_date: null,
            custom_session_dates: [],
            session_registration_mode: 'independent',
            allow_guest_rsvp: false,
            questions: [],
            ticket_types: [],
            event_people: []
        },
        eventFormStep: 1,
        bannerImageFile: null,
        /** @type {Record<string, File>} */
        eventPersonImageFiles: {},

        showRsvpModal: false,
        rsvpModalEventId: null,
        rsvpModalEventTitle: '',
        rsvpList: [],
        loadingRsvps: false,
        showEmailComposer: false,
        composerType: 'announcement',
        composerEventId: null,
        composerEventTitle: '',
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
            return this.composerType === 'announcement'
                ? `Announce Event${this.composerEventTitle ? ': ' + this.composerEventTitle : ''}`
                : `Send Reminder${this.composerEventTitle ? ': ' + this.composerEventTitle : ''}`;
        },
        get composerAction() {
            return this.composerType === 'announcement' ? 'announce' : 'remind';
        },
        
        init() {
            // Sync expand_sessions with localStorage (prefer expanded list when user chose it before)
            try {
                const u = new URL(window.location.href);
                const exp = u.searchParams.get('expand_sessions');
                if (exp === '1' || exp === '0') {
                    localStorage.setItem('eventsExpandSessions', exp === '1' ? '1' : '0');
                } else if (localStorage.getItem('eventsExpandSessions') === '1') {
                    u.searchParams.set('expand_sessions', '1');
                    window.location.replace(u.toString());
                    return;
                }
            } catch (e) { /* ignore */ }
            // Load view preference from localStorage
            const savedView = localStorage.getItem('eventsViewMode');
            if (savedView === 'list' || savedView === 'card') {
                this.viewMode = savedView;
            }
            // Watch for modal opening/closing to initialize/destroy editor
            this.$watch('showEventModal', (value) => {
                if (value) {
                    // Modal opened - initialize editor after a short delay to ensure DOM is ready
                    setTimeout(() => {
                        this.initEditor();
                    }, 100);
                } else {
                    // Modal closed - destroy editor
                    this.destroyEditor();
                }
            });
            this.$watch('showEmailComposer', (open) => {
                if (open) {
                    this.$nextTick(() => setTimeout(() => this.initComposerWysiwyg(), 80));
                }
            });
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
                        uploadImageUrl: API_PUBLIC + '/upload-email-image.php',
                        uploadVideoUrl: API_PUBLIC + '/upload-email-video.php',
                        csrfToken: csrfToken
                    });
                }
            }
            ta.value = this.composer.bodyHtml || '';
            ta.dispatchEvent(new Event('sync-to-quill'));
        },
        flushComposerHtmlFromEditor() {
            const ta = document.getElementById('email-composer-body');
            if (!ta || !window.__quillInstances) return;
            const quill = window.__quillInstances.get(ta);
            if (!quill) return;
            let html = quill.root.innerHTML;
            if (html === '<p><br></p>') html = '';
            this.composer.bodyHtml = html;
            ta.value = html;
        },
        
        saveViewPreference(view) {
            localStorage.setItem('eventsViewMode', view);
        },
        
        initEditor() {
            // Prevent multiple initializations
            if (this.editorInstance || this._initializingEditor) {
                return;
            }
            
            this._initializingEditor = true;
            
            const editorElement = document.getElementById('event-description-editor');
            if (!editorElement) {
                this._initializingEditor = false;
                return;
            }
            
            // Destroy existing editor first to be safe
            this.destroyEditor();
            
            // Wait a bit to ensure DOM is fully ready
            setTimeout(() => {
                const element = document.getElementById('event-description-editor');
                if (!element || this.editorInstance) {
                    this._initializingEditor = false;
                    return;
                }
                
                // Ensure element is empty
                element.innerHTML = '';
                
                // Double check for any leftover toolbars in the parent
                const parent = element.parentElement;
                if (parent) {
                    const toolbars = parent.querySelectorAll('.ql-toolbar');
                    toolbars.forEach(tb => tb.remove());
                }
                
                // Initialize Quill editor
                this.editorInstance = new Quill(element, {
                    theme: 'snow',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                            [{ 'color': [] }, { 'background': [] }],
                            [{ 'align': [] }],
                            ['link', 'image'],
                            ['clean']
                        ]
                    },
                    placeholder: 'Enter event description...',
                });
                
                // Set initial content
                if (this.eventForm.description) {
                    this.editorInstance.root.innerHTML = this.eventForm.description;
                }
                
                // Sync editor content with Alpine model
                this.editorInstance.on('text-change', () => {
                    this.eventForm.description = this.editorInstance.root.innerHTML;
                });
                if (typeof headcountInitQuillRichToolbar === 'function') {
                    headcountInitQuillRichToolbar(this.editorInstance, {
                        uploadImageUrl: API_PUBLIC + '/upload-email-image.php',
                        uploadVideoUrl: API_PUBLIC + '/upload-email-video.php',
                        csrfToken: csrfToken
                    });
                }
                
                this._initializingEditor = false;
            }, 50);
        },
        
        destroyEditor() {
            const editorElement = document.getElementById('event-description-editor');
            
            if (this.editorInstance) {
                // Remove event listeners
                try {
                    this.editorInstance.off('text-change');
                } catch (e) {
                    // Ignore errors if already destroyed
                }
                
                // Clear the reference
                this.editorInstance = null;
            }
            
            // Clean up the DOM element
            if (editorElement) {
                // Remove any leftover toolbars in the parent
                const parent = editorElement.parentElement;
                if (parent) {
                    const toolbars = parent.querySelectorAll('.ql-toolbar');
                    toolbars.forEach(tb => tb.remove());
                }
                
                editorElement.innerHTML = '';
                editorElement.className = editorElement.className.replace(/ql-\w+/g, '').trim();
            }
            
            // Reset initialization flag
            this._initializingEditor = false;
        },
        
        openCreateModal() {
            this.resetForm();
            this.showEventModal = true;
        },
        
        async openEditModal(eventId) {
            try {
                const response = await fetch(`${API_BASE_URL}?action=get&id=${encodeURIComponent(eventId)}`);
                const data = await response.json();
                
                if (data.success) {
                    this.eventForm = {
                        id: data.event.id,
                        title: data.event.title,
                        description: data.event.description || '',
                        banner_image: data.event.banner_image || null,
                        event_date: data.event.event_date,
                        start_time: data.event.start_time || '',
                        end_time: data.event.end_time || '',
                        location: data.event.location,
                        is_virtual: !!(data.event.is_virtual),
                        extra_details: data.event.extra_details || '',
                        categories: data.event.categories || (data.event.category ? [data.event.category] : []),
                        capacity: data.event.capacity || '',
                        ticket_price: data.event.ticket_price || data.event.price || '0.00',
                        registration_required: data.event.registration_required == 1 || data.event.require_rsvp == 1,
                        registration_deadline: (function (d) {
                            if (!d) return '';
                            const s = String(d).replace(' ', 'T').trim();
                            return s.length >= 16 ? s.slice(0, 16) : s;
                        })(data.event.registration_deadline),
                        status: data.event.status,
                        checkin_window_start: data.event.checkin_window_start || '',
                        checkin_window_end: data.event.checkin_window_end || '',
                        is_recurring: data.event.is_recurring || false,
                        recurrence_type: (data.event.recurrence_type && String(data.event.recurrence_type).toLowerCase()) || 'weekly',
                        recurrence_interval: data.event.recurrence_interval || 1,
                        recurrence_days: data.event.recurrence_days ? (Array.isArray(data.event.recurrence_days) ? data.event.recurrence_days.map(Number) : String(data.event.recurrence_days).split(',').map(Number)) : [],
                        recurrence_week_of_month: data.event.recurrence_week_of_month != null ? parseInt(data.event.recurrence_week_of_month, 10) : 5,
                        recurrence_day_of_week: (data.event.recurrence_type === 'monthly_weekday' && data.event.recurrence_days && data.event.recurrence_days.length) ? (Array.isArray(data.event.recurrence_days) ? parseInt(data.event.recurrence_days[0], 10) : parseInt(String(data.event.recurrence_days).split(',')[0], 10)) : 5,
                        recurrence_end_type: data.event.recurrence_end_type || 'never',
                        recurrence_end_after_count: data.event.recurrence_end_after_count || null,
                        recurrence_end_date: data.event.recurrence_end_date || null,
                        custom_session_dates: (data.event.recurrence_type === 'custom' && Array.isArray(data.event.custom_session_dates) && data.event.custom_session_dates.length)
                            ? data.event.custom_session_dates.map(String)
                            : [],
                        session_registration_mode: (data.event.session_registration_mode && ['independent', 'choose_one', 'all_sessions'].includes(data.event.session_registration_mode))
                            ? data.event.session_registration_mode
                            : 'independent',
                        allow_guest_rsvp: !!(data.event.allow_guest_rsvp),
                        questions: Array.isArray(data.event.questions) ? data.event.questions.map(q => ({ id: q.id, question_text: q.question_text || '', question_type: q.question_type || 'short_text', is_required: !!q.is_required, sort_order: q.sort_order != null ? q.sort_order : 0, options: Array.isArray(q.options) ? q.options.map(o => ({ id: o.id, option_label: o.option_label || '', sort_order: o.sort_order != null ? o.sort_order : 0 })) : [], depends_on_question_id: (q.depends_on_question_id !== undefined && q.depends_on_question_id !== null && q.depends_on_question_id !== '') ? String(q.depends_on_question_id) : null, depends_on_value: (q.depends_on_value !== undefined && q.depends_on_value !== null && q.depends_on_value !== '') ? String(q.depends_on_value) : null })) : [],
                        ticket_types: Array.isArray(data.event.ticket_types) ? data.event.ticket_types.map(tt => {
                            const toLocal = (d) => {
                                if (!d) return '';
                                const s = String(d).replace(' ', 'T').trim();
                                return s.length >= 16 ? s.slice(0, 16) : s;
                            };
                            return {
                                id: tt.id,
                                name: tt.name || '',
                                price: tt.price != null ? tt.price : 0,
                                quantity_limit: tt.quantity_limit != null && tt.quantity_limit !== '' ? tt.quantity_limit : '',
                                sort_order: tt.sort_order != null ? tt.sort_order : 0,
                                sale_starts_at: toLocal(tt.sale_starts_at),
                                sale_ends_at: toLocal(tt.sale_ends_at),
                                package_group: tt.package_group != null && tt.package_group !== undefined ? String(tt.package_group) : ''
                            };
                        }) : [],
                        event_people: Array.isArray(data.event.event_people) ? data.event.event_people.map((row, i) => ({
                            role: (row.role === 'organiser') ? 'organiser' : 'speaker',
                            display_name: row.display_name || '',
                            title: row.title || '',
                            image_path: row.image_path || '',
                            sort_order: row.sort_order != null ? row.sort_order : i,
                            remove_image: false
                        })) : []
                    };
                    this.eventFormStep = 1;
                    this.bannerImageFile = null; // Reset file when loading existing event
                    this.eventPersonImageFiles = {};
                    this.showEventModal = true;
                    // Update editor content after modal opens
                    setTimeout(() => {
                        if (this.editorInstance && this.eventForm.description) {
                            this.editorInstance.root.innerHTML = this.eventForm.description;
                        }
                    }, 200);
                }
            } catch (error) {
                console.error('Error loading event:', error);
                alert('Failed to load event details');
            }
        },
        
        async saveEvent() {
            this.formErrors = [];
            this.saving = true;
            
            // Get latest content from editor before saving
            if (this.editorInstance) {
                this.eventForm.description = this.editorInstance.root.innerHTML;
            }
            
            if (this.eventForm.is_recurring && this.eventForm.recurrence_type === 'monthly_weekday') {
                this.eventForm.recurrence_days = [parseInt(this.eventForm.recurrence_day_of_week, 10)];
            }
            if (this.eventForm.is_recurring && this.eventForm.recurrence_type === 'custom' && Array.isArray(this.eventForm.custom_session_dates)) {
                this.eventForm.custom_session_dates = this.eventForm.custom_session_dates.map(d => (d || '').trim()).filter(Boolean);
            }
            if (this.eventForm.is_recurring && this.eventForm.recurrence_type === 'custom') {
                const extra = (this.eventForm.custom_session_dates || []).map(d => (d || '').trim()).filter(Boolean);
                if (extra.length === 0) {
                    this.formErrors = ['For “Specific dates”, add at least one extra session date (the main event date above is the first session).'];
                    this.saving = false;
                    return;
                }
            }

            // Ensure every question has conditional fields so they are always sent (never omitted by JSON.stringify)
            if (Array.isArray(this.eventForm.questions)) {
                this.eventForm.questions.forEach(q => {
                    if (q.depends_on_question_id === undefined) q.depends_on_question_id = null;
                    if (q.depends_on_value === undefined) q.depends_on_value = null;
                });
            }

            if (this.eventForm.registration_deadline) {
                let v = String(this.eventForm.registration_deadline).trim();
                if (v.includes('T')) {
                    v = v.replace('T', ' ');
                }
                if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(v)) {
                    v += ':00';
                }
                this.eventForm.registration_deadline = v;
            } else {
                this.eventForm.registration_deadline = '';
            }

            if (Array.isArray(this.eventForm.ticket_types)) {
                this.eventForm.ticket_types.forEach(tt => {
                    ['sale_starts_at', 'sale_ends_at'].forEach(field => {
                        let v = tt[field] ? String(tt[field]).trim() : '';
                        if (v) {
                            if (v.includes('T')) v = v.replace('T', ' ');
                            if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(v)) v += ':00';
                            tt[field] = v;
                        } else {
                            tt[field] = '';
                        }
                    });
                });
            }
            
            try {
                const url = this.eventForm.id 
                    ? `${API_BASE_URL}?action=update` 
                    : `${API_BASE_URL}?action=create`;
                
                let body;
                let headers = {};
                
                const hasPersonImage = this.eventPersonImageFiles && Object.keys(this.eventPersonImageFiles).some((k) => !!this.eventPersonImageFiles[k]);
                const useFormData = !!(this.bannerImageFile || hasPersonImage);
                if (useFormData) {
                    const formData = new FormData();
                    if (this.bannerImageFile) {
                        formData.append('banner_image', this.bannerImageFile);
                    }
                    Object.keys(this.eventForm).forEach(key => {
                        // File is already appended as banner_image; skip data-URL preview to avoid duplicate fields and huge POST body
                        if (key === 'banner_image') {
                            return;
                        }
                        if (key === 'categories' && Array.isArray(this.eventForm[key])) {
                            this.eventForm[key].forEach(cat => formData.append('categories[]', cat));
                        } else if (key === 'recurrence_days' && Array.isArray(this.eventForm[key])) {
                            formData.append(key, this.eventForm[key].join(','));
                        } else if (this.eventForm[key] !== null && this.eventForm[key] !== undefined) {
                            const val = this.eventForm[key];
                            if (typeof val === 'boolean') {
                                formData.append(key, val ? '1' : '0');
                            } else if (typeof val === 'object') {
                                formData.append(key, JSON.stringify(val));
                            } else {
                                formData.append(key, val);
                            }
                        }
                    });
                    formData.set('is_recurring', this.eventForm.is_recurring ? '1' : '0');
                    if (this.eventPersonImageFiles) {
                        Object.keys(this.eventPersonImageFiles).forEach((k) => {
                            const f = this.eventPersonImageFiles[k];
                            if (f) {
                                formData.append('event_person_image_' + k, f);
                            }
                        });
                    }
                    body = formData;
                    // Don't set Content-Type header for FormData - browser will set it with boundary
                } else {
                    headers['Content-Type'] = 'application/json';
                    body = JSON.stringify(this.eventForm);
                }
                
                const response = await fetch(url, {
                    method: 'POST',
                    headers: headers,
                    body: body
                });
                
                // Get response text first to check if it's valid JSON
                const responseText = await response.text();
                let data;
                
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Invalid JSON response:', responseText);
                    console.error('Response status:', response.status);
                    console.error('Response headers:', [...response.headers.entries()]);
                    this.formErrors = ['Server returned an invalid response. Please check the console for details.'];
                    this.saving = false;
                    return;
                }
                
                if (data.success) {
                    window.location.reload();
                } else {
                    this.formErrors = data.errors || [data.message || 'Failed to save event'];
                }
            } catch (error) {
                console.error('Error saving event:', error);
                console.error('Error details:', error.message, error.stack);
                this.formErrors = ['An error occurred while saving: ' + error.message];
            } finally {
                this.saving = false;
            }
        },
        
        async openRsvpModal(eventId, eventTitle) {
            this.rsvpModalEventId = eventId;
            this.rsvpModalEventTitle = eventTitle || '';
            this.showRsvpModal = true;
            this.loadingRsvps = true;
            this.rsvpList = [];
            try {
                const response = await fetch(API_BASE_URL + '?action=rsvps&id=' + eventId, { credentials: 'same-origin' });
                const data = await response.json().catch(() => ({ success: false }));
                if (data.success && Array.isArray(data.rsvps)) {
                    this.rsvpList = data.rsvps;
                } else {
                    this.rsvpList = [];
                }
            } catch (e) {
                this.rsvpList = [];
            } finally {
                this.loadingRsvps = false;
            }
        },

        formatRsvpDate(createdAt) {
            if (!createdAt) return '—';
            const d = new Date(createdAt);
            return isNaN(d.getTime()) ? createdAt : d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },

        async duplicateEvent(eventId) {
            const confirmed = await confirmAction({
                title: 'Duplicate Event',
                message: 'Are you sure you want to duplicate this event?',
                type: 'info',
                okText: 'Duplicate',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${API_BASE_URL}?action=duplicate`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: eventId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Failed to duplicate event');
                }
            } catch (error) {
                console.error('Error duplicating event:', error);
                alert('An error occurred');
            }
        },
        
        async deleteEvent(eventId, eventTitle) {
            const confirmed = await confirmAction({
                title: 'Delete Event',
                message: `Are you sure you want to delete "${eventTitle}"? This action cannot be undone.`,
                type: 'danger',
                okText: 'Delete',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${API_BASE_URL}?action=delete`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: eventId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to delete event');
                }
            } catch (error) {
                console.error('Error deleting event:', error);
                alert('An error occurred');
            }
        },
        
        async openEmailComposer(type, eventId, eventTitle) {
            this.composerType = type === 'reminder' ? 'reminder' : 'announcement';
            this.composerEventId = eventId;
            this.composerEventTitle = eventTitle || '';
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
        },

        async loadComposerTemplates() {
            this.composerLoadingTemplates = true;
            try {
                const res = await fetch(API_PUBLIC + '/email-templates.php?action=list', { credentials: 'same-origin' });
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
            if (!this.composerEventId) {
                alert('Event not selected.');
                return;
            }
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
                const response = await fetch(`${API_BASE_URL}?action=${this.composerAction}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: this.composerEventId, subject: subject, body_html: bodyHtml })
                });

                const data = await response.json().catch(() => ({ success: false }));
                if (data.success) {
                    if (this.composerType === 'announcement') {
                        this.composeDefaults.announcementSubject = subject;
                        this.composeDefaults.announcementBody = bodyHtml;
                    } else {
                        this.composeDefaults.reminderSubject = subject;
                        this.composeDefaults.reminderBody = bodyHtml;
                    }
                    alert(data.message || 'Email sent successfully!');
                    this.showEmailComposer = false;
                } else {
                    alert((this.composerType === 'announcement' ? 'Failed to send announcement: ' : 'Failed to send reminder: ') + (data.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error sending composed email:', error);
                alert('An error occurred while sending email.');
            } finally {
                this.composerSending = false;
            }
        },

        async announceEvent(eventId, eventTitle) {
            await this.openEmailComposer('announcement', eventId, eventTitle);
        },

        async sendReminderEvent(eventId, eventTitle) {
            await this.openEmailComposer('reminder', eventId, eventTitle);
        },
        
        handleBannerImageChange(event) {
            const file = event.target.files[0];
            if (file) {
                // Validate file type
                if (!file.type.match('image.*')) {
                    alert('Please select an image file');
                    event.target.value = '';
                    return;
                }
                
                // Validate file size (5MB)
                if (file.size > 5242880) {
                    alert('Image size must be less than 5MB');
                    event.target.value = '';
                    return;
                }
                
                this.bannerImageFile = file;
                
                // Create preview
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.eventForm.banner_image = e.target.result; // Use data URL for preview
                };
                reader.readAsDataURL(file);
            }
        },
        
        removeBannerImage() {
            this.eventForm.banner_image = null;
            this.bannerImageFile = null;
            // Reset file input
            const fileInput = document.querySelector('input[type="file"][accept*="image"]');
            if (fileInput) {
                fileInput.value = '';
            }
        },
        
        getBannerImageUrl(bannerImage) {
            // If it's a data URL (preview), return as-is
            if (bannerImage && bannerImage.startsWith('data:')) {
                return bannerImage;
            }
            
            // If no banner image, use a placeholder via the image endpoint
            if (!bannerImage) {
                // Use a special path that will trigger the default banner fallback
                return '<?= e($basePath) ?>/public/api/image.php?path=event-banners/default.png';
            }
            
            // Otherwise, use the image serving endpoint
            return '<?= e($basePath) ?>/public/api/image.php?path=' + encodeURIComponent(bannerImage);
        },
        
        resetForm() {
            this.eventForm = {
                id: null,
                title: '',
                description: '',
                banner_image: null,
                event_date: '',
                start_time: '',
                end_time: '',
                location: '',
                categories: [],
                capacity: '',
                ticket_price: '0.00',
                registration_required: false,
                registration_deadline: '',
                status: 'draft',
                checkin_window_start: '',
                checkin_window_end: '',
                is_recurring: false,
                recurrence_type: 'weekly',
                recurrence_interval: 1,
                recurrence_days: [],
                recurrence_week_of_month: 5,
                recurrence_day_of_week: 5,
                recurrence_end_type: 'never',
                recurrence_end_after_count: null,
                recurrence_end_date: null,
                custom_session_dates: [],
                session_registration_mode: 'independent',
                allow_guest_rsvp: false,
                questions: [],
                ticket_types: [],
                event_people: []
            };
            this.eventFormStep = 1;
            this.bannerImageFile = null;
            this.eventPersonImageFiles = {};
            this.formErrors = [];
        },

        addTicketType() {
            this.eventForm.ticket_types = this.eventForm.ticket_types || [];
            this.eventForm.ticket_types.push({ name: '', price: 0, quantity_limit: '', sort_order: this.eventForm.ticket_types.length, sale_starts_at: '', sale_ends_at: '', package_group: '' });
        },
        removeTicketType(index) {
            this.eventForm.ticket_types.splice(index, 1);
        },

        addEventPerson(role) {
            this.eventForm.event_people = this.eventForm.event_people || [];
            const n = this.eventForm.event_people.length;
            this.eventForm.event_people.push({
                role: role === 'organiser' ? 'organiser' : 'speaker',
                display_name: '',
                title: '',
                image_path: '',
                sort_order: n,
                remove_image: false
            });
        },
        removeEventPerson(index) {
            if (!this.eventForm.event_people) return;
            this.eventForm.event_people.splice(index, 1);
            this.eventPersonImageFiles = {};
        },
        moveEventPerson(index, delta) {
            const arr = this.eventForm.event_people;
            if (!arr || index < 0 || index >= arr.length) return;
            const j = index + delta;
            if (j < 0 || j >= arr.length) return;
            const tmp = arr[index];
            arr[index] = arr[j];
            arr[j] = tmp;
            this.eventPersonImageFiles = {};
        },
        setEventPersonImageFile(index, evt) {
            const f = evt.target.files && evt.target.files[0];
            if (f) {
                this.eventPersonImageFiles[String(index)] = f;
                if (this.eventForm.event_people[index]) {
                    this.eventForm.event_people[index].remove_image = false;
                }
            }
        },

        // Category Multi-select helpers
        categorySearch: '',
        categoryDropdownOpen: false,
        get filteredCategories() {
            if (!this.categorySearch) return categories.filter(c => !this.isCategorySelected(c));
            return categories.filter(c => 
                c.name.toLowerCase().includes(this.categorySearch.toLowerCase()) && 
                !this.isCategorySelected(c)
            );
        },
        isCategorySelected(cat) {
            const val = cat.id || cat.name;
            return this.eventForm.categories.includes(val) || this.eventForm.categories.includes(String(val));
        },
        toggleCategory(cat) {
            const val = cat.id || cat.name;
            if (this.isCategorySelected(cat)) {
                this.eventForm.categories = this.eventForm.categories.filter(c => c != val);
            } else {
                this.eventForm.categories.push(val);
            }
            this.categorySearch = '';
        },
        removeCategory(val) {
            this.eventForm.categories = this.eventForm.categories.filter(c => c != val);
        }
    }
}
</script>

<div x-data="eventsApp()" x-init="init()" class="min-h-full">
    <?php
    $pageHeaderTitle = 'Events';
    $pageHeaderSubtitle = 'Manage your upcoming and past organization events.';
    ob_start();
    ?>
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-800" role="group" aria-label="View mode">
            <button @click="viewMode = 'card'; saveViewPreference('card')" :class="viewMode === 'card' ? 'bg-white text-brand-600 shadow-sm ring-1 ring-brand-200 dark:bg-gray-700 dark:text-brand-300 dark:ring-brand-500/40' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60 dark:text-gray-400 dark:hover:bg-gray-700/80 dark:hover:text-gray-200'" class="rounded-lg px-3 py-2 text-sm font-bold transition-all" title="Card View">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
            </button>
            <button @click="viewMode = 'list'; saveViewPreference('list')" :class="viewMode === 'list' ? 'bg-white text-brand-600 shadow-sm ring-1 ring-brand-200 dark:bg-gray-700 dark:text-brand-300 dark:ring-brand-500/40' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60 dark:text-gray-400 dark:hover:bg-gray-700/80 dark:hover:text-gray-200'" class="rounded-lg px-3 py-2 text-sm font-bold transition-all" title="List View">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
        </div>
        <?php if ($hasParentEventId): ?>
        <div class="flex items-center gap-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-800" role="group" aria-label="Recurring series display">
            <a href="<?= e($eventsListGroupedHref) ?>"
               onclick="try { localStorage.setItem('eventsExpandSessions','0'); } catch (e) {}"
               class="rounded-lg px-3 py-2 text-sm font-bold transition-all <?= !$expandSessions ? 'bg-white text-brand-600 shadow-sm ring-1 ring-brand-200 dark:bg-gray-700 dark:text-brand-300 dark:ring-brand-500/40' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60 dark:text-gray-400 dark:hover:bg-gray-700/80 dark:hover:text-gray-200' ?>"
               title="One row per recurring series">Grouped</a>
            <a href="<?= e($eventsListExpandedHref) ?>"
               onclick="try { localStorage.setItem('eventsExpandSessions','1'); } catch (e) {}"
               class="rounded-lg px-3 py-2 text-sm font-bold transition-all <?= $expandSessions ? 'bg-white text-brand-600 shadow-sm ring-1 ring-brand-200 dark:bg-gray-700 dark:text-brand-300 dark:ring-brand-500/40' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/60 dark:text-gray-400 dark:hover:bg-gray-700/80 dark:hover:text-gray-200' ?>"
               title="Show every session as its own row">All sessions</a>
        </div>
        <?php endif; ?>
        <?php if (!$isCoordinator): ?>
        <span class="hidden h-8 w-px flex-shrink-0 bg-gray-200 dark:bg-gray-600 sm:block" aria-hidden="true"></span>
        <a href="<?= e($adminBase . '/index.php?page=event-create') ?>" class="btn-primary inline-flex items-center gap-2 whitespace-nowrap flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            <span>Create Event</span>
        </a>
        <?php endif; ?>
    </div>
    <?php $pageHeaderActions = ob_get_clean(); require __DIR__ . '/components/page-header.php'; ?>

    <?php
    $categoryFilterOptions = ['all' => 'All Categories'];
    foreach ($categories as $cat) {
        $catKey = (string) ($cat['id'] ?? $cat['name']);
        $categoryFilterOptions[$catKey] = $cat['name'];
    }
    $filterBarAction = $adminBase . '/index.php';
    $filterBarHiddenFields = [['name' => 'page', 'value' => 'events'], ['name' => 'p', 'value' => '1']];
    $filterBarFields = [
        ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $status, 'width' => 'w-44', 'options' => [
            'all' => 'All Statuses', 'draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled', 'completed' => 'Completed',
        ]],
        ['name' => 'category', 'type' => 'select', 'label' => 'Category', 'value' => $category, 'width' => 'w-48', 'options' => $categoryFilterOptions],
        ['name' => 'search', 'type' => 'search', 'label' => 'Search', 'value' => $search, 'placeholder' => 'Search events...', 'width' => 'w-64'],
    ];
    require __DIR__ . '/components/filter-bar.php';
    if ($hasParentEventId): ?>
    <div class="mb-8 -mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 py-3 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-5">
        <form method="GET" action="<?= e($adminBase . '/index.php') ?>">
            <input type="hidden" name="page" value="events">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="hidden" name="category" value="<?= e($category) ?>">
            <input type="hidden" name="search" value="<?= e($search) ?>">
            <input type="hidden" name="p" value="1">
            <input type="hidden" name="expand_sessions" value="0">
            <label class="inline-flex cursor-pointer select-none items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="expand_sessions" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600" <?= $expandSessions ? 'checked' : '' ?>>
                <span>Show every session as its own card (longer list)</span>
            </label>
            <button type="submit" class="ml-3 text-theme-sm font-medium text-brand-600 hover:text-brand-700">Apply</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Events List -->
    <div class="mb-12">
        <?php if (empty($events)): ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-16 text-center shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-brand-400 text-sm font-medium mb-6">Coming soon</p>
                <p class="text-gray-500 text-base font-medium mb-6 dark:text-gray-400">No events scheduled. Time to plan something new!</p>
                <?php if (!$isCoordinator): ?>
                <a href="<?= e($adminBase . '/index.php?page=event-create') ?>" class="btn-primary px-6 py-3 text-base">
                    Create Event
                </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Card View -->
            <div x-show="viewMode === 'card'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($events as $event): ?>
                <?php
                $listEventDate = !empty($event['_list_surface_event_date']) ? $event['_list_surface_event_date'] : ($event['event_date'] ?? '');
                $listStartTime = array_key_exists('_list_surface_start_time', $event)
                    ? $event['_list_surface_start_time']
                    : ($event['start_time'] ?? null);
                $isPast = strtotime(substr((string) $listEventDate, 0, 10)) < strtotime('today');
                $statusClasses = [
                    'draft'     => 'ta-badge ta-badge-gray',
                    'published' => 'ta-badge ta-badge-success',
                    'cancelled' => 'ta-badge ta-badge-error',
                    'completed' => 'ta-badge ta-badge-brand'
                ];
                $statusClass = $statusClasses[$event['status']] ?? 'ta-badge ta-badge-gray';
                $listNavEventId = !empty($event['_list_surface_event_id']) ? (int) $event['_list_surface_event_id'] : (int) $event['id'];
                $checkinStatus = (!empty($event['_list_surface_event_id']) && array_key_exists('_list_surface_status', $event) && $event['_list_surface_status'] !== null && $event['_list_surface_status'] !== '')
                    ? (string) $event['_list_surface_status']
                    : (string) ($event['status'] ?? '');
                ?>
                <div class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm transition-all duration-300 hover:border-brand-200 dark:border-gray-800 dark:bg-white/[0.03]">
                    <!-- Banner Image (instances fall back to series parent via effective_banner_image) -->
                    <?php
                    $listBannerPath = $event['effective_banner_image'] ?? $event['banner_image'] ?? null;
                    if (!empty($listBannerPath)): ?>
                        <div class="mb-4 -mx-6 -mt-6 rounded-t-xl overflow-hidden h-48 bg-gray-100 dark:bg-gray-800">
                            <img src="<?= e($basePath . '/public/api/image.php?path=' . urlencode($listBannerPath)) ?>" 
                                 alt="<?= e($event['title']) ?> banner" 
                                 class="w-full h-48 object-cover"
                                 style="object-position: top center;"
                                 onerror="this.parentElement.innerHTML='<div class=\'w-full h-48 bg-gradient-to-r from-brand-500 to-purple-600 flex items-center justify-center\'><div class=\'text-white text-center\'><svg class=\'w-12 h-12 mx-auto mb-2 opacity-50\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\'></path></svg><p class=\'text-xs font-bold uppercase tracking-wider opacity-75\'>Image Not Found</p></div></div>'">
                        </div>
                    <?php else: ?>
                        <div class="mb-4 -mx-6 -mt-6 rounded-t-xl overflow-hidden bg-gradient-to-r from-brand-500 to-purple-600 h-32 flex items-center justify-center">
                            <div class="text-white text-center">
                                <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <p class="text-xs font-bold uppercase tracking-wider opacity-75">No Banner Image</p>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="flex flex-col gap-4">
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-2 mb-3">
                                <span class="<?= $statusClass ?>">
                                    <?= ucfirst($event['status']) ?>
                                </span>
                                
                                <?php 
                                $isRecurringInstance = !$collapseSeries && (!empty($event['is_recurring_instance']) || !empty($event['parent_event_id']));
                                $isRecurringParent = !$isRecurringInstance && (int) ($event['recurring_instance_count'] ?? 0) > 0;
                                ?>
                                <?php if ($isRecurringInstance): ?>
                                    <span class="ta-badge ta-badge-brand">Instance</span>
                                <?php elseif ($isRecurringParent): ?>
                                    <span class="ta-badge ta-badge-brand">Series</span>
                                <?php endif; ?>

                                <?php 
                                $eventCats = $eventCategoriesMap[$event['id']] ?? [];
                                if (!empty($eventCats)): 
                                    foreach ($eventCats as $ec):
                                ?>
                                    <span class="ta-badge" style="background-color: <?= e($ec['color'] ?? '#3B82F6') ?>15; color: <?= e($ec['color'] ?? '#3B82F6') ?>;">
                                        <?= e($ec['name']) ?>
                                    </span>
                                <?php
                                    endforeach;
                                elseif ($event['category']):
                                ?>
                                    <span class="ta-badge ta-badge-brand">
                                        <?= e($event['category']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <h3 class="mb-2 text-xl font-bold text-gray-900 transition-colors group-hover:text-brand-600 dark:text-white dark:group-hover:text-brand-400">
                                <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $listNavEventId) ?>" class="hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 rounded dark:focus-visible:ring-offset-gray-800">
                                    <?= e($event['title']) ?>
                                </a>
                            </h3>
                            <?php if ($collapseSeries && $hasParentEventId && (int)($event['recurring_instance_count'] ?? 0) > 0): ?>
                                <p class="text-xs text-brand-600 font-semibold mb-3"><?= (int) $event['recurring_instance_count'] ?> more session date<?= (int) $event['recurring_instance_count'] === 1 ? '' : 's' ?> in this series. Choose &ldquo;All sessions&rdquo; above to show each date as its own card, or open Event details for the full list.</p>
                            <?php endif; ?>
                            
                            <div class="space-y-2 mb-4">
                                <div class="flex items-start text-sm text-gray-600 dark:text-gray-300">
                                    <svg class="w-4 h-4 mr-2 text-gray-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    <span>
                                        <span class="font-medium"><?= formatDate($listEventDate) ?></span>
                                        <?php if ($listStartTime): ?>
                                            <span class="text-gray-500 dark:text-gray-400">at <?= formatTime($listStartTime) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($event['location']): ?>
                                <div class="flex items-start text-sm text-gray-600 dark:text-gray-300">
                                    <svg class="w-4 h-4 mr-2 text-gray-400 mt-0.5 flex-shrink-0 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    <span class="font-medium"><?= e($event['location']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex items-center space-x-6 border-t border-gray-100 pt-3 dark:border-gray-700">
                                <div class="flex flex-col">
                                    <span class="text-lg font-bold text-gray-900 dark:text-white"><?= (int)($event['rsvp_head_count'] ?? 0) ?></span>
                                    <span class="text-[10px] uppercase font-bold text-gray-400 tracking-wider dark:text-gray-400">People</span>
                                    <?php
                                    $regC = (int)($event['rsvp_registrant_count'] ?? 0);
                                    $headC = (int)($event['rsvp_head_count'] ?? 0);
                                    if ($regC > 0 && $headC !== $regC): ?>
                                    <span class="text-[9px] text-gray-400 mt-0.5 dark:text-gray-500"><?= $regC === 1 ? '1 registrant' : $regC . ' registrants' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="h-6 w-px bg-gray-200 dark:bg-gray-600"></div>
                                <div class="flex flex-col">
                                    <span class="text-lg font-bold text-gray-900 dark:text-white"><?= (int)($event['checkin_count'] ?? 0) ?></span>
                                    <span class="text-[10px] uppercase font-bold text-gray-400 tracking-wider dark:text-gray-400">Checked In</span>
                                </div>
                                <div class="h-6 w-px bg-gray-200 dark:bg-gray-600"></div>
                                <div class="flex flex-col">
                                    <span class="text-lg font-bold <?= $event['ticket_price'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-900 dark:text-white' ?>">
                                        <?= $event['ticket_price'] > 0 ? '$' . number_format($event['ticket_price'], 2) : 'FREE' ?>
                                    </span>
                                    <span class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Price</span>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <?php
                        $hasCheckIn = ($checkinStatus === 'published' && !$isPast);
                        $showCardActions = $hasCheckIn || !$isCoordinator;
                        ?>
                        <?php if ($showCardActions): ?>
                        <div class="mt-auto flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <?php if ($hasCheckIn): ?>
                                <a href="<?= e($adminBase . '/index.php?page=checkin&event_id=' . $listNavEventId) ?>" 
                                   class="event-card-action event-card-action--primary flex-1 min-w-[7rem] py-2 text-sm text-center">
                                    Check-In
                                </a>
                            <?php endif; ?>
                            <?php if (!$isCoordinator): ?>
                            <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $listNavEventId) ?>" class="event-card-action event-card-action--neutral flex-1 min-w-[7rem] py-2 text-center text-sm">
                                Event details
                            </a>
                            <a href="<?= e($adminBase . '/index.php?page=event-edit&id=' . (int) $event['id']) ?>" class="event-card-action event-card-action--indigo flex-1 min-w-[7rem] py-2 text-sm text-center">
                                Edit
                            </a>
                            <button type="button" data-event-title="<?= htmlspecialchars((string) ($event['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" @click="deleteEvent(<?= (int) $event['id'] ?>, $event.currentTarget.getAttribute('data-event-title'))" class="event-card-action event-card-action--rose flex-1 min-w-[7rem] py-2 text-sm text-center" title="Delete event">
                                Delete
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- List View -->
            <div x-show="viewMode === 'list'" class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
                <div class="w-full overflow-x-auto custom-scrollbar">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-y border-gray-100 dark:border-gray-800">
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date & Time</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Location</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</p></th>
                                <th class="py-3 pr-4 text-center"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">People</p></th>
                                <th class="py-3 pr-4 text-center"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Checked In</p></th>
                                <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</p></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <?php foreach ($events as $event): ?>
                                <?php
                                $listEventDate = !empty($event['_list_surface_event_date']) ? $event['_list_surface_event_date'] : ($event['event_date'] ?? '');
                                $listStartTime = array_key_exists('_list_surface_start_time', $event)
                                    ? $event['_list_surface_start_time']
                                    : ($event['start_time'] ?? null);
                                $isPast = strtotime(substr((string) $listEventDate, 0, 10)) < strtotime('today');
                                $statusClasses = [
                                    'draft'     => 'ta-badge ta-badge-gray',
                                    'published' => 'ta-badge ta-badge-success',
                                    'cancelled' => 'ta-badge ta-badge-error',
                                    'completed' => 'ta-badge ta-badge-brand'
                                ];
                                $statusClass = $statusClasses[$event['status']] ?? 'ta-badge ta-badge-gray';
                                $listNavEventId = !empty($event['_list_surface_event_id']) ? (int) $event['_list_surface_event_id'] : (int) $event['id'];
                                $checkinStatus = (!empty($event['_list_surface_event_id']) && array_key_exists('_list_surface_status', $event) && $event['_list_surface_status'] !== null && $event['_list_surface_status'] !== '')
                                    ? (string) $event['_list_surface_status']
                                    : (string) ($event['status'] ?? '');
                                ?>
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                                    <td class="py-3 pr-4">
                                        <div class="flex items-center gap-4">
                    <?php
                    $listBannerPath = $event['effective_banner_image'] ?? $event['banner_image'] ?? null;
                    if (!empty($listBannerPath)): ?>
                            <img src="<?= e($basePath . '/public/api/image.php?path=' . urlencode($listBannerPath)) ?>" 
                                                     alt="<?= e($event['title']) ?> banner" 
                                                     class="w-16 h-16 object-cover rounded-lg flex-shrink-0"
                                                     onerror="this.outerHTML='<div class=\'w-16 h-16 bg-gradient-to-r from-brand-500 to-purple-600 rounded-lg flex items-center justify-center flex-shrink-0\'><svg class=\'w-8 h-8 text-white opacity-50\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\'></path></svg></div>'">
                                            <?php else: ?>
                                                <div class="w-16 h-16 bg-gradient-to-r from-brand-500 to-purple-600 rounded-lg flex items-center justify-center flex-shrink-0">
                                                    <svg class="w-8 h-8 text-white opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-2 mb-1">
                                                    <h3 class="text-sm font-bold text-gray-900 truncate dark:text-white">
                                                        <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $listNavEventId) ?>" class="hover:text-brand-600 hover:underline dark:hover:text-brand-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded">
                                                            <?= e($event['title']) ?>
                                                        </a>
                                                    </h3>
                                                    <?php 
                                                    $eventCats = $eventCategoriesMap[$event['id']] ?? [];
                                                    if (!empty($eventCats)): 
                                                        foreach (array_slice($eventCats, 0, 2) as $ec):
                                                    ?>
                                                        <span class="ta-badge ta-badge-brand flex-shrink-0">
                                                            <?= e($ec['name']) ?>
                                                        </span>
                                                    <?php 
                                                        endforeach;
                                                    elseif ($event['category']):
                                                    ?>
                                                        <span class="ta-badge ta-badge-brand flex-shrink-0">
                                                            <?= e($event['category']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-xs text-gray-500 line-clamp-2 dark:text-gray-400"><?= e(substr(strip_tags($event['description'] ?? ''), 0, 100)) ?><?= strlen(strip_tags($event['description'] ?? '')) > 100 ? '...' : '' ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap text-theme-sm">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white"><?= formatDate($listEventDate) ?></div>
                                        <?php if ($listStartTime): ?>
                                            <div class="text-xs text-gray-500 dark:text-gray-400"><?= formatTime($listStartTime) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 pr-4 text-theme-sm">
                                        <div class="text-sm text-gray-900 dark:text-white"><?= e($event['location']) ?></div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <span class="<?= $statusClass ?>">
                                            <?= ucfirst($event['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap text-center text-theme-sm">
                                        <span class="text-sm font-bold text-gray-900 dark:text-white" title="<?= (int)($event['rsvp_registrant_count'] ?? 0) ?> registrants"><?= (int)($event['rsvp_head_count'] ?? 0) ?></span>
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap text-center text-theme-sm">
                                        <span class="text-sm font-bold text-gray-900 dark:text-white"><?= (int)($event['checkin_count'] ?? 0) ?></span>
                                    </td>
                                    <td class="py-3 pr-4 whitespace-nowrap text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <?php if ($checkinStatus === 'published' && !$isPast): ?>
                                                <a href="<?= e($adminBase . '/index.php?page=checkin&event_id=' . $listNavEventId) ?>" 
                                                   class="event-card-action event-card-action--primary text-xs py-1.5 px-3">
                                                    Check-In
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!$isCoordinator): ?>
                                            <a href="<?= e($adminBase . '/index.php?page=event-details&id=' . $listNavEventId) ?>" 
                                               class="btn-secondary text-xs py-1.5 px-3">
                                                Details
                                            </a>
                                            <a href="<?= e($adminBase . '/index.php?page=event-edit&id=' . (int) $event['id']) ?>"
                                               class="btn-secondary text-xs py-1.5 px-3">
                                                Edit
                                            </a>
                                            <button @click='openRsvpModal(<?= (int) $listNavEventId ?>, <?= json_encode($event['title']) ?>)' 
                                                    class="btn-secondary text-xs py-1.5 px-3"
                                                    title="View RSVPs">
                                                RSVPs
                                            </button>
                                            <button type="button" data-event-title="<?= htmlspecialchars((string) ($event['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" @click="deleteEvent(<?= (int) $event['id'] ?>, $event.currentTarget.getAttribute('data-event-title'))"
                                                    class="btn-ghost text-error-600 hover:bg-error-50 text-xs py-1.5 px-3"
                                                    title="Delete">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php /* --- Pagination --- */
    if ($totalPages > 1):
        // Build base query params (preserve filters, strip p)
        $paginationParams = [];
        if ($status !== 'all')     $paginationParams['status']   = $status;
        if ($category !== 'all')   $paginationParams['category'] = $category;
        if ($search !== '')        $paginationParams['search']   = $search;
        if ($expandSessions)       $paginationParams['expand_sessions'] = '1';
        $paginationParams['page'] = 'events';

        function paginationUrl(string $adminBase, array $base, int $p): string {
            $q = array_merge($base, ['p' => $p]);
            return $adminBase . '/index.php?' . http_build_query($q);
        }
        $windowSize  = 5;
        $halfWindow  = (int) floor($windowSize / 2);
        $windowStart = max(1, min($currentPageNum - $halfWindow, $totalPages - $windowSize + 1));
        $windowEnd   = min($totalPages, $windowStart + $windowSize - 1);
    ?>
    <nav class="flex items-center justify-between px-1 py-6" aria-label="Events pagination">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            <?php
            $firstItem = $offset + 1;
            $lastItem  = min($offset + $perPage, $totalCount);
            echo "Showing <strong class='text-gray-900'>{$firstItem}–{$lastItem}</strong> of <strong class='text-gray-900'>{$totalCount}</strong> events";
            ?>
        </p>
        <div class="flex items-center gap-1">
            <?php /* Previous */ ?>
            <?php if ($currentPageNum > 1): ?>
            <a href="<?= e(paginationUrl($adminBase, $paginationParams, $currentPageNum - 1)) ?>"
               class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                Prev
            </a>
            <?php else: ?>
            <span class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-300 bg-white border border-gray-100 rounded-xl cursor-not-allowed dark:bg-gray-800 dark:border-gray-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                Prev
            </span>
            <?php endif; ?>

            <?php /* Leading ellipsis */ ?>
            <?php if ($windowStart > 1): ?>
            <a href="<?= e(paginationUrl($adminBase, $paginationParams, 1)) ?>"
               class="hidden sm:inline-flex items-center justify-center w-9 h-9 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">1</a>
            <?php if ($windowStart > 2): ?>
            <span class="hidden sm:inline-flex items-center justify-center w-9 h-9 text-sm text-gray-400">…</span>
            <?php endif; ?>
            <?php endif; ?>

            <?php /* Page window */ ?>
            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
            <?php if ($p === $currentPageNum): ?>
            <span class="inline-flex items-center justify-center w-9 h-9 text-sm font-bold text-white bg-brand-600 border border-brand-600 rounded-xl shadow-sm"><?= $p ?></span>
            <?php else: ?>
            <a href="<?= e(paginationUrl($adminBase, $paginationParams, $p)) ?>"
               class="hidden sm:inline-flex items-center justify-center w-9 h-9 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-brand-50 hover:border-brand-300 hover:text-brand-700 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700"><?= $p ?></a>
            <?php endif; ?>
            <?php endfor; ?>

            <?php /* Trailing ellipsis */ ?>
            <?php if ($windowEnd < $totalPages): ?>
            <?php if ($windowEnd < $totalPages - 1): ?>
            <span class="hidden sm:inline-flex items-center justify-center w-9 h-9 text-sm text-gray-400">…</span>
            <?php endif; ?>
            <a href="<?= e(paginationUrl($adminBase, $paginationParams, $totalPages)) ?>"
               class="hidden sm:inline-flex items-center justify-center w-9 h-9 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php /* Next */ ?>
            <?php if ($currentPageNum < $totalPages): ?>
            <a href="<?= e(paginationUrl($adminBase, $paginationParams, $currentPageNum + 1)) ?>"
               class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">
                Next
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>
            <?php else: ?>
            <span class="inline-flex items-center gap-1 px-3 py-2 text-sm font-medium text-gray-300 bg-white border border-gray-100 rounded-xl cursor-not-allowed dark:bg-gray-800 dark:border-gray-800">
                Next
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </span>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>

    <!-- CREATE/EDIT EVENT MODAL -->
    <?php
    ob_start();
    ?>
    <form @submit.prevent="saveEvent()" class="space-y-6">
        
        <!-- Error Messages -->
        <div x-show="formErrors.length > 0" x-transition class="bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 rounded-xl mb-6">
            <div class="flex items-center mb-2">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span class="font-bold">Please fix the following:</span>
            </div>
            <ul class="list-disc list-inside text-sm opacity-90">
                <template x-for="error in formErrors" :key="error">
                    <li x-text="error"></li>
                </template>
            </ul>
        </div>
        
        <div class="flex gap-2 mb-4">
            <button type="button" @click="eventFormStep = 1" :class="eventFormStep === 1 ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1.5 rounded-lg text-sm font-bold">1. Details</button>
            <button type="button" @click="eventFormStep = 2" :class="eventFormStep === 2 ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'" class="px-3 py-1.5 rounded-lg text-sm font-bold">2. Custom questions</button>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3" x-show="eventFormStep === 1">
            <div class="lg:col-span-2 space-y-3">
                <!-- Title -->
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Event Title</label>
                    <input 
                        type="text" 
                        x-model="eventForm.title"
                        placeholder="e.g. Weekly Community Meetup"
                        class="ta-input w-full font-medium"
                        required
                    >
                </div>
                
                <!-- Banner Image -->
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Banner Image</label>
                    <div class="space-y-2">
                        <div x-show="eventForm.banner_image" class="relative">
                            <img :src="getBannerImageUrl(eventForm.banner_image)" alt="Event banner" class="w-full h-32 object-cover rounded-xl border border-gray-200 dark:border-gray-700">
                            <button type="button" @click="removeBannerImage()" class="absolute top-2 right-2 bg-rose-500 text-white p-1.5 rounded-lg hover:bg-rose-600 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        <input 
                            type="file" 
                            @change="handleBannerImageChange($event)"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all text-sm file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-brand-600 file:text-white hover:file:bg-brand-700 dark:border-gray-700"
                        >
                        <p class="text-xs text-gray-500 mt-1 dark:text-gray-400">Recommended: 1200x400px. Max size: 5MB</p>
                    </div>
                    <input type="hidden" x-model="eventForm.banner_image" x-ref="bannerImageInput">
                </div>
                
                <!-- Description -->
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Description</label>
                    <div class="border border-gray-200 rounded-xl overflow-hidden dark:border-gray-700">
                        <div id="event-description-editor" x-ref="descriptionEditor" class="min-h-[120px]"></div>
                    </div>
                    <textarea 
                        x-model="eventForm.description"
                        x-ref="descriptionTextarea"
                        class="hidden"
                    ></textarea>
                </div>

                <!-- Date and Time -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Event Date</label>
                        <input 
                            type="date" 
                            x-model="eventForm.event_date"
                            class="ta-input w-full"
                            required
                        >
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Start</label>
                            <input 
                                type="time" 
                                x-model="eventForm.start_time"
                                class="ta-input w-full"
                            >
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">End</label>
                            <input 
                                type="time" 
                                x-model="eventForm.end_time"
                                class="ta-input w-full"
                            >
                        </div>
                    </div>
                </div>

                <!-- Check-In Window -->
                <div class="bg-brand-50/50 border border-brand-100 rounded-xl p-3">
                    <div class="flex items-center gap-2 mb-1">
                        <svg class="w-4 h-4 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <label class="block text-xs font-bold text-brand-600 uppercase tracking-wider">Check-In Window (Optional)</label>
                    </div>
                    <p class="text-xs text-gray-600 mb-2 dark:text-gray-300">Set custom check-in times. If not set, check-in will be allowed 1 hour before the event start time.</p>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Window Start</label>
                            <input 
                                type="time" 
                                x-model="eventForm.checkin_window_start"
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all dark:border-gray-700"
                            >
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Window End</label>
                            <input 
                                type="time" 
                                x-model="eventForm.checkin_window_end"
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all dark:border-gray-700"
                            >
                        </div>
                    </div>
                </div>

                <!-- Virtual event -->
                <label class="flex items-start gap-4 cursor-pointer group">
                    <div class="flex items-center h-5 mt-0.5">
                        <input type="checkbox" x-model="eventForm.is_virtual"
                               class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                    </div>
                    <div class="min-w-0 flex-1">
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Virtual event</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Use a Zoom or Google Meet link as the location</p>
                    </div>
                </label>

                <!-- Location -->
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Location</label>
                    <p class="text-xs text-gray-500 mb-1 dark:text-gray-400" x-show="eventForm.is_virtual" x-cloak>e.g. Zoom or Google Meet link</p>
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 text-gray-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </span>
                        <input 
                            type="text" 
                            x-model="eventForm.location"
                            :placeholder="eventForm.is_virtual ? 'https://zoom.us/j/... or https://meet.google.com/...' : 'Venue name or address'"
                            class="w-full border border-gray-200 rounded-xl pl-11 pr-3 py-2 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all dark:border-gray-700"
                            required
                        >
                    </div>
                </div>

                <!-- Extra details (admin-only) -->
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Extra details (optional)</label>
                    <p class="text-xs text-gray-500 mb-1 dark:text-gray-400">Additional info shown on the event details page for admins</p>
                    <textarea 
                        x-model="eventForm.extra_details"
                        placeholder="Internal notes or extra event details..."
                        rows="3"
                        class="ta-input w-full"
                    ></textarea>
                </div>

                <div class="border border-gray-100 rounded-2xl p-4 bg-white space-y-3 dark:bg-gray-800 dark:border-gray-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider">Speakers &amp; organisers</label>
                            <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Shown on the public event page (name, title, photo).</p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <button type="button" @click="addEventPerson('speaker')" class="text-xs font-bold text-brand-600 hover:text-brand-800">+ Speaker</button>
                            <button type="button" @click="addEventPerson('organiser')" class="text-xs font-bold text-brand-600 hover:text-brand-800">+ Organiser</button>
                        </div>
                    </div>
                    <template x-for="(person, epIndex) in (eventForm.event_people || [])" :key="epIndex">
                        <div class="rounded-xl border border-gray-200 p-3 space-y-2 bg-gray-50/50 dark:bg-gray-800 dark:border-gray-700">
                            <div class="flex flex-wrap items-center gap-2">
                                <select x-model="person.role" class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs font-bold uppercase dark:border-gray-700">
                                    <option value="speaker">Speaker</option>
                                    <option value="organiser">Organiser</option>
                                </select>
                                <button type="button" @click="moveEventPerson(epIndex, -1)" class="p-1 text-gray-400 hover:text-gray-700 text-xs dark:text-gray-200" title="Move up">↑</button>
                                <button type="button" @click="moveEventPerson(epIndex, 1)" class="p-1 text-gray-400 hover:text-gray-700 text-xs dark:text-gray-200" title="Move down">↓</button>
                                <button type="button" @click="removeEventPerson(epIndex)" class="ml-auto p-1 text-rose-500 hover:text-rose-700 text-sm" title="Remove">×</button>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                <input type="text" x-model="person.display_name" placeholder="Name" class="border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700">
                                <input type="text" x-model="person.title" placeholder="Title (optional)" class="border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700">
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <template x-if="person.image_path && !person.remove_image">
                                    <img :src="getBannerImageUrl(person.image_path)" alt="" class="h-14 w-14 rounded-lg object-cover border border-gray-200 dark:border-gray-700">
                                </template>
                                <label class="text-xs font-medium text-gray-600 dark:text-gray-300">
                                    Photo
                                    <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="block mt-1 text-xs w-full max-w-xs"
                                           @change="setEventPersonImageFile(epIndex, $event)">
                                </label>
                                <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer dark:text-gray-300" x-show="person.image_path">
                                    <input type="checkbox" x-model="person.remove_image" class="rounded border-gray-300 text-brand-600">
                                    Remove photo
                                </label>
                            </div>
                        </div>
                    </template>
                    <p x-show="!(eventForm.event_people && eventForm.event_people.length)" class="text-xs text-gray-400 italic">No speakers or organisers yet.</p>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Status & Categories -->
                <div class="bg-gray-50 rounded-2xl p-4 border border-gray-100 space-y-4 dark:bg-gray-800 dark:border-gray-800">
                    <div>
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Status</label>
                        <div class="flex p-1 bg-white border border-gray-200 rounded-xl dark:bg-gray-800 dark:border-gray-700">
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" x-model="eventForm.status" value="draft" class="hidden peer">
                                <div class="py-2 text-center text-sm font-bold rounded-lg peer-checked:bg-gray-100 peer-checked:text-gray-900 text-gray-400 transition-all dark:bg-gray-800 dark:text-white">Draft</div>
                            </label>
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" x-model="eventForm.status" value="published" class="hidden peer">
                                <div class="py-2 text-center text-sm font-bold rounded-lg peer-checked:bg-emerald-50 peer-checked:text-emerald-700 text-gray-400 transition-all">Publish</div>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Categories</label>
                        <div class="relative">
                            <!-- Selected Chips Area -->
                            <div class="min-h-[42px] p-1.5 border border-gray-200 rounded-xl bg-white flex flex-wrap gap-2 focus-within:ring-2 focus-within:ring-brand-500/20 focus-within:border-brand-500 transition-all cursor-text dark:bg-gray-800 dark:border-gray-700" 
                                 @click="$refs.catSearch.focus()">
                                <template x-for="val in eventForm.categories" :key="val">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-brand-50 text-brand-700 text-[10px] font-bold uppercase tracking-wider rounded-lg border border-brand-100 group">
                                        <span class="w-1.5 h-1.5 rounded-full" :style="'background-color: ' + (categories.find(c => (c.id || c.name) == val)?.color || '#3B82F6')"></span>
                                        <span x-text="categories.find(c => (c.id || c.name) == val)?.name || val"></span>
                                        <button type="button" @click.stop="removeCategory(val)" class="text-brand-400 hover:text-brand-600 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                        </button>
                                    </span>
                                </template>
                                <input 
                                    x-ref="catSearch"
                                    x-model="categorySearch"
                                    @focus="categoryDropdownOpen = true"
                                    @click.away="categoryDropdownOpen = false"
                                    @keydown.escape="categoryDropdownOpen = false"
                                    placeholder="Add categories..."
                                    class="flex-1 min-w-[120px] bg-transparent border-none outline-none text-sm p-1"
                                >
                            </div>

                            <!-- Dropdown -->
                            <div x-show="categoryDropdownOpen && filteredCategories.length > 0" 
                                 x-cloak
                                 class="absolute z-[100] w-full mt-2 bg-white border border-gray-200 rounded-xl shadow-xl max-h-48 overflow-y-auto py-1 dark:bg-gray-800 dark:border-gray-700"
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0 transform -translate-y-2"
                                 x-transition:enter-end="opacity-100 transform translate-y-0">
                                <template x-for="cat in filteredCategories" :key="cat.id || cat.name">
                                    <button type="button" 
                                            @click="toggleCategory(cat)"
                                            class="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 flex items-center gap-3 transition-colors dark:bg-gray-800">
                                        <span class="w-2.5 h-2.5 rounded-full" :style="'background-color: ' + (cat.color || '#3B82F6')"></span>
                                        <span class="font-medium text-gray-700 dark:text-gray-200" x-text="cat.name"></span>
                                    </button>
                                </template>
                            </div>
                            
                            <?php if (empty($categories)): ?>
                                <p class="text-[10px] text-gray-400 italic mt-2">No categories found. <a href="?page=settings" class="text-brand-600 font-bold hover:underline">Add some</a></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Settings card -->
                    <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 dark:bg-gray-800 dark:border-gray-800">
                        <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4">Settings</h4>
                        <div class="space-y-4">
                            <label class="flex items-start gap-4 cursor-pointer group">
                                <div class="flex items-center h-5 mt-0.5">
                                    <input type="checkbox" x-model="eventForm.registration_required" 
                                           class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                                </div>
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Require RSVP</span>
                                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Attendees must register to attend</p>
                                </div>
                            </label>

                            <label class="flex items-start gap-4 cursor-pointer group">
                                <div class="flex items-center h-5 mt-0.5">
                                    <input type="checkbox" x-model="eventForm.allow_guest_rsvp" 
                                           class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                                </div>
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow guest RSVP</span>
                                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Non-members can RSVP once, then receive an email to complete their account</p>
                                </div>
                            </label>

                            <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                                <label class="block text-xs font-semibold text-gray-600 mb-1.5 dark:text-gray-300" for="registration_deadline">RSVP deadline</label>
                                <input type="datetime-local" id="registration_deadline" x-model="eventForm.registration_deadline"
                                       class="ta-select w-full">
                                <p class="text-xs text-gray-500 mt-1 dark:text-gray-400">Optional. After this time, online RSVP and online payment close. Staff can still check in walk-ins on the day of the event.</p>
                            </div>

                            <div x-show="eventForm.registration_required" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="pt-4 border-t border-gray-200 space-y-4 dark:border-gray-700">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-600 mb-1.5 dark:text-gray-300">Capacity</label>
                                        <input type="number" x-model="eventForm.capacity" min="0" placeholder="Unlimited" class="ta-select w-full">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-600 mb-1.5 dark:text-gray-300">Ticket price</label>
                                        <div class="relative">
                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-medium">$</span>
                                            <input type="number" x-model="eventForm.ticket_price" step="0.01" min="0" placeholder="0.00" class="w-full border border-gray-200 rounded-xl pl-7 pr-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none bg-white dark:bg-gray-800 dark:border-gray-700">
                                        </div>
                                    </div>
                                </div>
                                <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                                    <p class="text-xs font-semibold text-gray-600 mb-2 dark:text-gray-300">Ticket types (optional)</p>
                                    <p class="text-xs text-gray-500 mb-3 dark:text-gray-400">If you add ticket types, they will be used instead of the single ticket price for this event.</p>
                                    <template x-for="(tt, index) in (eventForm.ticket_types || [])" :key="index">
                                        <div class="mb-3 p-3 rounded-xl border border-gray-100 bg-gray-50/50 space-y-2 dark:bg-gray-800 dark:border-gray-800">
                                            <div class="flex flex-wrap items-end gap-2">
                                                <input type="text" x-model="tt.name" placeholder="Name (e.g. Early bird)" class="flex-1 min-w-[120px] border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700">
                                                <div class="relative w-24">
                                                    <span class="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs">$</span>
                                                    <input type="number" x-model="tt.price" step="0.01" min="0" placeholder="0" class="w-full border border-gray-200 rounded-lg pl-5 pr-2 py-2 text-sm dark:border-gray-700">
                                                </div>
                                                <input type="number" x-model="tt.quantity_limit" min="0" placeholder="Limit" class="w-20 border border-gray-200 rounded-lg px-2 py-2 text-sm dark:border-gray-700" title="Max quantity (optional)">
                                                <button type="button" @click="removeTicketType(index)" class="p-2 text-gray-400 hover:text-rose-600 rounded-lg" title="Remove">×</button>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                                <div>
                                                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 dark:text-gray-400">Sale starts</label>
                                                    <input type="datetime-local" x-model="tt.sale_starts_at" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs dark:border-gray-700">
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 dark:text-gray-400">Sale ends</label>
                                                    <input type="datetime-local" x-model="tt.sale_ends_at" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs dark:border-gray-700">
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5 dark:text-gray-400">Package group</label>
                                                    <input type="text" x-model="tt.package_group" maxlength="64" placeholder="e.g. pass" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs dark:border-gray-700" title="Same group = pick one tier only">
                                                </div>
                                            </div>
                                            <p class="text-[10px] text-gray-500 leading-snug dark:text-gray-400">Optional sale window for early bird. Same <strong>package group</strong> = one option per checkout; leave empty for add-ons or independent tickets.</p>
                                        </div>
                                    </template>
                                    <button type="button" @click="addTicketType()" class="text-sm font-medium text-brand-600 hover:text-brand-700">+ Add ticket type</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recurring -->
                <div class="bg-brand-50/80 rounded-2xl p-6 border border-brand-100">
                    <label class="flex items-center gap-4 cursor-pointer group mb-4">
                        <div class="flex items-center h-5">
                            <input type="checkbox" x-model="eventForm.is_recurring" 
                                   class="h-5 w-5 rounded border-brand-300 text-brand-600 focus:ring-brand-600 transition-all cursor-pointer">
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-xs font-bold text-brand-900 uppercase tracking-wider">Recurring</span>
                            <p class="text-xs text-brand-700/80 mt-0.5">Repeat this event on a schedule</p>
                        </div>
                    </label>
                    
                    <div x-show="eventForm.is_recurring" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="space-y-4 pt-4 border-t border-brand-100/80">
                        <div>
                            <label class="block text-xs font-semibold text-brand-700 mb-1.5">Frequency</label>
                            <select x-model="eventForm.recurrence_type" @change="if (eventForm.recurrence_type === 'custom' && (!eventForm.custom_session_dates || eventForm.custom_session_dates.length === 0)) eventForm.custom_session_dates = ['']" class="w-full bg-white border border-brand-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="monthly_weekday">Monthly (e.g. last Friday)</option>
                                <option value="yearly">Yearly</option>
                                <option value="custom">Specific dates</option>
                            </select>
                        </div>

                        <div x-show="eventForm.recurrence_type === 'custom'" class="space-y-3">
                            <p class="text-xs text-brand-800/90 leading-relaxed">The <strong>main event date</strong> at the top of this form is always <strong>session 1</strong>. Add each <strong>additional</strong> session below (e.g. next weekend, another month — any calendar dates you need).</p>
                            <div class="space-y-2">
                                <template x-for="(row, idx) in eventForm.custom_session_dates" :key="idx">
                                    <div class="flex items-center gap-2">
                                        <input type="date" x-model="eventForm.custom_session_dates[idx]" class="flex-1 bg-white border border-brand-200 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800">
                                        <button type="button" @click="eventForm.custom_session_dates.splice(idx, 1)" class="shrink-0 p-2 text-rose-600 hover:bg-rose-50 rounded-lg text-sm font-bold" title="Remove">×</button>
                                    </div>
                                </template>
                            </div>
                            <button type="button" @click="eventForm.custom_session_dates = [...(eventForm.custom_session_dates || []), '']" class="text-xs font-bold text-brand-600 hover:text-brand-800">+ Add session date</button>
                        </div>
                        
                        <div x-show="eventForm.recurrence_type === 'weekly'">
                            <label class="block text-xs font-semibold text-brand-700 mb-2">On days</label>
                            <div class="flex flex-wrap gap-1">
                                <template x-for="(day, index) in ['S', 'M', 'T', 'W', 'T', 'F', 'S']">
                                    <label class="cursor-pointer">
                                        <input type="checkbox" :value="index" x-model="eventForm.recurrence_days" class="hidden peer">
                                        <div class="w-8 h-8 flex items-center justify-center text-[10px] font-bold rounded-lg bg-white border border-brand-100 text-brand-400 peer-checked:bg-brand-600 peer-checked:text-white transition-all dark:bg-gray-800" x-text="day"></div>
                                    </label>
                                </template>
                            </div>
                        </div>

                        <div x-show="eventForm.recurrence_type === 'monthly_weekday'" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-brand-700 mb-1.5">Occurrence</label>
                                <select x-model="eventForm.recurrence_week_of_month" class="w-full bg-white border border-brand-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800">
                                    <option value="1">First</option>
                                    <option value="2">Second</option>
                                    <option value="3">Third</option>
                                    <option value="4">Fourth</option>
                                    <option value="5">Last</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-brand-700 mb-1.5">Day of week</label>
                                <select x-model="eventForm.recurrence_day_of_week" class="w-full bg-white border border-brand-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800">
                                    <option value="0">Sunday</option>
                                    <option value="1">Monday</option>
                                    <option value="2">Tuesday</option>
                                    <option value="3">Wednesday</option>
                                    <option value="4">Thursday</option>
                                    <option value="5">Friday</option>
                                    <option value="6">Saturday</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-brand-700 mb-1.5">Ends</label>
                            <select x-model="eventForm.recurrence_end_type" class="w-full bg-white border border-brand-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800">
                                <option value="never">Never</option>
                                <option value="after_count">After X times</option>
                                <option value="on_date">On specific date</option>
                            </select>
                        </div>
                        <div x-show="eventForm.recurrence_end_type === 'after_count'" x-transition class="space-y-1">
                            <label class="block text-xs font-semibold text-brand-700 mb-1.5">Number of occurrences</label>
                            <input type="number" min="1" step="1" x-model.number="eventForm.recurrence_end_after_count" class="w-full bg-white border border-brand-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800" placeholder="e.g. 10">
                        </div>
                        <div x-show="eventForm.recurrence_end_type === 'on_date'" x-transition class="space-y-1">
                            <label class="block text-xs font-semibold text-brand-700 mb-1.5">End date</label>
                            <input type="date" x-model="eventForm.recurrence_end_date" class="w-full bg-white border border-brand-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-brand-700 mb-1.5">Session registration</label>
                            <select x-model="eventForm.session_registration_mode" class="w-full bg-white border border-brand-200 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:bg-gray-800">
                                <option value="independent">Each session — members can RSVP to any sessions independently</option>
                                <option value="choose_one">Pick one session — only one session per person in this series</option>
                                <option value="all_sessions">All sessions — one RSVP registers for every published session</option>
                            </select>
                            <p class="text-[11px] text-brand-600/80 mt-1.5">Applies to this recurring series (parent event and its generated dates).</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Custom questions -->
        <div class="border-t border-gray-100 pt-4 mt-4 dark:border-gray-800" x-show="eventFormStep === 2">
            <div class="flex items-center justify-between mb-2">
                <label class="block text-[10px] font-bold text-brand-400 uppercase tracking-wider">Custom RSVP questions</label>
                <button type="button" @click="eventForm.questions.push({ question_text: '', question_type: 'short_text', is_required: false, sort_order: eventForm.questions.length, options: [], depends_on_question_id: null, depends_on_value: null })" class="text-xs font-bold text-brand-600 hover:text-brand-800">+ Add question</button>
            </div>
            <div class="space-y-3 max-h-[420px] overflow-y-auto">
                <template x-for="(q, index) in eventForm.questions" :key="index">
                    <div class="p-3 bg-gray-50 rounded-xl space-y-2 dark:bg-gray-800">
                        <div class="flex flex-wrap items-center gap-2">
                            <input type="text" x-model="q.question_text" placeholder="Question text" class="flex-1 min-w-[120px] border border-gray-200 rounded-lg px-2 py-1.5 text-sm dark:border-gray-700">
                            <select x-model="q.question_type" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm dark:border-gray-700">
                                <option value="short_text">Short text</option>
                                <option value="text">Long text</option>
                                <option value="number">Number</option>
                                <option value="checkbox">Single checkbox (yes/no)</option>
                                <option value="radio">Radio (single choice)</option>
                                <option value="dropdown">Dropdown</option>
                                <option value="multi_checkbox">Checkbox (multiple choices)</option>
                            </select>
                            <label class="flex items-center gap-1 text-xs"><input type="checkbox" x-model="q.is_required" class="rounded border-gray-300 text-brand-600"> Required</label>
                            <button type="button" @click="eventForm.questions.splice(index, 1)" class="text-red-600 hover:text-red-800 text-xs font-bold">Remove</button>
                        </div>
                        <template x-if="['radio','dropdown','multi_checkbox','checkbox'].includes(q.question_type)">
                            <div class="pl-2 border-l-2 border-brand-200 space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold text-brand-700">Options</span>
                                    <button type="button" @click="q.options = q.options || []; q.options.push({ option_label: '', sort_order: q.options.length })" class="text-xs font-bold text-brand-600 hover:text-brand-800">+ Add option</button>
                                </div>
                                <template x-for="(opt, oi) in (q.options || [])" :key="oi">
                                    <div class="flex items-center gap-2">
                                        <input type="text" x-model="opt.option_label" placeholder="Option label" class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-sm dark:border-gray-700">
                                        <button type="button" @click="q.options.splice(oi, 1)" class="text-red-600 hover:text-red-800 text-xs font-bold shrink-0">Remove</button>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <span class="text-xs text-gray-500 dark:text-gray-400">Show only when</span>
                            <select x-model="q.depends_on_question_id" class="border border-gray-200 rounded-lg px-2 py-1 text-sm dark:border-gray-700">
                                <option value="">None</option>
                                <template x-for="(prev, pi) in eventForm.questions.slice(0, index)" :key="prev.id != null ? prev.id : 'i'+pi">
                                    <option :value="prev.id != null && prev.id !== '' ? String(prev.id) : ('__idx_' + pi)" x-text="(prev.question_text || 'Question ' + (pi+1)).substring(0, 45) + ((prev.question_text && prev.question_text.length > 45) ? '...' : '')"></option>
                                </template>
                            </select>
                            <template x-if="q.depends_on_question_id">
                                <span class="flex items-center gap-1 text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">answer is</span>
                                    <select x-model="q.depends_on_value" class="border border-gray-200 rounded px-2 py-1 text-sm dark:border-gray-700">
                                        <option value="__any__">Not empty</option>
                                        <template x-for="(prev, pi) in eventForm.questions" :key="prev.id != null ? prev.id : 'p'+pi">
                                            <template x-if="String(prev.id) === String(q.depends_on_question_id) || q.depends_on_question_id === '__idx_' + pi">
                                                <template x-if="prev.options && prev.options.length">
                                                    <template x-for="(opt, oi) in prev.options" :key="oi">
                                                        <option :value="opt.option_label" x-text="opt.option_label || ('Option ' + (oi+1))"></option>
                                                    </template>
                                                </template>
                                                <template x-if="!prev.options || !prev.options.length">
                                                    <option value="Yes">Yes</option>
                                                </template>
                                            </template>
                                        </template>
                                    </select>
                                </span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        
        <!-- Submit Buttons -->
        <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <button type="button" @click="showEventModal = false" class="btn-secondary">Cancel</button>
            <template x-if="eventFormStep === 1">
                <button type="button" @click="eventFormStep = 2" class="btn-primary">Next</button>
            </template>
            <template x-if="eventFormStep === 2">
                <div class="flex gap-2">
                    <button type="button" @click="eventFormStep = 1" class="btn-secondary">Back</button>
                    <button type="submit" :disabled="saving" class="btn-primary shadow-lg disabled:opacity-50 min-w-[140px]">
                        <div class="flex items-center justify-center">
                            <svg x-show="saving" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span x-text="saving ? 'Saving...' : (eventForm.id ? 'Save Changes' : 'Create Event')"></span>
                        </div>
                    </button>
                </div>
            </template>
        </div>
    </form>
    <?php
    $modalContent = ob_get_clean();
    $modalName = 'showEventModal';
    $modalTitleDynamic = "eventForm.id ? 'Edit Event' : 'Create Event'";
    $maxWidth = '5xl';
    $modalScrollable = true;
    include __DIR__ . '/components/modal-base.php';
    ?>

    <div x-show="showEmailComposer" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showEmailComposer = false"></div>
        <div class="relative mx-auto w-[min(90vw,calc(100%-1.5rem))] max-h-[calc(100vh-2rem)] md:w-[min(60vw,64rem)] md:max-w-[min(60vw,64rem)] flex flex-col overflow-hidden bg-white rounded-2xl shadow-xl border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between shrink-0 dark:border-gray-800">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="composerTitle"></h3>
                <button type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-200" @click="showEmailComposer = false">Close</button>
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
            <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-3 shrink-0 bg-white dark:bg-gray-800 dark:border-gray-800">
                <button type="button" @click="showEmailComposer = false" class="px-4 py-2 rounded-lg border border-gray-200 text-sm dark:border-gray-700">Cancel</button>
                <button type="button" @click="sendComposedEmail()" :disabled="composerSending" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm disabled:opacity-50">
                    <span x-text="composerSending ? 'Sending...' : 'Send now'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- RSVPs per event modal -->
    <div x-show="showRsvpModal"
         x-cloak
         @keydown.escape.window="showRsvpModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity"
             @click="showRsvpModal = false"
             style="z-index: 1;"></div>
        <div class="relative flex max-h-[80vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700"
             @click.stop
             style="z-index: 2;">
            <div class="flex items-center justify-between p-4 border-b border-gray-200 flex-shrink-0 dark:border-gray-700">
                <h3 class="text-xl font-bold text-gray-800 dark:text-gray-100">RSVPs: <span x-text="rsvpModalEventTitle"></span></h3>
                <button type="button" @click="showRsvpModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-4 overflow-y-auto flex-1 min-h-0">
                <div x-show="loadingRsvps" class="py-12 text-center">
                    <div class="inline-block animate-spin w-8 h-8 border-4 border-brand-500 border-t-transparent rounded-full"></div>
                    <p class="mt-4 text-gray-500 font-bold uppercase tracking-widest text-xs dark:text-gray-400">Loading…</p>
                </div>
                <div x-show="!loadingRsvps && rsvpList.length === 0" class="py-12 text-center text-gray-500 dark:text-gray-400">
                    <p>No RSVPs yet for this event.</p>
                </div>
                <div x-show="!loadingRsvps && rsvpList.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Name</th>
                                <th class="px-4 py-2 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Email</th>
                                <th class="px-4 py-2 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Response date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="rsvp in rsvpList" :key="rsvp.id">
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white" x-text="(rsvp.first_name || '') + ' ' + (rsvp.last_name || '')"></td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300" x-text="rsvp.email || '—'"></td>
                                    <td class="px-4 py-3 text-gray-500 text-xs dark:text-gray-400" x-text="formatRsvpDate(rsvp.created_at)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Quill.js WYSIWYG Editor -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="<?= e($basePath) ?>/public/admin/js/quill-rich-toolbar.js"></script>

<style>
    [x-cloak] { display: none !important; }
    
    /* Quill Editor Styling */
    #event-description-editor {
        min-height: 120px;
    }
    
    .ql-editor {
        min-height: 100px;
        font-size: 14px;
        font-family: 'Inter', sans-serif;
    }
    
    .ql-container.ql-snow {
        border-bottom-left-radius: 0.75rem !important;
        border-bottom-right-radius: 0.75rem !important;
        border: 1px solid #e5e7eb !important;
        border-top: none !important;
    }
    
    .ql-toolbar.ql-snow {
        border-top-left-radius: 0.75rem !important;
        border-top-right-radius: 0.75rem !important;
        border: 1px solid #e5e7eb !important;
        background-color: #f9fafb !important;
    }
    .ql-hc-video,
    .ql-hc-emoji {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 28px !important;
        padding: 3px 5px !important;
    }
    #email-composer-body-wrap .ql-editor { min-height: 200px; font-size: 14px; }
    #email-composer-body-wrap .ql-toolbar.ql-snow { border-radius: 0.75rem 0.75rem 0 0; }
    #email-composer-body-wrap .ql-container.ql-snow { border-radius: 0 0 0.75rem 0.75rem; }
</style>


<?php require __DIR__ . '/includes/footer.php'; ?>
