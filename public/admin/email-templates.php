<?php
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

AuthMiddleware::requireCan('campaigns.send');
$organizationId = AuthMiddleware::getOrganizationId();

require_once __DIR__ . '/includes/layout-vars.php';
if (!empty($_GET['tab']) && in_array($_GET['tab'], ['campaign', 'automation', 'log'], true)) {
    $adminRouter = rtrim($adminBase, '/') . '/index.php';
    $dest = $adminRouter . '?page=email-campaigns&tab=' . urlencode($_GET['tab']);
    if (!empty($_GET['campaign'])) {
        $dest .= '&campaign=' . (int) $_GET['campaign'];
    }
    header('Location: ' . $dest, true, 302);
    exit;
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

// Get CSRF token
$csrfToken = CsrfMiddleware::getToken();

// Get all email templates
$templates = $db->query("SELECT * FROM email_templates WHERE organization_id = ? OR organization_id IS NULL ORDER BY template_type, id", [$organizationId]);

// If no custom templates exist, create defaults
if (empty($templates)) {
    $defaultTemplates = [
        [
            'template_type' => 'announcement',
            'subject' => 'New Event: {event_name}',
            'body_html' => '<h2>You\'re Invited!</h2><p>Hi {first_name},</p><p>We\'re excited to invite you to <strong>{event_name}</strong>!</p><p><strong>Date:</strong> {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {location}</p><p>{event_description}</p><p><a href="{rsvp_link}" style="background-color: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">RSVP Now</a></p><p>We hope to see you there!</p>',
            'is_default' => true
        ],
        [
            'template_type' => 'reminder_1week',
            'subject' => 'Reminder: {event_name} is Next Week',
            'body_html' => '<h2>Don\'t Forget!</h2><p>Hi {first_name},</p><p>This is a friendly reminder that <strong>{event_name}</strong> is coming up next week!</p><p><strong>Date:</strong> {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {location}</p><p>We\'re looking forward to seeing you there!</p>',
            'is_default' => true
        ],
        [
            'template_type' => 'reminder_1day',
            'subject' => 'Tomorrow: {event_name}',
            'body_html' => '<h2>See You Tomorrow!</h2><p>Hi {first_name},</p><p><strong>{event_name}</strong> is tomorrow!</p><p><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {location}</p><p>Don\'t forget to join us!</p>',
            'is_default' => true
        ],
        [
            'template_type' => 'confirmation',
            'subject' => 'RSVP Confirmed: {event_name}',
            'body_html' => '<h2>You\'re Registered!</h2><p>Hi {first_name},</p><p>Your RSVP for <strong>{event_name}</strong> has been confirmed!</p><p><strong>Date:</strong> {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {location}</p><p>We can\'t wait to see you there!</p>',
            'is_default' => true
        ],
        [
            'template_type' => 'receipt',
            'subject' => 'Receipt for {event_name}',
            'body_html' => '<h2>Payment Received</h2><p>Hi {first_name},</p><p>Thank you for your payment for <strong>{event_name}</strong>!</p><p><strong>Amount Paid:</strong> ${amount}<br><strong>Payment ID:</strong> {payment_id}<br><strong>Date:</strong> {payment_date}</p><p>Your registration is confirmed. We look forward to seeing you at the event!</p>',
            'is_default' => true
        ],
        [
            'template_type' => 'follow_up',
            'subject' => 'Thank you for attending {event_name}',
            'body_html' => '<h2>Thank You!</h2><p>Hi {first_name},</p><p>Thank you so much for joining us at <strong>{event_name}</strong>!</p><p>We hope you had a great time. We would love to see you again at our future events.</p><p><a href="{event_link}" style="background-color: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">View More Events</a></p><p>Best regards,<br>{organization_name}</p>',
            'is_default' => true
        ],
        [
            'template_type' => 'event_feedback',
            'subject' => 'How was {event_name}?',
            'body_html' => '<h2>We value your feedback</h2><p>Hi {first_name},</p><p>Thank you for attending <strong>{event_name}</strong> on {event_date}! Your feedback helps us improve future events.</p><p><a href="{feedback_link}" style="background-color: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Share Feedback</a></p><p>It only takes a minute.</p><p>Best regards,<br>{organization_name}</p>',
            'is_default' => true
        ],
        [
            'template_type' => 'schedule_change',
            'subject' => 'Updated: {event_name}',
            'body_html' => '<h2>Schedule update</h2><p>Hi {first_name},</p><p>There has been a change to <strong>{event_name}</strong>:</p>{change_summary}<p><strong>Current details</strong><br>Date: {event_date}<br>Time: {event_time}<br>Location: {event_location}</p><p><a href="{event_link}" style="background-color: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">View details</a></p><p>Best regards,<br>{organization_name}</p>',
            'is_default' => true
        ]
    ];

    foreach ($defaultTemplates as $template) {
        $db->insert('email_templates', [
            'organization_id' => $organizationId,
            'template_type' => $template['template_type'],
            'subject' => $template['subject'],
            'body_html' => $template['body_html'],
            'is_default' => $template['is_default']
        ]);
    }

    // Reload templates
    $templates = $db->query("SELECT * FROM email_templates WHERE organization_id = ? ORDER BY template_type, id", [$organizationId]);
}

$apiBaseUrl = $basePath . '/public/api';
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

$typeLabels = [
    'announcement'    => 'Announcement',
    'reminder_1week'  => 'Reminder · 1 week',
    'reminder_1day'   => 'Reminder · 1 day',
    'reminder_2hours' => 'Reminder · 2 hours',
    'confirmation'    => 'RSVP confirmation',
    'receipt'         => 'Receipt',
    'cancellation'    => 'Cancellation',
    'follow_up'       => 'Follow-up',
    'event_feedback'  => 'Event feedback request',
    'schedule_change' => 'Schedule change notice',
    'custom'          => 'Custom',
];

// Lightweight, reactive list for the library (full body is fetched on demand).
$templatesForJs = array_values(array_map(static function ($t) use ($typeLabels) {
    $type = $t['template_type'] ?? 'custom';
    return [
        'id'            => (int) $t['id'],
        'subject'       => (string) ($t['subject'] !== '' ? $t['subject'] : 'Untitled'),
        'template_type' => $type,
        'type_label'    => $typeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type)),
        'is_default'    => (bool) ($t['is_default'] ?? false),
    ];
}, $templates));

