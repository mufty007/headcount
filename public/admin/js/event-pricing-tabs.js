/**
 * Group/Tier vs Ticket Types tabs on event create/edit.
 */
(function (window, document) {
    'use strict';

    function initPricingTabs(root) {
        if (!root || root.getAttribute('data-pricing-tabs-bound') === '1') {
            return;
        }
        root.setAttribute('data-pricing-tabs-bound', '1');

        var panelGroup = document.getElementById('pricing-tab-panel-group-tier');
        var panelTickets = document.getElementById('pricing-tab-panel-ticket-types');
        var triggers = root.querySelectorAll('[data-pricing-tab]');
        var activeBtn = ['border-indigo-600', 'text-indigo-700', 'bg-white'];
        var inactiveBtn = ['border-transparent', 'text-gray-500'];

        function setTab(tabId) {
            if (tabId !== 'group-tier' && tabId !== 'ticket-types') {
                return;
            }
            root.setAttribute('data-active-tab', tabId);
            triggers.forEach(function (btn) {
                var on = btn.getAttribute('data-pricing-tab') === tabId;
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
                activeBtn.forEach(function (c) { btn.classList.toggle(c, on); });
                inactiveBtn.forEach(function (c) { btn.classList.toggle(c, !on); });
                if (on) {
                    btn.classList.remove('hover:text-gray-700', 'hover:bg-gray-50/80');
                } else {
                    btn.classList.add('hover:text-gray-700', 'hover:bg-gray-50/80');
                }
            });
            if (panelGroup) {
                panelGroup.classList.toggle('hidden', tabId !== 'group-tier');
            }
            if (panelTickets) {
                panelTickets.classList.toggle('hidden', tabId !== 'ticket-types');
            }
        }

        window.eventPricingTabsActivate = setTab;

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pricing-tab]');
            if (!btn || !root.contains(btn)) {
                return;
            }
            e.preventDefault();
            setTab(btn.getAttribute('data-pricing-tab'));
        });
    }

    function boot() {
        document.querySelectorAll('#event-pricing-tabs').forEach(initPricingTabs);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.eventPricingTabsInit = boot;
})(window, document);
