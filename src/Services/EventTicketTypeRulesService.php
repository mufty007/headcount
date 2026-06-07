<?php

namespace Headcount\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Utilities;

/**
 * Sale windows and package-group exclusivity for event_ticket_types.
 */
class EventTicketTypeRulesService
{
    /**
     * @param array<string,mixed> $row event_ticket_types row
     */
    public static function isOnSale(array $row, ?DateTimeInterface $now = null, ?string $orgTimeZoneIana = null): bool
    {
        $now = self::normalizeNow($now, $orgTimeZoneIana);
        $start = self::parseDbDateTime($row['sale_starts_at'] ?? null);
        $end = self::parseDbDateTime($row['sale_ends_at'] ?? null);

        if ($start !== null && $now < $start) {
            return false;
        }
        if ($end !== null && $now > $end) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function filterRowsForPublic(array $rows, ?DateTimeInterface $now = null, ?string $orgTimeZoneIana = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (self::isOnSale($row, $now, $orgTimeZoneIana)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * One primary countdown for the member portal: soonest upcoming sale start (not yet on sale),
     * else soonest sale end among currently on-sale types.
     *
     * @param list<array<string,mixed>> $rows Raw event_ticket_types rows including sale_starts_at / sale_ends_at
     * @return array{phase:string,target_at:string,headline:string,detail:string,ticket_type_id:?int}|null
     */
    public static function buildPortalSaleCountdown(array $rows, ?DateTimeInterface $now = null, ?string $orgTimeZoneIana = null): ?array
    {
        $now = self::normalizeNow($now, $orgTimeZoneIana);
        if ($rows === []) {
            return null;
        }
        $first = reset($rows);
        if ($first === false || !is_array($first) || !array_key_exists('sale_starts_at', $first)) {
            return null;
        }

        /** @var null|array{at: DateTimeImmutable, row: array<string,mixed>} */
        $beforeBest = null;
        /** @var null|array{at: DateTimeImmutable, row: array<string,mixed>} */
        $duringBest = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!self::isOnSale($row, $now, $orgTimeZoneIana)) {
                $start = self::parseDbDateTime($row['sale_starts_at'] ?? null);
                if ($start !== null && $now < $start) {
                    if ($beforeBest === null || $start < $beforeBest['at']) {
                        $beforeBest = ['at' => $start, 'row' => $row];
                    }
                }
                continue;
            }
            $end = self::parseDbDateTime($row['sale_ends_at'] ?? null);
            if ($end !== null && $now < $end) {
                if ($duringBest === null || $end < $duringBest['at']) {
                    $duringBest = ['at' => $end, 'row' => $row];
                }
            }
        }

        if ($beforeBest !== null) {
            return self::portalCountdownPayload('before_sale', $beforeBest['at'], $beforeBest['row']);
        }
        if ($duringBest !== null) {
            return self::portalCountdownPayload('during_sale', $duringBest['at'], $duringBest['row']);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{phase:string,target_at:string,headline:string,detail:string,ticket_type_id:?int}
     */
    private static function portalCountdownPayload(string $phase, DateTimeImmutable $targetAt, array $row): array
    {
        $utc = $targetAt->setTimezone(new DateTimeZone('UTC'));
        $label = self::ticketLabel($row);
        $detail = $label;
        if (isset($row['price']) && $row['price'] !== '' && $row['price'] !== null) {
            $detail .= ' · $' . number_format((float) $row['price'], 2, '.', '');
        }

        return [
            'phase' => $phase,
            'target_at' => $utc->format('c'),
            'headline' => $phase === 'before_sale' ? 'Early bird pricing starts' : 'Sale ends',
            'detail' => $detail,
            'ticket_type_id' => isset($row['id']) ? (int) $row['id'] : null,
        ];
    }

    /**
     * @param list<array{ticket_type_id?:int|mixed,quantity?:int|mixed}>|array<int,array<string,mixed>> $tickets
     * @param array<int,array<string,mixed>> $rowsById ticket type id => row from DB
     * @return array{ok:bool,message:?string}
     */
    public static function validateSelection(array $tickets, array $rowsById, ?DateTimeInterface $now = null, ?string $orgTimeZoneIana = null): array
    {
        $now = self::normalizeNow($now, $orgTimeZoneIana);
        $qtyByType = [];
        foreach ($tickets as $t) {
            if (!is_array($t)) {
                continue;
            }
            $typeId = (int) ($t['ticket_type_id'] ?? 0);
            $qty = (int) ($t['quantity'] ?? 0);
            if ($typeId <= 0 || $qty <= 0) {
                continue;
            }
            $qtyByType[$typeId] = ($qtyByType[$typeId] ?? 0) + $qty;
        }

        if ($qtyByType === []) {
            return ['ok' => true, 'message' => null];
        }

        $groupChosenTypes = [];

        foreach ($qtyByType as $typeId => $qty) {
            if (!isset($rowsById[$typeId])) {
                return ['ok' => false, 'message' => 'One or more selected tickets are not valid for this event.'];
            }
            $row = $rowsById[$typeId];
            if (!self::isOnSale($row, $now, $orgTimeZoneIana)) {
                $label = self::ticketLabel($row);

                return ['ok' => false, 'message' => $label . ' is not available for purchase at this time.'];
            }

            $limit = isset($row['quantity_limit']) && $row['quantity_limit'] !== '' && $row['quantity_limit'] !== null
                ? (int) $row['quantity_limit']
                : null;
            if ($limit !== null && $qty > $limit) {
                $label = self::ticketLabel($row);

                return ['ok' => false, 'message' => 'Quantity for ' . $label . ' exceeds limit of ' . $limit];
            }

            $group = isset($row['package_group']) ? trim((string) $row['package_group']) : '';
            if ($group !== '') {
                if (!isset($groupChosenTypes[$group])) {
                    $groupChosenTypes[$group] = [];
                }
                $groupChosenTypes[$group][$typeId] = true;
            }
        }

        foreach ($groupChosenTypes as $group => $typeIdSet) {
            if (count($typeIdSet) > 1) {
                return [
                    'ok' => false,
                    'message' => 'You can only choose one option in the "' . $group . '" package group.',
                ];
            }
        }

        return ['ok' => true, 'message' => null];
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function ticketLabel(array $row): string
    {
        $name = Utilities::decodeHtmlEntities(trim((string) ($row['name'] ?? '')));

        return $name !== '' ? $name : 'This ticket';
    }

    private static function normalizeNow(?DateTimeInterface $now, ?string $orgTimeZoneIana = null): DateTimeInterface
    {
        if ($now !== null) {
            return $now;
        }

        $tzName = OrgTimeZone::resolve($orgTimeZoneIana);

        return new DateTimeImmutable('now', new DateTimeZone($tzName));
    }

    private static function parseDbDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($s);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