$pageTitle = 'Email Templates';
$currentPage = 'email-templates';
include __DIR__ . '/includes/header.php';
?>

<div x-data="emailTemplatesApp()" x-init="init()" class="animate-fade-in"
     @keydown.window.ctrl.s.prevent="hasForm && save()"
     @keydown.window.meta.s.prevent="hasForm && save()">
    <?php
    $pageHeaderTitle = 'Email templates';
    $pageHeaderSubtitle = 'Create and edit reusable message designs for events and campaigns.';
    ob_start();
    ?>
    <button type="button" @click="newTemplate()" class="page-header-btn-primary whitespace-nowrap flex-shrink-0">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        <span>New template</span>
    </button>
    <?php $pageHeaderActions = ob_get_clean(); require __DIR__ . '/components/page-header.php'; ?>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[340px_minmax(0,1fr)] lg:items-start">

        <!-- ============== LEFT: Library ============== -->
        <aside class="flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] lg:sticky lg:top-[84px] lg:max-h-[calc(100vh-110px)]">
            <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-100 px-4 py-3.5 dark:border-gray-800">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white/90">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </span>
                    Library
                </h2>
                <span class="rounded-full border border-gray-200 bg-white px-2.5 py-0.5 text-theme-xs font-semibold text-gray-600 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300" x-text="templates.length + ' total'"></span>
            </div>

            <!-- Search -->
            <div class="shrink-0 px-3 pb-3 pt-3">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="search" x-model="search" placeholder="Search templates…"
                           class="w-full rounded-lg border border-gray-200 bg-gray-50 py-2 pl-9 pr-8 text-sm text-gray-800 outline-none transition focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                    <button type="button" x-show="search" @click="search=''" class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-gray-400 hover:text-gray-600 dark:text-gray-300" aria-label="Clear search">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <!-- List -->
            <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                <template x-for="t in filtered" :key="t.id">
                    <div class="group relative">
                        <button type="button" @click="select(t.id)"
                                :class="form.id == t.id ? 'border-brand-200 bg-brand-50 dark:border-brand-500/40 dark:bg-brand-500/10' : 'border-transparent hover:bg-gray-50 dark:hover:bg-white/[0.04]'"
                                class="flex w-full items-start gap-3 rounded-xl border px-3 py-2.5 text-left transition">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400"
                                  :class="form.id == t.id ? 'bg-brand-100 text-brand-600 dark:bg-brand-500/20 dark:text-brand-300' : 'bg-gray-100 dark:bg-white/[0.05]'">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </span>
                            <span class="min-w-0 flex-1 pr-10">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-white/90" x-text="t.subject"></span>
                                <span class="mt-1 inline-flex items-center gap-1.5">
                                    <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.06] dark:text-gray-400" x-text="t.type_label"></span>
                                    <span x-show="t.is_default" class="inline-block rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:bg-amber-500/15 dark:text-amber-400">Default</span>
                                </span>
                            </span>
                        </button>
                        <!-- Row actions -->
                        <div class="absolute right-2 top-2 flex items-center gap-0.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                            <button type="button" @click.stop="duplicate(t.id)" class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-brand-600 dark:hover:bg-white/[0.06] dark:bg-gray-800" aria-label="Duplicate template" title="Duplicate">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                            <button type="button" x-show="!t.is_default" @click.stop="remove(t.id, t.subject)" class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-rose-600 dark:hover:bg-white/[0.06] dark:bg-gray-800" aria-label="Delete template" title="Delete">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Empty / no-results -->
                <div x-show="filtered.length === 0" class="px-3 py-10 text-center">
                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="search ? 'No templates match “' + search + '”.' : 'No templates yet.'"></p>
                    <button type="button" x-show="!search" @click="newTemplate()" class="mt-3 text-sm font-semibold text-brand-600 hover:text-brand-700">Create your first template</button>
                </div>
            </div>
        </aside>

        <!-- ============== RIGHT: Workspace ============== -->
        <section class="flex min-h-[600px] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">

            <!-- Workspace header -->
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800 sm:px-6">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <h2 class="flex items-center gap-2 text-base font-bold tracking-tight text-gray-900 dark:text-white/90">
                            <span x-text="headerTitle"></span>
                            <span x-show="dirty" x-cloak class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-600 dark:bg-amber-500/15 dark:text-amber-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Unsaved
                            </span>
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="hasForm ? (mode === 'preview' ? 'Preview with sample data' : 'Edit subject and body') : 'Select or create a template'"></p>
                    </div>
                </div>

                <!-- Edit / Preview segmented control -->
                <div x-show="hasForm" x-cloak class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-white/[0.03]">
                    <button type="button" @click="backToEdit()"
                            :class="mode === 'edit' ? 'bg-white text-gray-900 shadow-sm dark:bg-white/[0.08] dark:text-white' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold transition">Edit</button>
                    <button type="button" @click="showPreview()"
                            :class="mode === 'preview' ? 'bg-white text-gray-900 shadow-sm dark:bg-white/[0.08] dark:text-white' : 'text-gray-500 hover:text-gray-700'"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold transition">Preview</button>
                </div>
            </div>

            <!-- Empty state -->
            <div x-show="!hasForm" class="flex flex-1 flex-col items-center justify-center p-8 text-center sm:p-12">
                <div class="max-w-md rounded-2xl border border-dashed border-gray-200 bg-gray-50/80 px-8 py-10 dark:border-gray-700 dark:bg-white/[0.02]">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-white/[0.05] dark:ring-gray-700">
                        <svg class="h-8 w-8 text-brand-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white/90">Design a template</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-gray-500 dark:text-gray-400">Pick one from the library on the left, or create a new layout for campaigns and events.</p>
                    <button type="button" @click="newTemplate()" class="page-header-btn-primary mx-auto mt-6">New template</button>
                </div>
            </div>

            <!-- Edit pane -->
            <div x-show="hasForm && mode === 'edit'" x-cloak class="flex min-h-0 flex-1 flex-col">
                <div class="min-h-0 flex-1 space-y-6 overflow-y-auto p-5 sm:p-8">
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Category</label>
                            <div class="relative">
                                <select x-model="form.template_type" @change="dirty = true"
                                        class="w-full appearance-none rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                    <template x-for="opt in typeOptions" :key="opt.value">
                                        <option :value="opt.value" x-text="opt.label"></option>
                                    </template>
                                </select>
                                <div class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-gray-400">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Subject line</label>
                            <input type="text" x-model="form.subject" @input="dirty = true" placeholder="Enter an engaging subject…"
                                   class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-800 outline-none transition placeholder:text-gray-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Message content</label>
                            <!-- Insert variable dropdown -->
                            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                <button type="button" @click="open = !open"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-brand-300 hover:text-brand-600 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                    Insert variable
                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <div x-show="open" x-cloak x-transition.origin.top.right
                                     class="absolute right-0 z-30 mt-2 max-h-80 w-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-2 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
                                    <template x-for="group in variableGroups" :key="group.label">
                                        <div class="mb-1">
                                            <p class="px-2 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wider text-gray-400" x-text="group.label"></p>
                                            <template x-for="v in group.items" :key="v">
                                                <button type="button" @click="insertVariable(v); open = false"
                                                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-brand-50 hover:text-brand-600 dark:text-gray-300 dark:hover:bg-white/[0.05] dark:bg-gray-800">
                                                    <span class="font-mono text-brand-400">{</span><span x-text="v"></span><span class="font-mono text-brand-400">}</span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-white/[0.02]">
                            <textarea x-model="form.body_html" id="template-body-textarea" rows="14" class="wysiwyg-editor w-full border-none px-4 py-4 text-sm outline-none focus:ring-0" placeholder="Start writing your template…"></textarea>
                        </div>
                        <p class="text-xs text-gray-400">Tip: press <kbd class="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-sans text-[10px] dark:border-gray-700 dark:bg-white/[0.05]">Ctrl/⌘ + S</kbd> to save.</p>
                    </div>
                </div>
            </div>

            <!-- Preview pane -->
            <div x-show="hasForm && mode === 'preview'" x-cloak class="flex min-h-0 flex-1 flex-col bg-gray-50/80 dark:bg-black/20">
                <div class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-gray-800">
                    <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Rendered with sample data</span>
                    <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-white/[0.03]">
                        <button type="button" @click="previewDevice = 'desktop'" :class="previewDevice === 'desktop' ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' : 'text-gray-400 hover:text-gray-600'" class="rounded-md p-1.5 transition" aria-label="Desktop preview" title="Desktop">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </button>
                        <button type="button" @click="previewDevice = 'mobile'" :class="previewDevice === 'mobile' ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' : 'text-gray-400 hover:text-gray-600'" class="rounded-md p-1.5 transition" aria-label="Mobile preview" title="Mobile">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a1 1 0 001-1V4a1 1 0 00-1-1H8a1 1 0 00-1 1v16a1 1 0 001 1z"/></svg>
                        </button>
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-6">
                    <div class="mx-auto transition-all duration-300" :class="previewDevice === 'mobile' ? 'max-w-[380px]' : 'max-w-2xl'">
                        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-700 dark:bg-gray-800">
                            <div class="border-b border-gray-100 bg-gray-50 px-5 py-3 dark:border-gray-800 dark:bg-white/[0.03]">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Subject</p>
                                <h4 class="mt-0.5 truncate text-sm font-bold text-gray-900 dark:text-white/90" x-text="previewSubject"></h4>
                            </div>
                            <div class="px-6 py-6 text-sm leading-relaxed text-gray-800 [&_a]:text-brand-600 [&_a]:underline [&_h1]:mb-2 [&_h1]:text-xl [&_h1]:font-bold [&_h2]:mb-2 [&_h2]:text-lg [&_h2]:font-semibold [&_p]:my-2 dark:text-gray-100" x-html="previewBody"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sticky action footer -->
            <div x-show="hasForm" x-cloak class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-white px-5 py-3.5 dark:border-gray-800 dark:bg-white/[0.02] sm:px-6">
                <button type="button" @click="sendTest()" :disabled="sendingTest"
                        class="page-header-btn-secondary inline-flex items-center gap-2 disabled:opacity-60">
                    <svg x-show="!sendingTest" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <svg x-show="sendingTest" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span x-text="sendingTest ? 'Sending…' : 'Send test to me'"></span>
                </button>
                <div class="flex items-center gap-2">
                    <button type="button" @click="discard()" class="page-header-btn-secondary">Discard</button>
                    <button type="button" @click="save()" :disabled="saving"
                            class="page-header-btn-primary inline-flex items-center gap-2 disabled:opacity-60">
                        <svg x-show="!saving" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                        <svg x-show="saving" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="saving ? 'Saving…' : 'Save template'"></span>
                    </button>
                </div>
            </div>
        </section>
    </div>

    <!-- Toasts -->
    <div class="pointer-events-none fixed bottom-5 right-5 z-[100000] flex flex-col gap-2" x-cloak>
        <template x-for="t in toasts" :key="t.id">
            <div x-transition.opacity.duration.200ms
                 class="pointer-events-auto flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-medium shadow-theme-lg"
                 :class="t.type === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/15 dark:text-rose-300' : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/15 dark:text-emerald-300'">
                <svg x-show="t.type !== 'error'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <svg x-show="t.type === 'error'" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span x-text="t.message"></span>
            </div>
        </template>
    </div>
