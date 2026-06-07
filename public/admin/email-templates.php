<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

AuthMiddleware::requireAdmin();
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

$config = require __DIR__ . '/../../config/config.php';
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

$pageTitle = 'Email Templates';
$currentPage = 'email-templates';
include __DIR__ . '/includes/header.php';
?>

<div x-data="emailTemplatesApp()" class="animate-fade-in">
    <?php
    $pageHeaderTitle = 'Email templates';
    $pageHeaderSubtitle = 'Create and edit reusable message designs for events and campaigns.';
    ob_start();
    ?>
    <button type="button" @click="openCreateForm()" class="page-header-btn-primary whitespace-nowrap flex-shrink-0">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        <span>New Template</span>
    </button>
    <?php $pageHeaderActions = ob_get_clean(); require __DIR__ . '/components/page-header.php'; ?>

    <div class="mx-auto flex max-w-[1600px] flex-col gap-6 md:gap-8">
        <!-- Row 1: Template library + variables (TailAdmin-style two-column from md) -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-12 md:items-stretch">
            <div class="flex min-h-0 flex-col md:col-span-7">
                <div class="flex max-h-[min(340px,48vh)] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:max-h-[min(420px,52vh)] lg:max-h-[min(480px,56vh)]">
                    <div class="flex shrink-0 items-center justify-between border-b border-gray-100 bg-gray-50/80 px-5 py-4 dark:border-gray-800 dark:bg-white/[0.02]">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white/90">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100 dark:bg-brand-500/15 dark:ring-brand-500/30">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                            </span>
                            Template library
                        </h2>
                        <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-theme-xs font-semibold text-gray-600 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300"><?= count($templates) ?> total</span>
                    </div>
                    <div class="scrollbar-hide min-h-0 flex-1 overflow-y-auto p-3">
                        <?php
                        $typeLabels = [
                            'announcement' => 'Announcements',
                            'reminder_1week' => 'Reminders (1wk)',
                            'reminder_1day' => 'Reminders (1d)',
                            'reminder_2hours' => 'Reminders (2h)',
                            'confirmation' => 'Confirmations',
                            'receipt' => 'Receipts',
                            'cancellation' => 'Cancellations',
                            'follow_up' => 'Follow-ups',
                            'custom' => 'Custom',
                        ];
                        $tableTitle = '';
                        $tableColumns = [
                            ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'class' => 'w-36'],
                            ['key' => 'subject', 'label' => 'Subject', 'type' => 'raw', 'raw_key' => 'subject_html'],
                            ['key' => 'actions', 'label' => '', 'type' => 'actions', 'actions_key' => 'actions_html', 'class' => 'text-right w-12'],
                        ];
                        $tableRows = [];
                        foreach ($templates as $template) {
                            $tid = (int) $template['id'];
                            $typeLabel = $typeLabels[$template['template_type']] ?? ucfirst(str_replace('_', ' ', $template['template_type']));
                            $subjectHtml = '<button type="button" @click="openEditForm(' . $tid . ')" :class="templateForm.id == ' . $tid . ' ? \'font-semibold text-brand-600\' : \'font-medium text-gray-900 dark:text-white/90\'" class="text-left text-theme-sm hover:text-brand-600">' . e($template['subject'] ?: 'Untitled') . '</button>';
                            $actionsHtml = '';
                            if (!$template['is_default']) {
                                $actionsHtml = '<button type="button" @click.stop="deleteTemplate(' . $tid . ', \'' . e($template['template_type']) . '\')" class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-rose-600 dark:hover:bg-white/[0.05]" aria-label="Delete template"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>';
                            }
                            $tableRows[] = [
                                'category' => $typeLabel,
                                'subject_html' => $subjectHtml,
                                'actions_html' => $actionsHtml,
                            ];
                        }
                        $tableEmptyMessage = 'No templates yet.';
                        require __DIR__ . '/components/data-table.php';
                        unset($tableTitle, $tableColumns, $tableRows, $tableEmptyMessage);
                        ?>
                    </div>
                </div>
            </div>

            <!-- Merge variables (row 1, right column from md) -->
            <div class="flex min-h-0 flex-col md:col-span-5">
                <div class="flex h-full max-h-[min(340px,48vh)] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:max-h-none">
                    <div class="shrink-0 border-b border-gray-200 bg-gray-50 px-5 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600 ring-1 ring-violet-100">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                            </span>
                            Merge variables
                        </h3>
                        <p class="mt-1 text-xs text-gray-500">Insert into the message body (click while editing).</p>
                    </div>
                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Attendee</span>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <button type="button" @click="insertVariable('first_name')" class="group flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[10px] font-bold uppercase tracking-tight text-gray-700 transition-all hover:border-brand-200 hover:text-brand-600 hover:shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                    <span class="text-brand-300 group-hover:text-brand-500">{</span>first_name<span class="text-brand-300 group-hover:text-brand-500">}</span>
                                </button>
                                <button type="button" @click="insertVariable('last_name')" class="group flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[10px] font-bold uppercase tracking-tight text-gray-700 transition-all hover:border-brand-200 hover:text-brand-600 hover:shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                    <span class="text-brand-300 group-hover:text-brand-500">{</span>last_name<span class="text-brand-300 group-hover:text-brand-500">}</span>
                                </button>
                            </div>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase text-gray-400 tracking-wider">Event Details</span>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <button type="button" @click="insertVariable('event_name')" class="group flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[10px] font-bold uppercase tracking-tight text-gray-700 transition-all hover:border-brand-200 hover:text-brand-600 hover:shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                    <span class="text-brand-300 group-hover:text-brand-500">{</span>event_name<span class="text-brand-300 group-hover:text-brand-500">}</span>
                                </button>
                                <button type="button" @click="insertVariable('event_date')" class="group flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[10px] font-bold uppercase tracking-tight text-gray-700 transition-all hover:border-brand-200 hover:text-brand-600 hover:shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                    <span class="text-brand-300 group-hover:text-brand-500">{</span>event_date<span class="text-brand-300 group-hover:text-brand-500">}</span>
                                </button>
                                <button type="button" @click="insertVariable('event_time')" class="group flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[10px] font-bold uppercase tracking-tight text-gray-700 transition-all hover:border-brand-200 hover:text-brand-600 hover:shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20">
                                    <span class="text-brand-300 group-hover:text-brand-500">{</span>event_time<span class="text-brand-300 group-hover:text-brand-500">}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Editor / preview -->
        <div class="min-w-0">
                <div class="flex min-h-[480px] flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:min-h-[520px]">
                    <!-- Dynamic Header -->
                    <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-5 py-4 sm:px-8 sm:py-5">
                        <div class="flex items-center gap-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-brand-100 bg-brand-50 text-brand-600 shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </div>
                            <div>
                                <h2 class="font-extrabold text-gray-900 text-lg tracking-tight" x-text="selectedTemplateId === 'preview' ? 'Visual preview' : (templateForm.id ? 'Edit template' : 'New template')"></h2>
                                <p class="text-sm text-gray-500 mt-0.5" x-text="selectedTemplateId === 'preview' ? 'Sample merge data' : 'Edit subject and body'"></p>
                            </div>
                        </div>
                        <button type="button" x-show="templateForm.id || showTemplateForm || selectedTemplateId === 'preview'" @click="cancelForm()" class="page-header-btn-secondary flex items-center gap-2 text-xs font-bold">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            Close Designer
                        </button>
                    </div>

                    <!-- Empty State -->
                    <div x-show="!templateForm.id && !showTemplateForm && selectedTemplateId !== 'preview'" class="flex flex-1 flex-col items-center justify-center p-8 text-center sm:p-12" x-transition>
                        <div class="max-w-md rounded-2xl border border-dashed border-gray-200 bg-gray-50/80 px-8 py-10">
                            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
                                <svg class="h-8 w-8 text-brand-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900">Design a template</h3>
                            <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-gray-500">Pick one from the library on the left, or create a new layout for campaigns and events.</p>
                            <button type="button" @click="openCreateForm()" class="page-header-btn-primary mx-auto mt-6">New template</button>
                        </div>
                    </div>

                    <!-- Form Mode (create or edit) -->
                    <div x-show="selectedTemplateId !== 'preview' && (templateForm.id || showTemplateForm)" class="flex-1 p-10 overflow-y-auto" x-cloak x-transition>
                        <form @submit.prevent="saveTemplate()" class="max-w-4xl mx-auto space-y-8">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div class="space-y-3">
                                    <label class="text-xs font-medium text-gray-600">Template Category</label>
                                    <div class="relative">
                                        <select x-model="templateForm.template_type" class="w-full appearance-none rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-bold text-gray-700 outline-none transition-all focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" required>
                                            <option value="announcement">Announcement</option>
                                            <option value="reminder_1week">Reminder (1 Week)</option>
                                            <option value="reminder_1day">Reminder (1 Day)</option>
                                            <option value="reminder_2hours">Reminder (2 Hours)</option>
                                            <option value="confirmation">RSVP Confirmation</option>
                                            <option value="receipt">Receipt</option>
                                            <option value="follow_up">Follow-up</option>
                                            <option value="custom">Custom Template</option>
                                        </select>
                                        <div class="absolute right-4 top-1/2 -trangray-y-1/2 pointer-events-none text-gray-400">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <label class="text-xs font-medium text-gray-600">Email Subject Line</label>
                                    <input type="text" x-model="templateForm.subject" placeholder="Enter an engaging subject..." class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-bold text-gray-800 outline-none transition-all placeholder:text-gray-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" required>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <label class="text-xs font-medium text-gray-600">Message Content</label>
                                <div class="rounded-2xl border border-gray-200 overflow-hidden bg-white shadow-inner-sm">
                                    <textarea x-model="templateForm.body_html" id="template-body-textarea" rows="12" class="wysiwyg-editor w-full border-none px-4 py-4 text-sm outline-none focus:ring-0" placeholder="Start writing your template..." required></textarea>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 pt-4 flex-wrap">
                                <button type="submit" class="page-header-btn-primary flex-1 min-w-[140px] justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                                    Save template
                                </button>
                                <button type="button" @click="previewCurrent()" class="page-header-btn-secondary px-8 justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    Preview
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Live Preview Sub-mode -->
                    <div x-show="selectedTemplateId === 'preview'" class="flex-1 overflow-y-auto bg-gray-50/80 p-8" x-cloak x-transition>
                        <div class="mx-auto max-w-2xl space-y-6">
                            <div class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                                <div class="mb-2 flex items-center gap-2">
                                     <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ta-badge-success">Previewing Changes</span>
                                </div>
                                <div>
                                    <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Subject Preview</span>
                                    <h4 class="mt-1 text-lg font-bold text-gray-900" x-text="previewSubject"></h4>
                                </div>
                                <div class="h-px bg-gray-200"></div>
                                <div class="min-h-[300px] max-w-none text-sm leading-relaxed text-gray-800 [&_a]:text-brand-600 [&_a]:underline [&_h1]:text-xl [&_h1]:font-bold [&_h2]:text-lg [&_h2]:font-semibold [&_p]:my-2" x-html="previewBody"></div>
                            </div>
                            
                            <button type="button" @click="selectedTemplateId = null" class="w-full page-header-btn-primary justify-center">Back to editor</button>
                        </div>
                    </div>
                </div>
        </div>
    </div>
