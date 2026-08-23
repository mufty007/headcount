<?php

namespace Headcount\Helpers;

/**
 * Permissions catalog — the single source of truth for granular capabilities.
 *
 * Each capability has a stable key (e.g. "events.manage"), a human label, a
 * group it belongs to (for the Settings UI), and a default grant per role.
 *
 * Resolution order (implemented in AuthMiddleware::can()):
 *   1. super-admin           -> always allowed
 *   2. per-user override      -> user_permissions row
 *   3. per-role override      -> role_permissions row
 *   4. role default           -> Permissions::roleDefault() (this file)
 *
 * The "member" role never has admin-area capabilities.
 */
class Permissions
{
    /**
     * Grouped capability catalog.
     * group label => [ key => [label, admin, coordinator] ]
     */
    private const CATALOG = [
        'Events & check-in' => [
            'events.manage'      => ['label' => 'Manage events (create, edit, delete)', 'admin' => true,  'coordinator' => false],
            'events.request'     => ['label' => 'Submit event requests',                 'admin' => true,  'coordinator' => true],
            'events.approve_requests' => ['label' => 'Approve event requests',            'admin' => true,  'coordinator' => false],
            'checkin.run'        => ['label' => 'Run event check-in',                    'admin' => true,  'coordinator' => true],
            'attendance.correct' => ['label' => 'Correct attendance after events',       'admin' => true,  'coordinator' => false],
            'checklists.view'    => ['label' => 'View event checklists & My Tasks',      'admin' => true,  'coordinator' => true],
            'checklists.manage_templates' => ['label' => 'Manage checklist templates in Settings', 'admin' => true, 'coordinator' => false],
        ],
        'Members & families' => [
            'members.manage' => ['label' => 'Manage members & families', 'admin' => true, 'coordinator' => false],
            'members.import' => ['label' => 'Import members',            'admin' => true, 'coordinator' => false],
        ],
        'Payments & refunds' => [
            'refunds.process' => ['label' => 'Process refunds & refund requests', 'admin' => true, 'coordinator' => true],
            'payments.manage' => ['label' => 'View & manage payments / transfers', 'admin' => true, 'coordinator' => false],
        ],
        'Programs, facilities & comms' => [
            'programs.manage'   => ['label' => 'Manage programs',                  'admin' => true, 'coordinator' => false],
            'facilities.manage' => ['label' => 'Manage facilities & bookings',     'admin' => true, 'coordinator' => false],
            'campaigns.send'    => ['label' => 'Send email campaigns & templates', 'admin' => true, 'coordinator' => false],
            'reports.view'      => ['label' => 'View reports',                     'admin' => true, 'coordinator' => false],
            'settings.access'   => ['label' => 'Access organization settings',     'admin' => true, 'coordinator' => false],
        ],
    ];

    /**
     * Roles that participate in the permission system (excludes "member").
     *
     * @return string[]
     */
    public static function roles(): array
    {
        return ['admin', 'coordinator'];
    }

    /**
     * Flat list of every capability key.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::CATALOG as $group) {
            foreach ($group as $key => $_meta) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Flat map of key => label.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach ($group as $key => $meta) {
                $out[$key] = $meta['label'];
            }
        }
        return $out;
    }

    /**
     * Grouped catalog for rendering the Settings UI.
     * Returns: [ ['label' => groupLabel, 'permissions' => ['key'=>['label'=>..,'admin'=>bool,'coordinator'=>bool], ...]], ... ]
     *
     * @return array<int,array{label:string,permissions:array}>
     */
    public static function groups(): array
    {
        $out = [];
        foreach (self::CATALOG as $groupLabel => $perms) {
            $out[] = [
                'label'       => $groupLabel,
                'permissions' => $perms,
            ];
        }
        return $out;
    }

    /**
     * Whether a capability key exists in the catalog.
     */
    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Human label for a capability key (falls back to the key).
     */
    public static function label(string $key): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$key])) {
                return $group[$key]['label'];
            }
        }
        return $key;
    }

    /**
     * Default grant for a role + capability when no DB override exists.
     * Unknown roles (e.g. "member") and unknown keys default to false.
     */
    public static function roleDefault(string $role, string $key): bool
    {
        if (!in_array($role, self::roles(), true)) {
            return false;
        }
        foreach (self::CATALOG as $group) {
            if (isset($group[$key])) {
                return (bool) ($group[$key][$role] ?? false);
            }
        }
        return false;
    }

    /**
     * All role defaults as key => bool for a given role (used to seed the UI).
     *
     * @return array<string,bool>
     */
    public static function defaultsForRole(string $role): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::roleDefault($role, $key);
        }
        return $out;
    }
}
