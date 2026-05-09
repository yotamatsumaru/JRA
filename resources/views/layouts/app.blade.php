<!DOCTYPE html>
<html lang="ja" x-data="{ darkMode: localStorage.getItem('jra_dark') === 'true' }" :class="{ 'dark': darkMode }" x-init="$watch('darkMode', v => localStorage.setItem('jra_dark', v))">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#15803d" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#052e16" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="JRA">
    <meta name="format-detection" content="telephone=no">

    {{-- Phase 6-P: PWA --}}
    <link rel="manifest" href="{{ url('/manifest.json') }}">
    <link rel="icon" type="image/svg+xml" href="{{ url('/icon.svg') }}">
    <link rel="apple-touch-icon" href="{{ url('/icon.svg') }}">

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

        /* ===== スマホ最適化 ===== */
        html { -webkit-text-size-adjust: 100%; }
        body {
            -webkit-tap-highlight-color: transparent;
            overscroll-behavior-y: none;
        }
        /* iOS Safe Area */
        .safe-area-bottom { padding-bottom: env(safe-area-inset-bottom); }
        .safe-area-top    { padding-top: env(safe-area-inset-top); }

        /* スマホで横スクロールできるテーブルラッパ */
        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        .table-scroll::-webkit-scrollbar { height: 6px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .dark .table-scroll::-webkit-scrollbar-thumb { background: #4b5563; }

        /* スマホ時のフォーム入力でズームを防ぐ (iOS 16px未満で自動ズーム発生) */
        @media (max-width: 640px) {
            input[type="text"], input[type="number"], input[type="date"],
            input[type="email"], input[type="password"], input[type="search"],
            select, textarea {
                font-size: 16px !important;
            }
        }

        /* タッチ操作で押しやすいボタン最小サイズ */
        @media (max-width: 640px) {
            .btn, button, [role="button"], a.btn-touch {
                min-height: 40px;
            }
        }
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
            <span class="leading-none">
                <x-icon name="check"   x-show="t.type === 'success'" class="w-5 h-5" />
                <x-icon name="x-mark"  x-show="t.type === 'error'"   class="w-5 h-5" />
                <x-icon name="warning" x-show="t.type === 'warning'" class="w-5 h-5" />
                <x-icon name="info"    x-show="t.type === 'info'"    class="w-5 h-5" />
            </span>
            <div class="flex-1 text-sm" x-text="t.message"></div>
            <button @click="remove(t.id)" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 leading-none"><x-icon name="x-mark" class="w-4 h-4" /></button>
        </div>
    </template>
</div>

<div class="min-h-screen flex flex-col" x-data="{ menuOpen: false }">

    {{-- ============== ヘッダー ============== --}}
    <nav class="bg-gradient-to-r from-turf-700 to-turf-900 dark:from-turf-900 dark:to-gray-900 text-white shadow-md sticky top-0 z-40">
        <div class="w-full px-4 sm:px-6 lg:px-8 xl:px-10 2xl:px-14">
            <div class="flex justify-between h-16 items-center">
                <div class="flex items-center space-x-6">
                    <a href="{{ route('dashboard') }}" class="flex items-center space-x-2 font-bold text-lg group">
                        <x-icon name="horse" class="w-7 h-7 group-hover:scale-110 transition-transform text-gold-400" />
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
                        <a href="{{ route('shutuba.index') }}" class="{{ request()->routeIs('shutuba.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="target" class="w-4 h-4" />
                            <span>出馬表</span>
                        </a>
                        <a href="{{ route('horses.index') }}" class="{{ request()->routeIs('horses.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="horse" class="w-4 h-4" />
                            <span>馬</span>
                        </a>
                        <a href="{{ route('jockeys.index') }}" class="{{ request()->routeIs('jockeys.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="user" class="w-4 h-4" />
                            <span>騎手</span>
                        </a>
                        <a href="{{ route('trainers.index') }}" class="{{ request()->routeIs('trainers.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="user" class="w-4 h-4" />
                            <span>調教師</span>
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
                                <a href="{{ route('analytics.course-trends') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="map" class="w-4 h-4 text-emerald-600" /><span>コース別傾向</span></a>
                                <a href="{{ route('analytics.pace') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="bolt" class="w-4 h-4 text-amber-500" /><span>ペース分析</span></a>
                                <a href="{{ route('analytics.pedigree.overview') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="dna" class="w-4 h-4 text-purple-500" /><span>血統分析(トップ)</span></a>
                                <a href="{{ route('analytics.pedigree.sires') }}" class="flex items-center space-x-2 px-4 py-2.5 pl-8 hover:bg-turf-50 dark:hover:bg-gray-700 text-sm"><x-icon name="crown" class="w-3.5 h-3.5 text-amber-500" /><span>父ランキング</span></a>
                                <a href="{{ route('analytics.pedigree.broodmares') }}" class="flex items-center space-x-2 px-4 py-2.5 pl-8 hover:bg-turf-50 dark:hover:bg-gray-700 text-sm"><x-icon name="flower" class="w-3.5 h-3.5 text-pink-500" /><span>母父ランキング</span></a>
                                <a href="{{ route('analytics.pedigree.heatmap') }}" class="flex items-center space-x-2 px-4 py-2.5 pl-8 hover:bg-turf-50 dark:hover:bg-gray-700 text-sm"><x-icon name="fire" class="w-3.5 h-3.5 text-rose-500" /><span>ヒートマップ</span></a>
                                <a href="{{ route('analytics.pedigree') }}" class="flex items-center space-x-2 px-4 py-2.5 pl-8 hover:bg-turf-50 dark:hover:bg-gray-700 text-sm"><x-icon name="search" class="w-3.5 h-3.5 text-purple-400" /><span>父詳細</span></a>
                                <a href="{{ route('analytics.jockey') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="user" class="w-4 h-4 text-sky-500" /><span>騎手×コース</span></a>
                                <a href="{{ route('analytics.horse') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="trophy" class="w-4 h-4 text-rose-500" /><span>馬×コース優位性</span></a>
                                <a href="{{ route('analytics.stats') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="trophy" class="w-4 h-4 text-gold-500" /><span>通算成績スタッツ</span></a>
                                <a href="{{ route('analytics.roi') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="cash" class="w-4 h-4 text-gold-500" /><span>回収率シミュ</span></a>
                                <div class="border-t my-1"></div>
                                <a href="{{ route('analytics.prediction-accuracy') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="target" class="w-4 h-4 text-rose-500" /><span>予想精度トラッキング</span></a>
                                <a href="{{ route('analytics.pace-style') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="grid" class="w-4 h-4 text-indigo-500" /><span>コース×ペース×脚質</span></a>
                                <div class="border-t my-1"></div>
                                <a href="{{ route('analytics.recommend.index') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="lightbulb" class="w-4 h-4 text-amber-500" /><span>推奨(血統+騎手+馬)</span></a>
                                <a href="{{ route('analytics.recommend.settings') }}" class="flex items-center space-x-2 px-4 py-2.5 pl-8 hover:bg-turf-50 dark:hover:bg-gray-700 text-sm"><x-icon name="cog" class="w-3.5 h-3.5 text-amber-400" /><span>重み設定</span></a>
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
                            <button @click="open = !open" @click.away="open = false" class="{{ request()->routeIs('admin.db.*') ? $active : $inactive }} flex items-center space-x-1.5">
                                <x-icon name="database" class="w-4 h-4" />
                                <span>DB</span>
                                <x-icon name="chevron-down" class="w-3 h-3" />
                            </button>
                            <div x-show="open" x-cloak x-transition class="absolute mt-2 w-52 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-md shadow-xl z-50 ring-1 ring-black/5 overflow-hidden">
                                <a href="{{ route('admin.db.index') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="home" class="w-4 h-4 text-turf-600" /><span>DBトップ</span></a>
                                <a href="{{ route('admin.db.stats') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="chart" class="w-4 h-4 text-sky-500" /><span>DB統計</span></a>
                                <a href="{{ route('admin.db.schema') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="map" class="w-4 h-4 text-emerald-500" /><span>ER図</span></a>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="{{ request()->routeIs('bets.*') || request()->routeIs('betting.*') ? $active : $inactive }} flex items-center space-x-1.5">
                                <x-icon name="cash" class="w-4 h-4" />
                                <span>馬券</span>
                                <x-icon name="chevron-down" class="w-3 h-3" />
                            </button>
                            <div x-show="open" x-cloak x-transition class="absolute mt-2 w-56 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-md shadow-xl z-50 ring-1 ring-black/5 overflow-hidden">
                                <a href="{{ route('betting.dashboard') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="chart" class="w-4 h-4 text-gold-500" /><span>収支ダッシュボード</span></a>
                                <a href="{{ route('bets.index') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="list" class="w-4 h-4 text-turf-600" /><span>買い目一覧</span></a>
                                <a href="{{ route('bets.create') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="plus" class="w-4 h-4 text-emerald-500" /><span>馬券を登録</span></a>
                                <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                                <a href="{{ route('bankroll.index') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="cash" class="w-4 h-4 text-amber-500" /><span>バンクロール管理</span></a>
                                <a href="{{ route('bets.whatif') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="sparkles" class="w-4 h-4 text-purple-500" /><span>What-if シミュレーション</span></a>
                                <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                                <a href="{{ route('betting.payouts.list') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="cash" class="w-4 h-4 text-gold-500" /><span>払戻金一覧</span></a>
                                <a href="{{ route('betting.payouts') }}" class="flex items-center space-x-2 px-4 py-2.5 hover:bg-turf-50 dark:hover:bg-gray-700"><x-icon name="chart" class="w-4 h-4 text-amber-500" /><span>払戻傾向</span></a>
                            </div>
                        </div>

                        <a href="{{ route('operations.index') }}" class="{{ request()->routeIs('operations.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="cog" class="w-4 h-4" />
                            <span>運用</span>
                        </a>

                        <a href="{{ route('watchlist.index') }}" class="{{ request()->routeIs('watchlist.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="star" class="w-4 h-4" />
                            <span>ウォッチ</span>
                        </a>

                        <a href="{{ route('shares.index') }}" class="{{ request()->routeIs('shares.*') ? $active : $inactive }} flex items-center space-x-1.5">
                            <x-icon name="share" class="w-4 h-4" />
                            <span>共有</span>
                        </a>

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
                        <x-icon name="moon" x-show="!darkMode" class="w-5 h-5" />
                        <x-icon name="sun" x-show="darkMode" x-cloak class="w-5 h-5" />
                    </button>

                    @auth
                    {{-- Phase 6-A: 通知ベル --}}
                    <a href="{{ route('notifications.index') }}"
                       class="hidden md:inline-flex items-center justify-center relative w-9 h-9 rounded-md hover:bg-turf-600/70 transition-colors"
                       title="通知センター">
                        <x-icon name="bell" class="w-5 h-5" />
                        @if(($headerUnreadNotifications ?? 0) > 0)
                            <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 text-[10px] font-bold rounded-full bg-rose-500 text-white ring-2 ring-turf-700">
                                {{ $headerUnreadNotifications > 99 ? '99+' : $headerUnreadNotifications }}
                            </span>
                        @endif
                    </a>

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

        {{-- ============== モバイルメニュー (フルパネル) ============== --}}
        <div
            x-show="menuOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="md:hidden bg-turf-800 dark:bg-gray-800 max-h-[calc(100vh-4rem)] overflow-y-auto safe-area-bottom"
        >
            <div class="px-2 py-2 space-y-0.5 text-sm">
                @php
                    $mLink   = 'flex items-center gap-2 px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700';
                    $mActive = 'flex items-center gap-2 px-3 py-2.5 rounded bg-turf-900/80 ring-1 ring-white/10';
                    $mSub    = 'flex items-center gap-2 pl-8 pr-3 py-2 rounded hover:bg-turf-600 active:bg-turf-700 text-[13px] text-turf-100';
                @endphp

                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="home" class="w-4 h-4" /> ダッシュボード
                </a>
                <a href="{{ route('races.index') }}" class="{{ request()->routeIs('races.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="flag" class="w-4 h-4" /> レース
                </a>
                <a href="{{ route('shutuba.index') }}" class="{{ request()->routeIs('shutuba.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="target" class="w-4 h-4" /> 出馬表
                </a>
                <a href="{{ route('horses.index') }}" class="{{ request()->routeIs('horses.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="horse" class="w-4 h-4" /> 馬
                </a>
                <a href="{{ route('jockeys.index') }}" class="{{ request()->routeIs('jockeys.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="user" class="w-4 h-4" /> 騎手
                </a>
                <a href="{{ route('trainers.index') }}" class="{{ request()->routeIs('trainers.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="user" class="w-4 h-4" /> 調教師
                </a>
                <a href="{{ route('venues.index') }}" class="{{ request()->routeIs('venues.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="map" class="w-4 h-4" /> 競馬場
                </a>

                {{-- 分析(折り畳み) --}}
                <div x-data="{ o: {{ request()->routeIs('analytics.*') ? 'true' : 'false' }} }" class="rounded">
                    <button @click="o = !o" class="w-full flex items-center justify-between px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700">
                        <span class="flex items-center gap-2"><x-icon name="chart" class="w-4 h-4" /> 分析</span>
                        <x-icon name="chevron-down" class="w-3 h-3 transition-transform" ::class="o ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="o" x-cloak class="space-y-0.5">
                        <a href="{{ route('analytics.venue') }}"        class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="map" class="w-4 h-4" /> 競馬場別傾向</a>
                        <a href="{{ route('analytics.course-trends') }}" class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="map" class="w-4 h-4" /> コース別傾向</a>
                        <a href="{{ route('analytics.pace') }}"         class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="bolt" class="w-4 h-4" /> ペース分析</a>
                        <a href="{{ route('analytics.pedigree.overview') }}" class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="dna" class="w-4 h-4" /> 血統分析(トップ)</a>
                        <a href="{{ route('analytics.pedigree.sires') }}" class="{{ $mSub }} pl-7 text-xs" @click="menuOpen=false"><x-icon name="crown" class="w-3.5 h-3.5" /> 父ランキング</a>
                        <a href="{{ route('analytics.pedigree.broodmares') }}" class="{{ $mSub }} pl-7 text-xs" @click="menuOpen=false"><x-icon name="flower" class="w-3.5 h-3.5" /> 母父ランキング</a>
                        <a href="{{ route('analytics.pedigree.heatmap') }}" class="{{ $mSub }} pl-7 text-xs" @click="menuOpen=false"><x-icon name="fire" class="w-3.5 h-3.5" /> ヒートマップ</a>
                        <a href="{{ route('analytics.pedigree') }}" class="{{ $mSub }} pl-7 text-xs" @click="menuOpen=false"><x-icon name="search" class="w-3.5 h-3.5" /> 父詳細</a>
                        <a href="{{ route('analytics.jockey') }}"   class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="user" class="w-4 h-4" /> 騎手×コース</a>
                        <a href="{{ route('analytics.horse') }}"    class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="trophy" class="w-4 h-4" /> 馬×コース優位性</a>
                        <a href="{{ route('analytics.stats') }}"    class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="chart" class="w-4 h-4" /> 通算成績スタッツ</a>
                        <a href="{{ route('analytics.roi') }}"      class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="cash" class="w-4 h-4" /> 回収率シミュ</a>
                        <div class="border-t border-turf-700 my-1"></div>
                        <a href="{{ route('analytics.prediction-accuracy') }}" class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="target" class="w-4 h-4" /> 予想精度トラッキング</a>
                        <a href="{{ route('analytics.pace-style') }}" class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="grid" class="w-4 h-4" /> コース×ペース×脚質</a>
                        <div class="border-t border-turf-700 my-1"></div>
                        <a href="{{ route('analytics.recommend.index') }}" class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="lightbulb" class="w-4 h-4" /> 推奨(血統+騎手+馬)</a>
                        <a href="{{ route('analytics.recommend.settings') }}" class="{{ $mSub }} pl-7 text-xs" @click="menuOpen=false"><x-icon name="cog" class="w-3.5 h-3.5" /> 重み設定</a>
                    </div>
                </div>

                {{-- 取込(折り畳み) --}}
                <div x-data="{ o: {{ request()->routeIs('import.*') ? 'true' : 'false' }} }" class="rounded">
                    <button @click="o = !o" class="w-full flex items-center justify-between px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700">
                        <span class="flex items-center gap-2"><x-icon name="upload" class="w-4 h-4" /> 取込</span>
                        <x-icon name="chevron-down" class="w-3 h-3 transition-transform" ::class="o ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="o" x-cloak class="space-y-0.5">
                        <a href="{{ route('import.netkeiba') }}" class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="globe" class="w-4 h-4" /> netkeibaから取込</a>
                        <a href="{{ route('import.csv') }}"      class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="document" class="w-4 h-4" /> CSV取込</a>
                        <a href="{{ route('import.image') }}"    class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="camera" class="w-4 h-4" /> 画像取込(GPT-4o)</a>
                        <a href="{{ route('import.logs') }}"     class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="list" class="w-4 h-4" /> 取込ログ</a>
                    </div>
                </div>

                {{-- DBビューア(折り畳み) --}}
                <div x-data="{ o: {{ request()->routeIs('admin.db.*') ? 'true' : 'false' }} }" class="rounded">
                    <button @click="o = !o" class="w-full flex items-center justify-between px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700">
                        <span class="flex items-center gap-2"><x-icon name="database" class="w-4 h-4" /> DBビューア</span>
                        <x-icon name="chevron-down" class="w-3 h-3 transition-transform" ::class="o ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="o" x-cloak class="space-y-0.5">
                        <a href="{{ route('admin.db.index') }}"  class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="home" class="w-4 h-4" /> DBトップ</a>
                        <a href="{{ route('admin.db.stats') }}"  class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="chart" class="w-4 h-4" /> DB統計</a>
                        <a href="{{ route('admin.db.schema') }}" class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="map" class="w-4 h-4" /> ER図</a>
                    </div>
                </div>

                {{-- 馬券(折り畳み) --}}
                <div x-data="{ o: {{ request()->routeIs('bets.*') || request()->routeIs('betting.*') ? 'true' : 'false' }} }" class="rounded">
                    <button @click="o = !o" class="w-full flex items-center justify-between px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700">
                        <span class="flex items-center gap-2"><x-icon name="cash" class="w-4 h-4" /> 馬券</span>
                        <x-icon name="chevron-down" class="w-3 h-3 transition-transform" ::class="o ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="o" x-cloak class="space-y-0.5">
                        <a href="{{ route('betting.dashboard') }}"     class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="chart" class="w-4 h-4" /> 収支ダッシュボード</a>
                        <a href="{{ route('bets.index') }}"            class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="list" class="w-4 h-4" /> 買い目一覧</a>
                        <a href="{{ route('bets.create') }}"           class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="plus" class="w-4 h-4" /> 馬券を登録</a>
                        <a href="{{ route('bankroll.index') }}"        class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="cash" class="w-4 h-4" /> バンクロール管理</a>
                        <a href="{{ route('bets.whatif') }}"           class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="sparkles" class="w-4 h-4" /> What-if</a>
                        <a href="{{ route('betting.payouts.list') }}"  class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="cash" class="w-4 h-4" /> 払戻金一覧</a>
                        <a href="{{ route('betting.payouts') }}"       class="{{ $mSub }}" @click="menuOpen=false"><x-icon name="chart" class="w-4 h-4" /> 払戻傾向</a>
                    </div>
                </div>

                <a href="{{ route('operations.index') }}" class="{{ request()->routeIs('operations.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="cog" class="w-4 h-4" /> 運用ダッシュボード
                </a>

                <a href="{{ route('watchlist.index') }}" class="{{ request()->routeIs('watchlist.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="star" class="w-4 h-4" /> ウォッチリスト
                </a>

                <a href="{{ route('shares.index') }}" class="{{ request()->routeIs('shares.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="share" class="w-4 h-4" /> 予想共有
                </a>

                <a href="{{ route('notes.index') }}" class="{{ request()->routeIs('notes.*') ? $mActive : $mLink }}" @click="menuOpen=false">
                    <x-icon name="pencil" class="w-4 h-4" /> メモ
                </a>

                <div class="border-t border-turf-700 dark:border-gray-700 my-2"></div>

                <button @click="darkMode = !darkMode" class="w-full flex items-center gap-2 px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700">
                    <span x-show="!darkMode" class="flex items-center gap-2"><x-icon name="moon" class="w-4 h-4" /> ダークモード</span>
                    <span x-show="darkMode" x-cloak class="flex items-center gap-2"><x-icon name="sun" class="w-4 h-4" /> ライトモード</span>
                </button>
                @auth
                <a href="{{ route('notifications.index') }}" class="flex items-center justify-between gap-2 px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700" @click="menuOpen=false">
                    <span class="flex items-center gap-2"><x-icon name="bell" class="w-4 h-4" /> 通知</span>
                    @if(($headerUnreadNotifications ?? 0) > 0)
                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 text-[10px] font-bold rounded-full bg-rose-500 text-white">
                            {{ $headerUnreadNotifications > 99 ? '99+' : $headerUnreadNotifications }}
                        </span>
                    @endif
                </a>
                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 px-3 py-2.5 rounded hover:bg-turf-600 active:bg-turf-700" @click="menuOpen=false">
                    <x-icon name="cog" class="w-4 h-4" /> プロフィール
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full flex items-center gap-2 px-3 py-2.5 rounded hover:bg-red-700 text-red-200">
                        <x-icon name="logout" class="w-4 h-4" /> ログアウト
                    </button>
                </form>
                @endauth
            </div>
        </div>
    </nav>

    {{-- バリデーションエラー（フラッシュはトーストで表示済み） --}}
    @if ($errors->any())
    <div class="bg-red-50 dark:bg-red-900/40 border-l-4 border-red-500 text-red-800 dark:text-red-200 px-4 py-3 mx-4 mt-4 rounded shadow">
        <div class="w-full flex items-start space-x-2">
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
    <main class="flex-1 w-full px-4 sm:px-6 lg:px-8 xl:px-10 2xl:px-14 py-6">
        @yield('content')
    </main>

    {{-- ============== フッター ============== --}}
    <footer class="bg-gray-800 dark:bg-black text-gray-400 text-center text-sm py-4">
        <span class="inline-flex items-center gap-1.5"><x-icon name="horse" class="w-4 h-4 text-gold-400" /> © {{ date('Y') }} JRA Analyzer - 個人利用専用</span>
    </footer>
</div>

@stack('scripts')

{{-- Phase 6-P: Service Worker 登録 --}}
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('{{ url('/service-worker.js') }}', { scope: '{{ url('/') }}/' })
                .then(function (reg) {
                    // 更新検知 (バックグラウンドで通知)
                    if (reg && reg.waiting) {
                        try { reg.waiting.postMessage({ type: 'SKIP_WAITING' }); } catch (e) {}
                    }
                })
                .catch(function () { /* SW登録失敗は致命的でないため握り潰す */ });
        });
    }
</script>
</body>
</html>