</div>

<script>
(function() {
'use strict';
const API_BASE_URL = '<?= e($apiBaseUrl) ?>/email-templates.php';
const csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';
const INITIAL_TEMPLATES = <?= json_encode($templatesForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const TYPE_OPTIONS = [
    { value: 'announcement',    label: 'Announcement' },
    { value: 'reminder_1week',  label: 'Reminder (1 week)' },
    { value: 'reminder_1day',   label: 'Reminder (1 day)' },
    { value: 'reminder_2hours', label: 'Reminder (2 hours)' },
    { value: 'confirmation',    label: 'RSVP confirmation' },
    { value: 'receipt',         label: 'Receipt' },
    { value: 'follow_up',       label: 'Follow-up' },
    { value: 'event_feedback',  label: 'Event feedback request' },
    { value: 'schedule_change', label: 'Schedule change notice' },
    { value: 'custom',          label: 'Custom' }
];

const SAMPLE_DATA = {
    first_name: 'John', last_name: 'Smith', email: 'john@example.com',
    event_name: 'Friday Night Service', event_date: 'December 15, 2024',
    event_time: '7:00 PM', location: 'Main Hall',
    event_description: 'Join us for an evening of worship and fellowship.',
    rsvp_link: '#rsvp', event_link: '#event', unsubscribe_link: '#unsubscribe',
    amount: '25.00', payment_id: 'pi_123456789', payment_date: 'December 10, 2024',
    organization_name: 'Headcount'
};

function emailTemplatesApp() {
    return {
        templates: INITIAL_TEMPLATES,
        typeOptions: TYPE_OPTIONS,
        variableGroups: [
            { label: 'Attendee', items: ['first_name', 'last_name', 'email'] },
            { label: 'Event', items: ['event_name', 'event_date', 'event_time', 'location', 'event_description'] },
            { label: 'Links', items: ['rsvp_link', 'event_link', 'unsubscribe_link'] },
            { label: 'Payment', items: ['amount', 'payment_id', 'payment_date'] },
            { label: 'Org', items: ['organization_name'] }
        ],
        search: '',
        hasForm: false,
        mode: 'edit',
        saving: false,
        sendingTest: false,
        dirty: false,
        _suppressDirty: false,
        previewDevice: 'desktop',
        previewSubject: '',
        previewBody: '',
        toasts: [],
        _toastSeq: 0,
        form: { id: null, template_type: 'custom', subject: '', body_html: '' },

        init() {
            const onChange = () => { if (this.hasForm && !this._suppressDirty) this.dirty = true; };
            this.$watch('form.subject', onChange);
            this.$watch('form.template_type', onChange);
            this.$watch('form.body_html', onChange);
            window.addEventListener('beforeunload', (e) => {
                if (this.dirty) { e.preventDefault(); e.returnValue = ''; }
            });
        },

        get filtered() {
            const q = this.search.trim().toLowerCase();
            if (!q) return this.templates;
            return this.templates.filter(t =>
                (t.subject || '').toLowerCase().includes(q) ||
                (t.type_label || '').toLowerCase().includes(q)
            );
        },

        get headerTitle() {
            if (this.mode === 'preview') return 'Preview';
            return this.form.id ? 'Edit template' : 'New template';
        },

        labelFor(type) {
            const o = this.typeOptions.find(x => x.value === type);
            return o ? o.label : type;
        },

        _quill() {
            const ta = document.getElementById('template-body-textarea');
            return (window.__quillInstances && ta) ? window.__quillInstances.get(ta) : null;
        },

        _setBody(html) {
            this._suppressDirty = true;
            this.form.body_html = html || '';
            this.$nextTick(() => {
                const q = this._quill();
                if (q) q.root.innerHTML = html || '';
                const ta = document.getElementById('template-body-textarea');
                if (ta) ta.value = html || '';
                setTimeout(() => { this._suppressDirty = false; this.dirty = false; }, 60);
            });
        },

        _syncFromQuill() {
            const q = this._quill();
            if (q) this.form.body_html = q.root.innerHTML;
        },

        newTemplate() {
            this.hasForm = true;
            this.mode = 'edit';
            this.form = { id: null, template_type: 'custom', subject: '', body_html: '' };
            this._setBody('<h2>Email title</h2>\n<p>Hi {first_name},</p>\n<p>Your content here…</p>');
        },

        async select(id) {
            if (!id) return;
            try {
                const r = await fetch(API_BASE_URL + '?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
                const d = await r.json();
                if (d.success) {
                    this.hasForm = true;
                    this.mode = 'edit';
                    this.form = {
                        id: d.template.id,
                        template_type: d.template.template_type,
                        subject: d.template.subject || '',
                        body_html: d.template.body_html || ''
                    };
                    this._setBody(this.form.body_html);
                } else {
                    this.toast(d.message || 'Failed to load template', 'error');
                }
            } catch (e) {
                this.toast('Failed to load template', 'error');
            }
        },

        async save() {
            if (!this.hasForm || this.saving) return;
            this._syncFromQuill();
            if (!this.form.subject.trim()) { this.toast('Add a subject before saving.', 'error'); return; }
            if (this.mode === 'preview') this.mode = 'edit';
            this.saving = true;
            const action = this.form.id ? 'update' : 'create';
            try {
                const r = await fetch(API_BASE_URL, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ ...this.form, action, csrf_token: csrfToken })
                });
                const d = await r.json();
                if (d.success) {
                    this.dirty = false;
                    if (action === 'update') {
                        const it = this.templates.find(t => t.id == this.form.id);
                        if (it) {
                            it.subject = this.form.subject || 'Untitled';
                            it.template_type = this.form.template_type;
                            it.type_label = this.labelFor(this.form.template_type);
                        }
                        this.toast('Template saved.', 'success');
                    } else {
                        this.toast('Template created.', 'success');
                        setTimeout(() => window.location.reload(), 700);
                    }
                } else {
                    this.toast(d.message || 'Failed to save template', 'error');
                }
            } catch (e) {
                this.toast('Something went wrong while saving.', 'error');
            } finally {
                this.saving = false;
            }
        },

        async remove(id, label) {
            const ok = await confirmAction({
                title: 'Delete template',
                message: 'Delete "' + String(label).replace(/"/g, '\\"') + '"? This cannot be undone.',
                type: 'danger', okText: 'Delete', cancelText: 'Cancel'
            });
            if (!ok) return;
            try {
                const r = await fetch(API_BASE_URL, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ id, action: 'delete', csrf_token: csrfToken })
                });
                const d = await r.json();
                if (d.success) {
                    this.templates = this.templates.filter(t => t.id != id);
                    if (this.form.id == id) this.discard(true);
                    this.toast('Template deleted.', 'success');
                } else {
                    this.toast(d.message || 'Failed to delete template', 'error');
                }
            } catch (e) {
                this.toast('Failed to delete template', 'error');
            }
        },

        async duplicate(id) {
            try {
                const r = await fetch(API_BASE_URL, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ id, action: 'duplicate', csrf_token: csrfToken })
                });
                const d = await r.json();
                if (d.success) {
                    this.toast('Template duplicated.', 'success');
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    this.toast(d.message || 'Failed to duplicate template', 'error');
                }
            } catch (e) {
                this.toast('Failed to duplicate template', 'error');
            }
        },

        insertVariable(variable) {
            const ta = document.getElementById('template-body-textarea');
            if (!ta) return;
            const q = this._quill();
            const insertion = '{' + variable + '}';
            if (q) {
                const range = q.getSelection(true) || { index: q.getLength() };
                q.insertText(range.index, insertion);
                q.setSelection(range.index + insertion.length);
                this.form.body_html = q.root.innerHTML;
            } else {
                const start = ta.selectionStart;
                const text = ta.value;
                this.form.body_html = text.substring(0, start) + insertion + text.substring(start);
                setTimeout(() => { ta.focus(); ta.setSelectionRange(start + insertion.length, start + insertion.length); }, 0);
            }
            this.dirty = true;
        },

        showPreview() {
            this._syncFromQuill();
            if (!this.form.subject || !this.form.body_html) {
                this.toast('Add a subject and body to preview.', 'error');
                return;
            }
            let subject = this.form.subject;
            let body = this.form.body_html;
            Object.keys(SAMPLE_DATA).forEach(key => {
                const re = new RegExp('{' + key + '}', 'g');
                subject = subject.replace(re, SAMPLE_DATA[key]);
                body = body.replace(re, SAMPLE_DATA[key]);
            });
            this.previewSubject = subject;
            this.previewBody = body;
            this.mode = 'preview';
        },

        backToEdit() {
            this.mode = 'edit';
            this._setBody(this.form.body_html);
        },

        async sendTest() {
            if (this.sendingTest) return;
            this._syncFromQuill();
            if (!this.form.subject || !this.form.body_html) {
                this.toast('Add a subject and body first.', 'error');
                return;
            }
            this.sendingTest = true;
            try {
                const r = await fetch(API_BASE_URL, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'send_test',
                        subject: this.form.subject,
                        body_html: this.form.body_html,
                        id: this.form.id,
                        csrf_token: csrfToken
                    })
                });
                const d = await r.json();
                this.toast(d.message || (d.success ? 'Test email sent.' : 'Could not send test.'), d.success ? 'success' : 'error');
            } catch (e) {
                this.toast('Could not send the test email.', 'error');
            } finally {
                this.sendingTest = false;
            }
        },

        discard(force) {
            if (!force && this.dirty) {
                if (!window.confirm('Discard unsaved changes?')) return;
            }
            this.hasForm = false;
            this.mode = 'edit';
            this.dirty = false;
            this.form = { id: null, template_type: 'custom', subject: '', body_html: '' };
        },

        toast(message, type) {
            const id = ++this._toastSeq;
            this.toasts.push({ id, message, type: type || 'success' });
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 4200);
        }
    };
}
window.emailTemplatesApp = emailTemplatesApp;
})();
</script>

