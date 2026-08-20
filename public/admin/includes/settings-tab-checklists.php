<?php
/**
 * Settings tab: Checklist templates & roles.
 * Included from settings.php when checklists.manage_templates is allowed.
 *
 * @var int $organizationId
 * @var string $apiBase checklist API URL
 */

use Headcount\Services\EventChecklistService;

$checklistApiBase = rtrim($basePath ?? '', '/') . '/public/api/event-checklist.php';
$checklistSvc = new EventChecklistService();
$checklistSvc->ensureOrgDefaults($organizationId);
$checklistTemplates = $checklistSvc->listTemplates($organizationId);
$checklistRoles = $checklistSvc->listRoles($organizationId);
?>
<div x-show="activeTab === 'checklists'" x-cloak class="space-y-6" x-data="checklistSettingsApp({
    apiBase: <?= htmlspecialchars(json_encode($checklistApiBase), ENT_QUOTES, 'UTF-8') ?>,
    templates: <?= htmlspecialchars(json_encode($checklistTemplates), ENT_QUOTES, 'UTF-8') ?>,
    roles: <?= htmlspecialchars(json_encode($checklistRoles), ENT_QUOTES, 'UTF-8') ?>
})">
    <div class="bento-card p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1">Checklist templates</h2>
        <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Edit the default and category-specific task packs used when creating events.</p>

        <div class="mb-4">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">Template</label>
            <select x-model="selectedTemplateId" @change="loadTemplateTasks()" class="ta-select w-full max-w-md mt-1">
                <template x-for="t in templates" :key="t.id">
                    <option :value="t.id" x-text="(t.category_name ? t.category_name : t.name) + ' (' + t.task_count + ' tasks)'"></option>
                </template>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500 border-b dark:border-gray-700">
                        <th class="py-2 pr-2">Task</th>
                        <th class="py-2 pr-2">Phase</th>
                        <th class="py-2 pr-2">Section</th>
                        <th class="py-2 pr-2">Default role</th>
                        <th class="py-2 pr-2">Due offset</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="task in templateTasks" :key="task.id">
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-2 max-w-xs" x-text="task.title"></td>
                            <td class="py-2 pr-2" x-text="task.phase"></td>
                            <td class="py-2 pr-2" x-text="task.section"></td>
                            <td class="py-2 pr-2" x-text="task.role_label || '—'"></td>
                            <td class="py-2 pr-2" x-text="task.due_offset_days"></td>
                            <td class="py-2">
                                <button type="button" @click="deleteTask(task.id)" class="text-red-600 text-xs hover:underline">Delete</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="mt-6 p-4 border border-dashed border-gray-300 rounded-xl dark:border-gray-600">
            <h3 class="font-semibold text-sm mb-3">Add template task</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <input type="text" x-model="newTask.title" placeholder="Task title" class="ta-input md:col-span-2">
                <select x-model="newTask.phase" class="ta-select">
                    <option value="pre">Pre-event</option>
                    <option value="day_of">Day-of</option>
                    <option value="post">Post-event</option>
                </select>
                <input type="text" x-model="newTask.section" placeholder="Section name" class="ta-input">
                <select x-model="newTask.default_role_id" class="ta-select">
                    <option value="">Default role</option>
                    <template x-for="r in roles" :key="r.id">
                        <option :value="r.id" x-text="r.label"></option>
                    </template>
                </select>
                <input type="number" x-model="newTask.due_offset_days" placeholder="Due offset days" class="ta-input">
            </div>
            <button type="button" @click="saveNewTask()" class="btn-primary text-sm mt-3">Add task</button>
        </div>
    </div>

    <div class="bento-card p-6">
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1">Leadership roles</h2>
        <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">System roles are seeded by default. Add custom roles for your organization.</p>
        <ul class="space-y-2 mb-4">
            <template x-for="r in roles" :key="r.id">
                <li class="text-sm text-gray-700 dark:text-gray-200">
                    <span x-text="r.label"></span>
                    <span class="text-gray-400 text-xs ml-2" x-text="r.is_system == 1 ? '(system)' : '(custom)'"></span>
                </li>
            </template>
        </ul>
        <div class="flex gap-2 max-w-md">
            <input type="text" x-model="newRoleLabel" placeholder="New role label" class="ta-input flex-1">
            <button type="button" @click="addRole()" class="btn-primary text-sm shrink-0">Add role</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', function() {
    if (typeof Alpine.data('checklistSettingsApp') !== 'undefined') return;
    Alpine.data('checklistSettingsApp', function(cfg) {
        return {
            apiBase: cfg.apiBase,
            templates: cfg.templates || [],
            roles: cfg.roles || [],
            selectedTemplateId: (cfg.templates && cfg.templates[0]) ? cfg.templates[0].id : null,
            templateTasks: [],
            newTask: { title: '', phase: 'pre', section: '', default_role_id: '', due_offset_days: -7 },
            newRoleLabel: '',
            init: function() { this.loadTemplateTasks(); },
            loadTemplateTasks: async function() {
                if (!this.selectedTemplateId) return;
                const res = await fetch(this.apiBase + '?action=template_tasks&template_id=' + this.selectedTemplateId);
                const data = await res.json();
                this.templateTasks = data.tasks || [];
            },
            saveNewTask: async function() {
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_template_task',
                        template_id: this.selectedTemplateId,
                        title: this.newTask.title,
                        phase: this.newTask.phase,
                        section: this.newTask.section,
                        default_role_id: this.newTask.default_role_id || null,
                        due_offset_days: parseInt(this.newTask.due_offset_days, 10) || -7
                    })
                });
                const data = await res.json();
                if (data.success) {
                    this.newTask.title = '';
                    this.loadTemplateTasks();
                } else alert(data.message || 'Failed');
            },
            deleteTask: async function(id) {
                if (!confirm('Delete this template task?')) return;
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_template_task', task_id: id })
                });
                const data = await res.json();
                if (data.success) this.loadTemplateTasks();
                else alert(data.message || 'Failed');
            },
            addRole: async function() {
                if (!this.newRoleLabel.trim()) return;
                const res = await fetch(this.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save_role', label: this.newRoleLabel.trim() })
                });
                const data = await res.json();
                if (data.success) location.reload();
                else alert(data.message || 'Failed');
            }
        };
    });
});
</script>
