// settings.js
let currentSettings = {};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadSettings();
    setupEventListeners();
});

// Load settings from API
async function loadSettings() {
    try {
        const response = await api.get('/settings');
        if (response.success) {
            currentSettings = response.data;
            populateForms();
        } else {
            Toast.error('Failed to load settings');
        }
    } catch (error) {
        console.error('Error loading settings:', error);
        Toast.error('Failed to load settings');
    }
}

// Populate forms with current settings
function populateForms() {
    // Organization settings
    if (currentSettings.organization) {
        const org = currentSettings.organization;
        document.getElementById('org-name').value = org.name || '';
        document.getElementById('org-slug').value = org.slug || '';
        document.getElementById('org-color').value = org.primary_color || '#3B82F6';
        document.getElementById('org-color-text').value = org.primary_color || '#3B82F6';
        document.getElementById('org-timezone').value = org.timezone || 'America/Indiana/Indianapolis';
        document.getElementById('org-date-format').value = org.date_format || 'Y-m-d';
        document.getElementById('org-time-format').value = org.time_format || 'H:i';
        
        // Logo preview
        if (org.logo_path) {
            const logoPreview = document.getElementById('logo-preview');
            logoPreview.src = '/Headcount/uploads/' + org.logo_path;
            logoPreview.classList.remove('hidden');
        }
    }

    // Email settings
    if (currentSettings.email) {
        const email = currentSettings.email;
        document.getElementById('email-api-key').value = email.smtp_api_key || '';
        document.getElementById('email-from').value = email.smtp_from_email || '';
        document.getElementById('email-from-name').value = email.smtp_from_name || '';
        document.getElementById('email-reply-to').value = email.smtp_reply_to || '';
    }

    // Payment settings
    if (currentSettings.payments) {
        const payments = currentSettings.payments;
        document.getElementById('stripe-publishable').value = payments.stripe_publishable_key || '';
        document.getElementById('stripe-secret').value = payments.stripe_secret_key || '';
        document.getElementById('stripe-webhook').value = payments.stripe_webhook_secret || '';
        document.getElementById('stripe-test-mode').checked = payments.stripe_test_mode !== false;
    }

    // Notification settings
    if (currentSettings.notifications) {
        const notif = currentSettings.notifications;
        document.getElementById('notifications-enabled').checked = notif.email_reminders_enabled !== false;
        document.getElementById('reminder-1week').checked = notif.reminder_1week !== false;
        document.getElementById('reminder-1day').checked = notif.reminder_1day !== false;
        document.getElementById('reminder-2hours').checked = notif.reminder_2hours !== false;
    }

    // Account settings
    if (currentSettings.account) {
        const account = currentSettings.account;
        document.getElementById('account-first-name').value = account.first_name || '';
        document.getElementById('account-last-name').value = account.last_name || '';
        document.getElementById('account-email').value = account.email || '';
    }
}

// Setup event listeners
function setupEventListeners() {
    // Color picker sync
    const colorPicker = document.getElementById('org-color');
    const colorText = document.getElementById('org-color-text');
    if (colorPicker && colorText) {
        colorPicker.addEventListener('input', function() {
            colorText.value = colorPicker.value;
        });
        colorText.addEventListener('input', function() {
            if (/^#[0-9A-F]{6}$/i.test(colorText.value)) {
                colorPicker.value = colorText.value;
            }
        });
    }

    // Logo preview
    const logoInput = document.getElementById('org-logo');
    if (logoInput) {
        logoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('logo-preview');
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Password strength indicator
    const newPassword = document.getElementById('account-new-password');
    if (newPassword) {
        newPassword.addEventListener('input', function() {
            checkPasswordStrength(this.value);
        });
    }

    // Form submissions
    document.getElementById('form-org').addEventListener('submit', handleOrgSubmit);
    document.getElementById('form-email').addEventListener('submit', handleEmailSubmit);
    document.getElementById('form-payments').addEventListener('submit', handlePaymentsSubmit);
    document.getElementById('form-notifications').addEventListener('submit', handleNotificationsSubmit);
    document.getElementById('form-account').addEventListener('submit', handleAccountSubmit);
}

// Switch tabs
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });

    // Remove active class from all tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-indigo-500', 'text-indigo-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });

    // Show selected tab content
    document.getElementById('tab-content-' + tabName).classList.remove('hidden');

    // Add active class to selected tab
    const activeTab = document.getElementById('tab-' + tabName);
    activeTab.classList.add('active', 'border-indigo-500', 'text-indigo-600');
    activeTab.classList.remove('border-transparent', 'text-gray-500');
}

