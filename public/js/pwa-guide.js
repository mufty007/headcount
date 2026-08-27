/**
 * First-login PWA install wizard (iOS / Android / desktop).
 */
(function () {
    'use strict';

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }

    function detectDevice() {
        const ua = navigator.userAgent || '';
        const iOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const android = /Android/i.test(ua);
        if (iOS) return 'ios';
        if (android) return 'android';
        return 'desktop';
    }

    function steps(device) {
        if (device === 'ios') {
            return [
                'Tap the Share button in Safari (square with an arrow).',
                'Scroll and tap Add to Home Screen.',
                'Tap Add. The app icon will appear on your home screen.',
            ];
        }
        if (device === 'android') {
            return [
                'Open this site in Chrome.',
                'Tap the menu (three dots) and choose Install app or Add to Home screen.',
                'Confirm Install. You can then open it like any other app.',
            ];
        }
        return [
            'In Chrome or Edge, look for the install icon in the address bar.',
            'Click Install app and confirm.',
            'The app opens in its own window from your desktop or Start menu.',
        ];
    }

    function staffNote(isStaff) {
        if (!isStaff) return '';
        return '<p class="mt-3 text-xs text-gray-500">Staff: the installed app currently opens the member portal. Use the web admin for attendance until a staff app is added.</p>';
    }

    window.headcountInitPwaGuide = function (opts) {
        const o = opts || {};
        if (!o.show || isStandalone()) return;
        const device = detectDevice();
        const list = steps(device).map(function (s) {
            return '<li class="mb-2">' + s + '</li>';
        }).join('');
        const wrap = document.createElement('div');
        wrap.id = 'hc-pwa-guide';
        wrap.innerHTML =
            '<div class="fixed inset-0 z-[10050] flex items-center justify-center p-4">' +
                '<div class="absolute inset-0 bg-black/50" data-hc-pwa-skip></div>' +
                '<div class="relative z-10 max-w-md w-full rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">' +
                    '<h2 class="text-xl font-bold text-gray-900 dark:text-white">Install this app</h2>' +
                    '<p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Add IMCA to your home screen for faster access.</p>' +
                    '<ol class="mt-4 list-decimal pl-5 text-sm text-gray-800 dark:text-gray-100">' + list + '</ol>' +
                    staffNote(!!o.staff) +
                    '<div class="mt-6 flex flex-wrap justify-end gap-2">' +
                        '<button type="button" class="rounded-lg border px-4 py-2 text-sm" data-hc-pwa-skip>Skip</button>' +
                        '<button type="button" class="rounded-lg border px-4 py-2 text-sm" data-hc-pwa-skip>Don\u2019t show again</button>' +
                        '<button type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" data-hc-pwa-done>Got it</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(wrap);

        function dismiss() {
            wrap.remove();
            if (o.markUrl) {
                fetch(o.markUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: o.csrf || '' }),
                }).catch(function () {});
            }
        }
        wrap.querySelectorAll('[data-hc-pwa-skip], [data-hc-pwa-done]').forEach(function (btn) {
            btn.addEventListener('click', dismiss);
        });
    };
})();
