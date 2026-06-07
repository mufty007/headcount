<?php

declare(strict_types=1);

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Parsed, validated report filters from GET (bookmarkable).
 */
final class ReportFilterSet
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $prevStartDate,
        public readonly string $prevEndDate,
        public readonly array $categories,
        public readonly ?int $eventId,
        public readonly string $searchQuery,
        public readonly ?int $minRsvpYes,
        public readonly ?float $minNoShowPct,
        public readonly string $revenueStatus,
        public readonly bool $compare,
        public readonly ?int $facilityId = null,
        public readonly ?int $programId = null,
        public readonly ?int $programCategoryId = null,
    ) {
    }

    public static function fromGet(array $get, Database $db, int $organizationId): self
    {
        $startDate = self::sanitizeDate($get['start_date'] ?? null, date('Y-m-01'));
        $endDate = self::sanitizeDate($get['end_date'] ?? null, date('Y-m-d'));
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $startDt = new \DateTime($startDate);
        $endDt = new \DateTime($endDate);
        $interval = $startDt->diff($endDt);
        $daysDiff = (int) $interval->days + 1;
        $prevEndDt = (clone $startDt)->modify('-1 day');
        $prevStartDt = (clone $prevEndDt)->modify('-' . max(0, $daysDiff - 1) . ' days');
        $prevStart = $prevStartDt->format('Y-m-d');
        $prevEnd = $prevEndDt->format('Y-m-d');

        $allowedCats = self::loadAllowedCategories($db, $organizationId);
        $rawCats = $get['categories'] ?? [];
        if (!is_array($rawCats)) {
            $rawCats = $rawCats !== '' && $rawCats !== null ? [(string) $rawCats] : [];
        }
        $categories = [];
        foreach ($rawCats as $c) {
            $c = (string) $c;
            if ($c !== '' && in_array($c, $allowedCats, true)) {
                $categories[] = $c;
            }
        }
        $categories = array_values(array_unique($categories));

        $eventId = isset($get['event_id']) && $get['event_id'] !== '' ? (int) $get['event_id'] : null;
        if ($eventId !== null && $eventId <= 0) {
            $eventId = null;
        }
        if ($eventId !== null) {
            $exists = $db->queryOne(
                'SELECT 1 FROM events WHERE id = :id AND organization_id = :org LIMIT 1',
                ['id' => $eventId, 'org' => $organizationId]
            );
            if (!$exists) {
                $eventId = null;
            }
        }

        $q = isset($get['q']) ? trim((string) $get['q']) : '';
        if (strlen($q) > 200) {
            $q = substr($q, 0, 200);
        }

        $minRsvp = isset($get['min_rsvp_yes']) && $get['min_rsvp_yes'] !== '' ? (int) $get['min_rsvp_yes'] : null;
        if ($minRsvp !== null && $minRsvp < 0) {
            $minRsvp = null;
        }

        $minNs = isset($get['min_no_show_pct']) && $get['min_no_show_pct'] !== '' ? (float) $get['min_no_show_pct'] : null;
        if ($minNs !== null && ($minNs < 0 || $minNs > 100)) {
            $minNs = null;
        }

        $revStatus = isset($get['revenue_status']) ? (string) $get['revenue_status'] : 'paid';
        if (!in_array($revStatus, ['paid', 'all'], true)) {
            $revStatus = 'paid';
        }

        $compare = isset($get['compare']) && (string) $get['compare'] === '1';

        $facilityId = isset($get['facility_id']) && $get['facility_id'] !== '' ? (int) $get['facility_id'] : null;
        if ($facilityId !== null && $facilityId <= 0) {
            $facilityId = null;
        }
        if ($facilityId !== null) {
            $exists = $db->queryOne(
                'SELECT 1 FROM facilities WHERE id = :id AND organization_id = :org LIMIT 1',
                ['id' => $facilityId, 'org' => $organizationId]
            );
            if (!$exists) {
                $facilityId = null;
            }
        }

        $programId = isset($get['program_id']) && $get['program_id'] !== '' ? (int) $get['program_id'] : null;
        if ($programId !== null && $programId <= 0) {
            $programId = null;
        }
        if ($programId !== null) {
            $exists = $db->queryOne(
                'SELECT 1 FROM programs WHERE id = :id AND organization_id = :org LIMIT 1',
                ['id' => $programId, 'org' => $organizationId]
            );
            if (!$exists) {
                $programId = null;
            }
        }

        $programCategoryId = isset($get['program_category_id']) && $get['program_category_id'] !== '' ? (int) $get['program_category_id'] : null;
        if ($programCategoryId !== null && $programCategoryId <= 0) {
            $programCategoryId = null;
        }
        if ($programCategoryId !== null) {
            $exists = $db->queryOne(
                'SELECT 1 FROM program_categories WHERE id = :id AND organization_id = :org LIMIT 1',
                ['id' => $programCategoryId, 'org' => $organizationId]
            );
            if (!$exists) {
                $programCategoryId = null;
            }
        }

        return new self(
            $startDate,
            $endDate,
            $prevStart,
            $prevEnd,
            $categories,
            $eventId,
            $q,
            $minRsvp,
            $minNs,
            $revStatus,
            $compare,
            $facilityId,
            $programId,
            $programCategoryId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toQueryParams(): array
    {
        $out = [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
        if ($this->categories !== []) {
            $out['categories'] = $this->categories;
        }
        if ($this->eventId !== null) {
            $out['event_id'] = (string) $this->eventId;
        }
        if ($this->searchQuery !== '') {
            $out['q'] = $this->searchQuery;
        }
        if ($this->minRsvpYes !== null) {
            $out['min_rsvp_yes'] = (string) $this->minRsvpYes;
        }
        if ($this->minNoShowPct !== null) {
            $out['min_no_show_pct'] = (string) $this->minNoShowPct;
        }
        if ($this->revenueStatus !== 'paid') {
            $out['revenue_status'] = $this->revenueStatus;
        }
        if ($this->compare) {
            $out['compare'] = '1';
        }
        if ($this->facilityId !== null) {
            $out['facility_id'] = (string) $this->facilityId;
        }
        if ($this->programId !== null) {
            $out['program_id'] = (string) $this->programId;
        }
        if ($this->programCategoryId !== null) {
            $out['program_category_id'] = (string) $this->programCategoryId;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function loadAllowedCategories(Database $db, int $organizationId): array
    {
        try {
            $rows = $db->query(
                'SELECT DISTINCT category FROM events WHERE organization_id = :org AND category IS NOT NULL AND category != \'\'',
                ['org' => $organizationId]
            );
            $list = [];
            foreach ($rows as $r) {
                $list[] = (string) $r['category'];
            }

            return array_values(array_unique($list));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public static function loadFacilities(Database $db, int $organizationId): array
    {
        try {
            return $db->query(
                'SELECT id, name FROM facilities WHERE organization_id = :org ORDER BY name ASC',
                ['org' => $organizationId]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{id:int,title:string}>
     */
    public static function loadPrograms(Database $db, int $organizationId): array
    {
        try {
            return $db->query(
                'SELECT id, title FROM programs WHERE organization_id = :org ORDER BY title ASC',
                ['org' => $organizationId]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public static function loadProgramCategories(Database $db, int $organizationId): array
    {
        try {
            return $db->query(
                'SELECT id, name FROM program_categories WHERE organization_id = :org ORDER BY name ASC',
                ['org' => $organizationId]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function sanitizeDate(?string $value, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $value);

        return $d ? $d->format('Y-m-d') : $default;
    }
}
