<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Utilities;

/**
 * Mixed public catalog: published events + programs, normalized, filtered, sorted, paginated.
 */
class PublicListingService
{
    private Database $db;
    private ProgramService $programService;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->programService = new ProgramService();
    }

    /**
     * @param array{
     *   type?:string,
     *   search?:string,
     *   category?:string,
     *   date_from?:string,
     *   date_to?:string,
     *   page?:int,
     *   per_page?:int
     * } $filters
     * @param array{
     *   audience?:string,
     *   member_id?:int|null,
     *   timezone?:string|null
     * } $context audience = portal|public
     * @return array{
     *   items: list<array<string,mixed>>,
     *   categories: list<array{value:string,label:string}>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int,
     *   timezone: string
     * }
     */
    public function list(int $organizationId, array $filters = [], array $context = []): array
    {
        $audience = ($context['audience'] ?? 'public') === 'portal' ? 'portal' : 'public';
        $memberId = isset($context['member_id']) ? (int) $context['member_id'] : 0;
        $memberId = $memberId > 0 ? $memberId : null;
        $timezone = OrgTimeZone::resolve($context['timezone'] ?? null);

        $type = $this->normalizeType($filters['type'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $dateFrom = $this->normalizeYmd($filters['date_from'] ?? '');
        $dateTo = $this->normalizeYmd($filters['date_to'] ?? '');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 12);
        if ($perPage < 1) {
            $perPage = 12;
        }
        $perPage = min(50, $perPage);

        $items = [];
        if ($type === 'all' || $type === 'event') {
            $items = array_merge($items, $this->loadEvents($organizationId, $audience, $memberId, $timezone));
        }
        if ($type === 'all' || $type === 'program') {
            $items = array_merge($items, $this->loadPrograms($organizationId, $audience, $memberId, $timezone));
        }

        $categories = $this->uniqueCategories($items);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, function (array $item) use ($needle): bool {
                $hay = mb_strtolower(trim(
                    (string) ($item['title'] ?? '') . ' ' .
                    (string) ($item['description'] ?? '') . ' ' .
                    (string) ($item['location'] ?? '')
                ));
                return $hay !== '' && mb_strpos($hay, $needle) !== false;
            }));
        }

        if ($category !== '') {
            $want = mb_strtolower($category);
            $items = array_values(array_filter($items, function (array $item) use ($want): bool {
                $name = mb_strtolower(trim((string) ($item['category'] ?? '')));
                $slug = mb_strtolower(trim((string) ($item['category_slug'] ?? '')));
                return $name === $want || $slug === $want;
            }));
        }

        if ($dateFrom !== '' || $dateTo !== '') {
            $items = array_values(array_filter($items, function (array $item) use ($dateFrom, $dateTo): bool {
                $d = (string) ($item['date_sort'] ?? '');
                if ($d === '' || $d === '9999-12-31') {
                    return false;
                }
                if ($dateFrom !== '' && $d < $dateFrom) {
                    return false;
                }
                if ($dateTo !== '' && $d > $dateTo) {
                    return false;
                }
                return true;
            }));
        }

        usort($items, static function (array $a, array $b): int {
            $da = (string) ($a['date_sort'] ?? '9999-12-31');
            $db = (string) ($b['date_sort'] ?? '9999-12-31');
            if ($da === '') {
                $da = '9999-12-31';
            }
            if ($db === '') {
                $db = '9999-12-31';
            }
            if ($da !== $db) {
                return strcmp($da, $db);
            }
            $ta = (string) ($a['time_sort'] ?? '99:99:99');
            $tb = (string) ($b['time_sort'] ?? '99:99:99');
            if ($ta !== $tb) {
                return strcmp($ta, $tb);
            }
            $typeCmp = strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? ''));
            if ($typeCmp !== 0) {
                return $typeCmp;
            }
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $total = count($items);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        return [
            'items' => array_values($pageItems),
            'categories' => $categories,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'timezone' => $timezone,
        ];
    }

    public function normalizeType(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === 'events' || $v === 'event') {
            return 'event';
        }
        if ($v === 'programs' || $v === 'program') {
            return 'program';
        }
        return 'all';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadEvents(int $organizationId, string $audience, ?int $memberId, string $timezone): array
    {
        $hasParent = false;
        $hasVirtual = false;
        $hasVisibility = EventVisibilityService::columnExists($this->db);
        try {
            $hasParent = $this->db->hasColumn('events', 'parent_event_id');
        } catch (\Throwable $e) {
            $hasParent = false;
        }
        try {
            $hasVirtual = $this->db->hasColumn('events', 'is_virtual');
        } catch (\Throwable $e) {
            $hasVirtual = false;
        }

        $parentSelect = $hasParent ? 'e.parent_event_id' : 'NULL AS parent_event_id';
        $virtualSelect = $hasVirtual ? 'e.is_virtual' : '0 AS is_virtual';
        $bannerSelect = $hasParent
            ? 'COALESCE(NULLIF(TRIM(e.banner_image), \'\'), e_parent.banner_image) AS banner_image'
            : 'e.banner_image';
        $parentJoin = $hasParent ? ' LEFT JOIN events e_parent ON e.parent_event_id = e_parent.id' : '';

        try {
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTime('now', $tz);
            $today = $now->format('Y-m-d');
            $currentTime = $now->format('H:i:s');
        } catch (\Throwable $e) {
            $today = date('Y-m-d');
            $currentTime = date('H:i:s');
        }

        $sql = "SELECT e.id, e.title, e.description, e.event_date, e.start_time, e.end_time,
                       e.location, e.category, e.capacity, e.ticket_price, e.status,
                       {$parentSelect}, {$virtualSelect}, {$bannerSelect},
                       (SELECT COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) FROM rsvps WHERE event_id = e.id AND status = 'yes') AS rsvp_count
                FROM events e
                {$parentJoin}
                WHERE e.organization_id = :org AND e.status = 'published'
                  AND (e.event_date > :today1 OR (e.event_date = :today2 AND (e.end_time IS NULL OR e.end_time > :nowtime)))";
        $params = [
            'org' => $organizationId,
            'today1' => $today,
            'today2' => $today,
            'nowtime' => $currentTime,
        ];

        if ($hasVisibility && $audience === 'public') {
            $sql .= " AND (e.visibility IS NULL OR e.visibility = '' OR e.visibility = 'public')";
        }

        $sql .= ' ORDER BY e.event_date ASC, e.start_time ASC';

        try {
            $rows = $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            error_log('PublicListingService events query failed: ' . $e->getMessage());
            return [];
        }

        if (!is_array($rows)) {
            $rows = [];
        }

        if ($hasVisibility && $audience === 'portal') {
            $rows = array_values(array_filter($rows, function (array $ev) use ($memberId): bool {
                return EventVisibilityService::portalMemberMayViewPublishedEvent($this->db, $ev, $memberId);
            }));
        }

        $rows = $this->dedupeEventSeries($rows);

        $eventIds = [];
        foreach ($rows as $ev) {
            $eid = (int) ($ev['id'] ?? 0);
            if ($eid > 0) {
                $eventIds[] = $eid;
            }
        }

        $categoryByEvent = $this->eventCategoriesById($eventIds);
        $rsvpByEvent = ($audience === 'portal' && $memberId) ? $this->rsvpsByEventId($eventIds, $memberId) : [];
        $recurringParentSet = $this->recurringParentIdSet();

        $out = [];
        foreach ($rows as $ev) {
            $eid = (int) ($ev['id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }
            $catRow = $categoryByEvent[$eid] ?? null;
            $categoryName = $catRow['name'] ?? '';
            if ($categoryName === '') {
                $categoryName = trim((string) ($ev['category'] ?? ''));
            }
            $categorySlug = $catRow['slug'] ?? strtolower($categoryName);

            $price = isset($ev['ticket_price']) ? (float) $ev['ticket_price'] : 0.0;
            $isFree = $price <= 0;
            $rsvpCount = (int) ($ev['rsvp_count'] ?? 0);
            $capacity = isset($ev['capacity']) && $ev['capacity'] !== '' && $ev['capacity'] !== null
                ? (int) $ev['capacity'] : null;
            $available = $capacity !== null ? max(0, $capacity - $rsvpCount) : null;
            $isFull = $capacity !== null && $available === 0;

            $dateSort = substr((string) ($ev['event_date'] ?? ''), 0, 10);
            $timeRaw = (string) ($ev['start_time'] ?? '');
            $timeSort = $this->normalizeTimeSort($timeRaw);

            $multiUpcoming = (int) ($ev['upcoming_sessions_in_series'] ?? 1) > 1;
            $isRecurring = $multiUpcoming
                || !empty($ev['parent_event_id'])
                || isset($recurringParentSet[$eid]);

            $title = Utilities::decodeHtmlEntities((string) ($ev['title'] ?? ''));
            $location = Utilities::decodeHtmlEntities((string) ($ev['location'] ?? ''));
            $description = Utilities::decodeHtmlEntities((string) ($ev['description'] ?? ''));
            $categoryName = Utilities::decodeHtmlEntities($categoryName);

            $item = [
                'type' => 'event',
                'id' => $eid,
                'title' => $title,
                'description' => $description,
                'image_url' => $this->imageUrl($ev['banner_image'] ?? null),
                'category' => $categoryName,
                'category_slug' => $categorySlug,
                'date_sort' => $dateSort !== '' ? $dateSort : '9999-12-31',
                'time_sort' => $timeSort,
                'date_label' => $this->formatDateLabel($dateSort),
                'time_label' => $this->formatTimeLabel($timeRaw),
                'meta_line' => $this->eventMetaLine($dateSort, $timeRaw),
                'location' => $location,
                'is_virtual' => !empty($ev['is_virtual']),
                'is_free' => $isFree,
                'price_label' => $isFree ? 'Free' : ('$' . number_format($price, 2)),
                'is_recurring' => $isRecurring,
                'upcoming_sessions_in_series' => (int) ($ev['upcoming_sessions_in_series'] ?? 1),
                'is_full' => $isFull,
                'available_spots' => $available,
                'start_time' => $timeRaw,
                'event_date' => $dateSort,
                'user_rsvp' => $rsvpByEvent[$eid] ?? null,
                'my_registration_status' => null,
            ];
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadPrograms(int $organizationId, string $audience, ?int $memberId, string $timezone): array
    {
        if (!$this->programService->tableExists('programs')) {
            return [];
        }

        $sql = "SELECT p.id, p.title, p.description, p.banner_image, p.location, p.is_virtual,
                       p.pricing_type, p.price_amount, pc.name AS category_name, pc.slug AS category_slug
                FROM programs p
                LEFT JOIN program_categories pc ON pc.id = p.category_id
                WHERE p.organization_id = :org AND p.status = 'published'";
        $params = ['org' => $organizationId];
        if ($audience === 'public') {
            $sql .= ' AND p.show_on_public_site = 1';
        }
        $sql .= ' ORDER BY p.title ASC';

        try {
            $rows = $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            error_log('PublicListingService programs query failed: ' . $e->getMessage());
            return [];
        }
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['id'] ?? 0);
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }
        $nextById = $this->programService->nextUpcomingSessionsByProgramIds($ids, $timezone);
        $regById = ($audience === 'portal' && $memberId) ? $this->registrationsByProgramId($ids, $memberId) : [];

        $out = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $next = $nextById[$pid] ?? null;
            $sessionDate = is_array($next) ? substr((string) ($next['session_date'] ?? ''), 0, 10) : '';
            $startTime = is_array($next) ? (string) ($next['start_time'] ?? '') : '';
            $pricingType = (string) ($row['pricing_type'] ?? 'free');
            $amount = isset($row['price_amount']) ? (float) $row['price_amount'] : 0.0;
            $isFree = $pricingType === 'free' || $amount <= 0;
            $isVirtual = !empty($row['is_virtual']);
            $location = Utilities::decodeHtmlEntities(trim((string) ($row['location'] ?? '')));
            $title = Utilities::decodeHtmlEntities((string) ($row['title'] ?? ''));
            $description = Utilities::decodeHtmlEntities((string) ($row['description'] ?? ''));
            $categoryName = Utilities::decodeHtmlEntities(trim((string) ($row['category_name'] ?? '')));
            $categorySlug = trim((string) ($row['category_slug'] ?? ''));
            if ($categoryName === '') {
                $categoryName = 'Program';
            }

            $locationDisplay = $location;
            if ($isVirtual) {
                $locationDisplay = $location !== '' ? ('Virtual · ' . $location) : 'Virtual';
            }

            $dateLabel = $this->formatDateLabel($sessionDate);
            $timeLabel = $this->formatTimeLabel($startTime);
            $metaParts = [];
            if ($dateLabel !== '') {
                $metaParts[] = $timeLabel !== '' ? ($dateLabel . ' at ' . $timeLabel) : $dateLabel;
            } else {
                $metaParts[] = 'Schedule TBA';
            }

            $reg = $regById[$pid] ?? null;

            $out[] = [
                'type' => 'program',
                'id' => $pid,
                'title' => $title,
                'description' => $description,
                'image_url' => $this->imageUrl($row['banner_image'] ?? null),
                'category' => $categoryName,
                'category_slug' => $categorySlug,
                'date_sort' => $sessionDate !== '' ? $sessionDate : '9999-12-31',
                'time_sort' => $this->normalizeTimeSort($startTime),
                'date_label' => $dateLabel,
                'time_label' => $timeLabel,
                'meta_line' => implode(' · ', $metaParts),
                'location' => $locationDisplay !== '' ? $locationDisplay : ($isVirtual ? 'Virtual' : ''),
                'is_virtual' => $isVirtual,
                'is_free' => $isFree,
                'price_label' => $isFree ? 'Free' : ('$' . number_format($amount, 2)),
                'is_recurring' => false,
                'upcoming_sessions_in_series' => 0,
                'is_full' => false,
                'available_spots' => null,
                'start_time' => $startTime,
                'event_date' => $sessionDate,
                'user_rsvp' => null,
                'my_registration_status' => $reg,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function dedupeEventSeries(array $events): array
    {
        $compare = static function (array $a, array $b): int {
            $da = (string) ($a['event_date'] ?? '');
            $db = (string) ($b['event_date'] ?? '');
            if ($da !== $db) {
                return strcmp($da, $db);
            }
            $ta = (string) ($a['start_time'] ?? '');
            $tb = (string) ($b['start_time'] ?? '');
            if ($ta === '') {
                $ta = '00:00:00';
            } elseif (strlen($ta) === 5) {
                $ta .= ':00';
            }
            if ($tb === '') {
                $tb = '00:00:00';
            } elseif (strlen($tb) === 5) {
                $tb .= ':00';
            }
            $tc = strcmp($ta, $tb);
            if ($tc !== 0) {
                return $tc;
            }
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        };

        $buckets = [];
        foreach ($events as $ev) {
            $pid = isset($ev['parent_event_id']) ? (int) $ev['parent_event_id'] : 0;
            $root = $pid > 0 ? $pid : (int) ($ev['id'] ?? 0);
            if ($root <= 0) {
                continue;
            }
            $buckets[$root][] = $ev;
        }
        $deduped = [];
        foreach ($buckets as $root => $list) {
            usort($list, $compare);
            $best = $list[0];
            $best['series_root_id'] = $root;
            $best['upcoming_sessions_in_series'] = count($list);
            $deduped[] = $best;
        }
        usort($deduped, $compare);
        return $deduped;
    }

    /**
     * @param list<int> $eventIds
     * @return array<int, array{name:string,slug:string}>
     */
    private function eventCategoriesById(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        try {
            $params = [];
            $ph = [];
            foreach (array_values(array_unique($eventIds)) as $i => $id) {
                $k = 'ecid' . $i;
                $ph[] = ':' . $k;
                $params[$k] = $id;
            }
            $rows = $this->db->query(
                "SELECT ec.event_id, c.name, c.slug
                 FROM event_categories ec
                 INNER JOIN categories c ON c.id = ec.category_id
                 WHERE ec.event_id IN (" . implode(',', $ph) . ")
                 ORDER BY c.sort_order ASC, c.name ASC",
                $params
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $eid = (int) ($row['event_id'] ?? 0);
            if ($eid <= 0 || isset($out[$eid])) {
                continue;
            }
            $out[$eid] = [
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $eventIds
     * @return array<int, array{id:int,status:string,created_at:?string}>
     */
    private function rsvpsByEventId(array $eventIds, int $userId): array
    {
        if ($eventIds === [] || $userId <= 0) {
            return [];
        }
        try {
            $params = ['uid' => $userId];
            $ph = [];
            foreach (array_values(array_unique($eventIds)) as $i => $id) {
                $k = 'reid' . $i;
                $ph[] = ':' . $k;
                $params[$k] = $id;
            }
            $rows = $this->db->query(
                "SELECT event_id, id, status, created_at FROM rsvps
                 WHERE user_id = :uid AND event_id IN (" . implode(',', $ph) . ")",
                $params
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $eid = (int) ($row['event_id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }
            $out[$eid] = [
                'id' => (int) ($row['id'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, true>
     */
    private function recurringParentIdSet(): array
    {
        try {
            $rows = $this->db->query('SELECT parent_event_id FROM recurring_events');
        } catch (\Throwable $e) {
            return [];
        }
        $set = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['parent_event_id'] ?? 0);
            if ($pid > 0) {
                $set[$pid] = true;
            }
        }
        return $set;
    }

    /**
     * @param list<int> $programIds
     * @return array<int, string>
     */
    private function registrationsByProgramId(array $programIds, int $userId): array
    {
        if ($programIds === [] || $userId <= 0) {
            return [];
        }
        try {
            $params = ['uid' => $userId];
            $ph = [];
            foreach (array_values(array_unique($programIds)) as $i => $id) {
                $k = 'prid' . $i;
                $ph[] = ':' . $k;
                $params[$k] = $id;
            }
            $rows = $this->db->query(
                "SELECT program_id, status FROM program_registrations
                 WHERE user_id = :uid AND program_id IN (" . implode(',', $ph) . ")",
                $params
            );
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['program_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $out[$pid] = (string) ($row['status'] ?? '');
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array{value:string,label:string}>
     */
    private function uniqueCategories(array $items): array
    {
        $seen = [];
        foreach ($items as $item) {
            $label = trim((string) ($item['category'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = ['value' => $label, 'label' => $label];
        }
        $list = array_values($seen);
        usort($list, static function (array $a, array $b): int {
            return strcasecmp($a['label'], $b['label']);
        });
        return $list;
    }

    private function imageUrl($path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        $url = hc_public_api_image_url(ltrim($path, '/'));
        return $url !== '' ? $url : null;
    }

    private function formatDateLabel(string $ymd): string
    {
        $ymd = substr($ymd, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return '';
        }
        try {
            $dt = \DateTime::createFromFormat('Y-m-d', $ymd);
            return $dt ? $dt->format('M j, Y') : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function formatTimeLabel(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            $ampm = $h >= 12 ? 'PM' : 'AM';
            $h12 = $h % 12;
            if ($h12 === 0) {
                $h12 = 12;
            }
            return $h12 . ':' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' ' . $ampm;
        }
        return '';
    }

    private function eventMetaLine(string $ymd, string $time): string
    {
        $date = $this->formatDateLabel($ymd);
        $t = $this->formatTimeLabel($time);
        if ($date !== '' && $t !== '') {
            return $date . ' at ' . $t;
        }
        return $date !== '' ? $date : ($t !== '' ? $t : '');
    }

    private function normalizeTimeSort(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '99:99:99';
        }
        if (strlen($time) === 5) {
            return $time . ':00';
        }
        return $time;
    }

    private function normalizeYmd($raw): string
    {
        $s = trim((string) $raw);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }
        return '';
    }
}
