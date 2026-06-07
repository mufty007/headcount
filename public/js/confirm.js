// confirm.js - Reusable confirmation dialog utility
// Prevent duplicate declaration if script is loaded multiple times
(function() {
    'use strict';
    
    // Check if already loaded
    if (typeof window.ConfirmDialog !== 'undefined' || typeof window.confirmDialog !== 'undefined') {
        return;
    }
    
class ConfirmDialog {
    constructor() {
        this.modalId = 'confirm-dialog-modal';
        this.initialized = false;
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    init() {
        // Ensure document.body exists
        if (!document.body) {
            // If body still doesn't exist, wait a bit more
            setTimeout(() => this.init(), 10);
            return;
        }

        // Create modal HTML if it doesn't exist
        if (!document.getElementById(this.modalId)) {
            const modalHTML = `
                <div id="${this.modalId}" class="modal" style="display: none;">
                    <div class="modal-overlay"></div>
                    <div class="modal-content" style="max-width: 480px;">
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 confirm-icon-container">
                                <svg class="confirm-icon h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="confirm-title mb-2 text-lg font-semibold tracking-tight text-gray-900"></h3>
                                <p class="confirm-message mb-4 text-sm leading-relaxed text-gray-600"></p>
                                <div class="confirm-input-container mb-4 hidden">
                                    <input type="text" id="confirm-input" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20" placeholder="">
                                </div>
                                <div class="flex flex-wrap justify-end gap-2 sm:gap-3">
                                    <button type="button" class="confirm-cancel-btn rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                                        Cancel
                                    </button>
                                    <button type="button" class="confirm-ok-btn rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2">
                                        Confirm
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }

        this.modal = document.getElementById(this.modalId);
        if (!this.modal) {
            // If modal still doesn't exist, something went wrong
            console.error('Failed to create confirm dialog modal');
            return;
        }

        this.overlay = this.modal.querySelector('.modal-overlay');
        this.titleEl = this.modal.querySelector('.confirm-title');
        this.messageEl = this.modal.querySelector('.confirm-message');
        this.inputContainer = this.modal.querySelector('.confirm-input-container');
        this.inputEl = this.modal.querySelector('#confirm-input');
        this.cancelBtn = this.modal.querySelector('.confirm-cancel-btn');
        this.okBtn = this.modal.querySelector('.confirm-ok-btn');
        this.iconContainer = this.modal.querySelector('.confirm-icon-container');
        this.iconEl = this.modal.querySelector('.confirm-icon');

        // Verify all elements exist
        if (!this.overlay || !this.titleEl || !this.messageEl || !this.inputContainer ||
            !this.inputEl || !this.cancelBtn || !this.okBtn || !this.iconContainer || !this.iconEl) {
            console.error('Failed to find all required elements in confirm dialog');
            return;
        }

        // Setup event listeners
        this.overlay.addEventListener('click', () => this.close(false));
        this.cancelBtn.addEventListener('click', () => this.close(false));
        this.okBtn.addEventListener('click', () => this.close(true));

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal && this.modal.classList.contains('active')) {
                this.close(false);
            }
        });

        // Handle Enter key in input
        this.inputEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                this.close(true);
            }
        });

        this.initialized = true;
    }

    show(options = {}) {
        return new Promise((resolve) => {
            // Wait for initialization if not ready
            if (!this.initialized || !this.modal) {
                // Try to initialize again
                if (!this.initialized) {
                    this.init();
                }
                // If still not ready, wait a bit
                if (!this.initialized || !this.modal) {
                    setTimeout(() => this.show(options).then(resolve), 50);
                    return;
                }
            }
            const {
                title = 'Confirm Action',
                message = 'Are you sure you want to proceed?',
                type = 'warning', // 'warning', 'danger', 'info'
                okText = 'Confirm',
                cancelText = 'Cancel',
                requireInput = false,
                inputPlaceholder = '',
                inputValue = '',
                showCancel = true
            } = options;

            // Ensure all elements exist
            if (!this.titleEl || !this.messageEl || !this.okBtn || !this.cancelBtn ||
                !this.inputContainer || !this.inputEl || !this.iconContainer || !this.iconEl) {
                console.error('Confirm dialog elements not ready');
                resolve(false);
                return;
            }

            // Set content
            this.titleEl.textContent = title;
            this.messageEl.textContent = message;
            this.okBtn.textContent = okText;
            this.cancelBtn.textContent = cancelText;

            // Show/hide cancel button
            if (showCancel && cancelText) {
                this.cancelBtn.style.display = '';
            } else {
                this.cancelBtn.style.display = 'none';
            }

            // Show/hide input
            if (requireInput) {
                this.inputContainer.classList.remove('hidden');
                this.inputEl.placeholder = inputPlaceholder;
                this.inputEl.value = inputValue;
                this.inputEl.focus();
            } else {
                this.inputContainer.classList.add('hidden');
            }

            // Set type styling
            this.setType(type);

            // Store resolve function
            this.resolve = resolve;

            // Show modal - remove inline display:none and add active class
            this.modal.style.display = ''; // Remove inline display:none
            this.modal.classList.add('active');
            // Force display to flex to ensure visibility
            this.modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Focus OK button if no input
            if (!requireInput) {
                setTimeout(() => this.okBtn.focus(), 100);
            }
        });
    }

    setType(type) {
        // Reset classes
        this.iconContainer.className =
            'confirm-icon-container flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-gray-100';
        this.okBtn.className = 'confirm-ok-btn rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2';

        switch (type) {
            case 'danger':
                this.iconContainer.classList.add('text-red-600');
                this.okBtn.classList.add('bg-red-600', 'hover:bg-red-700', 'focus:ring-red-500');
                // Update icon to trash/delete
                this.iconEl.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>';
                break;
            case 'info':
                this.iconContainer.classList.add('text-indigo-600');
                this.okBtn.classList.add('bg-indigo-600', 'hover:bg-indigo-700', 'focus:ring-indigo-500');
                // Update icon to info
                this.iconEl.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
                break;
            case 'warning':
            default:
                this.iconContainer.classList.add('text-amber-600');
                this.okBtn.classList.add('bg-amber-500', 'hover:bg-amber-600', 'focus:ring-amber-500');
                // Keep warning icon
                this.iconEl.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>';
                break;
        }
    }

    close(confirmed) {
        if (!this.modal.classList.contains('active')) return;

        this.modal.classList.remove('active');
        this.modal.style.display = 'none';
        document.body.style.overflow = '';

        // Get input value if required
        let result = confirmed;
        if (confirmed && this.inputContainer && !this.inputContainer.classList.contains('hidden')) {
            result = this.inputEl.value;
        }

        // Resolve promise
        if (this.resolve) {
            this.resolve(result);
            this.resolve = null;
        }
    }
}

// Create singleton instance (only if it doesn't exist)
var confirmDialog = new ConfirmDialog();

// Convenience function for simple confirmations
function confirmAction(options) {
    return confirmDialog.show(options);
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ConfirmDialog, confirmAction };
}

// Make available globally
window.ConfirmDialog = ConfirmDialog;
window.confirmDialog = confirmDialog;
window.confirmAction = confirmAction;

})(); // End of IIFE