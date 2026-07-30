<?php
/**
 * Portal branding: display name IMCA, org logo, theme color.
 * Sets $APP_NAME, $orgLogoUrl, $themeColor, $orgDisplayName, $portalBrand, $pwaManifestUrl, $pwaIconUrl.
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcDir = dirname(__DIR__);
    while ($hcDir !== dirname($hcDir) && !is_file($hcDir . '/vendor/autoload.php')) {
        $hcDir = dirname($hcDir);
    }
    define('HC_PROJECT_ROOT', $hcDir);
}

if (!function_exists('e')) {
    $helpersPath = HC_PROJECT_ROOT . '/src/helpers.php';
    if (is_file($helpersPath)) {
        require_once $helpersPath;
    }
}

if (!function_exists('headcount_portal_public_base_path')) {
    /**
     * Web path to the app public root (no trailing slash), e.g. "" or "/Headcount" or "/Headcount/public".
     */
    function headcount_portal_public_base_path(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/portal/';
        $requestPath = (string) parse_url($requestUri, PHP_URL_PATH);
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        if (stripos($scriptName, '/headcount/') !== false) {
            $base = preg_replace('#/portal/.*$#i', '', $scriptName);
        } else {
            $base = preg_replace('#/(?:api/)?portal/.*$#i', '', $requestPath);
        }
        $base = rtrim((string) $base, '/');

        if ($base === '' || $base === '/') {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
            if ($docRoot && strpos($scriptDir, $docRoot) === 0) {
                $relative = str_replace('\\', '/', substr($scriptDir, strlen($docRoot)));
                $relative = preg_replace('#/portal$#', '', rtrim($relative, '/'));
                if ($relative !== '' && $relative !== '/') {
                    $base = rtrim($relative, '/');
                    if ($base[0] !== '/') {
                        $base = '/' . $base;
                    }
                } else {
                    $base = '';
                }
            } else {
                $base = '';
            }
        }

        if ($base !== '' && $base[0] !== '/') {
            $base = '/' . $base;
        }

        return $base === '/' ? '' : $base;
    }
}