</div>

<script>
(function() {
'use strict';
const API_BASE_URL = '<?= e($apiBaseUrl) ?>/email-templates.php';
const csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';

function emailTemplatesApp() {
    return {
        showTemplateForm: false,
        saving: false,
        templateForm: {
            id: null,
            template_type: 'custom',
            subject: '',
            body_html: ''
        },
        previewSubject: '',
        previewBody: '',
        selectedTemplateId: null,

        openCreateForm() {
            this.selectedTemplateId = null;
            this.showTemplateForm = true;
            this.templateForm = {
                id: null,
                template_type: 'custom',
                subject: '',
                body_html: '<h2>Email Title</h2>\n<p>Hi {first_name},</p>\n<p>Your content here...</p>'
            };
            this.$nextTick(() => {
                const textarea = document.getElementById('template-body-textarea');
                const quill = window.__quillInstances && textarea ? window.__quillInstances.get(textarea) : null;
                if (quill) quill.root.innerHTML = this.templateForm.body_html;
            });
        },

        async openEditForm(templateId) {
            if (!templateId) return;
            try {
                const response = await fetch(API_BASE_URL + '?action=get&id=' + encodeURIComponent(templateId), {
                    credentials: 'same-origin'
                });
                const data = await response.json();
                if (data.success) {
                    this.selectedTemplateId = null;
                    this.showTemplateForm = true;
                    this.templateForm = {
                        id: data.template.id,
                        template_type: data.template.template_type,
                        subject: data.template.subject,
                        body_html: data.template.body_html
                    };
                    this.$nextTick(() => {
                        const textarea = document.getElementById('template-body-textarea');
                        const quill = window.__quillInstances && textarea ? window.__quillInstances.get(textarea) : null;
                        if (quill) quill.root.innerHTML = this.templateForm.body_html || '';
                    });
                }
            } catch (error) {
                console.error('Error loading template:', error);
                if (typeof confirmAction === 'function') confirmAction({ title: 'Error', message: 'Failed to load template', type: 'warning', okText: 'OK', showCancel: false });
            }
        },

        cancelForm() {
            this.showTemplateForm = false;
            this.templateForm = {
                id: null,
                template_type: 'custom',
                subject: '',
                body_html: ''
            };
            this.selectedTemplateId = null;
        },

        async saveTemplate() {
            this.saving = true;
            try {
                const textarea = document.getElementById('template-body-textarea');
                const quill = window.__quillInstances && textarea ? window.__quillInstances.get(textarea) : null;
                if (quill) {
                    this.templateForm.body_html = quill.root.innerHTML;
                }
                const action = this.templateForm.id ? 'update' : 'create';
                const payload = { ...this.templateForm, csrf_token: csrfToken, action: action };
                const response = await fetch(API_BASE_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    if (typeof confirmAction === 'function') confirmAction({ title: 'Save Failed', message: data.message || 'Failed to save template', type: 'warning', okText: 'OK', showCancel: false });
                }
            } catch (error) {
                console.error('Error saving template:', error);
                if (typeof confirmAction === 'function') confirmAction({ title: 'Error', message: 'An error occurred while saving', type: 'warning', okText: 'OK', showCancel: false });
            } finally {
                this.saving = false;
            }
        },

        async deleteTemplate(id, type) {
            const confirmed = await confirmAction({
                title: 'Delete Template',
                message: 'Are you sure you want to delete the template "' + String(type).replace(/"/g, '\\"') + '"?',
                type: 'danger',
                okText: 'Delete',
                cancelText: 'Cancel'
            });
            if (!confirmed) return;
            try {
                const response = await fetch(API_BASE_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ id, csrf_token: csrfToken, action: 'delete' })
                });
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    if (typeof confirmAction === 'function') confirmAction({ title: 'Delete Failed', message: data.message || 'Failed to delete template', type: 'warning', okText: 'OK', showCancel: false });
                }
            } catch (error) {
                console.error('Error deleting template:', error);
                if (typeof confirmAction === 'function') confirmAction({ title: 'Error', message: 'An error occurred', type: 'warning', okText: 'OK', showCancel: false });
            }
        },

        previewCurrent() {
            if (!this.templateForm.subject || !this.templateForm.body_html) {
                if (typeof confirmAction === 'function') confirmAction({ title: 'Missing Fields', message: 'Please fill in the subject and body before previewing.', type: 'warning', okText: 'OK', showCancel: false });
                return;
            }
            const sampleData = {
                first_name: 'John',
                last_name: 'Smith',
                email: 'john@example.com',
                event_name: 'Friday Night Service',
                event_date: 'December 15, 2024',
                event_time: '7:00 PM',
                location: 'Main Hall',
                event_description: 'Join us for an evening of worship and fellowship.',
                rsvp_link: '#rsvp',
                event_link: '#event',
                unsubscribe_link: '#unsubscribe',
                amount: '25.00',
                payment_id: 'pi_123456789',
                payment_date: 'December 10, 2024'
            };
            const textarea = document.getElementById('template-body-textarea');
            const quill = window.__quillInstances && textarea ? window.__quillInstances.get(textarea) : null;
            if (quill) {
                this.templateForm.body_html = quill.root.innerHTML;
            }
            let subject = this.templateForm.subject;
            let body = this.templateForm.body_html;
            Object.keys(sampleData).forEach(key => {
                const regex = new RegExp(`{${key}}`, 'g');
                subject = subject.replace(regex, sampleData[key]);
                body = body.replace(regex, sampleData[key]);
            });
            this.previewSubject = subject;
            this.previewBody = body;
            this.selectedTemplateId = 'preview';
        },

        insertVariable(variable) {
            const textarea = document.getElementById('template-body-textarea');
            if (!textarea) return;
            const quill = window.__quillInstances && textarea ? window.__quillInstances.get(textarea) : null;
            const insertion = `{${variable}}`;
            if (quill) {
                const range = quill.getSelection(true) || { index: quill.getLength() };
                quill.insertText(range.index, insertion);
                quill.setSelection(range.index + insertion.length);
                this.templateForm.body_html = quill.root.innerHTML;
            } else {
                const start = textarea.selectionStart;
                const text = textarea.value;
                this.templateForm.body_html = text.substring(0, start) + insertion + text.substring(start);
                setTimeout(() => {
                    textarea.focus();
                    textarea.setSelectionRange(start + insertion.length, start + insertion.length);
                }, 0);
            }
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
        padding: 12px 24px !important;
    }
    .ql-container.ql-snow { border: none !important; }
    .ql-editor { 
        padding: 32px 48px !important; 
        font-family: 'Inter', ui-sans-serif, system-ui, sans-serif !important;
        font-size: 15px !important;
        line-height: 1.6 !important;
        color: #1e293b !important;
        min-height: 400px !important;
    }
    .ql-editor h2 { font-weight: 800 !important; color: #0f172a !important; margin-bottom: 1rem !important; }
    .ql-editor p { margin-bottom: 1rem !important; }

    /* Scrollbar Hide */
    .scrollbar-hide::-webkit-scrollbar { display: none; }
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

    .ql-hc-video,
    .ql-hc-emoji { display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 28px !important; padding: 3px 5px !important; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>

