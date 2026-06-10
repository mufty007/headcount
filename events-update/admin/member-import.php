<?php
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

// Calculate base path if not set (use same logic as index.php)
if (!isset($basePath)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/admin/';
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Try to determine base path from script name first (more reliable)
    if (strpos($scriptName, '/headcount/') !== false) {
        // Extract base path from script name
        $basePath = preg_replace('#/admin/.*$#', '', $scriptName);
        $basePath = rtrim($basePath, '/');
    } else {
        // Fallback: extract from request URI
        $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
        $basePath = rtrim($basePath, '/');
    }
    
    // If base path is empty or just '/', check if we're in a subdirectory
    if (empty($basePath) || $basePath === '/') {
        // Check if DOCUMENT_ROOT suggests we're in a subdirectory
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
        
        // If script is in a subdirectory relative to document root
        if (strpos($scriptDir, $docRoot) === 0) {
            $relativePath = str_replace($docRoot, '', dirname($scriptDir));
            if (!empty($relativePath) && $relativePath !== '/') {
                $basePath = str_replace('\\', '/', $relativePath);
                $basePath = rtrim($basePath, '/');
            }
        }
    }
    
    // Ensure basePath starts with / if it's not empty
    if (!empty($basePath) && $basePath[0] !== '/') {
        $basePath = '/' . $basePath;
    }
}

if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

// Ensure assetsBase is set correctly
if (!isset($assetsBase)) {
    // If basePath contains /public, assets are relative to it
    if (strpos($basePath, '/public') !== false) {
        $assetsBase = $basePath . '/assets/';
    } else {
        $assetsBase = $basePath . '/public/assets/';
    }
}

// Load helpers

use Headcount\Helpers\Auth;
use Headcount\Helpers\Database;
use Headcount\Middleware\CsrfMiddleware;

// Initialize database
$config = require __DIR__ . '/../../config/config.php';
Database::getInstance($config['database']);

$auth = new Auth();
$auth->requireLogin();
\Headcount\Middleware\AuthMiddleware::requireCan('members.import');

$db = Database::getInstance();
$authUser = $auth->getCurrentUser();

// Format user data for header
$user = [
    'name' => trim(($authUser['first_name'] ?? '') . ' ' . ($authUser['last_name'] ?? '')) ?: 'Administrator',
    'email' => $authUser['email'] ?? 'admin@headcount.local'
];

$pageTitle = 'Import Members';
$currentPage = 'members';
$csrfToken = CsrfMiddleware::getToken();
require __DIR__ . '/includes/header.php';
?>

