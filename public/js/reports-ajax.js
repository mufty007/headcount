/**
 * Reports page — scoped AJAX navigation (no full reload on tab / date / filter changes).
 *
 * Intercepts internal report links and GET form submits inside #reports-content, fetches
 * the same server-rendered URL, swaps just that container, updates history + title, and
 * re-renders the ApexCharts via the existing reports-charts.js lifecycle (reports:rerender).
 *
 * Everything degrades gracefully: any error, a missing container in the response, or a
 * non-report target falls back to a normal full-page navigation. Loaded only on reports.php.
 */
(function () {
    'use strict';

    var CONTAINER_ID = 'reports-content';
    var container = document.getElementById(CONTAINER_ID);
    if (!container) {
        return; // not the reports page
    }

    // ---- helpers -------------------------------------------------------------

    function isReportsUrl(url) {
        try {
            var u = new URL(url, window.location.href);
            if (u.origin !== window.location.origin) return false;
            return u.searchParams.get('page') === 'reports';
        } catch (e) {
            return false;
        }
    }

    // Top progress bar (created once, reused).
    var bar = null;
    function ensureBar() {
        if (bar) return bar;
        bar = document.createElement('div');
        bar.setAttribute('aria-hidden', 'true');
        bar.style.cssText =
            'position:fixed;top:0;left:0;height:3px;width:0;z-index:99999;' +
            'background:#465fff;box-shadow:0 0 8px rgba(70,95,255,.6);' +
            'transition:width .25s ease,opacity .3s ease;opacity:0;pointer-events:none;';
        document.body.appendChild(bar);
        return bar;
    }
    var barTimer = null;
    function barStart() {
        var b = ensureBar();
        clearTimeout(barTimer);
        b.style.opacity = '1';
        b.style.width = '0';
        // force reflow so the transition runs
        void b.offsetWidth;
        b.style.width = '75%';
    }
    function barDone() {
        var b = ensureBar();
        b.style.width = '100%';
        barTimer = setTimeout(function () {
            b.style.opacity = '0';
            b.style.width = '0';
        }, 250);
    }

    // Re-run <script> tags inside the freshly swapped container (innerHTML scripts do not
    // execute on their own). Inline scripts only — the report data block lives here.
    function runScripts(root) {
        var scripts = root.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
            var old = scripts[i];
            var fresh = document.createElement('script');
            for (var a = 0; a < old.attributes.length; a++) {
                fresh.setAttribute(old.attributes[a].name, old.attributes[a].value);
            }
            fresh.textContent = old.textContent;
            old.parentNode.replaceChild(fresh, old);
        }
    }

    var navigating = false;

    function navigate(url, push) {
        if (navigating) return;
        navigating = true;
        barStart();

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var next = doc.getElementById(CONTAINER_ID);
                if (!next) throw new Error('reports container missing in response');

                // Destroy current charts while still in the DOM, then swap, then re-mount.
                window.dispatchEvent(new Event('reports:teardown'));
                container.innerHTML = next.innerHTML;
                runScripts(container);

                if (push !== false) {
                    window.history.pushState({ reportsAjax: true }, '', url);
                }
                if (doc.title) document.title = doc.title;

                // Re-init Alpine bindings inside the new content (filter panel collapse, etc.).
                if (window.Alpine && typeof window.Alpine.initTree === 'function') {
                    try { window.Alpine.initTree(container); } catch (e) {}
                }

                // Trigger chart destroy + remount from the refreshed window.REPORTS_CHART_DATA.
                window.dispatchEvent(new Event('reports:rerender'));

                container.scrollIntoView({ block: 'start', behavior: 'auto' });
                barDone();
                navigating = false;
            })
            .catch(function () {
                // Anything unexpected → just do a normal navigation.
                window.location.href = url;
            });
    }

    // ---- event delegation ----------------------------------------------------

    container.addEventListener('click', function (ev) {
        if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
            return;
        }
        var link = ev.target.closest ? ev.target.closest('a[href]') : null;
        if (!link || !container.contains(link)) return;
        if (link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-no-ajax')) return;

        var href = link.getAttribute('href');
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        if (!isReportsUrl(href)) return; // links leaving the reports page navigate normally

        ev.preventDefault();
        navigate(new URL(href, window.location.href).href, true);
    });

    container.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || form.tagName !== 'FORM') return;
        if ((form.method || 'get').toLowerCase() !== 'get') return; // only GET filters
        if (form.hasAttribute('data-no-ajax')) return;

        var action = form.getAttribute('action') || window.location.pathname;
        var params = new URLSearchParams(new FormData(form)).toString();
        var url = new URL(action, window.location.href);
        url.search = params;

        if (!isReportsUrl(url.href)) return; // let non-report forms submit normally

        ev.preventDefault();
        navigate(url.href, true);
    });

    // Back / forward within the AJAX history.
    window.addEventListener('popstate', function () {
        if (!isReportsUrl(window.location.href)) return;
        navigate(window.location.href, false);
    });

    // Seed history state so the first Back returns to the initial render cleanly.
    if (window.history && window.history.replaceState) {
        window.history.replaceState({ reportsAjax: true }, '', window.location.href);
    }
})();
