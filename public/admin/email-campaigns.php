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

$config = require HC_PROJECT_ROOT . '/config/config.php';
Database::getInstance($config['database']);

$csrfToken = CsrfMiddleware::getToken();

require_once __DIR__ . '/includes/layout-vars.php';
$apiBaseUrl = $basePath . '/public/api';
$adminBase = $basePath . '/admin';

$pageTitle = 'Email & campaigns';
$currentPage = 'email-campaigns';
$editCampaignId = isset($_GET['campaign']) ? (int) $_GET['campaign'] : 0;
include __DIR__ . '/includes/header.php';
?>

<div x-data="emailCampaignsApp()" x-init="init()" class="animate-fade-in">
    <?php
    $pageHeaderTitle = 'Email & campaigns';
    $pageHeaderSubtitle = 'Send broadcasts, configure automation, and review delivery.';
    $pageHeaderActions = '';
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="mx-auto w-full max-w-[1600px]">
        <nav role="tablist" aria-label="Email and campaigns sections" class="email-campaigns-tablist mb-8 flex w-full flex-nowrap gap-1 rounded-xl border border-gray-200 bg-gray-100 p-1 shadow-sm dark:bg-gray-800 dark:border-gray-700">
            <button type="button" id="email-tab-campaign" role="tab" aria-controls="email-tabpanel-campaign" :aria-selected="activeTab === 'campaign'" @click="setTab('campaign')"
                    :class="activeTab === 'campaign' ? 'email-campaigns-tab--active' : 'email-campaigns-tab--inactive'"
                    class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center rounded-lg px-2 py-2 text-center text-xs font-semibold transition-all sm:px-3 sm:text-sm"
                    title="Email campaigns">
                <span class="truncate">Campaigns</span>
            </button>
            <button type="button" id="email-tab-automation" role="tab" aria-controls="email-tabpanel-automation" :aria-selected="activeTab === 'automation'" @click="setTab('automation')"
                    :class="activeTab === 'automation' ? 'email-campaigns-tab--active' : 'email-campaigns-tab--inactive'"
                    class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center rounded-lg px-2 py-2 text-center text-xs font-semibold transition-all sm:px-3 sm:text-sm"
                    title="Automation">
                <span class="truncate">Automation</span>
            </button>
            <button type="button" id="email-tab-log" role="tab" aria-controls="email-tabpanel-log" :aria-selected="activeTab === 'log'" @click="setTab('log')"
                    :class="activeTab === 'log' ? 'email-campaigns-tab--active' : 'email-campaigns-tab--inactive'"
                    class="flex min-h-[44px] min-w-0 flex-1 items-center justify-center rounded-lg px-2 py-2 text-center text-xs font-semibold transition-all sm:px-3 sm:text-sm"
                    title="Analytics and delivery log">
                <span class="hidden sm:inline truncate">Analytics &amp; logs</span>
                <span class="sm:hidden truncate">Analytics</span>
            </button>
        </nav>

    <!-- Tab: Automation -->
    <div id="email-tabpanel-automation" role="tabpanel" aria-labelledby="email-tab-automation" x-show="activeTab === 'automation'" x-cloak :aria-hidden="activeTab === 'automation' ? 'false' : 'true'" class="mx-auto max-w-5xl space-y-8 py-2">
        <div class="relative overflow-hidden bento-card p-8">
            <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-8">
                <div class="flex items-center gap-6">
                    <div class="w-14 h-14 bg-brand-600 rounded-xl flex items-center justify-center shadow-sm text-white flex-shrink-0">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">Email automation</h3>
                        <p class="text-sm text-gray-500 mt-1 max-w-md dark:text-gray-400">Turn event reminder emails on or off and customize when they go out.</p>
                    </div>
                </div>
                <div class="flex items-center gap-4 rounded-xl border border-gray-200 bg-gray-50 px-5 py-3 dark:bg-gray-800 dark:border-gray-700">
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-300">Reminders enabled</span>
                    <div class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                         :class="automation.email_reminders_enabled ? 'bg-brand-600' : 'bg-gray-200'"
                         @click="automation.email_reminders_enabled = !automation.email_reminders_enabled; saveAutomation()">
                        <span class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out dark:bg-gray-800"
                              :class="automation.email_reminders_enabled ? 'translate-x-5' : 'translate-x-0'"></span>
                    </div>
                </div>
            </div>

            <div x-show="automation.email_reminders_enabled" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform -translate-y-4" x-transition:enter-end="opacity-100 transform translate-y-0" class="mt-10 space-y-10 border-t border-gray-200 pt-10 dark:border-gray-700">
                
                <!-- Standard Reminders Section -->
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Standard reminders</h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <template x-for="milestone in [
                            { key: 'reminder_1week', label: '1 Week Before', desc: 'Summary and key details' },
                            { key: 'reminder_1day', label: '24 Hours Before', desc: 'Final check-in instructions' },
                            { key: 'reminder_2hours', label: '2 Hours Before', desc: 'Last call - See you soon!' }
                        ]">
                            <label class="group relative flex cursor-pointer flex-col overflow-hidden rounded-xl border border-gray-200 bg-gray-50 p-5 transition-all hover:border-brand-200 hover:bg-white hover:shadow-card dark:bg-gray-800 dark:border-gray-700">
                                <input type="checkbox" x-model="automation[milestone.key]" @change="saveAutomation()" class="absolute right-4 top-4 w-5 h-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="milestone.label"></span>
                                <span class="text-xs text-gray-500 mt-1 dark:text-gray-400" x-text="milestone.desc"></span>
                                <div :class="automation[milestone.key] ? 'border-brand-500' : 'border-transparent'" class="absolute inset-0 border-2 rounded-2xl pointer-events-none transition-colors"></div>
                            </label>
                        </template>
                    </div>
                </div>

                <!-- Custom Reminders Section -->
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide dark:text-gray-400">Custom schedule</h4>
                        </div>
                        <button @click="addCustomReminder()" type="button" class="px-3 py-1.5 bg-white text-brand-600 rounded-lg text-xs font-semibold hover:bg-brand-50 transition-all inline-flex items-center gap-2 border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Sequence Step
                        </button>
                    </div>
                    
                    <div class="space-y-3">
                        <template x-for="(item, idx) in automation.custom_schedule" :key="idx">
                            <div class="group flex animate-fade-in items-center gap-4 rounded-xl border border-gray-200 bg-gray-50/50 p-4 dark:bg-gray-800 dark:border-gray-700">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full border border-gray-200 bg-white text-[10px] font-bold text-gray-400 transition-colors group-hover:text-brand-600 dark:bg-gray-800 dark:border-gray-700" x-text="idx + 1"></div>
                                <div class="flex items-center gap-2">
                                    <input type="number" x-model.number="item.value" min="1" max="365" class="w-16 bg-white border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-bold text-gray-800 outline-none focus:ring-2 focus:ring-brand-100 transition-all dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700" @change="saveAutomation()">
                                    <select x-model="item.unit" class="bg-white border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-bold text-gray-600 outline-none focus:ring-2 focus:ring-brand-100 transition-all dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700" @change="saveAutomation()">
                                        <option value="days">days before event</option>
                                        <option value="hours">hours before event</option>
                                    </select>
                                </div>
                                <div class="flex-1"></div>
                                <button @click="removeCustomReminder(idx); saveAutomation()" type="button" class="p-2 text-rose-300 hover:text-rose-600 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </template>
                        <div x-show="!automation.custom_schedule || automation.custom_schedule.length === 0" class="rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center dark:border-gray-700">
                            <p class="text-sm text-gray-400 font-medium">No custom sequence steps defined.</p>
                        </div>
                    </div>
                </div>

                <!-- Status Indicator -->
                <div class="flex items-center gap-3">
                    <div x-show="automationSaving" class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 dark:bg-gray-800 dark:border-gray-700">
                        <div class="w-1.5 h-1.5 bg-brand-500 rounded-full animate-ping"></div>
                        <span class="text-xs text-gray-500 font-medium dark:text-gray-400">Saving…</span>
                    </div>
                    <div x-show="automationSaved" x-transition class="flex items-center gap-2 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-1.5">
                        <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span class="text-xs font-medium text-emerald-800">Saved</span>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Tab: Email Log (Analytics) -->
    <div id="email-tabpanel-log" role="tabpanel" aria-labelledby="email-tab-log" x-show="activeTab === 'log'" x-cloak :aria-hidden="activeTab === 'log' ? 'false' : 'true'" class="flex flex-col gap-8 animate-fade-in">
        <!-- Analytics Header Overview -->
        <div class="bento-card p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-8">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-brand-600 rounded-xl flex items-center justify-center text-white shadow-sm">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Delivery log</h2>
                        <p class="text-sm text-gray-500 mt-1 dark:text-gray-400"><span x-text="logTotal"></span> messages</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <select x-model="logStatusFilter" @change="loadEmailLog()" class="min-w-[180px] appearance-none rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700">
                            <option value="">All statuses</option>
                            <option value="sent">Sent</option>
                            <option value="failed">Failed</option>
                            <option value="queued">Queued</option>
                        </select>
                        <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>
                    <button type="button" @click="loadEmailLog()" class="flex h-12 w-12 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-400 shadow-sm transition-all hover:border-brand-200 hover:text-brand-600 hover:shadow-md dark:bg-gray-800 dark:border-gray-700" aria-label="Refresh log">
                        <svg class="w-5 h-5" :class="logLoading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    </button>
                </div>
            </div>
            
            <div class="mt-8 p-4 bg-amber-50/60 backdrop-blur-sm border border-amber-100 rounded-2xl flex items-start gap-4">
                <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center text-amber-600 flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="flex-1">
                    <p class="text-[11px] text-amber-900 leading-relaxed font-semibold">
                        <strong>Note:</strong> &quot;Sent&quot; means the message was handed off to SMTP2GO. Inbox delivery still depends on reputation and recipient filters. Review delivery in the <a href="https://app.smtp2go.com" target="_blank" rel="noopener" class="text-amber-800 underline font-medium hover:text-amber-900">SMTP2GO dashboard</a>.
                    </p>
                </div>
            </div>
        </div>

        <div class="ta-table-wrap flex max-h-[800px] flex-col overflow-hidden bento-card">
            <div class="scrollbar-hide min-h-0 flex-1 overflow-x-auto overflow-y-auto">
                <table class="ta-table min-w-full">
                    <thead class="sticky top-0 z-10">
                        <tr>
                            <th class="px-6 py-4">Recipient</th>
                            <th class="px-6 py-4">Subject &amp; type</th>
                            <th class="px-6 py-4">Sent</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="log in emailLogs" :key="log.id">
                            <tr class="group">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 items-center justify-center rounded-full border border-gray-200 bg-brand-50 text-xs font-semibold text-brand-600 dark:border-gray-700" x-text="getInitials(log.recipient_first_name, log.recipient_last_name)"></div>
                                        <div class="flex flex-col min-w-0">
                                            <span class="text-sm font-medium text-gray-900 truncate dark:text-white" x-text="log.recipient_first_name ? (log.recipient_first_name + ' ' + (log.recipient_last_name || '')) : log.recipient_email"></span>
                                            <span class="text-xs text-gray-500 font-mono truncate dark:text-gray-400" x-text="log.recipient_email"></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-xs">
                                    <div class="flex flex-col gap-1">
                                        <span class="text-sm text-gray-800 truncate block dark:text-gray-100" x-text="log.subject"></span>
                                        <span class="self-start px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-medium rounded border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700" x-text="log.email_type || 'Custom broadcast'"></span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <span class="text-sm text-gray-800 dark:text-gray-100" x-text="formatLogDate(log.sent_at || log.created_at)"></span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="formatLogTime(log.sent_at || log.created_at)"></span>
                                    </div>
                                </td>
                                <td>
                                    <span :class="{
                                        'bg-emerald-100 text-emerald-800': log.status === 'sent',
                                        'bg-rose-100 text-rose-800': log.status === 'failed',
                                        'bg-amber-100 text-amber-800': log.status === 'queued'
                                    }" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold" x-text="log.status === 'sent' ? 'Sent' : log.status"></span>
                                </td>
                                <td class="text-right">
                                    <button type="button" @click="resendEmailLog(log.id)" :disabled="resendingLogId === log.id" class="px-4 py-2 bg-white border border-gray-200 rounded-lg text-sm font-medium text-brand-600 hover:border-brand-300 hover:bg-brand-50 transition-colors disabled:opacity-40 dark:bg-gray-800 dark:border-gray-700">
                                        <span x-show="resendingLogId !== log.id">Resend</span>
                                        <span x-show="resendingLogId === log.id" class="inline-flex items-center gap-2">
                                            <span class="w-3 h-3 border-2 border-brand-600 border-t-transparent rounded-full animate-spin"></span>
                                            Sending…
                                        </span>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="!logLoading && emailLogs.length === 0" class="flex flex-1 flex-col items-center justify-center border-t border-gray-200 bg-gray-50 p-12 text-center dark:bg-gray-800 dark:border-gray-700">
                <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl border border-gray-200 bg-white text-gray-300 shadow-card dark:bg-gray-800 dark:border-gray-700">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 00-2-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">No messages yet</h3>
                <p class="text-sm text-gray-500 max-w-sm mt-2 dark:text-gray-400">Delivery events will appear here after you send campaigns or automated emails.</p>
            </div>
        </div>
    </div>
    <!-- /Tab: Email Log -->

    <!-- Tab: Email Campaigns — row block A: compose; row block B: audience, merge, history -->
    <div id="email-tabpanel-campaign" role="tabpanel" aria-labelledby="email-tab-campaign" x-show="activeTab === 'campaign'" x-cloak :aria-hidden="activeTab === 'campaign' ? 'false' : 'true'" class="outline-none" tabindex="-1">
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px] xl:items-start">

            <!-- ============ LEFT: Composer ============ -->
            <div class="flex min-w-0 flex-col gap-6">
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            </span>
                            <div>
                                <h2 class="text-base font-bold text-gray-900 dark:text-white/90">Compose campaign</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Write once, send to your whole audience.</p>
                            </div>
                        </div>
                        <div x-show="campaign.id" x-cloak class="flex items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 py-1 dark:border-gray-700 dark:bg-white/[0.03]">
                            <span class="h-2 w-2 rounded-full bg-brand-500"></span>
                            <span class="text-xs font-medium text-gray-600 dark:text-gray-300" x-text="'#' + campaign.id + ' · ' + (campaign.status || 'draft')"></span>
                        </div>
                    </div>

                    <div class="space-y-4 p-5">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Start from template <span class="font-normal text-gray-400">(optional)</span></label>
                                <div class="relative">
                                    <select x-ref="campaignTemplateSelect" @change="campaignTemplatePicked($event)" class="w-full appearance-none rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                        <option value="">Blank campaign</option>
                                        <template x-for="t in campaignTemplates" :key="t.id">
                                            <option :value="t.id" x-text="(t.name || t.subject || 'Saved template') + ' · ' + (t.template_type || 'custom')"></option>
                                        </template>
                                    </select>
                                    <div class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-gray-400">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Subject line</label>
                                <input type="text" x-model="campaign.subject" placeholder="Enter the subject line…" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 outline-none transition placeholder:text-gray-300 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                            </div>
                        </div>

                        <div x-show="campaignTemplateLegacyWarning" x-transition class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/40 dark:bg-amber-500/10">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <p class="text-xs font-medium leading-relaxed text-amber-800 dark:text-amber-300">This template is from an older editor and only had plain text. The subject was applied — add the design below.</p>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800">
                        <label class="block px-5 pt-4 text-xs font-semibold text-gray-600 dark:text-gray-400">Message body</label>
                        <div id="campaign-body-editor" class="min-h-[600px] bg-white dark:bg-transparent"></div>
                    </div>
                </div>

                <!-- Campaign history -->
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white/90">Campaign history</h3>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Sent, scheduled, and draft campaigns.</p>
                        </div>
                        <button type="button" @click="campaignHistoryOpen = !campaignHistoryOpen; if (campaignHistoryOpen) loadCampaignHistory()" class="page-header-btn-secondary text-sm">
                            <span x-text="campaignHistoryOpen ? 'Hide' : 'Show history'"></span>
                            <svg class="h-4 w-4 transition-transform duration-300" :class="campaignHistoryOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>
                    <div x-show="campaignHistoryOpen" x-cloak x-transition>
                        <div x-show="campaignHistoryLoading" class="p-10 text-center">
                            <div class="inline-block h-7 w-7 animate-spin rounded-full border-2 border-brand-600 border-t-transparent"></div>
                        </div>
                        <div x-show="!campaignHistoryLoading && campaignHistoryRows.length === 0" class="p-10 text-center text-sm text-gray-500 dark:text-gray-400">No campaigns yet.</div>
                        <div x-show="!campaignHistoryLoading && campaignHistoryRows.length > 0" class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead>
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Subject</th>
                                        <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</th>
                                        <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Activity</th>
                                        <th class="px-5 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <template x-for="row in campaignHistoryRows" :key="row.id">
                                        <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                                            <td class="px-5 py-3 text-theme-sm font-medium text-gray-900 dark:text-white/90" x-text="row.subject || row.name || 'Untitled'"></td>
                                            <td class="px-5 py-3">
                                                <span :class="{
                                                    'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400': row.status === 'sent',
                                                    'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400': row.status === 'scheduled',
                                                    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': row.status === 'draft'
                                                }" class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium" x-text="row.status"></span>
                                            </td>
                                            <td class="px-5 py-3 text-theme-sm text-gray-600 dark:text-gray-300" x-text="row.sent_at || row.scheduled_at || row.created_at || '—'"></td>
                                            <td class="px-5 py-3 text-right">
                                                <a :href="'<?= e($adminBase) ?>/?page=email-campaigns&campaign=' + row.id" class="text-theme-sm font-medium text-brand-600 hover:text-brand-700">Open</a>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ RIGHT: Send rail ============ -->
            <aside class="flex flex-col gap-5 xl:sticky xl:top-[84px]">

                <!-- Audience -->
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-center gap-2.5 border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </span>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white/90">Audience</h3>
                    </div>
                    <div class="space-y-4 p-5">
                        <div class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Send to</label>
                            <div class="relative">
                                <select x-model="campaign.audience_type" class="w-full appearance-none rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                    <option value="all_members">All community members</option>
                                    <option value="single_member">A specific individual</option>
                                    <option value="event">Event participants</option>
                                    <option value="event_member">A person in an event</option>
                                    <option value="manual">Manual list (CSV/text)</option>
                                    <option value="segment">Group segment</option>
                                </select>
                                <div class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-gray-400">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                            </div>
                        </div>

                        <div x-show="campaign.audience_type === 'single_member'" x-transition class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Member</label>
                            <select x-model="campaign.member_id" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                <option value="">Loading members…</option>
                                <template x-for="m in campaignMembers" :key="m.id">
                                    <option :value="m.id" x-text="(m.last_name || '') + ', ' + (m.first_name || '')"></option>
                                </template>
                            </select>
                        </div>
                        <div x-show="campaign.audience_type === 'event'" x-transition class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Event</label>
                            <select x-model="campaign.event_id" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                <option value="">Loading events…</option>
                                <template x-for="ev in campaignEvents" :key="ev.id">
                                    <option :value="ev.id" x-text="ev.title"></option>
                                </template>
                            </select>
                        </div>
                        <div x-show="campaign.audience_type === 'event_member'" x-transition class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Event</label>
                            <select x-model="campaign.event_id" @change="campaign.member_id = ''; loadCampaignEventMembers(campaign.event_id)" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                <option value="">Loading events…</option>
                                <template x-for="ev in campaignEvents" :key="ev.id">
                                    <option :value="ev.id" x-text="ev.title"></option>
                                </template>
                            </select>
                            <label class="block pt-1 text-xs font-semibold text-gray-600 dark:text-gray-400">Attendee</label>
                            <select x-model="campaign.member_id" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                <option value="">Select an RSVP-yes attendee…</option>
                                <template x-for="m in campaignEventMembers" :key="m.id">
                                    <option :value="m.id" x-text="(m.last_name || '') + ', ' + (m.first_name || '')"></option>
                                </template>
                            </select>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">Only attendees who RSVP'd Yes are listed.</p>
                        </div>
                        <div x-show="campaign.audience_type === 'manual'" x-transition class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Email list</label>
                            <textarea x-model="campaign.manual_emails" rows="4" placeholder="Emails separated by lines or commas…" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 font-mono text-xs text-gray-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200"></textarea>
                        </div>
                        <div x-show="campaign.audience_type === 'segment'" x-transition class="space-y-1.5">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Group</label>
                            <select x-model="campaign.group_id" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-700 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                                <option value="">Loading groups…</option>
                                <template x-for="g in campaignGroups" :key="g.id">
                                    <option :value="g.id" x-text="g.name"></option>
                                </template>
                            </select>
                        </div>

                        <!-- Recipient count -->
                        <div class="flex items-center gap-3 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3 dark:border-brand-500/30 dark:bg-brand-500/10">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-600 dark:bg-brand-500/20 dark:text-brand-300">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M9 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Will reach</p>
                                <p class="text-sm font-bold text-gray-900 dark:text-white/90">
                                    <span x-show="recipientCountLoading" class="text-gray-400">Calculating…</span>
                                    <span x-show="!recipientCountLoading && recipientCount !== null"><span x-text="recipientCount"></span> recipient<span x-show="recipientCount !== 1">s</span></span>
                                    <span x-show="!recipientCountLoading && recipientCount === null" class="text-gray-400">Select an audience</span>
                                </p>
                            </div>
                            <button type="button" @click="refreshRecipientCount()" class="rounded-lg p-1.5 text-gray-400 transition hover:bg-white hover:text-brand-600 dark:hover:bg-white/[0.06] dark:bg-gray-800" aria-label="Recalculate recipients" title="Recalculate">
                                <svg class="h-4 w-4" :class="recipientCountLoading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Schedule -->
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                    <label class="flex cursor-pointer items-center justify-between">
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Schedule for later</span>
                        <span class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors" :class="campaign.schedule_enabled ? 'bg-brand-600' : 'bg-gray-200 dark:bg-gray-700'" @click="campaign.schedule_enabled = !campaign.schedule_enabled">
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition dark:bg-gray-800" :class="campaign.schedule_enabled ? 'translate-x-5' : 'translate-x-0'"></span>
                        </span>
                    </label>
                    <div x-show="campaign.schedule_enabled" x-transition class="mt-4 space-y-1.5">
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400">Delivery time</label>
                        <input type="datetime-local" x-model="campaign.scheduled_at" class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">
                    </div>
                </div>

                <!-- Merge tags -->
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Merge tags</h3>
                    <p class="mb-3 text-[11px] text-gray-500 dark:text-gray-400">Click to insert at the cursor.</p>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="tag in ['{first_name}', '{last_name}', '{name}', '{email}', '{organization_name}', '{event_name}', '{event_day}', '{event_date}', '{event_time}', '{event_location}']" :key="tag">
                            <button type="button" @mousedown.prevent="insertCampaignMergeTag(tag)" class="group flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-tight text-gray-700 transition hover:border-brand-200 hover:text-brand-600 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300">
                                <span class="font-normal text-brand-300 group-hover:text-brand-500">{</span><span x-text="tag.replace('{','').replace('}','')"></span><span class="font-normal text-brand-300 group-hover:text-brand-500">}</span>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Actions -->
                <div class="space-y-2 rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                    <button type="button" @click="campaignSendNow()" :disabled="campaignSending || !campaign.subject" class="page-header-btn-primary w-full justify-center disabled:opacity-50">
                        <svg x-show="!campaignSending" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        <svg x-show="campaignSending" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="campaignSending ? 'Sending…' : 'Send now'"></span>
                    </button>
                    <button type="button" @click="campaignSchedule()" :disabled="campaignSaving || !campaign.subject" class="page-header-btn-secondary w-full justify-center disabled:opacity-50">Schedule send</button>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="campaignSaveDraft()" :disabled="campaignSaving" class="page-header-btn-secondary justify-center disabled:opacity-50">Save draft</button>
                        <button type="button" @click="openCampaignPreview()" class="page-header-btn-secondary justify-center">Preview</button>
                    </div>
                    <button type="button" @click="showCampaignSaveTemplateModal = true" :disabled="campaignSaving" class="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold text-brand-600 transition hover:bg-brand-50 disabled:opacity-50 dark:text-brand-400 dark:hover:bg-brand-500/10">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h7"/></svg>
                        Save as library template
                    </button>
                </div>
            </aside>
        </div>
    </div>

    <!-- Campaign email preview (modal) -->
    <div x-show="campaignPreviewOpen" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-6 sm:p-10 overflow-hidden">
        <div @click="campaignPreviewOpen = false" class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]"></div>
        <div class="relative flex max-h-[95vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700">
            <div class="sticky top-0 z-20 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4 dark:bg-gray-800 dark:border-gray-700">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 bg-brand-50 rounded-xl flex items-center justify-center text-brand-600 flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Preview</h3>
                        <p class="text-sm text-gray-500 truncate dark:text-gray-400" x-text="campaign.subject || '(No subject)'"></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 bg-gray-100 p-1 rounded-xl border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                    <button type="button" @click="campaignPreviewModalWidth = 375" :class="campaignPreviewModalWidth === 375 ? 'bg-white text-brand-700 shadow-card' : 'text-gray-500 hover:text-gray-900'" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        <span class="hidden sm:inline" :class="campaignPreviewModalWidth === 375 ? '' : 'hidden md:inline'">Mobile</span>
                    </button>
                    <button type="button" @click="campaignPreviewModalWidth = 720" :class="campaignPreviewModalWidth === 720 ? 'bg-white text-brand-700 shadow-card' : 'text-gray-500 hover:text-gray-900'" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <span class="hidden sm:inline" :class="campaignPreviewModalWidth === 720 ? '' : 'hidden md:inline'">Desktop</span>
                    </button>
                    <div class="w-px h-6 bg-gray-200 mx-1"></div>
                    <button type="button" @click="campaignPreviewOpen = false" class="w-9 h-9 flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors dark:bg-gray-800 dark:text-gray-200" aria-label="Close preview">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-6 md:p-8 bg-gray-50 flex justify-center items-start min-h-[400px] dark:bg-gray-800">
                <iframe id="campaign-preview-modal-frame" title="Email preview" class="origin-top rounded-xl border border-gray-200 bg-white shadow-card transition-all duration-300 dark:bg-gray-800 dark:border-gray-700" :style="'width:' + campaignPreviewModalWidth + 'px;max-width:100%;height:85vh;'"></iframe>
            </div>
        </div>
    </div>

    <!-- Campaign Save as Template Modal -->
    <div x-show="showCampaignSaveTemplateModal" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-6" style="display: none;">
        <div @click="showCampaignSaveTemplateModal = false" class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]"></div>
        <div class="relative w-full max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-card-lg dark:bg-gray-800 dark:border-gray-700">
            <div class="w-14 h-14 bg-brand-50 rounded-xl flex items-center justify-center text-brand-600 mx-auto mb-4">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h7M8 7h4a1 1 0 011 1v1m-1-5l4 4"/></svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-2 dark:text-white">Save as template</h3>
            <p class="text-sm text-gray-500 mb-6 dark:text-gray-400">Add this campaign to your template library for reuse.</p>
            
            <div class="space-y-2 mb-6 text-left">
                <label class="text-xs font-medium text-gray-600 dark:text-gray-300" for="campaign-save-template-name">Template name</label>
                <input id="campaign-save-template-name" type="text" x-model="campaignSaveTemplateName" placeholder="e.g. Monthly newsletter" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-100 focus:border-brand-500 dark:bg-gray-800 dark:text-white dark:border-gray-700">
            </div>

            <div class="flex gap-3">
                <button type="button" @click="showCampaignSaveTemplateModal = false" class="flex-1 page-header-btn-secondary justify-center">Cancel</button>
                <button type="button" @click="campaignSaveAsTemplate()" class="flex-1 page-header-btn-primary justify-center">Save template</button>
            </div>
        </div>
    </div>

    <!-- Toasts -->
    <div class="pointer-events-none fixed bottom-5 right-5 z-[100000] flex flex-col gap-2" x-cloak>
        <template x-for="t in campaignToasts" :key="t.id">
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
const API_BASE = '<?= e($apiBaseUrl) ?>';
const csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';

