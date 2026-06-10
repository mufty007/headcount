<?php

namespace Headcount\Helpers;

/**
 * Load and replace event_ticket_types (admin + API).
 */
class EventTicketTypesPersistence
{
    /**
     * Normalize datetime from admin (datetime-local or SQL string) for MySQL DATETIME.
     */
    public static function normalizeTicketDatetimeForDb(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $s = str_replace('T', ' ', $s);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) {
            $s .= ':00';
        }
        $ts = strtotime($s);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /**
     * Value for HTML datetime-local inputs.
     */
    public static function formatForDatetimeLocalInput(?string $dbDatetime): string
    {
        if ($dbDatetime === null || $dbDatetime === '') {
            return '';
        }
        $s = str_replace(' ', 'T', trim($dbDatetime));

        return strlen($s) >= 16 ? substr($s, 0, 16) : $s;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function loadTicketTypesForEvent(Database $db, int $eventId): array
    {
        if ($eventId <= 0) {
            return [];
        }
        try {
            $ttExtra = $db->hasColumn('event_ticket_types', 'sale_starts_at')
                ? ', sale_starts_at, sale_ends_at, package_group'
                : '';
            $rows = $db->query(
                "SELECT id, event_id, name, price, quantity_limit, sort_order{$ttExtra}
                 FROM event_ticket_types
                 WHERE event_id = :event_id
                 ORDER BY sort_order ASC, id ASC",
                ['event_id' => $eventId]
            );

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            error_log('EventTicketTypesPersistence load: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Parse ticket_types from POST (array) or JSON body (string).
     *
     * @return list<array<string,mixed>>
     */
    public static function parseTicketTypesFromRequest(array $request): array
    {
        $raw = $request['ticket_types'] ?? null;
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'name' => $row['name'] ?? '',
                'price' => $row['price'] ?? 0,
                'quantity_limit' => $row['quantity_limit'] ?? '',
                'sort_order' => $row['sort_order'] ?? null,
                'sale_starts_at' => $row['sale_starts_at'] ?? '',
                'sale_ends_at' => $row['sale_ends_at'] ?? '',
                'package_group' => $row['package_group'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Delete all ticket types for an event and insert the given rows (replace-all).
     *
     * @param list<array<string,mixed>> $ticketTypes
     */
    public static function replaceTicketTypesForEvent(Database $db, int $eventId, array $ticketTypes): void
    {
        if ($eventId <= 0) {
            return;
        }
        try {
            $db->execute('DELETE FROM event_ticket_types WHERE event_id = :event_id', ['event_id' => $eventId]);
        } catch (\Throwable $e) {
            error_log('EventTicketTypesPersistence delete: ' . $e->getMessage());

            return;
        }

        $hasSale = $db->hasColumn('event_ticket_types', 'sale_starts_at');
        $idx = 0;
        foreach ($ticketTypes as $tt) {
            if (!is_array($tt)) {
                continue;
            }
            $name = isset($tt['name']) ? trim((string) $tt['name']) : '';
            if ($name === '') {
                continue;
            }
            $price = isset($tt['price']) ? (float) $tt['price'] : 0.0;
            if ($price < 0) {
                $price = 0.0;
            }
            $limit = isset($tt['quantity_limit']) && $tt['quantity_limit'] !== '' && $tt['quantity_limit'] !== null
                ? (int) $tt['quantity_limit']
                : null;
            $sort = isset($tt['sort_order']) && $tt['sort_order'] !== '' && $tt['sort_order'] !== null
                ? (int) $tt['sort_order']
                : $idx;
            $insert = [
                'event_id' => $eventId,
                'name' => substr($name, 0, 255),
                'price' => $price,
                'quantity_limit' => $limit,
                'sort_order' => $sort,
            ];
            if ($hasSale) {
                $insert['sale_starts_at'] = self::normalizeTicketDatetimeForDb($tt['sale_starts_at'] ?? null);
                $insert['sale_ends_at'] = self::normalizeTicketDatetimeForDb($tt['sale_ends_at'] ?? null);
                $pg = isset($tt['package_group']) ? trim((string) $tt['package_group']) : '';
                $insert['package_group'] = $pg !== '' ? substr($pg, 0, 64) : null;
            }
            try {
                $db->insert('event_ticket_types', $insert);
            } catch (\Throwable $e) {
                error_log('EventTicketTypesPersistence insert: ' . $e->getMessage());
            }
            $idx++;
        }
    }
}
