<?php

/**
 * Payment History Page
 * Shows all payments for the logged-in member
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Require authentication
PortalAuthMiddleware::requireAuth();

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

$member = PortalAuthMiddleware::getMember();

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$apiBase = $baseUrlPath . '/api/portal/';

// Set page title
$pageTitle = 'Payment History';

// Include header
require __DIR__ . '/includes/header.php';
?>

<div class="mb-5 md:mb-8">
    <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Payment History</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">View your payment transactions</p>
</div>

<div class="mb-8">
        <!-- Payment Summary -->
        <div class="bento-card mb-5 md:mb-8 !p-4 sm:!p-6">
            <h2 class="text-base sm:text-xl font-semibold text-gray-900 dark:text-white mb-3 sm:mb-4">Summary</h2>
            <div class="grid grid-cols-3 gap-2 sm:gap-6">
                <div class="rounded-xl bg-gray-50 dark:bg-gray-800/60 p-3 sm:p-0 sm:bg-transparent">
                    <div class="text-[10px] sm:text-sm font-medium text-gray-500 dark:text-gray-400">Paid</div>
                    <div id="total-paid" class="text-lg sm:text-3xl font-bold text-green-600 dark:text-green-300">$0.00</div>
                </div>
                <div class="rounded-xl bg-gray-50 dark:bg-gray-800/60 p-3 sm:p-0 sm:bg-transparent">
                    <div class="text-[10px] sm:text-sm font-medium text-gray-500 dark:text-gray-400">Refunded</div>
                    <div id="total-refunded" class="text-lg sm:text-3xl font-bold text-red-600 dark:text-red-300">$0.00</div>
                </div>
                <div class="rounded-xl bg-gray-50 dark:bg-gray-800/60 p-3 sm:p-0 sm:bg-transparent">
                    <div class="text-[10px] sm:text-sm font-medium text-gray-500 dark:text-gray-400">Count</div>
                    <div id="total-transactions" class="text-lg sm:text-3xl font-bold text-gray-900 dark:text-white">0</div>
                </div>
            </div>
        </div>

        <!-- Payments List -->
        <div class="bento-card !p-0 overflow-hidden">
            <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base sm:text-xl font-semibold text-gray-900 dark:text-white">All Payments</h2>
            </div>
            <div id="payments-list" class="p-4 sm:p-6">
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">Loading payments...</div>
            </div>
        </div>
    </div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;

        // Load payment history
        async function loadPayments() {
            try {
                const response = await fetch(apiBase + 'payments/history');
                const data = await response.json();

                if (data.success) {
                    displayPayments(data.payments || []);
                    calculateSummary(data.payments || []);
                } else {
                    document.getElementById('payments-list').innerHTML = 
                        '<div class="text-center py-8 text-red-500">Error loading payments</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('payments-list').innerHTML = 
                    '<div class="text-center py-8 text-red-500">Error loading payments</div>';
            }
        }

        function displayPayments(payments) {
            const container = document.getElementById('payments-list');
            
            if (payments.length === 0) {
                container.innerHTML = '<div class="text-center py-8 text-gray-500 dark:text-gray-400">No payments found</div>';
                return;
            }

            container.innerHTML = payments.map(payment => {
                const dateStr = headcountFormatEventDate(payment.event_date);
                const paymentDate = new Date(payment.created_at);
                const paymentDateStr = paymentDate.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                });
                
                const statusColors = {
                    'paid': 'bg-green-100 dark:bg-green-500/15 text-green-800 dark:text-green-300',
                    'pending': 'bg-yellow-100 dark:bg-yellow-500/15 text-yellow-800 dark:text-yellow-300',
                    'refunded': 'bg-red-100 dark:bg-red-500/15 text-red-800 dark:text-red-300',
                    'failed': 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-100'
                };
                
                const statusColor = statusColors[payment.status] || 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-100';
                
                return `
                    <div class="border-b border-gray-200 dark:border-gray-700 py-4 last:border-b-0">
                        <div class="flex flex-col gap-3">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white leading-snug min-w-0">${escapeHtml(payment.event_title || 'Event')}</h3>
                                <span class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full ${statusColor}">
                                    ${payment.status.charAt(0).toUpperCase() + payment.status.slice(1)}
                                </span>
                            </div>
                            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                <div>Event Date: ${dateStr}</div>
                                <div>Payment Date: ${paymentDateStr}</div>
                                <div>Amount: <span class="font-semibold">$${parseFloat(payment.amount).toFixed(2)}</span></div>
                                ${payment.refund_amount > 0 ? `<div>Refunded: <span class="font-semibold text-red-600 dark:text-red-300">$${parseFloat(payment.refund_amount).toFixed(2)}</span></div>` : ''}
                            </div>
                            <button onclick="downloadReceipt(${payment.id})" 
                                    class="w-full sm:w-auto min-h-[44px] inline-flex items-center justify-center px-4 py-2.5 text-sm font-semibold bg-blue-600 text-white rounded-xl hover:bg-blue-700">
                                View Receipt
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function calculateSummary(payments) {
            let totalPaid = 0;
            let totalRefunded = 0;
            
            payments.forEach(payment => {
                if (payment.status === 'paid') {
                    totalPaid += parseFloat(payment.amount || 0);
                }
                if (payment.refund_amount > 0) {
                    totalRefunded += parseFloat(payment.refund_amount || 0);
                }
            });
            
            document.getElementById('total-paid').textContent = '$' + totalPaid.toFixed(2);
            document.getElementById('total-refunded').textContent = '$' + totalRefunded.toFixed(2);
            document.getElementById('total-transactions').textContent = payments.length;
        }

        async function downloadReceipt(paymentId) {
            try {
                const response = await fetch(apiBase + 'payments/receipt/' + paymentId);
                const html = await response.text();
                
                if (html && !html.includes('error')) {
                    // Open receipt in new window for printing
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write(html);
                    printWindow.document.close();
                    printWindow.focus();
                    // Auto-print dialog
                    setTimeout(() => {
                        printWindow.print();
                    }, 500);
                } else {
                    alert('Receipt not available');
                }
            } catch (error) {
                console.error('Error downloading receipt:', error);
                alert('Error loading receipt');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load payments on page load
        document.addEventListener('DOMContentLoaded', loadPayments);
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