// The GrapesJS editor instance is deliberately kept OUT of Alpine's reactive
// data. Alpine deep-proxies reactive properties; wrapping a large stateful
// third-party object (GrapesJS holds DOM refs + circular Backbone models) makes
// heavy operations like loadProjectData() freeze the main thread (blank app).
// A module-scoped reference avoids reactivity entirely — there is only ever one
// campaigns editor on the page.
let campaignEditorInstance = null;

function emailCampaignsApp() {
    return {
        activeTab: 'campaign',

        // Email Log tab
        emailLogs: [],
        logLoading: false,
        logTotal: 0,
        logStatusFilter: '',
        resendingLogId: null,
        
        automation: {
            email_reminders_enabled: true,
            reminder_1week: true,
            reminder_1day: true,
            reminder_2hours: false,
            custom_schedule: []
        },
        automationSaving: false,
        automationSaved: false,
        
        orgLogoUrl: '',
        orgName: '',

        // Campaign Builder (GrapesJS)
        campaign: {
            id: null,
            status: '',
            subject: '',
            audience_type: 'all_members',
            member_id: '',
            event_id: '',
            manual_emails: '',
            group_id: '',
            schedule_enabled: false,
            scheduled_at: ''
        },
        campaignMembers: [],
        campaignEventMembers: [],
        campaignHistoryOpen: false,
        campaignHistoryLoading: false,
        campaignHistoryRows: [],
        campaignEvents: [],
        campaignGroups: [],
        campaignSaving: false,
        campaignSending: false,
        campaignTemplates: [],
        showCampaignSaveTemplateModal: false,
        campaignSaveTemplateName: '',
        editCampaignId: <?= (int) $editCampaignId ?>,
        campaignPreviewOpen: false,
        campaignPreviewHtml: '',
        campaignPreviewModalWidth: 600,
        campaignTemplateLegacyWarning: false,

        // Recipient-count preview + toast feedback
        recipientCount: null,
        recipientCountLoading: false,
        _recipientCountTimer: null,
        campaignToasts: [],
        _campaignToastSeq: 0,

        init() {
            const params = new URLSearchParams(window.location.search);
            const tab = params.get('tab');
            if (tab === 'automation') this.activeTab = 'automation';
            else if (tab === 'log') this.activeTab = 'log';
            else this.activeTab = 'campaign';
            if (this.editCampaignId) this.activeTab = 'campaign';
            this.$watch('activeTab', () => this.syncEmailCampaignsUrl());
            this.$watch('campaign.audience_type', async (type) => {
                if (type === 'event_member') {
                    if (this.campaign.event_id) {
                        await this.loadCampaignEventMembers(this.campaign.event_id);
                    } else {
                        this.campaignEventMembers = [];
                    }
                }
            });
            this.$watch('campaign.event_id', async (eventId) => {
                if (this.campaign.audience_type === 'event_member') {
                    this.campaign.member_id = '';
                    await this.loadCampaignEventMembers(eventId);
                }
            });
            // Recompute the recipient-count preview whenever the audience changes.
            ['campaign.audience_type', 'campaign.member_id', 'campaign.event_id', 'campaign.group_id', 'campaign.manual_emails'].forEach((prop) => {
                this.$watch(prop, () => this.scheduleRecipientCount());
            });
            this.$nextTick(() => {
                if (this.activeTab === 'campaign') { this.bootstrapCampaignTab(); this.scheduleRecipientCount(); }
                if (this.activeTab === 'automation') this.loadAutomation();
                if (this.activeTab === 'log') this.loadEmailLog();
                this.syncEmailCampaignsUrl();
            });
        },

        setTab(tab) {
            if (this.activeTab !== tab) {
                this.activeTab = tab;
                if (tab === 'campaign') this.bootstrapCampaignTab();
                else if (tab === 'automation') this.loadAutomation();
                else if (tab === 'log') this.loadEmailLog();
            }
            this.syncEmailCampaignsUrl();
        },

        syncEmailCampaignsUrl() {
            try {
                const u = new URL(window.location.href);
                u.searchParams.set('page', 'email-campaigns');
                if (this.activeTab === 'log') u.searchParams.set('tab', 'log');
                else if (this.activeTab === 'automation') u.searchParams.set('tab', 'automation');
                else u.searchParams.delete('tab');
                if (this.editCampaignId && this.activeTab === 'campaign') {
                    u.searchParams.set('campaign', String(this.editCampaignId));
                } else {
                    u.searchParams.delete('campaign');
                }
                const next = u.pathname + u.search + u.hash;
                const cur = window.location.pathname + window.location.search + window.location.hash;
                if (next !== cur) history.replaceState(null, '', next);
            } catch (e) { /* ignore */ }
        },

        bootstrapCampaignTab() {
            this.loadCampaignData();
            this.loadCampaignTemplates();
            this.loadOrgLogo();
            this.$nextTick(() => {
                this.initCampaignEditor();
                this.$nextTick(() => {
                    if (this.editCampaignId) this.loadCampaignForEdit(this.editCampaignId);
                });
            });
            if (this.campaignPreviewOpen && this.campaignPreviewHtml) {
                this.$nextTick(() => { setTimeout(() => this.campaignWritePreviewModalFrame(), 200); });
            }
        },

        async loadOrgLogo() {
            if (this.orgLogoUrl) return;
            try {
                const res = await fetch(API_BASE + '/settings.php?action=get_organization', { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success && data.organization) {
                    const url = data.organization.logo_url || data.organization.logo_path || '';
                    this.orgLogoUrl = url;
                    this.orgName = data.organization.name || '';
                }
            } catch (e) { console.error('Load org logo error:', e); }
        },

        buildCampaignAudienceConfig() {
            const manual = this.campaign.manual_emails
                ? this.campaign.manual_emails.split(/[\n,]+/).map(e => e.trim()).filter(Boolean)
                : [];
            const uid = this.campaign.audience_type === 'single_member'
                ? (parseInt(this.campaign.member_id, 10) || 0)
                : 0;
            const eventMemberId = this.campaign.audience_type === 'event_member'
                ? (parseInt(this.campaign.member_id, 10) || 0)
                : 0;
            return JSON.stringify({
                event_id: this.campaign.event_id || null,
                manual_emails: manual,
                group_id: this.campaign.group_id || null,
                user_id: uid > 0 ? uid : null,
                event_user_id: eventMemberId > 0 ? eventMemberId : null
            });
        },

        async loadCampaignHistory() {
            this.campaignHistoryLoading = true;
            try {
                const res = await fetch(API_BASE + '/campaigns.php?action=list', { credentials: 'same-origin' });
                const data = await res.json();
                this.campaignHistoryRows = (data.success && Array.isArray(data.campaigns)) ? data.campaigns : [];
            } catch (e) {
                console.error('Load campaign history:', e);
                this.campaignHistoryRows = [];
            } finally {
                this.campaignHistoryLoading = false;
            }
        },

        async loadCampaignData() {
            try {
                const memRes = await fetch(API_BASE + '/members.php?action=list', { credentials: 'same-origin' });
                const memData = await memRes.json();
                if (memData.success && Array.isArray(memData.members)) this.campaignMembers = memData.members;
                else this.campaignMembers = [];
            } catch (e) { console.error('Load campaign members error:', e); this.campaignMembers = []; }
            try {
                const eventsRes = await fetch(API_BASE + '/events.php?action=list', { credentials: 'same-origin' });
                const eventsData = await eventsRes.json();
                if (eventsData.success && Array.isArray(eventsData.events)) this.campaignEvents = eventsData.events;
                else this.campaignEvents = [];
            } catch (e) { console.error('Load campaign events error:', e); this.campaignEvents = []; }
            try {
                const groupsRes = await fetch(API_BASE + '/groups.php?action=list', { credentials: 'same-origin' });
                if (groupsRes.ok) {
                    const groupsData = await groupsRes.json();
                    if (groupsData.success && Array.isArray(groupsData.groups)) this.campaignGroups = groupsData.groups;
                    else this.campaignGroups = [];
                } else {
                    this.campaignGroups = [];
                }
            } catch (e) { console.error('Load campaign groups error:', e); this.campaignGroups = []; }
        },

        async loadCampaignEventMembers(eventId) {
            const eid = parseInt(eventId, 10) || 0;
            this.campaignEventMembers = [];
            if (eid < 1) return;
            try {
                const res = await fetch(API_BASE + '/events.php?action=rsvps&id=' + eid, { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success && Array.isArray(data.rsvps)) {
                    const seen = new Set();
                    this.campaignEventMembers = data.rsvps
                        .filter((r) => {
                            const id = Number(r.user_id || r.id || 0);
                            if (!id || !r.email || seen.has(id)) return false;
                            seen.add(id);
                            return true;
                        })
                        .map((r) => ({
                            id: Number(r.user_id || r.id),
                            first_name: r.first_name || '',
                            last_name: r.last_name || '',
                            email: r.email || ''
                        }));
                }
            } catch (e) {
                this.campaignEventMembers = [];
            }
        },

        async loadCampaignTemplates() {
            try {
                const res = await fetch(API_BASE + '/email-templates.php?action=list', { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success && Array.isArray(data.templates)) this.campaignTemplates = data.templates;
                else this.campaignTemplates = [];
            } catch (e) { console.error('Load campaign templates error:', e); }
        },

        campaignTemplatePicked(ev) {
            const sel = ev.target;
            const v = sel && sel.value;
            if (sel) sel.value = '';
            if (v) this.campaignLoadTemplate(v);
        },

        campaignGetBodyFragment() {
            const ed = campaignEditorInstance;
            if (!ed) return '';
            // Commit any in-progress inline text edit back into the component model.
            // gjs-get-inlined-html serializes from the model, not the live
            // contenteditable, so without this a merge tag typed/inserted into a
            // text block can be visible in the editor yet missing from the export.
            try {
                const doc = ed.Canvas && ed.Canvas.getDocument && ed.Canvas.getDocument();
                const ae = doc && doc.activeElement;
                if (ae && typeof ae.blur === 'function') ae.blur();
            } catch (e) { /* non-fatal */ }
            try { return ed.runCommand('gjs-get-inlined-html') || ''; }
            catch (e) { return ed.getHtml() || ''; }
        },

        campaignBodyHasContent(html) {
            const d = document.createElement('div');
            d.innerHTML = html || '';
            return (d.textContent || '').trim().length > 0;
        },

        validateCampaignAudience() {
            if (this.campaign.audience_type === 'event' && !this.campaign.event_id) {
                if (typeof confirmAction === 'function') confirmAction({ title: 'Event required', message: 'Select an event for "Event Participants" before sending.', type: 'warning', okText: 'OK', showCancel: false });
                return false;
            }
            if (this.campaign.audience_type === 'event_member' && (!this.campaign.event_id || !this.campaign.member_id)) {
                if (typeof confirmAction === 'function') confirmAction({ title: 'Audience required', message: 'Select both an event and an attendee for "Specific Person in an Event".', type: 'warning', okText: 'OK', showCancel: false });
                return false;
            }
            return true;
        },

        insertCampaignMergeTag(tag) {
            const ed = campaignEditorInstance;
            if (!ed) return;
            // The GrapesJS canvas is an iframe, so an actively-edited text block is
            // contenteditable inside ed.Canvas.getDocument(), NOT the top document.
            // 1) If a text block is being edited, insert at the caret in the iframe.
            try {
                const doc = ed.Canvas.getDocument();
                const ae = doc && doc.activeElement;
                if (ae && ae.isContentEditable) {
                    doc.execCommand('insertText', false, tag);
                    return;
                }
            } catch (e) { /* fall through */ }
            // 2) Otherwise append the tag into the selected component (model-level,
            //    so it is guaranteed to survive export), or drop a new text block.
            try {
                const cmp = ed.getSelected();
                if (cmp && typeof cmp.append === 'function' && cmp.get('editable') !== false && cmp.get('type') !== 'wrapper') {
                    cmp.append(tag);
                } else {
                    ed.addComponents('<div data-gjs-type="text" style="padding:4px 0;">' + tag + '</div>');
                }
            } catch (e) {
                // Last resort: copy to clipboard so the user can paste it manually.
                if (navigator.clipboard) navigator.clipboard.writeText(tag);
                this.campaignToast('Merge tag ' + tag + ' copied — paste it into a text block.', 'success');
            }
        },

        initCampaignEditor() {
            if (campaignEditorInstance) return;
            const el = document.getElementById('campaign-body-editor');
            if (!el || typeof grapesjs === 'undefined') return;
            el.innerHTML = '';
            const ed = grapesjs.init({
                container: '#campaign-body-editor',
                height: '600px',
                width: '100%',          // fill the editor column; 'auto' makes it overflow into the sidebar
                fromElement: false,
                storageManager: false,           // we persist via our own campaigns API
                plugins: ['grapesjs-preset-newsletter'],
                pluginsOpts: {
                    'grapesjs-preset-newsletter': {
                        inlineCss: true,
                        modalLabelImport: 'Paste your HTML/CSS here',
                        modalLabelExport: 'Copy the code below'
                    }
                }
            });

            // Route the Asset Manager's uploads to our existing endpoint, which
            // returns { success, url }. GrapesJS expects us to add the asset itself.
            try {
                ed.AssetManager.getConfig().uploadFile = (e) => {
                    const files = e.dataTransfer ? e.dataTransfer.files : e.target.files;
                    if (!files || !files.length) return;
                    const fd = new FormData();
                    fd.append('image', files[0]);
                    return fetch(API_BASE + '/upload-email-image.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-CSRF-Token': csrfToken },
                        body: fd
                    })
                        .then((r) => r.json())
                        .then((d) => {
                            if (d && d.success && d.url) ed.AssetManager.add(d.url);
                            else this.campaignToast((d && d.message) || 'Image upload failed.', 'error');
                        })
                        .catch(() => this.campaignToast('Image upload failed.', 'error'));
                };
            } catch (e) { /* asset manager not ready — non-fatal */ }

            // Pre-composed, email-safe starter blocks users can drag onto a blank
            // canvas. Registered under a "Starters" category so they sit at the top
            // of the block panel alongside the preset's primitive blocks.
            try {
                const bm = ed.BlockManager;
                const starter = (id, label, content) => bm.add(id, {
                    label: label, category: 'Starters', content: content,
                    attributes: { class: 'fa fa-th-large' }
                });
                starter('hc-header-logo', 'Header + Logo',
                    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-bottom:1px solid #e2e8f0;">' +
                    '<tr><td align="center" style="padding:20px 24px;">' +
                    '<img alt="Logo" src="https://placehold.co/180x44?text=Your+Logo" style="max-height:44px;max-width:180px;display:inline-block;"/>' +
                    '</td></tr></table>');
                starter('hc-hero', 'Hero + Button',
                    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;">' +
                    '<tr><td align="center" style="padding:48px 24px;">' +
                    '<h1 style="margin:0 0 12px;font-family:Arial,sans-serif;font-size:30px;line-height:1.2;color:#ffffff;">Your headline here</h1>' +
                    '<p style="margin:0 0 24px;font-family:Arial,sans-serif;font-size:16px;color:#cbd5e1;">A short supporting sentence that sets up the message.</p>' +
                    '<a href="#" style="display:inline-block;background:#465fff;color:#ffffff;text-decoration:none;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;padding:12px 28px;border-radius:8px;">Call to action</a>' +
                    '</td></tr></table>');
                starter('hc-article', 'Article',
                    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;">' +
                    '<tr><td style="padding:24px;">' +
                    '<img alt="" src="https://placehold.co/552x240?text=Image" style="width:100%;max-width:552px;height:auto;border-radius:8px;display:block;margin-bottom:16px;"/>' +
                    '<h2 style="margin:0 0 8px;font-family:Arial,sans-serif;font-size:20px;color:#0f172a;">Article title</h2>' +
                    '<p style="margin:0 0 16px;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#475569;">Write a short paragraph describing the update, event, or announcement.</p>' +
                    '<a href="#" style="display:inline-block;background:#465fff;color:#ffffff;text-decoration:none;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;padding:10px 22px;border-radius:8px;">Read more</a>' +
                    '</td></tr></table>');
                starter('hc-button', 'Button',
                    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:16px 24px;">' +
                    '<a href="#" style="display:inline-block;background:#465fff;color:#ffffff;text-decoration:none;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;padding:12px 28px;border-radius:8px;">Button</a>' +
                    '</td></tr></table>');
            } catch (e) { /* block manager not ready — non-fatal */ }

            campaignEditorInstance = ed;
        },

        // Load an existing campaign/template into the editor. Prefer the reloadable
        // GrapesJS project JSON (design_json); fall back to importing raw body_html
        // (legacy Quill content) as editable components.
        campaignLoadDesign(designJson, bodyHtml) {
            this.initCampaignEditor();
            const ed = campaignEditorInstance;
            if (!ed) return;
            if (designJson) {
                try {
                    const data = typeof designJson === 'string' ? JSON.parse(designJson) : designJson;
                    ed.loadProjectData(data);
                    return;
                } catch (e) { console.warn('design_json load failed, using body_html', e); }
            }
            try {
                ed.setComponents(bodyHtml || '');
            } catch (e) {
                console.warn('body_html import failed', e);
                this.campaignToast('Could not load that content into the builder.', 'error');
            }
        },

        async openCampaignPreview() {
            const frag = this.campaignGetBodyFragment();
            if (!this.campaignBodyHasContent(frag)) {
                if (typeof confirmAction === 'function') confirmAction({ title: 'No content', message: 'Add message body content before preview.', type: 'warning', okText: 'OK', showCancel: false });
                else alert('Add message body content before preview.');
                return;
            }
            await this.loadOrgLogo();
            if (typeof headcountBuildCampaignPreviewHtml !== 'function') {
                if (typeof confirmAction === 'function') confirmAction({ title: 'Preview unavailable', message: 'Editor script failed to load.', type: 'warning', okText: 'OK', showCancel: false });
                return;
            }
            this.campaignPreviewHtml = headcountBuildCampaignPreviewHtml(frag, this.orgName || 'Organization', this.orgLogoUrl || '');
            this.campaignPreviewOpen = true;
            this.$nextTick(() => { setTimeout(() => this.campaignWritePreviewModalFrame(), 120); });
        },

        campaignWritePreviewModalFrame() {
            const frame = document.getElementById('campaign-preview-modal-frame');
            const html = this.campaignPreviewHtml;
            if (!frame || !html) return;
            try {
                frame.contentDocument.open();
                frame.contentDocument.write(html);
                frame.contentDocument.close();
            } catch (e) {
                try { frame.srcdoc = html; } catch (e2) { console.warn('Campaign preview iframe write failed', e2); }
            }
        },

        async loadCampaignForEdit(campaignId) {
            if (!campaignId) return;
            try {
                const res = await fetch(API_BASE + '/campaigns.php?action=get&id=' + campaignId, { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success && data.campaign) {
                    const c = data.campaign;
                    this.campaign.id = c.id;
                    this.campaign.status = c.status || '';
                    this.campaign.subject = c.subject || '';
                    this.campaign.audience_type = c.audience_type || 'all_members';
                    const ac = c.audience_config || {};
                    this.campaign.member_id = ac.user_id ? String(ac.user_id) : '';
                    if (c.audience_type === 'event_member' && ac.event_user_id) {
                        this.campaign.member_id = String(ac.event_user_id);
                    }
                    this.campaign.event_id = ac.event_id || '';
                    if (c.audience_type === 'event_member' && ac.event_id) {
                        await this.loadCampaignEventMembers(ac.event_id);
                    }
                    this.campaign.manual_emails = Array.isArray(ac.manual_emails) ? ac.manual_emails.join('\n') : '';
                    this.campaign.group_id = ac.group_id || '';
                    const raw = c.body_html || '';
                    const inner = typeof headcountExtractBodyFromCampaignHtml === 'function'
                        ? headcountExtractBodyFromCampaignHtml(raw)
                        : raw;
                    this.$nextTick(() => {
                        this.campaignLoadDesign(c.design_json || null, inner);
                    });
                }
            } catch (e) { console.error('Load campaign for edit:', e); }
            this.editCampaignId = null;
        },

        async campaignLoadTemplate(templateId) {
            if (!templateId || templateId === '' || templateId === '0') return;
            this.campaignTemplateLegacyWarning = false;
            try {
                const res = await fetch(API_BASE + '/email-templates.php?action=get&id=' + templateId, { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success && data.template) {
                    const t = data.template;
                    if (t.subject) this.campaign.subject = t.subject;
                    const hasBody = t.body_html && String(t.body_html).trim().length > 0;
                    if (hasBody || t.design_json) {
                        const inner = (hasBody && typeof headcountExtractBodyFromCampaignHtml === 'function')
                            ? headcountExtractBodyFromCampaignHtml(t.body_html)
                            : (t.body_html || '');
                        this.campaignLoadDesign(t.design_json || null, inner);
                    } else {
                        console.warn('Template has no body_html:', t);
                    }
                } else {
                    console.error('Template load failed:', data.message);
                }
            } catch (e) { console.error('Load template error:', e); }
        },


        async campaignSaveAsTemplate() {
            if (!this.campaignSaveTemplateName.trim()) {
                this.campaignToast('Enter a template name.', 'error');
                return;
            }
            try {
                const html = this.campaignGetBodyFragment();
                const res = await fetch(API_BASE + '/email-templates.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'create',
                        csrf_token: csrfToken,
                        template_type: 'custom',
                        subject: this.campaign.subject || this.campaignSaveTemplateName,
                        body_html: html,
                        design_json: campaignEditorInstance ? JSON.stringify(campaignEditorInstance.getProjectData()) : null,
                        name: this.campaignSaveTemplateName.trim()
                    })
                });
                const data = await res.json();
                if (data.success) {
                    this.showCampaignSaveTemplateModal = false;
                    this.campaignSaveTemplateName = '';
                    this.loadCampaignTemplates();
                    this.campaignToast('Saved to your template library.', 'success');
                } else throw new Error(data.message || 'Save failed');
            } catch (e) {
                this.campaignToast(e.message || 'Could not save template.', 'error');
            }
        },

        async campaignSaveDraft() {
            try {
                this.initCampaignEditor();
                const html = this.campaignGetBodyFragment();
                this.campaignSaving = true;
                const res = await fetch(API_BASE + '/campaigns.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'save_draft',
                        csrf_token: csrfToken,
                        id: this.campaign.id,
                        subject: this.campaign.subject,
                        body_html: html,
                        design_json: campaignEditorInstance ? JSON.stringify(campaignEditorInstance.getProjectData()) : null,
                        audience_type: this.campaign.audience_type,
                        audience_config: this.buildCampaignAudienceConfig()
                    })
                });
                const data = await res.json();
                if (data.success) {
                    this.campaign.id = data.campaign_id;
                    this.campaign.status = 'draft';
                    if (this.campaignHistoryOpen) this.loadCampaignHistory();
                    this.campaignToast('Draft saved.', 'success');
                } else throw new Error(data.message || 'Save failed');
            } catch (e) {
                this.campaignToast(e.message || 'Could not save draft.', 'error');
            } finally { this.campaignSaving = false; }
        },

        async campaignSchedule() {
            try {
                const html = this.campaignGetBodyFragment();
                if (!this.campaignBodyHasContent(html)) {
                    this.campaignToast('Add message body content before scheduling.', 'error');
                    return;
                }
                if (!this.validateCampaignAudience()) return;
                if (!this.campaign.schedule_enabled || !this.campaign.scheduled_at) {
                    this.campaignToast('Enable “Schedule for later” and pick a date and time.', 'error');
                    return;
                }
                this.campaignSaving = true;
                const res = await fetch(API_BASE + '/campaigns.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'schedule',
                        csrf_token: csrfToken,
                        id: this.campaign.id,
                        subject: this.campaign.subject,
                        body_html: html,
                        design_json: campaignEditorInstance ? JSON.stringify(campaignEditorInstance.getProjectData()) : null,
                        audience_type: this.campaign.audience_type,
                        audience_config: this.buildCampaignAudienceConfig(),
                        scheduled_at: this.campaign.scheduled_at
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (this.campaignHistoryOpen) this.loadCampaignHistory();
                    this.campaign.id = data.campaign_id;
                    this.campaign.status = 'scheduled';
                    this.campaignToast('Campaign scheduled.', 'success');
                } else throw new Error(data.message || 'Schedule failed');
            } catch (e) {
                this.campaignToast(e.message || 'Could not schedule the campaign.', 'error');
            } finally { this.campaignSaving = false; }
        },

        async campaignSendNow() {
            const html = this.campaignGetBodyFragment();
            if (!this.campaignBodyHasContent(html)) {
                this.campaignToast('Add message body content before sending.', 'error');
                return;
            }
            if (!this.validateCampaignAudience()) return;

            // Confirm with the real recipient count before sending — guards against
            // accidental blasts and empty audiences.
            await this.refreshRecipientCount();
            const n = this.recipientCount;
            if (n === 0) { this.campaignToast('No recipients match this audience.', 'error'); return; }
            const who = (n === null) ? 'the selected audience' : (n + ' recipient' + (n === 1 ? '' : 's'));
            const ok = (typeof confirmAction === 'function')
                ? await confirmAction({ title: 'Send campaign now?', message: 'This will email ' + who + '. This cannot be undone.', type: 'warning', okText: 'Send now', cancelText: 'Cancel' })
                : window.confirm('Send this campaign to ' + who + '?');
            if (!ok) return;

            this.campaignSending = true;
            try {
                const res = await fetch(API_BASE + '/campaigns.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'send',
                        csrf_token: csrfToken,
                        id: this.campaign.id,
                        subject: this.campaign.subject,
                        body_html: html,
                        design_json: campaignEditorInstance ? JSON.stringify(campaignEditorInstance.getProjectData()) : null,
                        audience_type: this.campaign.audience_type,
                        audience_config: this.buildCampaignAudienceConfig()
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (this.campaignHistoryOpen) this.loadCampaignHistory();
                    this.campaign.id = data.campaign_id;
                    this.campaign.status = 'sent';
                    this.campaignToast(data.message || 'Campaign handed off to SMTP2GO.', 'success');
                } else throw new Error(data.message || 'Send failed');
            } catch (e) {
                this.campaignToast(e.message || 'Could not send the campaign.', 'error');
            } finally { this.campaignSending = false; }
        },

        async loadAutomation() {
            try {
                const res = await fetch(API_BASE + '/settings.php?action=get_email_automation', { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success && data.automation) {
                    const raw = data.automation.custom_schedule;
                    const custom_schedule = Array.isArray(raw) ? raw.map(function (e) { return { value: Math.max(1, parseInt(e.value, 10) || 1), unit: e.unit === 'hours' ? 'hours' : 'days' }; }) : [];
                    this.automation = {
                        email_reminders_enabled: !!data.automation.email_reminders_enabled,
                        reminder_1week: !!data.automation.reminder_1week,
                        reminder_1day: !!data.automation.reminder_1day,
                        reminder_2hours: !!data.automation.reminder_2hours,
                        custom_schedule: custom_schedule
                    };
                }
            } catch (e) {
                console.error('Load automation error:', e);
            }
        },
        
        async saveAutomation() {
            this.automationSaving = true;
            this.automationSaved = false;
            try {
                const res = await fetch(API_BASE + '/settings.php?action=update_email_automation', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ ...this.automation, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    this.automationSaved = true;
                    setTimeout(() => { this.automationSaved = false; }, 2000);
                } else {
                    this.campaignToast(data.message || 'Failed to save automation settings.', 'error');
                }
            } catch (e) {
                console.error('Save automation error:', e);
                this.campaignToast('Failed to save automation settings.', 'error');
            }
            this.automationSaving = false;
        },
        
        addCustomReminder() {
            if (!this.automation.custom_schedule) this.automation.custom_schedule = [];
            this.automation.custom_schedule.push({ value: 3, unit: 'days' });
            this.saveAutomation();
        },
        removeCustomReminder(idx) {
            if (!this.automation.custom_schedule) return;
            this.automation.custom_schedule.splice(idx, 1);
        },
        
        async loadEmailLog() {
            this.logLoading = true;
            try {
                const url = `${API_BASE}/email-logs.php?limit=100` + (this.logStatusFilter ? '&status=' + encodeURIComponent(this.logStatusFilter) : '');
                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success) {
                    this.emailLogs = data.logs || [];
                    this.logTotal = data.total || 0;
                }
            } catch (e) {
                console.error('Load email log error:', e);
            }
            this.logLoading = false;
        },
        
        async resendEmailLog(logId) {
            if (!logId || this.resendingLogId) return;
            this.resendingLogId = logId;
            try {
                const res = await fetch(API_BASE + '/email-logs.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'resend', id: logId })
                });
                const data = await res.json();
                if (data.success) {
                    await this.loadEmailLog();
                    this.campaignToast('Email resent.', 'success');
                } else {
                    this.campaignToast(data.message || 'Resend failed.', 'error');
                }
            } catch (e) {
                console.error('Resend error:', e);
                this.campaignToast('Resend failed.', 'error');
            }
            this.resendingLogId = null;
        },

        scheduleRecipientCount() {
            if (this._recipientCountTimer) clearTimeout(this._recipientCountTimer);
            this._recipientCountTimer = setTimeout(() => this.refreshRecipientCount(), 350);
        },

        async refreshRecipientCount() {
            this.recipientCountLoading = true;
            try {
                const res = await fetch(API_BASE + '/campaigns.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'count_recipients',
                        csrf_token: csrfToken,
                        audience_type: this.campaign.audience_type,
                        audience_config: this.buildCampaignAudienceConfig()
                    })
                });
                const data = await res.json();
                this.recipientCount = (data && data.success) ? (data.count || 0) : null;
            } catch (e) {
                this.recipientCount = null;
            } finally {
                this.recipientCountLoading = false;
            }
        },

        campaignToast(message, type) {
            const id = ++this._campaignToastSeq;
            this.campaignToasts.push({ id, message, type: type || 'success' });
            setTimeout(() => { this.campaignToasts = this.campaignToasts.filter(t => t.id !== id); }, 4200);
        },

        formatLogDate(dateStr) {
            if (!dateStr) return '\u2014';
            const d = new Date(dateStr);
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        },
        formatLogTime(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr);
            return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        },
        getInitials(first, last) {
            if (!first && !last) return '';
            return ((first ? first[0] : '') + (last ? last[0] : '')).toUpperCase();
        }
    };
}
window.emailCampaignsApp = emailCampaignsApp;
})();
</script>