<!-- Quill WYSIWYG (template body) -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="<?= e($basePath) ?>/public/admin/js/quill-rich-toolbar.js"></script>

<style>
    [x-cloak] { display: none !important; }

    /* Quill (template editor) */
    .ql-toolbar.ql-snow {
        border: none !important;
        border-bottom: 1px solid #f1f5f9 !important;
        background: #f8fafc !important;
        padding: 12px 16px !important;
    }
    .ql-container.ql-snow { border: none !important; }
    .ql-editor {
        padding: 24px !important;
        font-family: 'Outfit', ui-sans-serif, system-ui, sans-serif !important;
        font-size: 15px !important;
        line-height: 1.6 !important;
        color: #1e293b !important;
        min-height: 320px !important;
    }
    .ql-editor h2 { font-weight: 800 !important; color: #0f172a !important; margin-bottom: 1rem !important; }
    .ql-editor p { margin-bottom: 1rem !important; }
    .dark .ql-toolbar.ql-snow { background: rgba(255,255,255,0.03) !important; border-bottom-color: #1f2937 !important; }
    .dark .ql-editor { color: #e2e8f0 !important; }

    /* Scrollbar hide */
    .scrollbar-hide::-webkit-scrollbar { display: none; }
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

    .ql-hc-video,
    .ql-hc-emoji { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 28px !important; padding: 3px 5px !important; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
