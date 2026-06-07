<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - Headcount Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">
    <div class="text-center">
        <h1 class="text-4xl font-bold text-gray-900 mb-4">You're Offline</h1>
        <p class="text-gray-600 mb-6">Please check your internet connection and try again.</p>
        <button onclick="window.location.reload()" 
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Retry
        </button>
    </div>
</body>
</html>
