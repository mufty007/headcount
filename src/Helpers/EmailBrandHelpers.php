<?php

/**
 * Email branding and unsubscribe helpers.
 */

function wrapEmailWithBranding($htmlBody, $logoUrl = null, $orgName = '') {
    // Build the branding header block
    $brandHeader = '';
    if ($logoUrl !== null || $orgName !== '') {
        $brandHeader = '<div style="padding:16px 24px;border-bottom:1px solid #eee;background:#fafafa;text-align:center;">';
        if ($logoUrl !== null && $logoUrl !== '') {
            $brandHeader .= '<img src="' . htmlspecialchars($logoUrl) . '" alt="Logo" style="max-height:60px;max-width:200px;display:inline-block;margin-bottom:8px;">';
        }
        if ($orgName !== '') {
            $brandHeader .= '<div style="font-size:16px;font-weight:bold;color:#1e293b;">' . htmlspecialchars($orgName) . '</div>';
        }
        $brandHeader .= '</div>';
    }

    // If the body is already a full HTML document (e.g. Unlayer export),
    // inject the branding header into the existing <body> instead of double-wrapping.
    if (stripos(trim($htmlBody), '<!DOCTYPE') === 0 || stripos(trim($htmlBody), '<html') === 0) {
        if ($brandHeader !== '') {
            // Insert branding immediately after the opening <body> tag
            $htmlBody = preg_replace('/(<body[^>]*>)/i', '$1' . $brandHeader, $htmlBody, 1);
        }
        return $htmlBody;
    }

    // Plain HTML fragment — wrap with a container as before
    return '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;border:1px solid #f1f5f9;">'
        . $brandHeader
        . '<div style="padding:32px;">' . $htmlBody . '</div>'
        . '</div>';
}


/**
 * Generate signed unsubscribe URL for campaign emails (CAN-SPAM).
 *
 * @param int $organizationId
 * @param string $email Recipient email
 * @param int|null $campaignId Optional campaign id
 * @param string $baseUrl Base URL of the app (e.g. config app.url)
 * @param string $signingKey Secret key for HMAC (e.g. config security.encryption_key)
 * @return string Full unsubscribe URL
 */
function generateUnsubscribeUrl($organizationId, $email, $campaignId, $baseUrl, $signingKey)
{
    $cid = $campaignId !== null && $campaignId !== '' ? (int) $campaignId : '';
    $payload = (int) $organizationId . '|' . $email . '|' . $cid;
    $token = hash_hmac('sha256', $payload, $signingKey ?: 'headcount-unsubscribe');
    $baseUrl = rtrim($baseUrl, '/');
    $path = (strpos($baseUrl, '/public') !== false ? $baseUrl : $baseUrl . '/public') . '/unsubscribe.php';
    return $path . '?org=' . (int) $organizationId . '&email=' . rawurlencode($email) . '&token=' . $token;
}

/**
 * Verify unsubscribe token (from GET params).
 *
 * @param int $organizationId
 * @param string $email
 * @param string $token
 * @param string $signingKey
 * @return bool
 */
function verifyUnsubscribeToken($organizationId, $email, $token, $signingKey)
{
    $expected = hash_hmac('sha256', (int) $organizationId . '|' . $email . '|', $signingKey ?: 'headcount-unsubscribe');
    $expected2 = hash_hmac('sha256', (int) $organizationId . '|' . $email . '|0', $signingKey ?: 'headcount-unsubscribe');
    return hash_equals($expected, $token) || hash_equals($expected2, $token);
}

/**
 * Append CAN-SPAM unsubscribe footer to email HTML.
 *
 * @param string $html Email body HTML
 * @param string $unsubscribeUrl Full URL for unsubscribe link
 * @param string $orgName Optional organization name for footer
 * @return string HTML with footer appended
 */
function appendUnsubscribeFooter($html, $unsubscribeUrl, $orgName = '')
{
    $footer = '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;text-align:center;">';
    $footer .= '<a href="' . htmlspecialchars($unsubscribeUrl) . '" style="color:#6366f1;">Unsubscribe</a> from these emails';
    if ($orgName !== '') {
        $footer .= ' &middot; ' . htmlspecialchars($orgName);
    }
    $footer .= '</div>';

    // For full HTML documents (e.g. Unlayer), insert before </body> not after </html>
    if (stripos(trim($html), '<!DOCTYPE') === 0 || stripos(trim($html), '<html') === 0) {
        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $footer . '</body>', $html, 1);
        }
        // No </body> tag found — just append inside the html
        return $html . $footer;
    }

    // Plain fragment — just append
    return $html . $footer;
}

/**
 * Build a fully-qualified logo URL for emails from an app base URL and stored logo path.
 *
 * @param string $appUrl   Base app URL from config, e.g. https://example.org/headcount
 * @param string $logoPath Stored logo path from organizations.logo_path (relative or absolute)
 * @return string|null Absolute logo URL or null if not available
 */
function buildLogoUrlForEmail($appUrl, $logoPath)
{
    $appUrl = rtrim((string)$appUrl, '/');
    if ($logoPath === null || $logoPath === '') {
        return null;
    }

    // Absolute URL already
    if (strpos($logoPath, 'http://') === 0 || strpos($logoPath, 'https://') === 0) {
        return $logoPath;
    }

    $normalizedPath = '/' . ltrim($logoPath, '/');

    // If it already starts with /public/, just prepend app url
    if (strpos($normalizedPath, '/public/') === 0) {
        return $appUrl . $normalizedPath;
    }

    // Default: assume stored relative to /public
    return $appUrl . '/public' . $normalizedPath;
}