if (!function_exists('headcount_portal_logo_url')) {
    /**
     * Browser-loadable URL for an organization logo_path.
     */
    function headcount_portal_logo_url(?string $logoPath, string $assetsFallback): string
    {
        $logoPath = trim((string) $logoPath);
        if ($logoPath === '') {
            return $assetsFallback;
        }
        if (filter_var($logoPath, FILTER_VALIDATE_URL)) {
            return $logoPath;
        }

        $base = headcount_portal_public_base_path();
        $relative = ltrim(str_replace('\\', '/', $logoPath), '/');

        // Settings stores e.g. "uploads/organizations/1/logo.svg" under public/.
        // Prefer a direct static URL (works for SVG/PNG) over the image proxy.
        if (preg_match('#^(?:public/)?uploads/#i', $relative)) {
            if (stripos($relative, 'public/') !== 0) {
                $relative = 'public/' . $relative;
            }
            $url = ($base === '' ? '' : $base) . '/' . $relative;
            $url = preg_replace('#/+#', '/', $url);
            return ($url[0] === '/' ? $url : '/' . $url);
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $imagePath = ltrim($logoPath, '/');

        return $protocol . '://' . $host . $base . '/api/image.php?path=' . rawurlencode($imagePath);
    }
}

if (!function_exists('headcount_portal_logo_filesystem_path')) {
    /**
     * Resolve org logo to a local filesystem path for PWA icon generation.
     */
    function headcount_portal_logo_filesystem_path(?string $logoPath): ?string
    {
        $logoPath = trim((string) $logoPath);
        if ($logoPath === '' || filter_var($logoPath, FILTER_VALIDATE_URL)) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $logoPath), '/');
        $candidates = [
            HC_PROJECT_ROOT . '/public/uploads/' . $relative,
            HC_PROJECT_ROOT . '/uploads/' . $relative,
            HC_PROJECT_ROOT . '/public/' . $relative,
            HC_PROJECT_ROOT . '/' . $relative,
        ];
        // Common storage: logos/foo.png under uploads
        if (strpos($relative, 'logos/') === 0 || strpos($relative, 'organizations/') === 0) {
            $candidates[] = HC_PROJECT_ROOT . '/public/uploads/' . $relative;
            $candidates[] = HC_PROJECT_ROOT . '/uploads/' . $relative;
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}

if (!function_exists('headcount_load_portal_branding')) {
    /**
     * @return array{app_name:string,org_name:string,logo_url:string,logo_path:?string,theme_color:string,organization_id:?int}
     */
    function headcount_load_portal_branding(?int $authenticatedOrgId = null): array
    {
        $appName = 'IMCA';
        $themeColor = '#465fff';
        $orgName = 'IMCA';
        $logoPath = null;
        $organizationId = null;

        try {
            if (!class_exists('Headcount\\Helpers\\Database', false)) {
                require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
            }
            $configPath = HC_PROJECT_ROOT . '/config/config.php';
            if (!is_file($configPath)) {
                throw new \RuntimeException('config missing');
            }
            $config = require $configPath;
            $db = \Headcount\Helpers\Database::getInstance($config['database'] ?? null);

            if ($authenticatedOrgId === null && class_exists('Headcount\\Middleware\\PortalAuthMiddleware')) {
                try {
                    if (\Headcount\Middleware\PortalAuthMiddleware::isAuthenticated()) {
                        $member = \Headcount\Middleware\PortalAuthMiddleware::getMember();
                        if (!empty($member['organization_id'])) {
                            $authenticatedOrgId = (int) $member['organization_id'];
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            if (function_exists('headcount_resolve_portal_organization_id')) {
                $organizationId = headcount_resolve_portal_organization_id($authenticatedOrgId, $config, $db);
            } elseif ($authenticatedOrgId) {
                $organizationId = $authenticatedOrgId;
            }

            if ($organizationId) {
                $row = $db->queryOne(
                    'SELECT id, name, logo_path, primary_color FROM organizations WHERE id = :id LIMIT 1',
                    ['id' => $organizationId]
                );
                if ($row) {
                    $orgName = (string) ($row['name'] ?? 'IMCA');
                    $logoPath = $row['logo_path'] ?? null;
                    $pc = (string) ($row['primary_color'] ?? '');
                    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $pc)) {
                        $themeColor = $pc;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Soft-fail: keep IMCA defaults
        }

        return [
            'app_name' => $appName,
            'org_name' => $orgName,
            'logo_url' => '', // filled by caller with assets base
            'logo_path' => $logoPath ? (string) $logoPath : null,
            'theme_color' => $themeColor,
            'organization_id' => $organizationId,
        ];
    }
}

// --- Apply branding into page-scope variables ---
if (!isset($basePath)) {
    $basePath = headcount_portal_public_base_path();
}
if (!isset($portalBase)) {
    $portalBase = $basePath . '/portal';
}
if (!isset($assetsBase)) {
    if (strpos($basePath, '/public') !== false) {
        $assetsBase = $basePath . '/assets/';
    } else {
        $assetsBase = ($basePath === '' ? '' : $basePath) . '/public/assets/';
    }
    $assetsBase = preg_replace('#/+#', '/', $assetsBase);
    if ($assetsBase !== '' && $assetsBase[0] !== '/') {
        $assetsBase = '/' . $assetsBase;
    }
}

$fallbackLogo = rtrim($assetsBase, '/') . '/images/logo.svg';
$_portalAuthOrgId = null;
if (isset($member) && is_array($member) && !empty($member['organization_id'])) {
    $_portalAuthOrgId = (int) $member['organization_id'];
} elseif (isset($_SESSION['organization_id'])) {
    $_portalAuthOrgId = (int) $_SESSION['organization_id'];
}

$portalBrand = headcount_load_portal_branding($_portalAuthOrgId);
$APP_NAME = $portalBrand['app_name']; // IMCA
$orgDisplayName = $portalBrand['org_name'];
$themeColor = $portalBrand['theme_color'];
$orgLogoUrl = headcount_portal_logo_url($portalBrand['logo_path'], $fallbackLogo);
$portalBrand['logo_url'] = $orgLogoUrl;

$pwaManifestUrl = ($basePath === '' ? '' : $basePath) . '/manifest.php';
$pwaManifestUrl = preg_replace('#/+#', '/', $pwaManifestUrl);
if ($pwaManifestUrl === '' || $pwaManifestUrl[0] !== '/') {
    $pwaManifestUrl = '/' . ltrim((string) $pwaManifestUrl, '/');
}
$pwaIconUrl = rtrim($portalBase, '/') . '/pwa-icon.php';
$swUrl = ($basePath === '' ? '' : $basePath) . '/sw.js';
$swUrl = preg_replace('#/+#', '/', $swUrl);
if ($swUrl === '' || $swUrl[0] !== '/') {
    $swUrl = '/' . ltrim((string) $swUrl, '/');
}