// Handle organization form submission
async function handleOrgSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const colorValue = document.getElementById('org-color').value;
    formData.append('primary_color', colorValue);

    try {
        const response = await api.post('/settings/org', formData);
        if (response.success) {
            Toast.success('Organization settings saved successfully');
            await loadSettings();
        } else {
            Toast.error(response.message || 'Failed to save organization settings');
            if (response.errors) {
                response.errors.forEach(error => {
                    console.error(error.field + ': ' + error.message);
                });
            }
        }
    } catch (error) {
        console.error('Error saving organization settings:', error);
        Toast.error('Failed to save organization settings');
    }
}

// Handle email form submission
async function handleEmailSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    
    // Don't send empty API key
    if (!data.smtp_api_key) {
        delete data.smtp_api_key;
    }

    try {
        const response = await api.post('/settings/email', data);
        if (response.success) {
            Toast.success('Email settings saved successfully');
            await loadSettings();
        } else {
            Toast.error(response.message || 'Failed to save email settings');
            if (response.errors) {
                response.errors.forEach(error => {
                    console.error(error.field + ': ' + error.message);
                });
            }
        }
    } catch (error) {
        console.error('Error saving email settings:', error);
        Toast.error('Failed to save email settings');
    }
}

// Handle payments form submission
async function handlePaymentsSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    data.stripe_test_mode = document.getElementById('stripe-test-mode').checked;
    
    // Don't send empty secret keys
    if (!data.stripe_secret_key) {
        delete data.stripe_secret_key;
    }
    if (!data.stripe_webhook_secret) {
        delete data.stripe_webhook_secret;
    }

    try {
        const response = await api.post('/settings/payments', data);
        if (response.success) {
            Toast.success('Payment settings saved successfully');
            await loadSettings();
        } else {
            Toast.error(response.message || 'Failed to save payment settings');
            if (response.errors) {
                response.errors.forEach(error => {
                    console.error(error.field + ': ' + error.message);
                });
            }
        }
    } catch (error) {
        console.error('Error saving payment settings:', error);
        Toast.error('Failed to save payment settings');
    }
}

// Handle notifications form submission
async function handleNotificationsSubmit(e) {
    e.preventDefault();
    
    const data = {
        email_reminders_enabled: document.getElementById('notifications-enabled').checked,
        reminder_1week: document.getElementById('reminder-1week').checked,
        reminder_1day: document.getElementById('reminder-1day').checked,
        reminder_2hours: document.getElementById('reminder-2hours').checked
    };

    try {
        const response = await api.post('/settings/notifications', data);
        if (response.success) {
            Toast.success('Notification settings saved successfully');
            await loadSettings();
        } else {
            Toast.error(response.message || 'Failed to save notification settings');
            if (response.errors) {
                response.errors.forEach(error => {
                    console.error(error.field + ': ' + error.message);
                });
            }
        }
    } catch (error) {
        console.error('Error saving notification settings:', error);
        Toast.error('Failed to save notification settings');
    }
}

// Handle account form submission
async function handleAccountSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    
    // Don't send empty password fields
    if (!data.new_password) {
        delete data.new_password;
        delete data.confirm_password;
        delete data.current_password;
    }

    // Validate password match
    if (data.new_password && data.new_password !== data.confirm_password) {
        Toast.error('Passwords do not match');
        return;
    }

    try {
        const response = await api.post('/settings/account', data);
        if (response.success) {
            Toast.success('Account settings saved successfully');
            await loadSettings();
        } else {
            Toast.error(response.message || 'Failed to save account settings');
            if (response.errors) {
                response.errors.forEach(error => {
                    console.error(error.field + ': ' + error.message);
                });
            }
        }
    } catch (error) {
        console.error('Error saving account settings:', error);
        Toast.error('Failed to save account settings');
    }
}

// Test email configuration
async function testEmail() {
    try {
        Toast.info('Testing email configuration...');
        const response = await api.post('/settings/test-email');
        if (response.success) {
            Toast.success('Email test successful! Check your inbox.');
        } else {
            Toast.error(response.message || 'Email test failed');
        }
    } catch (error) {
        console.error('Error testing email:', error);
        Toast.error('Failed to test email configuration');
    }
}

