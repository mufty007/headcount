<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Org-level coupons for events, programs, and facilities.
 */
class CouponService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function tablesExist(): bool
    {
        return $this->db->tableExists('coupons');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOrg(int $organizationId): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT * FROM coupons WHERE organization_id = :org ORDER BY created_at DESC',
            ['org' => $organizationId]
        ) ?: [];
        foreach ($rows as &$row) {
            $this->hydrate($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id, int $organizationId): ?array
    {
        if (!$this->tablesExist()) {
            return null;
        }
        $row = $this->db->queryOne(
            'SELECT * FROM coupons WHERE id = :id AND organization_id = :org',
            ['id' => $id, 'org' => $organizationId]
        );
        if (!$row) {
            return null;
        }
        $this->hydrate($row);
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success:bool,id?:int,message?:string}
     */
    public function save(int $organizationId, array $data, ?int $id = null): array
    {
        if (!$this->tablesExist()) {
            return ['success' => false, 'message' => 'Coupons are not installed. Run migration 090.'];
        }
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            return ['success' => false, 'message' => 'Coupon code is required.'];
        }
        $percent = isset($data['percent_off']) && $data['percent_off'] !== '' ? (float) $data['percent_off'] : null;
        $amount = isset($data['amount_off']) && $data['amount_off'] !== '' ? (float) $data['amount_off'] : null;
        if ($percent === null && $amount === null) {
            return ['success' => false, 'message' => 'Enter a percent off (or amount off).'];
        }
        if ($percent !== null && ($percent <= 0 || $percent > 100)) {
            return ['success' => false, 'message' => 'Percent off must be between 0 and 100.'];
        }
        $row = [
            'organization_id' => $organizationId,
            'code' => substr($code, 0, 64),
            'percent_off' => $percent,
            'amount_off' => $amount,
            'applies_to_events' => empty($data['applies_to_events']) ? 0 : 1,
            'applies_to_programs' => empty($data['applies_to_programs']) ? 0 : 1,
            'applies_to_facilities' => empty($data['applies_to_facilities']) ? 0 : 1,
            'valid_from' => self::emptyToNull($data['valid_from'] ?? null),
            'valid_until' => self::emptyToNull($data['valid_until'] ?? null),
            'max_redemptions' => isset($data['max_redemptions']) && $data['max_redemptions'] !== '' ? max(1, (int) $data['max_redemptions']) : null,
            'max_per_user' => isset($data['max_per_user']) && $data['max_per_user'] !== '' ? max(1, (int) $data['max_per_user']) : null,
            'active' => isset($data['active']) ? (empty($data['active']) ? 0 : 1) : 1,
        ];
        if (!$row['applies_to_events'] && !$row['applies_to_programs'] && !$row['applies_to_facilities']) {
            return ['success' => false, 'message' => 'Select at least one type: events, programs, or facilities.'];
        }
        try {
            if ($id) {
                unset($row['organization_id']);
                $this->db->update('coupons', $id, $row);
            } else {
                $id = (int) $this->db->insert('coupons', $row);
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'That code already exists.'];
        }
        $this->syncTargets($id, $data);
        $this->syncUsers($id, $data);
        return ['success' => true, 'id' => $id];
    }

    public function delete(int $id, int $organizationId): bool
    {
        if (!$this->tablesExist() || $id <= 0) {
            return false;
        }
        $this->db->execute(
            'DELETE FROM coupons WHERE id = :id AND organization_id = :org',
            ['id' => $id, 'org' => $organizationId]
        );
        return true;
    }

    /**
     * @return array{valid:bool,message?:string,coupon?:array<string,mixed>}
     */
    public function validate(int $organizationId, string $code, string $entityType, int $entityId, ?int $userId = null): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['valid' => false, 'message' => 'Empty code'];
        }
        if (!$this->tablesExist()) {
            return ['valid' => false, 'message' => 'Invalid code'];
        }
        $c = $this->db->queryOne(
            'SELECT * FROM coupons WHERE organization_id = :org AND UPPER(code) = :code AND active = 1',
            ['org' => $organizationId, 'code' => $code]
        );
        if (!$c) {
            return ['valid' => false, 'message' => 'Invalid code'];
        }
        $flag = [
            'event' => 'applies_to_events',
            'program' => 'applies_to_programs',
            'facility' => 'applies_to_facilities',
        ][$entityType] ?? null;
        if ($flag === null || empty($c[$flag])) {
            return ['valid' => false, 'message' => 'Code does not apply here'];
        }
        $today = date('Y-m-d');
        if (!empty($c['valid_from']) && $today < $c['valid_from']) {
            return ['valid' => false, 'message' => 'Code not yet valid'];
        }
        if (!empty($c['valid_until']) && $today > $c['valid_until']) {
            return ['valid' => false, 'message' => 'Code expired'];
        }
        if ($c['max_redemptions'] !== null && (int) $c['redemptions_count'] >= (int) $c['max_redemptions']) {
            return ['valid' => false, 'message' => 'Code fully redeemed'];
        }
        $this->hydrate($c);
        if (!empty($c['target_ids'][$entityType]) && !in_array($entityId, $c['target_ids'][$entityType], true)) {
            return ['valid' => false, 'message' => 'Code does not apply to this item'];
        }
        if (!empty($c['user_ids'])) {
            if ($userId === null || !in_array((int) $userId, $c['user_ids'], true)) {
                return ['valid' => false, 'message' => 'This code is not available for your account'];
            }
        }
        if ($userId && !empty($c['max_per_user']) && $this->db->tableExists('coupon_redemptions')) {
            $used = $this->db->queryOne(
                'SELECT COUNT(*) AS c FROM coupon_redemptions WHERE coupon_id = :cid AND user_id = :uid',
                ['cid' => (int) $c['id'], 'uid' => $userId]
            );
            if ((int) ($used['c'] ?? 0) >= (int) $c['max_per_user']) {
                return ['valid' => false, 'message' => 'You have already used this code'];
            }
        }
        return ['valid' => true, 'coupon' => $c];
    }

    /**
     * Public-safe discount fields (no ids, targets, or audience lists).
     *
     * @param array<string, mixed> $coupon
     * @return array{code:string,percent_off:?float,amount_off:?float,label:string}
     */
    public static function publicDiscount(array $coupon): array
    {
        $percent = isset($coupon['percent_off']) && $coupon['percent_off'] !== '' && $coupon['percent_off'] !== null
            ? (float) $coupon['percent_off']
            : 0.0;
        $amount = isset($coupon['amount_off']) && $coupon['amount_off'] !== '' && $coupon['amount_off'] !== null
            ? (float) $coupon['amount_off']
            : 0.0;
        $label = '';
        if ($percent > 0) {
            $label = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '% off';
        } elseif ($amount > 0) {
            $label = '$' . number_format($amount, 2) . ' off';
        }
        return [
            'code' => strtoupper((string) ($coupon['code'] ?? '')),
            'percent_off' => $percent > 0 ? $percent : null,
            'amount_off' => $amount > 0 ? $amount : null,
            'label' => $label,
        ];
    }

    public static function applyDiscount(float $total, ?array $coupon): float
    {
        if ($total <= 0 || !$coupon) {
            return $total;
        }
        if (!empty($coupon['percent_off'])) {
            return round($total * (1 - ((float) $coupon['percent_off']) / 100), 2);
        }
        if (!empty($coupon['amount_off'])) {
            return max(0, round($total - (float) $coupon['amount_off'], 2));
        }
        return $total;
    }

    public function recordRedemption(int $couponId, int $organizationId, string $entityType, int $entityId, float $discountedAmount, ?int $userId = null): void
    {
        if (!$this->tablesExist()) {
            return;
        }
        $this->db->execute(
            'UPDATE coupons SET redemptions_count = redemptions_count + 1 WHERE id = :id',
            ['id' => $couponId]
        );
        if ($this->db->tableExists('coupon_redemptions')) {
            $this->db->insert('coupon_redemptions', [
                'coupon_id' => $couponId,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'entity_type' => $entityType,
                'entity_id' => $entityId ?: null,
                'discounted_amount' => round($discountedAmount, 2),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRedemptions(int $organizationId, int $couponId = 0): array
    {
        if (!$this->db->tableExists('coupon_redemptions')) {
            return [];
        }
        $sql = "SELECT r.*, c.code, CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.email
                FROM coupon_redemptions r
                INNER JOIN coupons c ON c.id = r.coupon_id
                LEFT JOIN users u ON u.id = r.user_id
                WHERE r.organization_id = :org";
        $params = ['org' => $organizationId];
        if ($couponId > 0) {
            $sql .= ' AND r.coupon_id = :cid';
            $params['cid'] = $couponId;
        }
        $sql .= ' ORDER BY r.used_at DESC LIMIT 500';
        return $this->db->query($sql, $params) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array &$row): void
    {
        $id = (int) ($row['id'] ?? 0);
        $row['target_ids'] = ['event' => [], 'program' => [], 'facility' => []];
        $row['user_ids'] = [];
        if ($id > 0 && $this->db->tableExists('coupon_targets')) {
            foreach ($this->db->query('SELECT entity_type, entity_id FROM coupon_targets WHERE coupon_id = :id', ['id' => $id]) ?: [] as $t) {
                $type = (string) ($t['entity_type'] ?? '');
                if (isset($row['target_ids'][$type])) {
                    $row['target_ids'][$type][] = (int) $t['entity_id'];
                }
            }
        }
        if ($id > 0 && $this->db->tableExists('coupon_users')) {
            foreach ($this->db->query('SELECT user_id FROM coupon_users WHERE coupon_id = :id', ['id' => $id]) ?: [] as $u) {
                $row['user_ids'][] = (int) $u['user_id'];
            }
        }
        $max = (int) ($row['max_redemptions'] ?? 0);
        $used = (int) ($row['redemptions_count'] ?? 0);
        $row['remaining'] = $max > 0 ? max(0, $max - $used) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncTargets(int $couponId, array $data): void
    {
        if (!$this->db->tableExists('coupon_targets')) {
            return;
        }
        $this->db->execute('DELETE FROM coupon_targets WHERE coupon_id = :id', ['id' => $couponId]);
        $map = [
            'event' => $data['event_ids'] ?? ($data['target_ids']['event'] ?? []),
            'program' => $data['program_ids'] ?? ($data['target_ids']['program'] ?? []),
            'facility' => $data['facility_ids'] ?? ($data['target_ids']['facility'] ?? []),
        ];
        foreach ($map as $type => $ids) {
            foreach ((array) $ids as $eid) {
                $eid = (int) $eid;
                if ($eid > 0) {
                    $this->db->insert('coupon_targets', [
                        'coupon_id' => $couponId,
                        'entity_type' => $type,
                        'entity_id' => $eid,
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncUsers(int $couponId, array $data): void
    {
        if (!$this->db->tableExists('coupon_users')) {
            return;
        }
        $this->db->execute('DELETE FROM coupon_users WHERE coupon_id = :id', ['id' => $couponId]);
        foreach ((array) ($data['user_ids'] ?? []) as $uid) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $this->db->insert('coupon_users', [
                    'coupon_id' => $couponId,
                    'user_id' => $uid,
                ]);
            }
        }
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
