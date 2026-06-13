/**
 * campaign-email-helpers.js
 *
 * Generic, editor-agnostic helpers for the Email Center "Send email" tab.
 * Copied verbatim from quill-rich-toolbar.js so the campaigns page (now using
 * GrapesJS) does not need to load Quill. quill-rich-toolbar.js still exports the
 * same globals for the pages that continue to use Quill (templates, events,
 * members, program-edit); these definitions are byte-identical so loading order
 * never matters.
 *
 * Exposes:
 *   - window.headcountExtractBodyFromCampaignHtml(storedHtml)
 *   - window.headcountBuildCampaignPreviewHtml(fragment, orgName, logoUrl)
 */
(function (global) {
    'use strict';

    function escapeAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function extractBodyFromCampaignHtml(stored) {
        var s = (stored || '').trim();
        if (!s) return '';
        if (/^\s*<!DOCTYPE/i.test(s) || /^\s*<html[\s>]/i.test(s)) {
            try {
                var doc = new DOMParser().parseFromString(s, 'text/html');
                if (doc && doc.body) return doc.body.innerHTML;
            } catch (e) { /* ignore */ }
        }
        return s;
    }

    function buildCampaignPreviewHtml(fragment, orgName, logoUrl) {
        orgName = orgName || 'Organization';
        var logoBlock = '';
        if (logoUrl) {
            logoBlock = '<div style="text-align:center;padding-bottom:16px;border-bottom:1px solid #e2e8f0;margin-bottom:24px;">' +
                '<img src="' + escapeAttr(logoUrl) + '" alt="" style="max-height:56px;max-width:200px;display:inline-block;" />' +
                '</div>';
        }
        var header = '<div style="font-size:20px;font-weight:700;color:#0f172a;text-align:center;padding:8px 0 20px;">' + escapeAttr(orgName) + '</div>';
        var footer = '<div style="margin-top:32px;padding-top:20px;border-top:1px solid #e2e8f0;font-size:13px;color:#64748b;text-align:center;">' + escapeAttr(orgName) + '</div>';
        var body = fragment || '';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
            '<style>body{margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased;}' +
            '.email-shell{max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.08);overflow:hidden;}' +
            '.email-pad{padding:32px 28px;}' +
            '.body{font-size:15px;line-height:1.6;color:#1e293b;}' +
            '.body img{max-width:100%;height:auto;border-radius:4px;}' +
            '.body video{max-width:100%;height:auto;border-radius:4px;}' +
            '.body .video-preview{display:block;position:relative;text-decoration:none;}' +
            '</style></head><body><div class="email-shell"><div class="email-pad">' +
            logoBlock + header +
            '<div class="body">' + body + '</div>' + footer +
            '</div></div></body></html>';
    }

    global.headcountExtractBodyFromCampaignHtml = extractBodyFromCampaignHtml;
    global.headcountBuildCampaignPreviewHtml = buildCampaignPreviewHtml;
})(typeof window !== 'undefined' ? window : this);
