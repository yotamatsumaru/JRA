<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'JRA Analyzer') - {{ config('app.name', 'JRA Analyzer') }}</title>

    {{-- Tailwind CSS via CDN --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50:  '#f0f9ff',
                            100: '#e0f2fe',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            900: '#0c4a6e',
                        }
                    },
                    fontFamily: {
                        sans: ['Noto Sans JP', 'Hiragino Sans', 'Yu Gothic', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    {{-- Google Fonts (Noto Sans JP) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">

    {{-- Alpine.js --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    {{-- ApexCharts --}}
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    @stack('head')
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-800">

<div class="min-h-screen flex flex-col" x-data="{ menuOpen: false }">

    {{-- ヘッダー --}}
    <nav class="bg-gradient-to-r from-primary-700 to-primary-900 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">
                <div class="flex items-center space-x-8">
                    <a href="{{ route('dashboard') }}" class="flex items-center space-x-2 font-bold text-lg">
                        <span class="text-2xl">🏇</span>
                        <span>JRA Analyzer</span>
                    </a>
                    <div class="hidden md:flex items-center space-x-1">
                        @php $active = 'bg-primary-900 px-3 py-2 rounded text-sm font-medium'; @endphp
                        @php $inactive = 'hover:bg-primary-600 px-3 py-2 rounded text-sm font-medium'; @endphp
                        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? $active : $inactive }}">ダッシュボード</a>
                        <a href="{{ route('races.index') }}" class="{{ request()->routeIs('races.*') ? $active : $inactive }}">レース</a>
                        <a href="{{ route('horses.index') }}" class="{{ request()->routeIs('horses.*') ? $active : $inactive }}">馬</a>
                        <a href="{{ route('jockeys.index') }}" class="{{ request()->routeIs('jockeys.*') ? $active : $inactive }}">騎手</a>
                        <a href="{{ route('venues.index') }}" class="{{ request()->routeIs('venues.*') ? $active : $inactive }}">競馬場</a>

                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="{{ request()->routeIs('analytics.*') ? $active : $inactive }} flex items-center space-x-1">
                                <span>分析</span>
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                            </button>
                            <div x-show="open" x-transition class="absolute mt-2 w-48 bg-white text-gray-800 rounded shadow-lg z-50">
                                <a href="{{ route('analytics.venue') }}" class="block px-4 py-2 hover:bg-gray-100">競馬場別傾向</a>
                                <a href="{{ route('analytics.pace') }}" class="block px-4 py-2 hover:bg-gray-100">ペース分析</a>
                                <a href="{{ route('analytics.pedigree') }}" class="block px-4 py-2 hover:bg-gray-100">血統傾向</a>
                                <a href="{{ route('analytics.jockey') }}" class="block px-4 py-2 hover:bg-gray-100">騎手×コース</a>
                                <a href="{{ route('analytics.roi') }}" class="block px-4 py-2 hover:bg-gray-100">回収率シミュ</a>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="{{ request()->routeIs('import.*') ? $active : $inactive }} flex items-center space-x-1">
                                <span>取込</span>
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                            </button>
                            <div x-show="open" x-transition class="absolute mt-2 w-48 bg-white text-gray-800 rounded shadow-lg z-50">
                                <a href="{{ route('import.netkeiba') }}" class="block px-4 py-2 hover:bg-gray-100">netkeibaから取込</a>
                                <a href="{{ route('import.csv') }}" class="block px-4 py-2 hover:bg-gray-100">CSV取込</a>
                                <a href="{{ route('import.image') }}" class="block px-4 py-2 hover:bg-gray-100">画像取込(GPT-4o)</a>
                                <a href="{{ route('import.logs') }}" class="block px-4 py-2 hover:bg-gray-100">取込ログ</a>
                            </div>
                        </div>

                        <a href="{{ route('notes.index') }}" class="{{ request()->routeIs('notes.*') ? $active : $inactive }}">メモ</a>
                    </div>
                </div>

                @auth
                <div class="hidden md:flex items-center space-x-4 relative" x-data="{ open: false }">
                    <button @click="open = !open" @click.away="open = false" class="flex items-center space-x-2 hover:bg-primary-600 px-3 py-2 rounded text-sm">
                        <span>{{ Auth::user()->name }}</span>
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                    </button>
                    <div x-show="open" x-transition class="absolute right-0 top-full mt-2 w-48 bg-white text-gray-800 rounded shadow-lg z-50">
                        <a href="{{ route('profile.edit') }}" class="block px-4 py-2 hover:bg-gray-100">プロフィール</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 hover:bg-gray-100">ログアウト</button>
                        </form>
                    </div>
                </div>
                @endauth

                {{-- モバイルメニュー --}}
                <button class="md:hidden" @click="menuOpen = !menuOpen">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>

        <div x-show="menuOpen" class="md:hidden bg-primary-800">
            <div class="px-2 py-2 space-y-1">
                <a href="{{ route('dashboard') }}" class="block px-3 py-2 rounded hover:bg-primary-600">ダッシュボード</a>
                <a href="{{ route('races.index') }}" class="block px-3 py-2 rounded hover:bg-primary-600">レース</a>
                <a href="{{ route('horses.index') }}" class="block px-3 py-2 rounded hover:bg-primary-600">馬</a>
                <a href="{{ route('jockeys.index') }}" class="block px-3 py-2 rounded hover:bg-primary-600">騎手</a>
                <a href="{{ route('venues.index') }}" class="block px-3 py-2 rounded hover:bg-primary-600">競馬場</a>
                <a href="{{ route('analytics.venue') }}" class="block px-3 py-2 rounded hover:bg-primary-600">分析</a>
                <a href="{{ route('import.index') }}" class="block px-3 py-2 rounded hover:bg-primary-600">取込</a>
                <a href="{{ route('notes.index') }}" class="block px-3 py-2 rounded hover:bg-primary-600">メモ</a>
                @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full text-left px-3 py-2 rounded hover:bg-primary-600">ログアウト</button>
                </form>
                @endauth
            </div>
        </div>
    </nav>

    {{-- Flashメッセージ --}}
    @if (session('status'))
    <div class="bg-green-100 border-l-4 border-green-500 text-green-800 px-4 py-3 mx-4 mt-4 rounded shadow" x-data="{ show: true }" x-show="show">
        <div class="flex items-center justify-between max-w-7xl mx-auto">
            <span>✅ {{ session('status') }}</span>
            <button @click="show = false" class="text-green-600 hover:text-green-800">✕</button>
        </div>
    </div>
    @endif

    @if ($errors->any())
    <div class="bg-red-100 border-l-4 border-red-500 text-red-800 px-4 py-3 mx-4 mt-4 rounded shadow">
        <ul class="list-disc list-inside max-w-7xl mx-auto">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- メインコンテンツ --}}
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @yield('content')
    </main>

    {{-- フッター --}}
    <footer class="bg-gray-800 text-gray-300 text-center text-sm py-4">
        © {{ date('Y') }} JRA Analyzer - 個人利用専用
    </footer>
</div>

@stack('scripts')
</body>
</html>
