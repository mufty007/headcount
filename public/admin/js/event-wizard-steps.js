/**
 * Multi-step navigation for event create (7 steps) and event edit (6 steps).
 */
(function (window, document) {
    'use strict';

    var mode = null;
    var currentStep = 1;

    function detectMode() {
        if (document.getElementById('event-edit-form')) {
            return 'edit';
        }
        if (document.getElementById('event-create-form')) {
            return 'create';
        }
        return null;
    }

    function totalSteps() {
        return mode === 'edit' ? 6 : 7;
    }

    function wizardRoot() {
        return document.querySelector('.admin-event-wizard');
    }

    function updateStepIndicators(step) {
        var total = totalSteps();
        for (var i = 1; total >= i; i++) {
            var item = document.getElementById('step-item-' + i);
            if (!item) {
                continue;
            }
            item.classList.remove('active', 'completed');
            if (i === step) {
                item.classList.add('active');
            } else if (step > i) {
                item.classList.add('completed');
            }
        }
    }

    function showEditStep(step) {
        var total = totalSteps();
        for (var i = 1; total >= i; i++) {
            var panel = document.getElementById('panel-' + i);
            if (panel) {
                panel.classList.toggle('active', i === step);
            }
        }
        if (step === total && typeof window.eventEditUpdateReviewSummary === 'function') {
            window.eventEditUpdateReviewSummary();
        }
        if (step === 5 && typeof window.eventEditValidateTeamStep === 'function') {
            window.eventEditValidateTeamStep();
        }
    }

    function showCreateStep(step) {
        document.querySelectorAll('.event-step').forEach(function (el) {
            var s = parseInt(el.getAttribute('data-step'), 10);
            el.classList.toggle('hidden', s !== step);
        });
        document.querySelectorAll('.event-step-nav').forEach(function (el) {
            var s = parseInt(el.getAttribute('data-step'), 10);
            el.classList.toggle('hidden', s !== step);
        });
        if (step === 7 && typeof window.eventCreateUpdateReviewSummary === 'function') {
            window.eventCreateUpdateReviewSummary();
        }
        if (step === 6 && typeof window.eventCreateValidateTeamStep === 'function') {
            window.eventCreateValidateTeamStep();
        }
    }

    function showStep(step) {
        if (!mode) {
            mode = detectMode();
        }
        if (!mode) {
            return;
        }

        var total = totalSteps();
        currentStep = Math.max(1, Math.min(total, parseInt(step, 10) || 1));

        if (mode === 'edit') {
            showEditStep(currentStep);
        } else {
            showCreateStep(currentStep);
        }

        updateStepIndicators(currentStep);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    window.showStep = showStep;

    function stepFromProgressItem(el) {
        if (!el) {
            return null;
        }
        var raw = el.getAttribute('data-wizard-step');
        if (raw) {
            return parseInt(raw, 10);
        }
        var id = el.id || '';
        var match = id.match(/step-item-(\d+)/);
        return match ? parseInt(match[1], 10) : null;
    }

    function handleWizardClick(e) {
        if (!mode) {
            mode = detectMode();
        }
        if (!mode) {
            return;
        }

        if (e.target.closest('[data-pricing-tab]')) {
            return;
        }

        var root = wizardRoot();
        if (!root || !root.contains(e.target)) {
            return;
        }

        var progressItem = e.target.closest('.step-item[data-wizard-step], .step-item[id^="step-item-"]');
        if (progressItem && root.contains(progressItem)) {
            var progressStep = stepFromProgressItem(progressItem);
            if (progressStep) {
                e.preventDefault();
                showStep(progressStep);
                return;
            }
        }

        var goto = e.target.closest('[data-goto-step]');
        if (goto && goto.closest('#event-edit-form, #event-create-form')) {
            e.preventDefault();
            showStep(goto.getAttribute('data-goto-step'));
            return;
        }

        if (mode === 'create') {
            var form = document.getElementById('event-create-form');
            if (!form || !form.contains(e.target)) {
                return;
            }
            if (e.target.closest('.event-step-next')) {
                e.preventDefault();
                if (currentStep === 6 && typeof window.eventCreateTeamStepOk === 'function') {
                    if (!window.eventCreateTeamStepOk()) {
                        return;
                    }
                }
                showStep(currentStep + 1);
            } else if (e.target.closest('.event-step-back') || e.target.id === 'event-step-back-6' || e.target.id === 'event-step-back-7') {
                e.preventDefault();
                showStep(currentStep - 1);
            }
        } else if (mode === 'edit') {
            var editForm = document.getElementById('event-edit-form');
            if (!editForm || !editForm.contains(e.target)) {
                return;
            }
            var editNext = e.target.closest('.event-edit-step-next');
            if (editNext) {
                e.preventDefault();
                if (currentStep === 5 && typeof window.eventEditTeamStepOk === 'function') {
                    if (!window.eventEditTeamStepOk()) {
                        return;
                    }
                }
                showStep(currentStep + 1);
            }
        }
    }

    function handleWizardKeydown(e) {
        if (e.key !== 'Enter' && e.key !== ' ') {
            return;
        }
        var root = wizardRoot();
        var progressItem = e.target.closest('.step-item[data-wizard-step], .step-item[id^="step-item-"]');
        if (!root || !progressItem || !root.contains(progressItem)) {
            return;
        }
        var progressStep = stepFromProgressItem(progressItem);
        if (!progressStep) {
            return;
        }
        e.preventDefault();
        showStep(progressStep);
    }

    function bindNavigation() {
        mode = detectMode();
        if (!mode) {
            return;
        }

        document.addEventListener('click', handleWizardClick, true);
        document.addEventListener('keydown', handleWizardKeydown, true);
        showStep(1);

        if (typeof window.eventPricingTabsInit === 'function') {
            window.eventPricingTabsInit();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindNavigation);
    } else {
        bindNavigation();
    }
})(window, document);
