<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Facility add-ons / packages and booking snapshots.
 */
class FacilityAddonService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function tablesExist(): bool
    {
        return $this->db->tableExists('facility_addons');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForFacility(int $facilityId, bool $activeOnly = false): array
    {
        if (!$this->tablesExist() || $facilityId <= 0) {
            return [];
        }
        $sql = 'SELECT * FROM facility_addons WHERE facility_id = :fid';
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $rows = $this->db->query($sql, ['fid' => $facilityId]) ?: [];
        foreach ($rows as &$row) {
            $items = json_decode((string) ($row['package_items'] ?? ''), true);
            $row['package_items'] = is_array($items) ? $items : [];
            $row['price'] = (float) ($row['price'] ?? 0);
            $row['active'] = !empty($row['active']);
        }
        unset($row);
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $addons
     */
    public function replaceForFacility(int $facilityId, array $addons): void
    {
        if (!$this->tablesExist() || $facilityId <= 0) {
            return;
        }
        $this->db->execute('DELETE FROM facility_addons WHERE facility_id = :fid', ['fid' => $facilityId]);
        foreach ($addons as $i => $a) {
            if (!is_array($a)) {
                continue;
            }
            $title = trim((string) ($a['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $kind = (($a['kind'] ?? 'addon') === 'package') ? 'package' : 'addon';
            $items = $a['package_items'] ?? [];
            if (is_string($items)) {
                $decoded = json_decode($items, true);
                $items = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $items);
            }
            $items = array_values(array_filter(array_map(static function ($v) {
                return trim((string) $v);
            }, is_array($items) ? $items : []), static fn ($v) => $v !== ''));
            $this->db->insert('facility_addons', [
                'facility_id' => $facilityId,
                'title' => substr($title, 0, 255),
                'description' => self::emptyToNull($a['description'] ?? null),
                'price' => max(0, round((float) ($a['price'] ?? 0), 2)),
                'kind' => $kind,
                'package_items' => $items ? json_encode($items) : null,
                'sort_order' => isset($a['sort_order']) ? (int) $a['sort_order'] : (int) $i,
                'active' => (!array_key_exists('active', $a) || $a['active'] === true || $a['active'] === 1 || $a['active'] === '1') ? 1 : 0,
            ]);
        }
    }

    /**
     * @param list<array{id?:int,quantity?:int}|int> $selections
     * @return array{lines: list<array<string,mixed>>, extra: float}
     */
    public function quote(int $facilityId, $selections): array
    {
        $lines = [];
        $extra = 0.0;
        if (!$this->tablesExist()) {
            return ['lines' => $lines, 'extra' => $extra];
        }
        $wanted = [];
        foreach ((array) $selections as $sel) {
            if (is_array($sel)) {
                $id = (int) ($sel['id'] ?? $sel['addon_id'] ?? 0);
                $qty = max(1, (int) ($sel['quantity'] ?? 1));
            } else {
                $id = (int) $sel;
                $qty = 1;
            }
            if ($id > 0) {
                $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
            }
        }
        if ($wanted === []) {
            return ['lines' => $lines, 'extra' => $extra];
        }
        foreach ($this->listForFacility($facilityId, true) as $addon) {
            $id = (int) $addon['id'];
            if (!isset($wanted[$id])) {
                continue;
            }
            $qty = $wanted[$id];
            $price = (float) $addon['price'];
            $lineTotal = round($price * $qty, 2);
            $extra += $lineTotal;
            $lines[] = [
                'addon_id' => $id,
                'title' => (string) $addon['title'],
                'price' => $price,
                'quantity' => $qty,
                'line_total' => $lineTotal,
            ];
        }
        return ['lines' => $lines, 'extra' => round($extra, 2)];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public function attachToBooking(int $bookingId, array $lines): void
    {
        if (!$this->db->tableExists('facility_booking_addons') || $bookingId <= 0) {
            return;
        }
        $this->db->execute('DELETE FROM facility_booking_addons WHERE booking_id = :id', ['id' => $bookingId]);
        foreach ($lines as $line) {
            $this->db->insert('facility_booking_addons', [
                'booking_id' => $bookingId,
                'addon_id' => !empty($line['addon_id']) ? (int) $line['addon_id'] : null,
                'title' => substr((string) ($line['title'] ?? 'Add-on'), 0, 255),
                'price' => round((float) ($line['price'] ?? 0), 2),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForBooking(int $bookingId): array
    {
        if (!$this->db->tableExists('facility_booking_addons') || $bookingId <= 0) {
            return [];
        }
        return $this->db->query(
            'SELECT * FROM facility_booking_addons WHERE booking_id = :id ORDER BY id ASC',
            ['id' => $bookingId]
        ) ?: [];
    }

    private static function emptyToNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }
}
