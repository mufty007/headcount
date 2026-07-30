<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <script>
    (function(){var K='headcount-portal-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#465fff">
    <meta name="apple-mobile-web-app-title" content="IMCA">
    <title>Offline - IMCA</title>
    <script>window.tailwind = window.tailwind || {}; window.tailwind.config = { darkMode: 'class' };</script>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-900 flex items-center justify-center min-h-[100dvh]">
    <div class="text-center px-6 max-w-sm">
        <p class="text-sm font-bold tracking-tight text-brand-600 mb-2">IMCA</p>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-3">You're offline</h1>
        <p class="text-gray-600 dark:text-gray-400 mb-6 text-sm leading-relaxed">Check your connection, then try again. Cached pages may still work.</p>
        <button onclick="window.location.reload()"
                class="w-full px-6 py-3 bg-indigo-600 text-white rounded-xl font-semibold hover:bg-indigo-700 transition-colors min-h-[44px]">
            Retry
        </button>
    </div>
</body>
</html>