// Test Stripe connection
async function testStripe() {
    try {
        Toast.info('Testing Stripe connection...');
        const response = await api.post('/settings/test-stripe');
        if (response.success) {
            Toast.success('Stripe connection successful!');
        } else {
            Toast.error(response.message || 'Stripe test failed');
        }
    } catch (error) {
        console.error('Error testing Stripe:', error);
        Toast.error('Failed to test Stripe connection');
    }
}

// Check password strength
function checkPasswordStrength(password) {
    const strengthDiv = document.getElementById('password-strength');
    const strengthBar = document.getElementById('password-strength-bar');
    const strengthText = document.getElementById('password-strength-text');
    
    if (!password) {
        strengthDiv.classList.add('hidden');
        return;
    }
    
    strengthDiv.classList.remove('hidden');
    
    let strength = 0;
    let feedback = [];
    
    if (password.length >= 8) strength++;
    else feedback.push('At least 8 characters');
    
    if (/[a-z]/.test(password)) strength++;
    else feedback.push('lowercase letter');
    
    if (/[A-Z]/.test(password)) strength++;
    else feedback.push('uppercase letter');
    
    if (/[0-9]/.test(password)) strength++;
    else feedback.push('number');
    
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    const strengthLabels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
    const strengthColors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-blue-500', 'bg-green-500'];
    
    strengthBar.className = 'h-full transition-all ' + strengthColors[strength - 1];
    strengthBar.style.width = (strength * 20) + '%';
    strengthText.textContent = strengthLabels[strength - 1] + (feedback.length > 0 ? ' - Needs: ' + feedback.join(', ') : '');
    strengthText.className = 'mt-1 text-xs ' + (strength < 3 ? 'text-red-600' : strength < 4 ? 'text-yellow-600' : 'text-green-600');
}

// Category Management
let currentCategoryId = null;
let categoriesData = [];

// Load categories when categories tab is shown
const originalSwitchTab = switchTab;
switchTab = function(tabName) {
    originalSwitchTab(tabName);
    if (tabName === 'categories') {
        loadCategories();
    }
};

// Load categories
async function loadCategories() {
    try {
        const response = await api.get('categories');
        if (response.success && response.data) {
            categoriesData = response.data.categories || [];
            renderCategories(categoriesData);
        } else {
            document.getElementById('categories-list').innerHTML = `
                <li class="p-4 text-center text-gray-500">Failed to load categories</li>
            `;
        }
    } catch (error) {
        console.error('Failed to load categories:', error);
        document.getElementById('categories-list').innerHTML = `
            <li class="p-4 text-center text-gray-500">Error loading categories</li>
        `;
    }
}

// Render categories list
function renderCategories(categories) {
    const categoriesList = document.getElementById('categories-list');
    
    if (categories.length === 0) {
        categoriesList.innerHTML = `
            <li class="p-4 text-center text-gray-500">
                No categories yet. <button onclick="openCategoryModal()" class="text-indigo-600 hover:text-indigo-800">Create your first category</button>
            </li>
        `;
        return;
    }
    
    categoriesList.innerHTML = categories.map(category => {
        const statusClass = category.is_active == 1 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
        return `
            <li class="p-4 hover:bg-gray-50">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <div class="w-4 h-4 rounded" style="background-color: ${category.color || '#3B82F6'}"></div>
                        <div>
                            <h4 class="text-sm font-medium text-gray-900">${escapeHtml(category.name)}</h4>
                            ${category.description ? `<p class="text-sm text-gray-500">${escapeHtml(category.description)}</p>` : ''}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-1 text-xs font-semibold rounded-full ${statusClass}">
                            ${category.is_active == 1 ? 'Active' : 'Inactive'}
                        </span>
                        <button onclick="openEditCategoryModal(${category.id})" 
                                class="px-3 py-1 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700">
                            Edit
                        </button>
                        <button onclick="openDeleteCategoryModal(${category.id})" 
                                class="px-3 py-1 text-sm bg-red-600 text-white rounded hover:bg-red-700">
                            Delete
                        </button>
                    </div>
                </div>
            </li>
        `;
    }).join('');
}