<div x-data="importApp()" x-init="init()">
    
    <?php
    $pageHeaderTitle = 'Import Members from CSV';
    $pageHeaderSubtitle = 'Upload a CSV file, map columns, and bulk-import members.';
    $pageHeaderBreadcrumb = [
        ['label' => 'Members', 'url' => $adminBase . '/?page=members'],
        ['label' => 'Import'],
    ];
    require __DIR__ . '/components/page-header.php';
    ?>

    <!-- Step Indicator -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4 flex-1">
                <!-- Step 1 -->
                <div class="flex items-center">
                    <div :class="step >= 1 ? 'bg-brand-600 text-white' : 'bg-gray-300 text-gray-600'" 
                         class="w-10 h-10 rounded-full flex items-center justify-center font-bold">
                        1
                    </div>
                    <span class="ml-2 font-medium text-gray-700 dark:text-gray-200">Upload File</span>
                </div>
                <div class="flex-1 h-1 bg-gray-300 mx-4">
                    <div :class="step >= 2 ? 'bg-brand-600' : 'bg-gray-300'" 
                         class="h-full transition-all duration-300" 
                         :style="'width: ' + (step >= 2 ? '100%' : '0%')"></div>
                </div>
                
                <!-- Step 2 -->
                <div class="flex items-center">
                    <div :class="step >= 2 ? 'bg-brand-600 text-white' : 'bg-gray-300 text-gray-600'" 
                         class="w-10 h-10 rounded-full flex items-center justify-center font-bold">
                        2
                    </div>
                    <span class="ml-2 font-medium text-gray-700 dark:text-gray-200">Map Columns</span>
                </div>
                <div class="flex-1 h-1 bg-gray-300 mx-4">
                    <div :class="step >= 3 ? 'bg-brand-600' : 'bg-gray-300'" 
                         class="h-full transition-all duration-300" 
                         :style="'width: ' + (step >= 3 ? '100%' : '0%')"></div>
                </div>
                
                <!-- Step 3 -->
                <div class="flex items-center">
                    <div :class="step >= 3 ? 'bg-brand-600 text-white' : 'bg-gray-300 text-gray-600'" 
                         class="w-10 h-10 rounded-full flex items-center justify-center font-bold">
                        3
                    </div>
                    <span class="ml-2 font-medium text-gray-700 dark:text-gray-200">Review & Import</span>
                </div>
            </div>
        </div>
    </div>

    <!-- STEP 1: Upload File -->
    <div x-show="step === 1" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-8">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90 mb-4">Step 1: Upload CSV File</h2>
        
        <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50/80 p-4">
            <h3 class="mb-2 font-medium text-brand-900">📋 CSV Format Requirements:</h3>
            <ul class="ml-4 list-disc space-y-1 text-sm text-brand-900/90">
                <li>File must be in CSV format (.csv)</li>
                <li>First row should contain column headers</li>
                <li>Required columns: First Name, Last Name</li>
                <li>Optional columns: Email, Phone, Gender</li>
                <li>Maximum file size: 10MB</li>
            </ul>
        </div>

        <!-- Download Sample Template -->
        <div class="mb-6">
            <button type="button" @click="downloadSample()" class="flex items-center space-x-2 text-sm font-medium text-brand-600 hover:text-brand-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span>Download Sample CSV Template</span>
            </button>
        </div>

        <!-- File Upload Area -->
        <div 
            @drop.prevent="handleFileDrop($event)"
            @dragover.prevent
            @dragenter.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            :class="dragging ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-gray-200 dark:border-gray-700'"
            class="rounded-2xl border-2 border-dashed bg-gray-50/50 p-12 text-center transition-colors dark:bg-white/[0.02]"
        >
            <input 
                type="file" 
                id="csvFile" 
                accept=".csv"
                @change="handleFileSelect($event)"
                class="hidden"
            >
            
            <div x-show="!fileName">
                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                </svg>
                <p class="text-gray-600 mb-2 dark:text-gray-300">Drag and drop your CSV file here, or</p>
                <label for="csvFile" class="btn-primary inline-block cursor-pointer">
                    Choose File
                </label>
            </div>

            <div x-show="fileName" class="space-y-4">
                <svg class="w-16 h-16 text-green-500 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-gray-800 font-medium dark:text-gray-100" x-text="fileName"></p>
                <p class="text-sm text-gray-500 dark:text-gray-400" x-text="fileSize"></p>
                <button @click="removeFile()" class="text-red-600 hover:text-red-800 text-sm">
                    Remove file
                </button>
            </div>
        </div>

        <div x-show="uploadError" class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <span x-text="uploadError"></span>
        </div>

        <div class="mt-6 flex justify-end">
            <button 
                @click="parseCSV()"
                :disabled="!fileName"
                class="btn-primary disabled:cursor-not-allowed disabled:opacity-50"
            >
                Continue to Column Mapping →
            </button>
        </div>
    </div>

    <!-- STEP 2: Map Columns -->
    <div x-show="step === 2" class="bento-card p-8">
        <h2 class="text-xl font-bold text-gray-800 mb-4 dark:text-gray-100">Step 2: Map CSV Columns to Database Fields</h2>
        
        <p class="text-gray-600 mb-6 dark:text-gray-300">Match your CSV columns to the correct database fields</p>

        <div class="space-y-4">
            <!-- First Name Mapping -->
            <div class="grid grid-cols-3 gap-4 items-center">
                <div class="font-medium text-gray-700 dark:text-gray-200">First Name <span class="text-red-500">*</span></div>
                <select x-model="columnMapping.first_name" class="col-span-2 border border-gray-300 rounded-lg px-4 py-2">
                    <option value="">-- Select Column --</option>
                    <template x-for="col in csvHeaders" :key="col">
                        <option :value="col" x-text="col"></option>
                    </template>
                </select>
            </div>

            <!-- Last Name Mapping -->
            <div class="grid grid-cols-3 gap-4 items-center">
                <div class="font-medium text-gray-700 dark:text-gray-200">Last Name <span class="text-red-500">*</span></div>
                <select x-model="columnMapping.last_name" class="col-span-2 border border-gray-300 rounded-lg px-4 py-2">
                    <option value="">-- Select Column --</option>
                    <template x-for="col in csvHeaders" :key="col">
                        <option :value="col" x-text="col"></option>
                    </template>
                </select>
            </div>

            <!-- Email Mapping -->
            <div class="grid grid-cols-3 gap-4 items-center">
                <div class="font-medium text-gray-700 dark:text-gray-200">Email</div>
                <select x-model="columnMapping.email" class="col-span-2 border border-gray-300 rounded-lg px-4 py-2">
                    <option value="">-- Select Column (Optional) --</option>
                    <template x-for="col in csvHeaders" :key="col">
                        <option :value="col" x-text="col"></option>
                    </template>
                </select>
            </div>

            <!-- Phone Mapping -->
            <div class="grid grid-cols-3 gap-4 items-center">
                <div class="font-medium text-gray-700 dark:text-gray-200">Phone</div>
                <select x-model="columnMapping.phone" class="col-span-2 border border-gray-300 rounded-lg px-4 py-2">
                    <option value="">-- Select Column (Optional) --</option>
                    <template x-for="col in csvHeaders" :key="col">
                        <option :value="col" x-text="col"></option>
                    </template>
                </select>
            </div>

            <!-- Gender Mapping -->
            <div class="grid grid-cols-3 gap-4 items-center">
                <div class="font-medium text-gray-700 dark:text-gray-200">Gender</div>
                <select x-model="columnMapping.gender" class="col-span-2 border border-gray-300 rounded-lg px-4 py-2">
                    <option value="">-- Select Column (Optional) --</option>
                    <template x-for="col in csvHeaders" :key="col">
                        <option :value="col" x-text="col"></option>
                    </template>
                </select>
            </div>
        </div>

        <!-- Preview -->
        <div class="mt-8">
            <h3 class="font-bold text-gray-800 mb-3 dark:text-gray-100">Preview (First 5 Rows)</h3>
            <div class="overflow-x-auto">
                <table class="w-full border border-gray-200 dark:border-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="border px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-200">First Name</th>
                            <th class="border px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-200">Last Name</th>
                            <th class="border px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-200">Email</th>
                            <th class="border px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-200">Phone</th>
                            <th class="border px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-200">Gender</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, index) in previewData" :key="index">
                            <tr>
                                <td class="border px-4 py-2 text-sm" x-text="row[columnMapping.first_name] || '-'"></td>
                                <td class="border px-4 py-2 text-sm" x-text="row[columnMapping.last_name] || '-'"></td>
                                <td class="border px-4 py-2 text-sm" x-text="row[columnMapping.email] || '-'"></td>
                                <td class="border px-4 py-2 text-sm" x-text="row[columnMapping.phone] || '-'"></td>
                                <td class="border px-4 py-2 text-sm" x-text="row[columnMapping.gender] || '-'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 flex justify-between">
            <button 
                @click="step = 1"
                class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg font-medium dark:text-gray-100"
            >
                ← Back
            </button>
            <button 
                @click="validateAndContinue()"
                :disabled="!columnMapping.first_name || !columnMapping.last_name"
                class="rounded-xl bg-brand-600 px-6 py-2 font-medium text-white shadow-sm transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Continue to Review →
            </button>
        </div>
    </div>

    <!-- STEP 3: Review & Import -->
    <div x-show="step === 3" class="bento-card p-8">
        <h2 class="text-xl font-bold text-gray-800 mb-4 dark:text-gray-100">Step 3: Review & Import</h2>

        <!-- Duplicate Handling -->
        <div class="ta-alert ta-alert-warning mb-6 flex-col items-start">
            <h3 class="font-medium mb-3">⚠️ How should we handle duplicates?</h3>
            <div class="space-y-2">
                <label class="flex items-center">
                    <input type="radio" x-model="duplicateAction" value="skip" class="mr-2">
                    <span class="text-sm">Skip duplicates (keep existing records)</span>
                </label>
                <label class="flex items-center">
                    <input type="radio" x-model="duplicateAction" value="update" class="mr-2">
                    <span class="text-sm">Update duplicates (overwrite with new data)</span>
                </label>
                <label class="flex items-center">
                    <input type="radio" x-model="duplicateAction" value="create" class="mr-2">
                    <span class="text-sm">Create new records (allow duplicates)</span>
                </label>
            </div>
            <p class="text-xs mt-2 opacity-80">Duplicates are detected by matching email or phone number</p>
        </div>

        <!-- Import Summary -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="rounded-xl bg-brand-50/80 p-4">
                <div class="text-3xl font-bold text-brand-600" x-text="csvData.length"></div>
                <div class="text-sm text-brand-900/90">Total Rows</div>
            </div>
            <div class="bg-green-50 rounded-lg p-4">
                <div class="text-3xl font-bold text-green-600" x-text="validRows"></div>
                <div class="text-sm text-green-800">Valid Rows</div>
            </div>
            <div class="bg-red-50 rounded-lg p-4">
                <div class="text-3xl font-bold text-red-600" x-text="csvData.length - validRows"></div>
                <div class="text-sm text-red-800">Invalid Rows</div>
            </div>
        </div>

        <!-- Validation Errors -->
        <div x-show="validationErrors.length > 0" class="ta-alert ta-alert-error mb-6 flex-col items-start">
            <h3 class="font-medium mb-2">Validation Errors:</h3>
            <ul class="text-sm space-y-1 ml-4 list-disc max-h-40 overflow-y-auto">
                <template x-for="error in validationErrors" :key="error">
                    <li x-text="error"></li>
                </template>
            </ul>
        </div>

        <!-- Import Progress -->
        <div x-show="importing" class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Importing...</span>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200" x-text="importProgress + '%'"></span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-4">
                <div 
                    class="h-4 rounded-full bg-brand-600 transition-all duration-300"
                    :style="'width: ' + importProgress + '%'"
                ></div>
            </div>
        </div>

        <!-- Import Result -->
        <div x-show="importComplete" class="ta-alert ta-alert-success mb-6 flex-col items-start">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="font-medium">Import Complete!</span>
            </div>
            <div class="text-sm">
                <p><strong x-text="importResult.imported"></strong> members imported successfully</p>
                <p><strong x-text="importResult.skipped"></strong> duplicates skipped</p>
                <p><strong x-text="importResult.errors"></strong> errors</p>
            </div>
        </div>

        <div class="flex justify-between">
            <button 
                @click="step = 2"
                :disabled="importing"
                class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg font-medium disabled:opacity-50 dark:text-gray-100"
            >
                ← Back
            </button>
            <div class="space-x-2">
                <button 
                    x-show="!importComplete"
                    @click="startImport()"
                    :disabled="importing || validRows === 0"
                    class="rounded-xl bg-brand-600 px-6 py-2 font-medium text-white shadow-sm transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Start Import
                </button>
                <a 
                    x-show="importComplete"
                    href="<?= e($adminBase . '/?page=members') ?>"
                    class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg font-medium inline-block"
                >
                    View Members →
                </a>
            </div>
        </div>
    </div>

