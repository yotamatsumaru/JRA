<!DOCTYPE html>
<html lang="ja" x-data="{ darkMode: localStorage.getItem('jra_dark') === 'true' }" :class="{ 'dark': darkMode }" x-init="$watch('darkMode', v => localStorage.setItem('jra_dark', v))">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'JRA Analyzer') - {{ config('app.name', 'JRA Analyzer') }}</title>

    {{-- Tailwind CSS via CDN --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        // 競馬テーマカラー
                        turf: {        // ターフグリーン (メイン)
                            50:  '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d',
                            950: '#052e16',
                        },
                        sand: {        // サンドコース (アクセント)
                            50:  '#fdf8f1',
                            100: '#faedd6',
                            200: '#f4d8a8',
                            300: '#ecbb73',
                            400: '#e39e4d',
                            500: '#dc8330',
                            600: '#c66525',
                            700: '#a44b22',
                            800: '#853d22',
                            900: '#6e331f',
                        },
                        gold: {        // 金色 (賞金・1着)
                            50:  '#fffbeb',
                            100: '#fef3c7',
                            300: '#fcd34d',
                            500: '#f59e0b',
                            600: '#d97706',
                            700: '#b45309',
                        },
                        primary: {     // 互換: 既存ビュー用にprimary残す（turfに寄せる）
                            50:  '#f0fdf4',
                            100: '#dcfce7',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            900: '#14532d',
                        }
                    },
                    fontFamily: {
                        sans: ['Noto Sans JP', 'Hiragino Sans', 'Yu Gothic', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'slide-in-right': 'slide-in-right 0.3s ease-out',
                        'fade-in': 'fade-in 0.2s ease-out',
                        'shimmer': 'shimmer 1.5s linear infinite',
                    },
                    keyframes: {
                        'slide-in-right': {
                            '0%': { transform: 'translateX(120%)', opacity: '0' },
                            '100%': { transform: 'translateX(0)', opacity: '1' }
                        },
                        'fade-in': {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        'shimmer': {
                            '0%': { backgroundPosition: '-1000px 0' },
                            '100%': { backgroundPosition: '1000px 0' }
                        }
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

    {{-- カスタムスタイル --}}
    <style>
        [x-cloak] { display: none !important; }

        /* スケルトンローディング */
        .skeleton {
            background: linear-gradient(90deg, #e5e7eb 0%, #f3f4f6 50%, #e5e7eb 100%);
            background-size: 1000px 100%;
            animation: shimmer 1.5s linear infinite;
            border-radius: 0.375rem;
        }
        .dark .skeleton {
            background: linear-gradient(90deg, #374151 0%, #4b5563 50%, #374151 100%);
            background-size: 1000px 100%;
        }

        /* ダークモード時のApexCharts調整 */
        .dark .apexcharts-text { fill: #d1d5db !important; }
        .dark .apexcharts-gridline { stroke: #374151 !important; }
        .dark .apexcharts-tooltip { background: #1f2937 !important; color: #f9fafb !important; border-color: #374151 !important; }
        .dark .apexcharts-legend-text { color: #d1d5db !important; }
        .dark .apexcharts-xaxis-label, .dark .apexcharts-yaxis-label { fill: #9ca3af !important; }
    </style>

    @stack('head')
</head>
<body class="font-sans antialiased bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 transition-colors duration-200">

{{-- ============== トースト通知システム ============== --}}
<div
    x-data="{
        toasts: [],
        add(message, type = 'success', duration = 3500) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, type });
            setTimeout(() => this.remove(id), duration);
        },
        remove(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
        init() {
            window.toast = (msg, type = 'success', duration = 3500) => this.add(msg, type, duration);
            // フラッシュからの自動表示
            @if (session('status'))
                this.add(@json(session('status')), 'success');
            @endif
            @if (session('error'))
                this.add(@json(session('error')), 'error');
            @endif
        }
    }"
    class="fixed top-4 right-4 z-[100] space-y-2 pointer-events-none"
    style="max-width: 24rem;"
>
    <template x-for="t in toasts" :key="t.id">
        <div
            x-show="true"
            x-transition:enter="animate-slide-in-right"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-x-0"
            x-transition:leave-end="opacity-0 translate-x-12"
            class="pointer-events-auto rounded-lg shadow-lg px-4 py-3 flex items-start space-x-3 min-w-[280px]"
            :class="{
                'bg-turf-50 dark:bg-turf-900 border-l-4 border-turf-500 text-turf-900 dark:text-turf-100': t.type === 'success',
                'bg-red-50 dark:bg-red-900 border-l-4 border-red-500 text-red-900 dark:text-red-100': t.type === 'error',
                'bg-amber-50 dark:bg-amber-900 border-l-4 border-amber-500 text-amber-900 dark:text-amber-100': t.type === 'warning',
                'bg-sky-50 dark:bg-sky-900 border-l-4 border-sky-500 text-sky-900 dark:text-sky-100': t.type === 'info',
            }"
        >
            <span class="text-xl leading-none">
                <span x-show="t.type === 'success'">✓</span>
                <span x-show="t.type === 'error'">✕</span>
                <span x-show="t.type === 'warning'">⚠</span>
                <span x-show="t.type === 'info'">ℹ</span>
            </span>
            <div class="flex-1 text-sm" x-text="t.message"></div>
            <button @click="remove(t.id)" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 leading-none">✕</button>
        </div>
    </template>
</div>

<div class="min-h-screen flex flex-col" x-data="{ menuOpen: false }">

    {{-- ============== ヘッダー ============== --}}
    <nav class="bg-gradient-to-r from-turf-700 to-turf-900 dark:from-turf-900 dark:to-gray-900 text-white shadow-md sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16 items-center">
                <div class="flex items-center space-x-6">
                    <a href="{{ route('dashboard') }}" class="flex items-center space-x-2 font-bold text-lg group">
                        <span class="text-2xl group-hover:scale-110 transition-transform">🏇</span>
                        <span>JRA Analyzer</span>
                    </a>
                    <div class="hidden md:flex items-center space-x-1">
                        @php
                            $active = 'bg-turf-900/80 dark:bg-black/40 px-3 py-2 rounded-md text-sm font-medium ring-1 ring-white/10';
                            $inactive = 'hover:bg-turf-600/70 px-3 py-2 rounded-md text-sm font-medium transition-colors';
                        @endphp

                        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="home" class="w-4 h-4" />
                            <span>ダッシュボード</span>
                        </a>
                        <a href="{{ route('races.index') }}" class="{{ request()->routeIs('races.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="flag" class="w-4 h-4" />
                            <span>レース</span>
                        </a>
                        <a href="{{ route('horses.index') }}" class="{{ request()->routeIs('horses.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <span class="text-base leading-none">🐎</span>
                            <span>馬</span>
                        </a>
                        <a href="{{ route('jockeys.index') }}" class="{{ request()->routeIs('jockeys.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="user" class="w-4 h-4" />
                            <span>騎手</span>
                        </a>
                        <a href="{{ route('venues.index') }}" class="{{ request()->routeIs('venues.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="map" class="w-4 h-4" />
                            <span>競馬場</span>
                        </a>

                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="{{ request()->routeIs('analytics.*') ? $active : $inactive }} flex items-center space-x-1.5">
                                <x-icon name="chart" class="w-4 h-4" />
                                <span>分析</span>
                                <x-icon name="chevron-down" class="w-3 h-3" />
                            </button>
                            <div x-show="open" x-cloak x-transition class="absolute mt-2 w-52 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-md shadow-xl z-50 ring-1 ring-black/5 overflow-hidden">
                                <a href="{{ route('analytics.venue') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="map" class="w-4 h-4 text-turf-600" /><span>競馬場別傾向</span></a>
                                <a href="{{ route('analytics.pace') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="bolt" class="w-4 h-4 text-amber-500" /><span>ペース分析</span></a>
                                <a href="{{ route('analytics.pedigree') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="dna" class="w-4 h-4 text-purple-500" /><span>血統傾向</span></a>
                                <a href="{{ route('analytics.jockey') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="user" class="w-4 h-4 text-sky-500" /><span>騎手×コース</span></a>
                                <a href="{{ route('analytics.roi') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="cash" class="w-4 h-4 text-gold-500" /><span>回収率シミュ</span></a>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="{{ request()->routeIs('import.*') ? $active : $inactive }} flex items-center space-x-1.5">
                                <x-icon name="upload" class="w-4 h-4" />
                                <span>取込</span>
                                <x-icon name="chevron-down" class="w-3 h-3" />
                            </button>
                            <div x-show="open" x-cloak x-transition class="absolute mt-2 w-52 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-md shadow-xl z-50 ring-1 ring-black/5 overflow-hidden">
                                <a href="{{ route('import.netkeiba') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="globe" class="w-4 h-4 text-sky-500" /><span>netkeibaから取込</span></a>
                                <a href="{{ route('import.csv') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="document" class="w-4 h-4 text-emerald-500" /><span>CSV取込</span></a>
                                <a href="{{ route('import.image') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="camera" class="w-4 h-4 text-purple-500" /><span>画像取込(GPT-4o)</span></a>
                                <a href="{{ route('import.logs') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="list" class="w-4 h-4 text-gray-500" /><span>取込ログ</span></a>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="{{ request()->routeIs('bets.*') || request()->routeIs('betting.*') ? $active : $inactive }} flex items-center space-x-1.5">
                                <x-icon name="cash" class="w-4 h-4" />
                                <span>馬券</span>
                                <x-icon name="chevron-down" class="w-3 h-3" />
                            </button>
                            <div x-show="open" x-cloak x-transition class="absolute mt-2 w-52 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-md shadow-xl z-50 ring-1 ring-black/5 overflow-hidden">
                                <a href="{{ route('betting.dashboard') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="chart" class="w-4 h-4 text-gold-500" /><span>収支ダッシュボード</span></a>
                                <a href="{{ route('bets.index') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="list" class="w-4 h-4 text-turf-600" /><span>買い目一覧</span></a>
                                <a href="{{ route('bets.create') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="plus" class="w-4 h-4 text-emerald-500" /><span>馬券を登録</span></a>
                                <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                                <a href="{{ route('betting.payouts.list') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="cash" class="w-4 h-4 text-gold-500" /><span>払戻金一覧</span></a>
                                <a href="{{ route('betting.payouts') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="chart" class="w-4 h-4 text-amber-500" /><span>払戻傾向</span></a>
                            </div>
                        </div>

                        <a href="{{ route('notes.index') }}" class="{{ request()->routeIs('notes.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="pencil" class="w-4 h-4" />
                            <span>メモ</span>
                        </a>
                    </div>
                </div>

                <div class="flex items-center space-x-2">
                    {{-- ダークモードトグル --}}
                    <button
                        @click="darkMode = !darkMode"
                        class="hidden md:flex items-center justify-center w-9 h-9 rounded-md hover:bg-turf-600/70 transition-colors"
                        :title="darkMode ? 'ライトモードに切替' : 'ダークモードに切替'"
                    >
                        <span x-show="!darkMode" class="text-lg">🌙</span>
                        <span x-show="darkMode" x-cloak class="text-lg">☀️</span>
                    </button>

                    @auth
                    <div class="hidden md:flex items-center relative" x-data="{ open: false }">
                        <button @click="open = !open" @click.away="open = false" class="flex items-center space-x-2 hover:bg-turf-600/70 px-3 py-2 rounded-md text-sm transition-colors">
                            <div class="w-7 h-7 rounded-full bg-gold-500 text-turf-900 flex items-center justify-center font-bold text-xs">
                                {{ mb_substr(Auth::user()->name, 0, 1) }}
                            </div>
                            <span>{{ Auth::user()->name }}</span>
                            <x-icon name="chevron-down" class="w-3 h-3" />
                        </button>
                        <div x-show="open" x-cloak x-transition class="absolute right-0 top-full mt-2 w-48 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-md shadow-xl z-50 ring-1 ring-black/5 overflow-hidden">
                            <a href="{{ route('profile.edit') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700">
                                <x-icon name="cog" class="w-4 h-4" /><span>プロフィール</span>
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex items-center space-x-2 w-full text-left px-4 py-2.5 hover:bg-red-50 dark:hover:bg-red-900/40 text-red-600 dark:text-red-400">
                                    <x-icon name="logout" class="w-4 h-4" /><span>ログアウト</span>
                                </button>
                            </form>
                        </div>
                    </div>
                    @endauth

                    {{-- モバイルメニュー --}}
                    <button class="md:hidden p-2" @click="menuOpen = !menuOpen">
                        <x-icon name="menu" class="w-6 h-6" />
                    </button>
                </div>
            </div>
        </div>

        <div x-show="menuOpen" x-cloak class="md:hidden bg-turf-800 dark:bg-gray-800">
            <div class="px-2 py-2 space-y-1">
                <a href="{{ route('dashboard') }}" class="block px-3 py-2 rounded hover:bg-turf-600">ダッシュボード</a>
                <a href="{{ route('races.index') }}" class="block px-3 py-2 rounded hover:bg-turf-600">レース</a>
                <a href="{{ route('horses.index') }}" class="block px-3 py-2 rounded hover:bg-turf-600">馬</a>
                <a href="{{ route('jockeys.index') }}" class="block px-3 py-2 rounded hover:bg-turf-600">騎手</a>
                <a href="{{ route('venues.index') }}" class="block px-3 py-2 rounded hover:bg-turf-600">競馬場</a>
                <a href="{{ route('analytics.venue') }}" class="block px-3 py-2 rounded hover:bg-turf-600">分析</a>
                <a href="{{ route('betting.dashboard') }}" class="block px-3 py-2 rounded hover:bg-turf-600">馬券・収支</a>
                <a href="{{ route('import.index') }}" class="block px-3 py-2 rounded hover:bg-turf-600">取込</a>
                <a href="{{ route('notes.index') }}" class="block px-3 py-2 rounded hover:bg-turf-600">メモ</a>
                <button @click="darkMode = !darkMode" class="block w-full text-left px-3 py-2 rounded hover:bg-turf-600">
                    <span x-show="!darkMode">🌙 ダークモード</span>
                    <span x-show="darkMode" x-cloak>☀️ ライトモード</span>
                </button>
                @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full text-left px-3 py-2 rounded hover:bg-red-700">ログアウト</button>
                </form>
                @endauth
            </div>
        </div>
    </nav>

    {{-- バリデーションエラー（フラッシュはトーストで表示済み） --}}
    @if ($errors->any())
    <div class="bg-red-50 dark:bg-red-900/40 border-l-4 border-red-500 text-red-800 dark:text-red-200 px-4 py-3 mx-4 mt-4 rounded shadow">
        <div class="max-w-7xl mx-auto flex items-start space-x-2">
            <x-icon name="warning" class="w-5 h-5 mt-0.5 flex-shrink-0" />
            <ul class="list-disc list-inside text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    {{-- ============== メインコンテンツ ============== --}}
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @yield('content')
    </main>

    {{-- ============== フッター ============== --}}
    <footer class="bg-gray-800 dark:bg-black text-gray-400 text-center text-sm py-4">
        <span class="text-gold-400">🏇</span> © {{ date('Y') }} JRA Analyzer - 個人利用専用
    </footer>
</div>

@stack('scripts')
</body>
</html>
