<div class="fixed right-4 top-4 z-50 flex items-center gap-1 rounded-xl border border-gray-200 bg-white/95 p-1 shadow-theme-sm backdrop-blur-sm dark:border-slate-600 dark:bg-slate-800/95" role="group" aria-label="Theme">
    <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-700" data-headcount-theme="light">Light</button>
    <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-700" data-headcount-theme="dark">Dark</button>
    <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-700" data-headcount-theme="system">System</button>
</div>
<script>
(function () {
    var K = 'headcount-admin-theme';
    function apply() {
        var t = null;
        try { t = localStorage.getItem(K); } catch (e) {}
        var d = t === 'dark' || (t !== 'light' && typeof matchMedia !== 'undefined' && matchMedia('(prefers-color-scheme:dark)').matches);
        document.documentElement.classList.toggle('dark', !!d);
    }
    document.querySelectorAll('[data-headcount-theme]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var v = btn.getAttribute('data-headcount-theme');
            try { localStorage.setItem(K, v); } catch (e) {}
            apply();
        });
    });
    if (typeof matchMedia !== 'undefined') {
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            var t = null;
            try { t = localStorage.getItem(K); } catch (e) {}
            if (t === 'system' || t === null) apply();
        });
    }
})();
</script>
