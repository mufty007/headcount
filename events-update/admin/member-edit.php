<?php
/**
 * Standalone edit member (replaces modal flow)
 */
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
use Headcount\Services\MemberService;

AuthMiddleware::requireCan('members.manage');

if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

$organizationId = AuthMiddleware::getOrganizationId();
$db = Database::getInstance();
$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($memberId <= 0) {
    redirect($adminBase . '/index.php?page=members');
    exit;
}

$memberService = new MemberService();
try {
    $member = $memberService->getMember($memberId);
} catch (\Exception $e) {
    redirect($adminBase . '/index.php?page=members');
    exit;
}

if ((int) ($member['organization_id'] ?? 0) !== (int) $organizationId) {
    http_response_code(403);
    die('Unauthorized');
}

$errors = [];
$formData = [
    'first_name' => $member['first_name'] ?? '',
    'last_name' => $member['last_name'] ?? '',
    'email' => $member['email'] ?? '',
    'phone' => $member['phone'] ?? '',
    'gender' => $member['gender'] ?? '',
    'date_of_birth' => $member['date_of_birth'] ?? '',
];

if (isPost()) {
    CsrfMiddleware::verify();

    $formData = [
        'first_name' => sanitize(post('first_name')),
        'last_name' => sanitize(post('last_name')),
        'email' => sanitize(post('email')),
        'phone' => sanitize(post('phone')),
        'gender' => post('gender'),
        'date_of_birth' => post('date_of_birth') ?: null,
    ];

    if ($formData['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($formData['last_name'] === '') {
        $errors[] = 'Last name is required.';
    }
    if ($formData['email'] === '') {
        $errors[] = 'Email is required.';
    } elseif (!isValidEmail($formData['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        try {
            $memberService->updateMember($memberId, [
                'first_name' => $formData['first_name'],
                'last_name' => $formData['last_name'],
                'email' => $formData['email'],
                'phone' => $formData['phone'] ?: null,
                'gender' => $formData['gender'] ?: null,
                'date_of_birth' => $formData['date_of_birth'],
            ]);
            setFlash('success', 'Member updated successfully.');
            redirect($adminBase . '/index.php?page=member-details&id=' . $memberId);
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Member';
$currentPage = 'members';
require __DIR__ . '/includes/header.php';

$flash = getFlash();
?>

<div class="animate-fade-in max-w-2xl mx-auto">
    <?php
    $pageHeaderTitle = 'Edit Member';
    $pageHeaderSubtitle = 'Update the details for ' . e($formData['first_name'] . ' ' . $formData['last_name']) . '.';
    $pageHeaderBreadcrumb = [
        ['label' => 'Members', 'url' => $adminBase . '/index.php?page=members'],
        ['label' => $formData['first_name'] . ' ' . $formData['last_name'], 'url' => $adminBase . '/index.php?page=member-details&id=' . $memberId],
        ['label' => 'Edit'],
    ];
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if ($flash && $flash['type'] === 'success'): ?>
        <div class="ta-alert ta-alert-success mb-6">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
            <p class="font-medium"><?= e($flash['message']) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="ta-alert ta-alert-error mb-6 flex-col items-start">
            <p class="font-semibold mb-1">Please fix the following errors:</p>
            <ul class="list-disc list-inside text-sm space-y-0.5">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Multi-Step Form -->
    <!-- Step Progress -->
    <div class="multi-step-progress">
        <div class="step-item active" id="step-item-1">
            <div class="step-circle">1</div>
            <span class="step-label">Personal Info</span>
        </div>
        <div class="step-item" id="step-item-2">
            <div class="step-circle">2</div>
            <span class="step-label">Contact</span>
        </div>
        <div class="step-item" id="step-item-3">
            <div class="step-circle">3</div>
            <span class="step-label">Review</span>
        </div>
    </div>

    <form method="POST" action="" id="member-edit-form">
        <input type="hidden" name="csrf_token" value="<?= CsrfMiddleware::getToken() ?>">

        <!-- Step 1: Personal Info -->
        <div class="step-panel active" id="panel-1">
            <?php ob_start(); $formSectionCols = 2; ?>
                <div>
                    <label class="form-label" for="first_name">First Name <span class="text-red-500">*</span></label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($formData['first_name']) ?>"
                           class="ta-input w-full"
                           required>
                </div>
                <div>
                    <label class="form-label" for="last_name">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($formData['last_name']) ?>"
                           class="ta-input w-full"
                           required>
                </div>
                <div>
                    <label class="form-label" for="gender">Gender</label>
                    <select id="gender" name="gender"
                            class="ta-select w-full">
                        <option value="">Prefer not to say</option>
                        <option value="male" <?= $formData['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $formData['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= $formData['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="date_of_birth">Date of Birth</label>
                    <input type="date" id="date_of_birth" name="date_of_birth"
                           value="<?= e($formData['date_of_birth'] ? substr($formData['date_of_birth'], 0, 10) : '') ?>"
                           class="ta-input w-full">
                </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Personal Information';
            require __DIR__ . '/components/form-section.php';
            unset($formSectionCols);
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-primary" onclick="showStep(2)">
                    Next: Contact
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=member-details&id=' . $memberId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 2: Contact -->
        <div class="step-panel" id="panel-2">
            <?php ob_start(); $formSectionCols = 2; ?>
            <div class="md:col-span-2">
                <label class="form-label" for="email">Email Address <span class="text-red-500">*</span></label>
                <input type="email" id="email" name="email" value="<?= e($formData['email']) ?>"
                       class="ta-input w-full"
                       required>
                <p class="form-hint">Used for event notifications and account access.</p>
            </div>
            
            <div class="mb-4">
                <label class="form-label" for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?= e($formData['phone']) ?>"
                       class="ta-input w-full"
                       placeholder="(555) 123-4567">
                <p class="form-hint">Optional. Multiple members can share a phone number (e.g. family).</p>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Contact Information';
            require __DIR__ . '/components/form-section.php';
            unset($formSectionCols);
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" onclick="showStep(1)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" onclick="showStep(3)">
                    Review Changes
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=member-details&id=' . $memberId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 3: Review -->
        <div class="step-panel" id="panel-3">
            <?php ob_start(); ?>
            <div class="review-summary mb-6" id="member-review-summary"></div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Review Changes';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" onclick="showStep(2)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="submit" class="btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Save Changes
                </button>
                <a href="<?= e($adminBase . '/index.php?page=member-details&id=' . $memberId) ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>
    </form>
</div>

<script>
(function() {
    var currentStep = 1;
    var totalSteps = 3;

    function showStep(step) {
        currentStep = Math.max(1, Math.min(totalSteps, step));
        
        for (var i = 1; i <= totalSteps; i++) {
            var panel = document.getElementById('panel-' + i);
            var item = document.getElementById('step-item-' + i);
            if (panel) panel.classList.toggle('active', i === currentStep);
            if (item) {
                item.classList.remove('active', 'completed');
                if (i === currentStep) item.classList.add('active');
                else if (i < currentStep) item.classList.add('completed');
            }
        }
        
        if (currentStep === 3) updateReviewSummary();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function updateReviewSummary() {
        var form = document.getElementById('member-edit-form');
        if (!form) return;
        
        function val(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? (el.value || '—') : '—';
        }
        function selectText(name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (!el) return '—';
            var opt = el.options[el.selectedIndex];
            return (opt && opt.text) ? opt.text : (el.value || '—');
        }
        
        var rows = [
            ['First Name', val('first_name')],
            ['Last Name', val('last_name')],
            ['Gender', selectText('gender')],
            ['Date of Birth', val('date_of_birth')],
            ['Email', val('email')],
            ['Phone', val('phone')],
        ];
        
        var html = rows.map(function(r) {
            return '<div class="review-row"><span class="review-label">' + r[0] + '</span><span class="review-value">' + (r[1] || '—') + '</span></div>';
        }).join('');
        
        var el = document.getElementById('member-review-summary');
        if (el) el.innerHTML = html;
    }

    window.showStep = showStep;
    showStep(1);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
