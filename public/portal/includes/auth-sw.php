<?php
/**
 * Shared auth page footer: SW registration for PWA install before login.
 */
$swRegisterUrl = isset($swUrl) ? $swUrl : (($baseUrlPath ?? $basePath ?? '') . '/sw.js');
$swRegisterUrl = preg_replace('#/+#', '/', (string) $swRegisterUrl);
if ($swRegisterUrl === '' || ($swRegisterUrl[0] ?? '') !== '/') {
    $swRegisterUrl = '/' . ltrim((string) $swRegisterUrl, '/');
}
$swScope = rtrim(($portalBase ?? (($baseUrlPath ?? '') . '/portal')), '/') . '/';
?>
<script>
(function () {
    if (!('serviceWorker' in navigator)) return;
    var swUrl = <?= json_encode($swRegisterUrl) ?>;
    var scope = <?= json_encode($swScope) ?>;
    window.addEventListener('load', function () {
        navigator.serviceWorker.register(swUrl, { scope: scope }).catch(function () {
            navigator.serviceWorker.register(swUrl).catch(function () {});
        });
    });
})();
</script>