<!-- GrapesJS drag-and-drop email builder (Send email tab) -->
<link href="https://unpkg.com/grapesjs@0.21.13/dist/css/grapes.min.css" rel="stylesheet">
<script src="https://unpkg.com/grapesjs@0.21.13/dist/grapes.min.js"></script>
<script src="https://unpkg.com/grapesjs-preset-newsletter@1.0.2/dist/index.js"></script>
<script src="<?= e($basePath) ?>/public/admin/js/campaign-email-helpers.js"></script>

<style>
    [x-cloak] { display: none !important; }

    /* TailAdmin-style segmented tabs — single row, no harsh focus ring */
    .email-campaigns-tablist button:focus { outline: none; }
    .email-campaigns-tablist button:focus-visible {
        outline: 2px solid rgb(99 102 241);
        outline-offset: 2px;
    }
    .email-campaigns-tab--active {
        background: #fff;
        color: rgb(67 56 202);
        box-shadow: 0 1px 2px 0 rgb(16 24 40 / 0.06);
        border: 1px solid rgb(228 231 236);
    }
    .email-campaigns-tab--inactive {
        background: transparent;
        color: rgb(71 85 105);
        border: 1px solid transparent;
    }
    .email-campaigns-tab--inactive:hover {
        color: rgb(15 23 42);
        background: rgb(255 255 255 / 0.65);
    }
    
    /* GrapesJS email builder — keep the whole IDE inside its column (next to the 380px sidebar) */
    #campaign-body-editor { min-height: 600px; width: 100%; max-width: 100%; border: none; overflow: hidden; }
    #campaign-body-editor .gjs-editor,
    #campaign-body-editor .gjs-editor-cont { width: 100%; max-width: 100%; }
    #campaign-body-editor .gjs-cv-canvas { background: #f1f5f9; }

    /* Scrollbar Hide */
    .scrollbar-hide::-webkit-scrollbar { display: none; }
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