</div>

<script>
function importApp() {
    return {
        step: 1,
        fileName: '',
        fileSize: '',
        file: null,
        dragging: false,
        uploadError: '',
        
        csvHeaders: [],
        csvData: [],
        previewData: [],
        
        columnMapping: {
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            gender: ''
        },
        
        duplicateAction: 'skip',
        validRows: 0,
        validationErrors: [],
        
        importing: false,
        importProgress: 0,
        importComplete: false,
        importResult: {
            imported: 0,
            skipped: 0,
            errors: 0
        },
        
        init() {
            // Auto-detect column mapping when CSV is loaded
        },
        
        handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                this.processFile(file);
            }
        },
        
        handleFileDrop(event) {
            this.dragging = false;
            const file = event.dataTransfer.files[0];
            if (file) {
                this.processFile(file);
            }
        },
        
        processFile(file) {
            this.uploadError = '';
            
            // Validate file type
            if (!file.name.endsWith('.csv')) {
                this.uploadError = 'Please upload a CSV file';
                return;
            }
            
            // Validate file size (10MB)
            if (file.size > 10 * 1024 * 1024) {
                this.uploadError = 'File size must be less than 10MB';
                return;
            }
            
            this.file = file;
            this.fileName = file.name;
            this.fileSize = this.formatFileSize(file.size);
        },
        
        formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' bytes';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },
        
        removeFile() {
            this.file = null;
            this.fileName = '';
            this.fileSize = '';
            document.getElementById('csvFile').value = '';
        },
        
        parseCSV() {
            if (!this.file) return;
            
            const reader = new FileReader();
            reader.onload = (e) => {
                const text = e.target.result;
                const lines = text.split('\n').filter(line => line.trim());
                
                if (lines.length < 2) {
                    this.uploadError = 'CSV file must have at least 2 rows (header + data)';
                    return;
                }
                
                // Parse headers
                this.csvHeaders = this.parseCSVLine(lines[0]);
                
                // Parse data
                this.csvData = [];
                for (let i = 1; i < lines.length; i++) {
                    const values = this.parseCSVLine(lines[i]);
                    const row = {};
                    this.csvHeaders.forEach((header, index) => {
                        row[header] = values[index] || '';
                    });
                    this.csvData.push(row);
                }
                
                // Auto-detect column mapping
                this.autoDetectColumns();
                
                // Set preview data (first 5 rows)
                this.previewData = this.csvData.slice(0, 5);
                
                // Move to step 2
                this.step = 2;
            };
            
            reader.readAsText(this.file);
        },
        
        parseCSVLine(line) {
            const result = [];
            let current = '';
            let inQuotes = false;
            
            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                
                if (char === '"') {
                    inQuotes = !inQuotes;
                } else if (char === ',' && !inQuotes) {
                    result.push(current.trim());
                    current = '';
                } else {
                    current += char;
                }
            }
            result.push(current.trim());
            
            return result;
        },
        
        autoDetectColumns() {
            // Try to auto-detect common column names
            const headerLower = this.csvHeaders.map(h => h.toLowerCase());
            
            // First Name
            const firstNameVariations = ['first name', 'firstname', 'first', 'fname', 'given name'];
            const firstNameMatch = this.csvHeaders.find((h, i) => 
                firstNameVariations.includes(headerLower[i])
            );
            if (firstNameMatch) this.columnMapping.first_name = firstNameMatch;
            
            // Last Name
            const lastNameVariations = ['last name', 'lastname', 'last', 'lname', 'surname', 'family name'];
            const lastNameMatch = this.csvHeaders.find((h, i) => 
                lastNameVariations.includes(headerLower[i])
            );
            if (lastNameMatch) this.columnMapping.last_name = lastNameMatch;
            
            // Email
            const emailVariations = ['email', 'e-mail', 'email address'];
            const emailMatch = this.csvHeaders.find((h, i) => 
                emailVariations.includes(headerLower[i])
            );
            if (emailMatch) this.columnMapping.email = emailMatch;
            
            // Phone
            const phoneVariations = ['phone', 'phone number', 'mobile', 'cell', 'telephone'];
            const phoneMatch = this.csvHeaders.find((h, i) => 
                phoneVariations.includes(headerLower[i])
            );
            if (phoneMatch) this.columnMapping.phone = phoneMatch;
            
            // Gender
            const genderVariations = ['gender', 'sex'];
            const genderMatch = this.csvHeaders.find((h, i) => 
                genderVariations.includes(headerLower[i])
            );
            if (genderMatch) this.columnMapping.gender = genderMatch;
        },
        
        validateAndContinue() {
            this.validationErrors = [];
            this.validRows = 0;
            
            // Validate each row
            this.csvData.forEach((row, index) => {
                const rowNum = index + 2; // +2 because row 1 is header
                
                const firstName = row[this.columnMapping.first_name]?.trim();
                const lastName = row[this.columnMapping.last_name]?.trim();
                const email = row[this.columnMapping.email]?.trim();
                
                if (!firstName) {
                    this.validationErrors.push(`Row ${rowNum}: Missing first name`);
                    return;
                }
                
                if (!lastName) {
                    this.validationErrors.push(`Row ${rowNum}: Missing last name`);
                    return;
                }
                
                if (email && !this.isValidEmail(email)) {
                    this.validationErrors.push(`Row ${rowNum}: Invalid email format (${email})`);
                    return;
                }
                
                this.validRows++;
            });
            
            this.step = 3;
        },
        
        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },
        
        async startImport() {
            this.importing = true;
            this.importProgress = 0;
            
            const apiBase = '<?= e($basePath) ?>/public/api';
            const batchSize = 50;
            let imported = 0;
            let skipped = 0;
            let errors = 0;
            
            for (let i = 0; i < this.csvData.length; i += batchSize) {
                const batch = this.csvData.slice(i, i + batchSize);
                
                // Prepare batch data
                const members = batch.map(row => ({
                    first_name: row[this.columnMapping.first_name]?.trim(),
                    last_name: row[this.columnMapping.last_name]?.trim(),
                    email: row[this.columnMapping.email]?.trim() || null,
                    phone: row[this.columnMapping.phone]?.trim() || null,
                    gender: row[this.columnMapping.gender]?.toLowerCase() || null
                })).filter(m => m.first_name && m.last_name);
                
                try {
                    const response = await fetch(apiBase + '/import-members.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': '<?= e($csrfToken) ?>'
                        },
                        body: JSON.stringify({
                            members: members,
                            duplicate_action: this.duplicateAction,
                            csrf_token: '<?= e($csrfToken) ?>'
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        imported += data.imported;
                        skipped += data.skipped;
                        errors += data.errors;
                    }
                } catch (error) {
                    console.error('Import error:', error);
                    errors += batch.length;
                }
                
                this.importProgress = Math.round(((i + batch.length) / this.csvData.length) * 100);
            }
            
            this.importResult = { imported, skipped, errors };
            this.importing = false;
            this.importComplete = true;
        },
        
        downloadSample() {
            const csv = 'First Name,Last Name,Email,Phone,Gender\nJohn,Smith,john.smith@email.com,(555) 123-4567,Male\nJane,Doe,jane.doe@email.com,(555) 987-6543,Female\nBob,Johnson,bob.j@email.com,(555) 555-5555,Male';
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'member-import-sample.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
    }
}
</script>

<style>
    [x-cloak] { display: none !important; }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
