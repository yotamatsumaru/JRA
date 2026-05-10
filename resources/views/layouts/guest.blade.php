<!DOCTYPE html>
<html lang="ja" x-data="{ darkMode: localStorage.getItem('jra_dark') === 'true' }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'JRA Analyzer')</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gradient-to-br from-primary-50 via-blue-50 to-indigo-100 dark:from-gray-900 dark:via-gray-900 dark:to-black min-h-screen flex flex-col items-center justify-center p-4" style="font-family: 'Noto Sans JP', sans-serif;">

    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <a href="/" class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-primary-600 text-white shadow-lg">
                <x-icon name="horse" class="w-9 h-9" />
            </a>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-2">JRA Analyzer</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400">中央競馬データ分析アプリ</p>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl px-8 py-8">
            @if (session('status'))
            <div class="mb-4 bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-200 px-3 py-2 rounded text-sm">
                {{ session('status') }}
            </div>
            @endif

            @yield('content')
        </div>

        <p class="text-center text-xs text-gray-500 dark:text-gray-500 mt-4">
            © {{ date('Y') }} JRA Analyzer
        </p>
    </div>

</body>
</html>
