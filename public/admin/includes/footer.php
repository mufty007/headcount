<?php
if (!isset($APP_NAME)) { $APP_NAME = 'IMCA'; }
if (!isset($user)) {
    $user = [
        'name'  => $_SESSION['user_name'] ?? $_SESSION['name'] ?? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Admin',
        'email' => $_SESSION['user_email'] ?? $_SESSION['email'] ?? 'admin@headcount.local',
    ];
}
if (!isset($adminBase) || !isset($navUrls) || !isset($basePath)) {
    require_once __DIR__ . '/layout-vars.php';
}
?>
      </div><!-- /.inner content wrapper -->
    </main>

  </div><!-- /.main-content -->
</div><!-- /.app-container -->

<!-- Modal container -->
<div id="modal-container"></div>

<!-- Scripts -->
<?php
if (isset($additionalJS) && is_array($additionalJS)) {
    foreach ($additionalJS as $js) {
        if (is_string($js) && stripos($js, 'quill') !== false) continue;
        echo '<script src="' . htmlspecialchars($js) . '"></script>' . "\n";
    }
}

if (!isset($basePath)) {
    $requestUri  = $_SERVER['REQUEST_URI'] ?? '/admin/';
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $scriptName  = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($scriptName, '/headcount/') !== false) {
        $basePath = preg_replace('#/admin/.*$#', '', $scriptName);
        $basePath = rtrim($basePath, '/');
    } else {
        $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
        $basePath = rtrim($basePath, '/');
    }
    if (empty($basePath) || $basePath === '/') {
        $docRoot   = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
        if (strpos($scriptDir, $docRoot) === 0) {
            $rel = str_replace($docRoot, '', dirname($scriptDir));
            if (!empty($rel) && $rel !== '/') {
                $basePath = str_replace('\\', '/', $rel);
                $basePath = rtrim($basePath, '/');
            }
        }
    }
    if (!empty($basePath) && $basePath[0] !== '/') {
        $basePath = '/' . $basePath;
    }
}

function buildJsPath($basePath, $filename) {
    if (empty($basePath) || $basePath === '/') {
        $p = '/public/js/' . $filename;
    } else {
        $bp = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
        $p  = rtrim($bp, '/') . '/public/js/' . $filename;
    }
    $p = preg_replace('#/+#', '/', $p);
    if ($p[0] !== '/') $p = '/' . $p;
    return $p;
}
?>
<script src="<?= e(buildJsPath($basePath, 'modal.js')) ?>"></script>
<script src="<?= e(buildJsPath($basePath, 'confirm.js')) ?>"></script>
<script src="<?= e(buildJsPath($basePath, 'modern-ui.js')) ?>"></script>

<?php
$stripeApi = '';
if (!empty($basePath)) {
    $bp = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
    $stripeApi = preg_replace('#/+#', '/', rtrim($bp, '/') . '/public/api/payment-transfers.php');
}
?>
<script>
(function(){
    var api = <?= json_encode($stripeApi) ?>;
    if (!api || typeof localStorage === 'undefined') return;
    var KEY = 'headcount_stripe_admin_org_reconcile_ms';
    var intervalMs = 3 * 60 * 60 * 1000;
    var last = parseInt(localStorage.getItem(KEY) || '0', 10);
    if (Date.now() - last < intervalMs) return;
    setTimeout(function() {
        fetch(api, {method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({action:'reconcile_organization'})})
            .then(function(){ localStorage.setItem(KEY, String(Date.now())); })
            .catch(function(){ localStorage.setItem(KEY, String(Date.now())); });
    }, 6000);
})();
</script>
<?php
$hcShowPwaGuide = false;
try {
    if (class_exists(\Headcount\Helpers\Database::class) && class_exists(\Headcount\Middleware\AuthMiddleware::class)) {
        $pwaDb = \Headcount\Helpers\Database::getInstance();
        $pwaUid = (int) \Headcount\Middleware\AuthMiddleware::getUserId();
        if ($pwaUid > 0 && $pwaDb->hasColumn('users', 'pwa_guide_seen_at')) {
            $pwaRow = $pwaDb->queryOne('SELECT pwa_guide_seen_at FROM users WHERE id = :id', ['id' => $pwaUid]);
            $hcShowPwaGuide = is_array($pwaRow) && empty($pwaRow['pwa_guide_seen_at']);
        }
    }
} catch (\Throwable $e) {
    $hcShowPwaGuide = false;
}
$pwaApi = function_exists('buildJsPath')
    ? preg_replace('#/js/[^/]+$#', '/api/pwa-guide.php', buildJsPath($basePath ?? '', 'pwa-guide.js'))
    : '/public/api/pwa-guide.php';
$pwaJs = function_exists('buildJsPath') ? buildJsPath($basePath ?? '', 'pwa-guide.js') : '/public/js/pwa-guide.js';
$pwaCsrf = class_exists(\Headcount\Middleware\CsrfMiddleware::class)
    ? \Headcount\Middleware\CsrfMiddleware::getToken()
    : '';
?>
<script src="<?= e($pwaJs) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof headcountInitPwaGuide === 'function') {
        headcountInitPwaGuide({
            show: <?= $hcShowPwaGuide ? 'true' : 'false' ?>,
            staff: true,
            markUrl: <?= json_encode($pwaApi) ?>,
            csrf: <?= json_encode($pwaCsrf) ?>
        });
    }
});
</script>
</body>
</html>