// Open category modal for create
function openCategoryModal() {
    currentCategoryId = null;
    document.getElementById('category-modal-title').textContent = 'Add Category';
    document.getElementById('category-submit-text').textContent = 'Add Category';
    document.getElementById('category-form').reset();
    document.getElementById('category-id').value = '';
    document.getElementById('category-errors').textContent = '';
    document.getElementById('category-color').value = '#3B82F6';
    document.getElementById('category-color-text').value = '#3B82F6';
    document.getElementById('category-active').checked = true;
    showModal('category-modal');
}

// Open category modal for edit
function openEditCategoryModal(categoryId) {
    const category = categoriesData.find(c => c.id == categoryId);
    if (!category) {
        Toast.error('Category not found');
        return;
    }
    
    currentCategoryId = categoryId;
    document.getElementById('category-modal-title').textContent = 'Edit Category';
    document.getElementById('category-submit-text').textContent = 'Update Category';
    document.getElementById('category-id').value = category.id;
    document.getElementById('category-name').value = category.name || '';
    document.getElementById('category-description').value = category.description || '';
    document.getElementById('category-color').value = category.color || '#3B82F6';
    document.getElementById('category-color-text').value = category.color || '#3B82F6';
    document.getElementById('category-sort-order').value = category.sort_order || 0;
    document.getElementById('category-active').checked = category.is_active == 1;
    document.getElementById('category-errors').textContent = '';
    showModal('category-modal');
}

// Close category modal
function closeCategoryModal() {
    closeModal('category-modal');
    currentCategoryId = null;
}

// Handle category form submission
async function handleCategorySubmit(event) {
    event.preventDefault();
    const form = event.target;
    const errorsDiv = document.getElementById('category-errors');
    errorsDiv.textContent = '';
    
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    if (typeof csrfToken !== 'undefined') {
        data.csrf_token = csrfToken;
    }
    
    // Convert checkbox values
    data.is_active = data.is_active ? 1 : 0;
    data.sort_order = parseInt(data.sort_order) || 0;
    
    // Sync color picker and text field
    data.color = document.getElementById('category-color').value;
    
    try {
        let response;
        if (currentCategoryId) {
            // Update existing category
            response = await api.put(`categories/${currentCategoryId}`, data);
        } else {
            // Create new category
            response = await api.post('categories', data);
        }
        
        if (response.success) {
            Toast.success(currentCategoryId ? 'Category updated successfully!' : 'Category created successfully!');
            closeCategoryModal();
            loadCategories();
        } else {
            errorsDiv.textContent = response.message || 'Failed to save category';
            if (response.errors && response.errors.length > 0) {
                errorsDiv.textContent = response.errors.map(e => e.message || e).join(', ');
            }
        }
    } catch (error) {
        errorsDiv.textContent = error.message || 'An error occurred';
        Toast.error('Failed to save category');
    }
}

// Open delete category modal
function openDeleteCategoryModal(categoryId) {
    currentCategoryId = categoryId;
    document.getElementById('delete-category-errors').textContent = '';
    showModal('delete-category-modal');
}

// Close delete category modal
function closeDeleteCategoryModal() {
    closeModal('delete-category-modal');
    currentCategoryId = null;
}

// Confirm delete category
async function confirmDeleteCategory() {
    if (!currentCategoryId) return;
    
    const errorsDiv = document.getElementById('delete-category-errors');
    errorsDiv.textContent = '';
    
    try {
        const response = await api.delete(`categories/${currentCategoryId}`);
        if (response.success) {
            Toast.success('Category deleted successfully!');
            closeDeleteCategoryModal();
            loadCategories();
        } else {
            errorsDiv.textContent = response.message || 'Failed to delete category';
        }
    } catch (error) {
        errorsDiv.textContent = error.message || 'An error occurred';
        Toast.error('Failed to delete category');
    }
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Sync color picker and text field
document.addEventListener('DOMContentLoaded', function() {
    const colorPicker = document.getElementById('category-color');
    const colorText = document.getElementById('category-color-text');
    if (colorPicker && colorText) {
        colorPicker.addEventListener('input', function() {
            colorText.value = colorPicker.value;
        });
        colorText.addEventListener('input', function() {
            if (/^#[0-9A-F]{6}$/i.test(colorText.value)) {
                colorPicker.value = colorText.value;
            }
        });
    }
});