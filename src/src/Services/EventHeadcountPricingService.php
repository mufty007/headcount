<?php

namespace Headcount\Services;

/**
 * Headcount-based package pricing (flat price per inclusive headcount range).
 */
class EventHeadcountPricingService
{
    public const MODEL_PER_PERSON = 'per_person';
    public const MODEL_HEADCOUNT_TIER = 'headcount_tier';

    /**
     * @return list<array{min:int,max:int|null,price:float}>
     */
    public function parseTiersFromEvent(array $event): array
    {
        $raw = $event['headcount_pricing_tiers'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $data = json_decode($raw, true);
        } else {
            $data = $raw;
        }
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $min = isset($row['min']) ? (int) $row['min'] : 0;
            $max = array_key_exists('max', $row) && $row['max'] !== null && $row['max'] !== ''
                ? (int) $row['max']
                : null;
            $price = isset($row['price']) ? (float) $row['price'] : 0.0;
            if ($min < 1 || $price <= 0) {
                continue;
            }
            if ($max !== null && $max < $min) {
                continue;
            }
            $out[] = ['min' => $min, 'max' => $max, 'price' => $price];
        }
        usort($out, function ($a, $b) {
            return $a['min'] <=> $b['min'];
        });

        return $out;
    }

    public function usesHeadcountTiers(array $event): bool
    {
        return ($event['pricing_model'] ?? self::MODEL_PER_PERSON) === self::MODEL_HEADCOUNT_TIER
            && $this->parseTiersFromEvent($event) !== [];
    }

    /**
     * Validate tiers for admin save. Returns error message or null if OK.
     *
     * @param list<array{min:int,max:int|null,price:float}> $tiers
     */
    public function validateTiersForSave(array $tiers): ?string
    {
        if ($tiers === []) {
            return 'Add at least one tier with min heads, max heads, and package price.';
        }
        foreach ($tiers as $t) {
            if ($t['min'] < 1) {
                return 'Each tier must have a minimum of at least 1 person.';
            }
            if ($t['max'] !== null && $t['max'] < $t['min']) {
                return 'Max heads cannot be less than min heads in a tier.';
            }
            if ($t['price'] <= 0) {
                return 'Each tier must have a package price greater than zero.';
            }
        }
        usort($tiers, function ($a, $b) {
            return $a['min'] <=> $b['min'];
        });
        for ($i = 0; $i < count($tiers); $i++) {
            for ($j = $i + 1; $j < count($tiers); $j++) {
                if ($this->rangesOverlap($tiers[$i], $tiers[$j])) {
                    return 'Tiers cannot overlap. Use non-overlapping headcount ranges.';
                }
            }
        }
        if ($tiers[0]['min'] !== 1) {
            return 'The first tier must start at 1 person (smallest group).';
        }
        for ($i = 1; $i < count($tiers); $i++) {
            $prev = $tiers[$i - 1];
            $cur = $tiers[$i];
            if ($prev['max'] === null) {
                return 'Only the last tier may have an open-ended maximum (leave max blank).';
            }
            if ($cur['min'] !== $prev['max'] + 1) {
                return 'Tiers must cover every group size without gaps. After a tier ending at ' . $prev['max'] . ', the next tier must start at ' . ($prev['max'] + 1) . '.';
            }
        }

        return null;
    }

    /**
     * @param array{min:int,max:int|null,price:float} $a
     * @param array{min:int,max:int|null,price:float} $b
     */
    private function rangesOverlap(array $a, array $b): bool
    {
        $aMax = $a['max'] ?? PHP_INT_MAX;
        $bMax = $b['max'] ?? PHP_INT_MAX;

        return !($aMax < $b['min'] || $bMax < $a['min']);
    }

    /**
     * Resolve checkout total for legacy (non–ticket-type) path.
     *
     * @return array{success:bool,amount?:float,message?:string,line_label?:string}
     */
    public function resolveLegacyCheckoutAmount(array $event, int $heads, string $eventTitleForLine): array
    {
        $heads = max(1, $heads);
        $ticketPrice = (float) ($event['ticket_price'] ?? 0);

        if (!$this->usesHeadcountTiers($event)) {
            if ($ticketPrice <= 0) {
                return ['success' => false, 'message' => 'Event is free'];
            }
            $total = $ticketPrice * $heads;

            return [
                'success' => true,
                'amount' => $total,
                'line_label' => $eventTitleForLine,
            ];
        }

        $tiers = $this->parseTiersFromEvent($event);
        foreach ($tiers as $t) {
            $max = $t['max'] ?? PHP_INT_MAX;
            if ($heads >= $t['min'] && $heads <= $max) {
                $rangeLabel = $t['max'] === null
                    ? "{$t['min']}+ people"
                    : ($t['min'] === $t['max'] ? "{$t['min']} person" : "{$t['min']}–{$t['max']} people");

                return [
                    'success' => true,
                    'amount' => (float) $t['price'],
                    'line_label' => $eventTitleForLine . ' – ' . $rangeLabel . ' ($' . number_format($t['price'], 2) . ')',
                ];
            }
        }

        $last = $tiers[count($tiers) - 1] ?? null;
        $maxCovered = $last && $last['max'] !== null ? (int) $last['max'] : null;

        if ($maxCovered !== null) {
            return [
                'success' => false,
                'message' => 'This group size is not covered by a price tier. Maximum package is for ' . $maxCovered . ' people. Reduce guests or contact the organizer.',
            ];
        }

        return ['success' => false, 'message' => 'Could not determine price for this group size.'];
    }

    /**
     * Quote for portal UI (no side effects).
     *
     * @return array{amount:float|null,label:string,per_person_fallback:bool}
     */
    public function quoteForHeads(array $event, int $heads): array
    {
        $heads = max(1, $heads);
        $ticketPrice = (float) ($event['ticket_price'] ?? 0);

        if (!$this->usesHeadcountTiers($event)) {
            if ($ticketPrice <= 0) {
                return ['amount' => null, 'label' => 'Free', 'per_person_fallback' => true];
            }

            return [
                'amount' => round($ticketPrice * $heads, 2),
                'label' => '$' . number_format($ticketPrice * $heads, 2) . ' total',
                'per_person_fallback' => true,
            ];
        }

        $r = $this->resolveLegacyCheckoutAmount($event, $heads, '');
        if ($r['success']) {
            return [
                'amount' => round($r['amount'], 2),
                'label' => '$' . number_format($r['amount'], 2) . ' package total',
                'per_person_fallback' => false,
            ];
        }

        return [
            'amount' => null,
            'label' => $r['message'] ?? 'No price for this group size',
            'per_person_fallback' => false,
        ];
    }

    /**
     * Normalize POST/API input into tier list for validation + JSON encode.
     *
     * @param mixed $raw JSON string or array of rows
     * @return array{tiers: list<array{min:int,max:int|null,price:float}>|null, error: string|null}
     */
    public function normalizeTiersFromInput($raw): array
    {
        if ($raw === null || $raw === '') {
            return ['tiers' => [], 'error' => null];
        }
        if (is_string($raw)) {
            $data = json_decode($raw, true);
            if ($data === null && trim($raw) !== '' && $raw !== '[]') {
                return ['tiers' => null, 'error' => 'Invalid headcount_pricing_tiers JSON.'];
            }
        } else {
            $data = $raw;
        }
        if (!is_array($data)) {
            return ['tiers' => [], 'error' => null];
        }
        $tiers = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $min = isset($row['min']) ? (int) $row['min'] : 0;
            $maxRaw = $row['max'] ?? null;
            if ($maxRaw === '' || $maxRaw === null) {
                $max = null;
            } else {
                $max = (int) $maxRaw;
            }
            $price = isset($row['price']) ? (float) $row['price'] : 0.0;
            if ($min < 1 && $price <= 0 && $maxRaw === null && $max === null) {
                continue;
            }
            $tiers[] = ['min' => $min, 'max' => $max, 'price' => $price];
        }

        return ['tiers' => $tiers, 'error' => null];
    }
}
