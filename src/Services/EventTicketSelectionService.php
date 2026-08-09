<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Utilities;

/**
 * Parse, validate, quote, and persist event ticket type selections for RSVPs.
 */
class EventTicketSelectionService
{
    /**
     * @param array<string,mixed> $input
     * @return list<array{ticket_type_id:int,quantity:int}>
     */
    public static function parseTicketsFromRequest(array $input): array
    {
        $ticketsRaw = isset($input['tickets']) && is_array($input['tickets']) ? $input['tickets'] : [];
        $tickets = [];
        foreach ($ticketsRaw as $t) {
            if (!is_array($t)) {
                continue;
            }
            $typeId = (int) ($t['ticket_type_id'] ?? 0);
            $qty = (int) ($t['quantity'] ?? 0);
            if ($typeId <= 0 || $qty <= 0) {
                continue;
            }
            $tickets[] = ['ticket_type_id' => $typeId, 'quantity' => $qty];
        }

        return $tickets;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function loadTypeMapForEvent(Database $db, int $eventId): array
    {
        if ($eventId <= 0) {
            return [];
        }
        try {
            $ttExtra = $db->hasColumn('event_ticket_types', 'sale_starts_at')
                ? ', sale_starts_at, sale_ends_at, package_group'
                : '';
            $ticketTypeRows = $db->query(
                "SELECT id, event_id, name, price, quantity_limit{$ttExtra} FROM event_ticket_types WHERE event_id = :eid",
                ['eid' => $eventId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        $typeMap = [];
        foreach ($ticketTypeRows as $row) {
            $typeMap[(int) $row['id']] = $row;
        }

        return $typeMap;
    }

    /**
     * @return array{has_named_types:bool,has_paid_types:bool}
     */
    public static function eventTicketTypeFlags(Database $db, int $eventId, float $fallbackTicketPrice = 0.0): array
    {
        $typeMap = self::loadTypeMapForEvent($db, $eventId);
        $hasNamedTypes = $typeMap !== [];
        $hasPaidTypes = $fallbackTicketPrice > 0;
        foreach ($typeMap as $row) {
            if ((float) ($row['price'] ?? 0) > 0) {
                $hasPaidTypes = true;
                break;
            }
        }

        return [
            'has_named_types' => $hasNamedTypes,
            'has_paid_types' => $hasPaidTypes,
        ];
    }

    /**
     * Whether the event can use Stripe checkout (flat price, paid ticket types, or headcount tiers).
     *
     * @param array<string,mixed> $event
     */
    public static function eventSupportsPaidCheckout(Database $db, array $event): bool
    {
        $eventId = (int) ($event['id'] ?? 0);
        $ticketPrice = (float) ($event['ticket_price'] ?? 0);
        $flags = self::eventTicketTypeFlags($db, $eventId, $ticketPrice);
        if ($flags['has_paid_types'] || $ticketPrice > 0) {
            return true;
        }

        return (new EventHeadcountPricingService())->usesHeadcountTiers($event);
    }

    /**
     * Block free RSVP endpoints when payment/checkout is required.
     * Returns null when an unpaid RSVP is allowed; otherwise an error message.
     *
     * Named ticket types: unpaid only when the selection totals $0 (optional free tickets).
     * Flat price / headcount tiers: always require checkout.
     *
     * @param array<string,mixed> $event
     * @param list<array{ticket_type_id:int,quantity:int}> $tickets
     */
    public static function freeRsvpDeniedMessage(Database $db, array $event, array $tickets = []): ?string
    {
        $eventId = (int) ($event['id'] ?? 0);
        $ticketPrice = (float) ($event['ticket_price'] ?? 0);
        $flags = self::eventTicketTypeFlags($db, $eventId, $ticketPrice);
        $payMsg = 'This event requires payment. Use Continue to payment in the guest form, or log in to register.';

        if ($flags['has_named_types']) {
            if ($tickets === []) {
                return null;
            }
            $typeMap = self::loadTypeMapForEvent($db, $eventId);
            $quote = self::quoteSelection($tickets, $typeMap);
            if ($quote['totalAmount'] > 0) {
                return 'This selection requires payment. Choose Continue to payment, or select free ticket options only.';
            }

            return null;
        }

        if ((new EventHeadcountPricingService())->usesHeadcountTiers($event) || $ticketPrice > 0 || $flags['has_paid_types']) {
            return $payMsg;
        }

        return null;
    }

    /**
     * @param list<array{ticket_type_id:int,quantity:int}> $tickets
     * @param array<int,array<string,mixed>> $typeMap
     * @return array{totalTickets:int,totalAmount:float,paidTickets:int,freeTickets:int}
     */
    public static function quoteSelection(array $tickets, array $typeMap): array
    {
        $totalTickets = 0;
        $totalAmount = 0.0;
        $paidTickets = 0;
        $freeTickets = 0;

        foreach ($tickets as $t) {
            $typeId = (int) ($t['ticket_type_id'] ?? 0);
            $qty = (int) ($t['quantity'] ?? 0);
            if ($typeId <= 0 || $qty <= 0 || !isset($typeMap[$typeId])) {
                continue;
            }
            $price = (float) ($typeMap[$typeId]['price'] ?? 0);
            $totalTickets += $qty;
            $totalAmount += $price * $qty;
            if ($price > 0) {
                $paidTickets += $qty;
            } else {
                $freeTickets += $qty;
            }
        }

        return [
            'totalTickets' => $totalTickets,
            'totalAmount' => $totalAmount,
            'paidTickets' => $paidTickets,
            'freeTickets' => $freeTickets,
        ];
    }

    /**
     * @param list<array{ticket_type_id:int,quantity:int}> $tickets
     * @param array<string,mixed>|null $potluckNorm normalized potluck payload with party_adults/party_children
     */
    public static function headsForCapacity(array $tickets, int $guestCount, ?array $potluckNorm, bool $isPotluck = false): int
    {
        if ($tickets !== []) {
            $heads = 0;
            foreach ($tickets as $t) {
                $heads += (int) ($t['quantity'] ?? 0);
            }

            return max(1, $heads);
        }
        if ($isPotluck && $potluckNorm !== null
            && isset($potluckNorm['party_adults'], $potluckNorm['party_children'])) {
            return max(1, (int) $potluckNorm['party_adults'] + (int) $potluckNorm['party_children']);
        }

        return max(1, 1 + max(0, $guestCount));
    }

    /**
     * Validate ticket selection against rules and org timezone.
     *
     * @param list<array{ticket_type_id:int,quantity:int}> $tickets
     * @param array<int,array<string,mixed>> $typeMap
     * @return array{ok:bool,message:?string}
     */
    public static function validateSelectionRules(
        array $tickets,
        array $typeMap,
        ?string $orgTimeZoneIana = null
    ): array {
        return EventTicketTypeRulesService::validateSelection($tickets, $typeMap, null, $orgTimeZoneIana);
    }

    /**
     * @param list<array{ticket_type_id:int,quantity:int}> $tickets
     * @param array<int,array<string,mixed>> $typeMap
     */
    public static function persistForRsvp(Database $db, int $rsvpId, array $tickets, array $typeMap): void
    {
        if ($rsvpId <= 0 || $tickets === [] || !$db->hasColumn('rsvps', 'ticket_selection_json')) {
            return;
        }

        $stored = [];
        foreach ($tickets as $t) {
            $typeId = (int) ($t['ticket_type_id'] ?? 0);
            $qty = (int) ($t['quantity'] ?? 0);
            if ($typeId <= 0 || $qty <= 0 || !isset($typeMap[$typeId])) {
                continue;
            }
            $row = $typeMap[$typeId];
            $name = Utilities::decodeHtmlEntities(trim((string) ($row['name'] ?? '')));
            $stored[] = [
                'ticket_type_id' => $typeId,
                'quantity' => $qty,
                'name' => $name !== '' ? $name : 'Ticket',
                'price' => (float) ($row['price'] ?? 0),
            ];
        }
        if ($stored === []) {
            return;
        }

        $db->update('rsvps', $rsvpId, [
            'ticket_selection_json' => json_encode($stored),
        ]);
    }

    /**
     * @param mixed $json
     * @return list<array{ticket_type_id?:int,quantity?:int,name?:string,price?:float}>
     */
    public static function decodeStoredSelection($json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Human-readable summary for admin lists.
     *
     * @param mixed $json
     */
    public static function formatSelectionSummary($json): string
    {
        $rows = self::decodeStoredSelection($json);
        if ($rows === []) {
            return '';
        }
        $parts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? 'Ticket'));
            $qty = (int) ($row['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $parts[] = $name . ' ×' . $qty;
        }

        return implode(', ', $parts);
    }

    /**
     * Resolve org timezone for an event row.
     *
     * @param array<string,mixed> $event
     */
    public static function orgTimezoneForEvent(Database $db, array $event): string
    {
        $orgTz = OrgTimeZone::FALLBACK_IANA;
        $oid = isset($event['organization_id']) ? (int) $event['organization_id'] : 0;
        if ($oid > 0) {
            $otg = $db->queryOne('SELECT timezone FROM organizations WHERE id = ?', [$oid]);
            $orgTz = OrgTimeZone::resolve(is_array($otg) ? ($otg['timezone'] ?? null) : null);
        }

        return $orgTz;
    }
}
