<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <script>
    (function(){var K='headcount-portal-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - Headcount Portal</title>
    <script>window.tailwind = window.tailwind || {}; window.tailwind.config = { darkMode: 'class' };</script>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-900 flex items-center justify-center min-h-screen">
    <div class="text-center px-6">
        <h1 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">You're Offline</h1>
        <p class="text-gray-600 dark:text-gray-400 mb-6">Please check your internet connection and try again.</p>
        <button onclick="window.location.reload()"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            Retry
        </button>
    </div>
</body>
</html>
