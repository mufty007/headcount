<?php

namespace Headcount\Services;

/**
 * Quote program registration totals (per-week sum or all-weeks bundle).
 */
class ProgramPricingService
{
    public const MIN_CHARGE = 0.50;

    /**
     * @param array<string,mixed> $program
     * @param list<int> $selectedWeekIds
     * @param list<array<string,mixed>> $allWeeks rows from program_weeks
     * @return array{success:bool,message?:string,line_items:array,subtotal:float,bundle_applied:bool,total:float,selected_count:int,all_weeks_count:int}
     */
    public function quote(array $program, array $selectedWeekIds, array $allWeeks): array
    {
        $mode = (string) ($program['registration_mode'] ?? 'whole_program');
        if ($mode !== 'select_weeks') {
            $amount = (float) ($program['price_amount'] ?? 0);
            if (($program['pricing_type'] ?? 'free') === 'free') {
                $amount = 0.0;
            }
            return [
                'success' => true,
                'line_items' => [['label' => (string) ($program['title'] ?? 'Program'), 'amount' => $amount]],
                'subtotal' => $amount,
                'bundle_applied' => false,
                'total' => round($amount, 2),
                'selected_count' => 0,
                'all_weeks_count' => 0,
            ];
        }

        $allIds = [];
        $weekById = [];
        foreach ($allWeeks as $w) {
            $wid = (int) ($w['id'] ?? 0);
            if ($wid <= 0) {
                continue;
            }
            $allIds[] = $wid;
            $weekById[$wid] = $w;
        }

        $selected = [];
        foreach ($selectedWeekIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($weekById[$id])) {
                $selected[$id] = true;
            }
        }
        $selectedIds = array_keys($selected);
        if ($selectedIds === []) {
            return ['success' => false, 'message' => 'Select at least one week', 'line_items' => [], 'subtotal' => 0, 'bundle_applied' => false, 'total' => 0, 'selected_count' => 0, 'all_weeks_count' => count($allIds)];
        }

        $lineItems = [];
        $subtotal = 0.0;
        foreach ($selectedIds as $wid) {
            $w = $weekById[$wid];
            $price = (float) ($w['price_amount'] ?? 0);
            $lineItems[] = [
                'week_id' => $wid,
                'label' => (string) ($w['title'] ?? 'Week'),
                'amount' => $price,
            ];
            $subtotal += $price;
        }
        $subtotal = round($subtotal, 2);

        $bundleApplied = false;
        $total = $subtotal;
        $bundle = isset($program['bundle_all_weeks_price']) && $program['bundle_all_weeks_price'] !== ''
            ? (float) $program['bundle_all_weeks_price']
            : null;
        $allSelected = count($allIds) > 0
            && count($selectedIds) === count($allIds)
            && count(array_diff($allIds, $selectedIds)) === 0;
        if ($allSelected && $bundle !== null && $bundle >= 0) {
            $bundleApplied = true;
            $total = round($bundle, 2);
        }

        if (($program['pricing_type'] ?? 'free') === 'free') {
            $total = 0.0;
            $subtotal = 0.0;
            foreach ($lineItems as &$li) {
                $li['amount'] = 0.0;
            }
            unset($li);
        }

        return [
            'success' => true,
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'bundle_applied' => $bundleApplied,
            'total' => $total,
            'selected_count' => count($selectedIds),
            'all_weeks_count' => count($allIds),
        ];
    }

    /**
     * Apply coupon discount to a quoted total.
     */
    public function applyCouponDiscount(float $total, ?array $coupon): float
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

    public function meetsMinimumCharge(float $amount, string $pricingType): bool
    {
        if ($pricingType === 'free' || $amount <= 0) {
            return true;
        }
        return $amount >= self::MIN_CHARGE;
    }
}
