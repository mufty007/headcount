<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Fixed potluck food categories (slug + display label), signup validation, and RSVP column updates.
 */
class PotluckCategoryService
{
    /** @var array<string, string> slug => label */
    private const CATEGORIES = [
        'meat_dishes' => 'Meat dishes',
        'finger_foods_wraps' => 'Finger foods / wraps',
        'salads' => 'Salads',
        'bread_flatbread_naan' => 'Bread / flatbread / naan',
        'dips_spreads' => 'Dips / spreads',
        'soups_stews' => 'Soups / stews',
        'rice' => 'Rice',
        'noodles_pasta' => 'Noodles / pasta',
        'vegetarian_vegan' => 'Vegetarian / vegan mains',
        'side_dishes' => 'Side dishes / hot sides',
        'fruit' => 'Fruit / cut fruit',
        'disposables_utensils' => 'Plates, cups, utensils, napkins',
        'desserts' => 'Desserts',
        'beverages' => 'Beverages',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    private const SERVING_SIDE_LABELS = [
        'brothers' => "Brothers' side",
        'sisters' => "Sisters' side",
        'both' => 'Both sides',
    ];

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function optionsForApi(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function orderedSlugs(): array
    {
        return array_keys(self::CATEGORIES);
    }

    /**
     * Parse events.potluck_allowed_slugs from a DB row. Null or empty after parsing = use full category list.
     *
     * @param array<string, mixed> $event
     * @return list<string>|null Non-null list means restrict to these slugs only (subset of master list).
     */
    public static function parsePotluckAllowedSlugsFromEvent(array $event): ?array
    {
        if (!array_key_exists('potluck_allowed_slugs', $event)) {
            return null;
        }
        $raw = $event['potluck_allowed_slugs'];
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $list = $decoded;
        } elseif (is_array($raw)) {
            $list = $raw;
        } else {
            return null;
        }
        $out = [];
        foreach ($list as $item) {
            $s = is_string($item) ? trim($item) : '';
            if ($s !== '' && self::isValidSlug($s) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param list<string>|null $allowedSlugs from parsePotluckAllowedSlugsFromEvent; null = all
     * @return list<array{id: string, label: string}>
     */
    public static function optionsForApiFiltered(?array $allowedSlugs): array
    {
        if ($allowedSlugs === null || $allowedSlugs === []) {
            return self::optionsForApi();
        }
        $out = [];
        foreach (self::orderedSlugs() as $id) {
            if (in_array($id, $allowedSlugs, true)) {
                $out[] = ['id' => $id, 'label' => self::CATEGORIES[$id]];
            }
        }

        return $out;
    }

    /**
     * @param list<string>|null $allowedSlugs null or empty = no restriction beyond master list
     */
    public static function isSlugInAllowedPotluckList(string $slug, ?array $allowedSlugs): bool
    {
        if ($allowedSlugs === null || $allowedSlugs === []) {
            return true;
        }

        return in_array($slug, $allowedSlugs, true);
    }

    /**
     * Checkbox state for admin event forms: full list selected when DB has no restriction.
     *
     * @param array<string, mixed> $event
     * @return list<string>
     */
    public static function adminSelectedSlugsForPotluckForm(array $event): array
    {
        if (empty($event['is_potluck'])) {
            return self::orderedSlugs();
        }
        $p = self::parsePotluckAllowedSlugsFromEvent($event);

        return $p === null ? self::orderedSlugs() : $p;
    }

    /**
     * Build JSON for events.potluck_allowed_slugs. Null means all categories (legacy).
     *
     * @param list<string>|array<int|string,mixed> $postedSlugList from potluck_allowed_slugs[] POST
     */
    public static function potluckAllowedSlugsJsonForStorage(bool $isPotluckEvent, array $postedSlugList): ?string
    {
        if (!$isPotluckEvent) {
            return null;
        }
        $ordered = self::orderedSlugs();
        $picked = [];
        foreach ($postedSlugList as $item) {
            if (!is_string($item)) {
                continue;
            }
            $s = trim($item);
            if ($s !== '' && self::isValidSlug($s)) {
                $picked[] = $s;
            }
        }
        $picked = array_values(array_unique($picked));
        if ($picked === [] || count($picked) >= count($ordered)) {
            return null;
        }

        return json_encode($picked);
    }

    public static function isValidSlug(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        return array_key_exists($slug, self::CATEGORIES);
    }

    public static function labelForSlug(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return self::CATEGORIES[$slug] ?? null;
    }

    public static function isValidServingSide(?string $side): bool
    {
        if ($side === null || $side === '') {
            return false;
        }

        return array_key_exists($side, self::SERVING_SIDE_LABELS);
    }

    /**
     * Whether the client is signing up to bring a listed potluck dish (category required).
     * When potluck_bringing_food is absent, non-empty category implies bringing a dish (legacy clients).
     */
    public static function requiresPotluckDishCategoryFromRequest(array $input): bool
    {
        if (!array_key_exists('potluck_bringing_food', $input)) {
            return true;
        }

        return self::isTruthyPotluckBringingFood($input['potluck_bringing_food']);
    }

    /**
     * True = bringing food (dish details expected); false = attending without a listed dish.
     */
    public static function isTruthyPotluckBringingFood(mixed $v): bool
    {
        if ($v === false || $v === 0 || $v === '0') {
            return false;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === 'false' || $s === 'no' || $s === 'off') {
                return false;
            }
        }

        return true;
    }

    public static function labelForServingSide(?string $side): ?string
    {
        if ($side === null || $side === '') {
            return null;
        }

        return self::SERVING_SIDE_LABELS[$side] ?? null;
    }

    /**
     * Normalize category + note only (legacy 3-arg callers).
     *
     * @return array{ok: bool, slug: ?string, note: ?string, error: ?string}
     */
    public static function normalizeInput(?string $categorySlug, mixed $itemNote, bool $requiredCategory): array
    {
        $r = self::normalizePotluckSignup([
            'potluck_category' => $categorySlug,
            'potluck_item_note' => $itemNote,
        ], false, $requiredCategory);

        return [
            'ok' => $r['ok'],
            'slug' => $r['slug'],
            'note' => $r['note'],
            'error' => $r['error'],
        ];
    }

    /**
     * Full potluck signup validation for APIs (category, note rules, quantity, serving side, party counts).
     *
     * @param array<string, mixed> $input Keys: potluck_category, potluck_item_note, potluck_quantity, potluck_serving_side, potluck_party_adults, potluck_party_children
     * @param list<string>|null $allowedPotluckSlugs null = all master categories allowed for this event
     * @return array{
     *   ok: bool,
     *   slug: ?string,
     *   note: ?string,
     *   quantity: ?int,
     *   serving_side: ?string,
     *   party_adults: ?int,
     *   party_children: ?int,
     *   error: ?string
     * }
     */
    public static function normalizePotluckSignup(array $input, bool $requireFullSignup, bool $requireCategory = true, ?array $allowedPotluckSlugs = null): array
    {
        $catRaw = $input['potluck_category'] ?? $input['category'] ?? null;
        $slug = $catRaw !== null && $catRaw !== '' ? trim((string) $catRaw) : null;
        $noteRaw = $input['potluck_item_note'] ?? $input['item_note'] ?? null;
        $noteTrimmed = is_scalar($noteRaw) ? trim((string) $noteRaw) : '';
        $note = $noteTrimmed !== '' ? mb_substr($noteTrimmed, 0, 500) : null;

        if ($requireCategory) {
            if ($slug === null || $slug === '' || !self::isValidSlug($slug)) {
                return [
                    'ok' => false,
                    'slug' => null,
                    'note' => $note,
                    'quantity' => null,
                    'serving_side' => null,
                    'party_adults' => null,
                    'party_children' => null,
                    'error' => 'Please select a food category for this potluck event.',
                ];
            }
            if (!self::isSlugInAllowedPotluckList($slug, $allowedPotluckSlugs)) {
                return [
                    'ok' => false,
                    'slug' => null,
                    'note' => $note,
                    'quantity' => null,
                    'serving_side' => null,
                    'party_adults' => null,
                    'party_children' => null,
                    'error' => 'That food category is not available for this event.',
                ];
            }
        } else {
            if ($slug !== null && $slug !== '' && !self::isValidSlug($slug)) {
                return [
                    'ok' => false,
                    'slug' => null,
                    'note' => $note,
                    'quantity' => null,
                    'serving_side' => null,
                    'party_adults' => null,
                    'party_children' => null,
                    'error' => 'Invalid food category.',
                ];
            }
            if ($slug !== null && $slug !== '' && !self::isSlugInAllowedPotluckList($slug, $allowedPotluckSlugs)) {
                return [
                    'ok' => false,
                    'slug' => null,
                    'note' => $note,
                    'quantity' => null,
                    'serving_side' => null,
                    'party_adults' => null,
                    'party_children' => null,
                    'error' => 'That food category is not available for this event.',
                ];
            }
            if ($slug === null || $slug === '') {
                if (!$requireFullSignup) {
                    return [
                        'ok' => true,
                        'slug' => null,
                        'note' => $note,
                        'quantity' => null,
                        'serving_side' => null,
                        'party_adults' => null,
                        'party_children' => null,
                        'error' => null,
                    ];
                }
                $adultsRaw = $input['potluck_party_adults'] ?? $input['party_adults'] ?? null;
                $childrenRaw = $input['potluck_party_children'] ?? $input['party_children'] ?? null;
                $adults = is_numeric($adultsRaw) ? (int) $adultsRaw : -1;
                $children = is_numeric($childrenRaw) ? (int) $childrenRaw : -1;
                if ($adults < 1 || $adults > 500) {
                    return [
                        'ok' => false,
                        'slug' => null,
                        'note' => null,
                        'quantity' => null,
                        'serving_side' => null,
                        'party_adults' => null,
                        'party_children' => null,
                        'error' => 'Please enter how many adults are attending (including yourself), at least 1.',
                    ];
                }
                if ($children < 0 || $children > 500) {
                    return [
                        'ok' => false,
                        'slug' => null,
                        'note' => null,
                        'quantity' => null,
                        'serving_side' => null,
                        'party_adults' => null,
                        'party_children' => null,
                        'error' => 'Please enter a valid number of children attending (0 or more).',
                    ];
                }

                return [
                    'ok' => true,
                    'slug' => null,
                    'note' => null,
                    'quantity' => null,
                    'serving_side' => null,
                    'party_adults' => $adults,
                    'party_children' => $children,
                    'error' => null,
                ];
            }
        }

        if ($slug === 'other' && ($note === null || $note === '')) {
            return [
                'ok' => false,
                'slug' => $slug,
                'note' => $note,
                'quantity' => null,
                'serving_side' => null,
                'party_adults' => null,
                'party_children' => null,
                'error' => 'Please describe what you are bringing when you select Other.',
            ];
        }

        if (!$requireFullSignup) {
            return [
                'ok' => true,
                'slug' => $slug,
                'note' => $note,
                'quantity' => null,
                'serving_side' => null,
                'party_adults' => null,
                'party_children' => null,
                'error' => null,
            ];
        }

        $qtyRaw = $input['potluck_quantity'] ?? $input['quantity'] ?? null;
        $qty = is_numeric($qtyRaw) ? (int) $qtyRaw : -1;
        if ($qty < 1 || $qty > 999) {
            return [
                'ok' => false,
                'slug' => $slug,
                'note' => $note,
                'quantity' => null,
                'serving_side' => null,
                'party_adults' => null,
                'party_children' => null,
                'error' => 'Please enter how much you are bringing (quantity between 1 and 999).',
            ];
        }

        $sideRaw = $input['potluck_serving_side'] ?? $input['serving_side'] ?? null;
        $side = $sideRaw !== null && $sideRaw !== '' ? strtolower(trim((string) $sideRaw)) : '';
        if (!self::isValidServingSide($side)) {
            return [
                'ok' => false,
                'slug' => $slug,
                'note' => $note,
                'quantity' => $qty,
                'serving_side' => null,
                'party_adults' => null,
                'party_children' => null,
                'error' => 'Please select whether the item is for the brothers\' side, sisters\' side, or both.',
            ];
        }

        $adultsRaw = $input['potluck_party_adults'] ?? $input['party_adults'] ?? null;
        $childrenRaw = $input['potluck_party_children'] ?? $input['party_children'] ?? null;
        $adults = is_numeric($adultsRaw) ? (int) $adultsRaw : -1;
        $children = is_numeric($childrenRaw) ? (int) $childrenRaw : -1;
        if ($adults < 1 || $adults > 500) {
            return [
                'ok' => false,
                'slug' => $slug,
                'note' => $note,
                'quantity' => $qty,
                'serving_side' => $side,
                'party_adults' => null,
                'party_children' => null,
                'error' => 'Please enter how many adults are attending (including yourself), at least 1.',
            ];
        }
        if ($children < 0 || $children > 500) {
            return [
                'ok' => false,
                'slug' => $slug,
                'note' => $note,
                'quantity' => $qty,
                'serving_side' => $side,
                'party_adults' => null,
                'party_children' => null,
                'error' => 'Please enter a valid number of children attending (0 or more).',
            ];
        }

        return [
            'ok' => true,
            'slug' => $slug,
            'note' => $note,
            'quantity' => $qty,
            'serving_side' => $side,
            'party_adults' => $adults,
            'party_children' => $children,
            'error' => null,
        ];
    }

    public static function sortOrderIndex(string $slug): int
    {
        $keys = self::orderedSlugs();
        $i = array_search($slug, $keys, true);

        return $i === false ? 999 : (int) $i;
    }

    /**
     * Shape normalized signup result for applyPotluckState().
     *
     * @param array<string, mixed> $norm Result of normalizePotluckSignup with ok=true
     * @return array{slug?: string, note?: ?string, quantity?: int, serving_side?: string, party_adults?: int, party_children?: int}|null
     */
    public static function applyPayloadFromNormalization(array $norm): ?array
    {
        $slug = isset($norm['slug']) && $norm['slug'] !== null && (string) $norm['slug'] !== ''
            ? (string) $norm['slug']
            : null;
        $out = [];
        if ($slug !== null) {
            $out['slug'] = $slug;
            $out['note'] = $norm['note'] ?? null;
            if (array_key_exists('quantity', $norm) && $norm['quantity'] !== null) {
                $out['quantity'] = (int) $norm['quantity'];
            }
            if (array_key_exists('serving_side', $norm) && $norm['serving_side'] !== null && $norm['serving_side'] !== '') {
                $out['serving_side'] = (string) $norm['serving_side'];
            }
        }
        if (array_key_exists('party_adults', $norm) && $norm['party_adults'] !== null) {
            $out['party_adults'] = (int) $norm['party_adults'];
        }
        if (array_key_exists('party_children', $norm) && $norm['party_children'] !== null) {
            $out['party_children'] = (int) $norm['party_children'];
        }

        return $out === [] ? null : $out;
    }

    /**
     * Validate potluck when the RSVP will be "yes" on a potluck event. Call before creating/updating the RSVP row.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $currentRsvp RSVP row before update (for PUT); for POST use empty potluck
     * @param array<string, mixed>|null $fullInput When provided and fieldsProvided, used for full signup validation
     * @return string|null Error message or null
     */
    public static function validateForYesPotluck(
        Database $db,
        array $event,
        string $finalStatus,
        bool $fieldsProvided,
        ?string $categorySlug,
        mixed $itemNote,
        array $currentRsvp,
        ?array $fullInput = null
    ): ?string {
        if (!$db->hasColumn('rsvps', 'potluck_category') || !$db->hasColumn('rsvps', 'potluck_item_note')) {
            return null;
        }
        if (empty($event['is_potluck']) || strtolower(trim($finalStatus)) !== 'yes') {
            return null;
        }
        $hasExtended = $db->hasColumn('rsvps', 'potluck_quantity')
            && $db->hasColumn('rsvps', 'potluck_serving_side')
            && $db->hasColumn('rsvps', 'potluck_party_adults')
            && $db->hasColumn('rsvps', 'potluck_party_children');

        $allowedSlugs = self::parsePotluckAllowedSlugsFromEvent($event);

        if ($fieldsProvided) {
            $input = is_array($fullInput) ? $fullInput : [
                'potluck_category' => $categorySlug,
                'potluck_item_note' => $itemNote,
            ];
            if (!isset($input['potluck_category']) && $categorySlug !== null) {
                $input['potluck_category'] = $categorySlug;
            }
            if (!isset($input['potluck_item_note']) && array_key_exists('potluck_item_note', $input) === false) {
                $input['potluck_item_note'] = $itemNote;
            }
            $requireDish = self::requiresPotluckDishCategoryFromRequest($input);
            $norm = self::normalizePotluckSignup($input, $hasExtended, $requireDish, $allowedSlugs);

            return $norm['ok'] ? null : (string) $norm['error'];
        }
        $existing = isset($currentRsvp['potluck_category']) ? trim((string) $currentRsvp['potluck_category']) : '';
        if ($existing === '') {
            if ($hasExtended) {
                $pa = isset($currentRsvp['potluck_party_adults']) ? (int) $currentRsvp['potluck_party_adults'] : 0;
                $pc = isset($currentRsvp['potluck_party_children']) ? (int) $currentRsvp['potluck_party_children'] : 0;
                if ($pa >= 1 && $pc >= 0) {
                    return null;
                }
            }
            return 'Please select a food category for this potluck event.';
        }
        if (!self::isValidSlug($existing)) {
            return 'Please update your potluck selection (invalid food category).';
        }
        if (!self::isSlugInAllowedPotluckList($existing, $allowedSlugs)) {
            return 'Please update your potluck selection; that category is no longer offered for this event.';
        }
        if ($hasExtended) {
            $q = isset($currentRsvp['potluck_quantity']) ? (int) $currentRsvp['potluck_quantity'] : 0;
            $side = isset($currentRsvp['potluck_serving_side']) ? trim((string) $currentRsvp['potluck_serving_side']) : '';
            $pa = isset($currentRsvp['potluck_party_adults']) ? (int) $currentRsvp['potluck_party_adults'] : 0;
            if ($q < 1 || !self::isValidServingSide($side) || $pa < 1) {
                return 'Please complete potluck details (quantity, serving side, and adults/children attending).';
            }
        }

        return null;
    }

    /**
     * Apply potluck columns after RSVP row reflects final status.
     *
     * @param array{
     *   slug?: string,
     *   note?: ?string,
     *   quantity?: int,
     *   serving_side?: string,
     *   party_adults?: int,
     *   party_children?: int
     * }|null $normalized
     */
    public static function applyPotluckState(
        Database $db,
        array $event,
        int $rsvpId,
        string $finalStatus,
        ?array $normalized,
        bool $fieldsProvided
    ): void {
        if (!$db->hasColumn('rsvps', 'potluck_category') || !$db->hasColumn('rsvps', 'potluck_item_note')) {
            return;
        }
        $hasQty = $db->hasColumn('rsvps', 'potluck_quantity');
        $hasSide = $db->hasColumn('rsvps', 'potluck_serving_side');
        $hasAdults = $db->hasColumn('rsvps', 'potluck_party_adults');
        $hasChildren = $db->hasColumn('rsvps', 'potluck_party_children');
        $hasGuestCount = $db->hasColumn('rsvps', 'guest_count');

        $isPotluck = !empty($event['is_potluck']);
        $yes = strtolower(trim($finalStatus)) === 'yes';
        if (!$isPotluck || !$yes) {
            $sets = ['potluck_category = NULL', 'potluck_item_note = NULL'];
            if ($hasQty) {
                $sets[] = 'potluck_quantity = NULL';
            }
            if ($hasSide) {
                $sets[] = 'potluck_serving_side = NULL';
            }
            if ($hasAdults) {
                $sets[] = 'potluck_party_adults = NULL';
            }
            if ($hasChildren) {
                $sets[] = 'potluck_party_children = NULL';
            }
            $sql = 'UPDATE rsvps SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $db->execute($sql, ['id' => $rsvpId]);

            return;
        }
        if ($fieldsProvided && $normalized !== null) {
            $slugVal = isset($normalized['slug']) && $normalized['slug'] !== '' && $normalized['slug'] !== null
                ? (string) $normalized['slug']
                : null;
            $params = ['id' => $rsvpId];
            $sets = [];
            if ($slugVal !== null) {
                $params['c'] = $slugVal;
                $params['n'] = $normalized['note'] ?? null;
                $sets[] = 'potluck_category = :c';
                $sets[] = 'potluck_item_note = :n';
                if ($hasQty && array_key_exists('quantity', $normalized)) {
                    $sets[] = 'potluck_quantity = :pq';
                    $params['pq'] = (int) $normalized['quantity'];
                } elseif ($hasQty) {
                    $sets[] = 'potluck_quantity = NULL';
                }
                if ($hasSide && array_key_exists('serving_side', $normalized) && $normalized['serving_side'] !== null && $normalized['serving_side'] !== '') {
                    $sets[] = 'potluck_serving_side = :ps';
                    $params['ps'] = (string) $normalized['serving_side'];
                } elseif ($hasSide) {
                    $sets[] = 'potluck_serving_side = NULL';
                }
            } else {
                $sets[] = 'potluck_category = NULL';
                $sets[] = 'potluck_item_note = NULL';
                if ($hasQty) {
                    $sets[] = 'potluck_quantity = NULL';
                }
                if ($hasSide) {
                    $sets[] = 'potluck_serving_side = NULL';
                }
            }
            if ($hasAdults && array_key_exists('party_adults', $normalized)) {
                $sets[] = 'potluck_party_adults = :pa';
                $params['pa'] = (int) $normalized['party_adults'];
            }
            if ($hasChildren && array_key_exists('party_children', $normalized)) {
                $sets[] = 'potluck_party_children = :pc';
                $params['pc'] = (int) $normalized['party_children'];
            }
            if ($hasGuestCount && array_key_exists('party_adults', $normalized) && array_key_exists('party_children', $normalized)) {
                $sets[] = 'guest_count = :gc';
                $params['gc'] = max(0, (int) $normalized['party_adults'] + (int) $normalized['party_children'] - 1);
            }
            if ($sets !== []) {
                $sql = 'UPDATE rsvps SET ' . implode(', ', $sets) . ' WHERE id = :id';
                $db->execute($sql, $params);
            }
        }
    }
}
