@extends('layouts.app')
@section('title', '調教師一覧')

@section('content')
<div class="space-y-4">

    <x-page-header title="調教師一覧" subtitle="勝利数順 ランキング" icon="user" />

    <form method="GET" x-data="{ open: window.innerWidth >= 640 }" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <button type="button" @click="open = !open" class="w-full flex items-center justify-between mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200 sm:cursor-default">
            <span class="flex items-center space-x-2">
                <x-icon name="filter" class="w-4 h-4 text-turf-600 dark:text-turf-400" />
                <span>絞り込み</span>
            </span>
            <x-icon name="chevron-down" class="w-4 h-4 sm:hidden transition-transform" ::class="open ? 'rotate-180' : ''" />
        </button>
        <div x-show="open" x-transition.opacity class="flex flex-wrap gap-3 text-sm items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">キーワード</label>
                <div class="relative">
                    <x-icon name="search" class="w-4 h-4 absolute left-2 top-2 text-gray-400" />
                    <input type="text" name="keyword" value="{{ request('keyword') }}"
                        class="w-full border dark:border-gray-600 rounded pl-7 pr-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">所属</label>
                <select name="belonging" class="border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                    <option value="">すべて</option>
                    @foreach (['美浦','栗東','フリー','地方'] as $b)
                        <option value="{{ $b }}" @selected(request('belonging') == $b)>{{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded-md text-sm font-medium">
                <x-icon name="search" class="w-4 h-4" />
                <span>検索</span>
            </button>
            <a href="{{ route('trainers.index') }}" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 px-3 py-1.5 text-xs">クリア</a>
        </div>
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 table-scroll">
        <table class="w-full text-sm min-w-[640px]">
            <thead class="bg-gray-50 dark:bg-gray-700/60 text-xs text-gray-600 dark:text-gray-300 uppercase">
                <tr>
                    <th class="text-left px-3 py-2.5 w-12">#</th>
                    <th class="text-left px-3 py-2.5">調教師</th>
                    <th class="text-left px-3 py-2.5">カナ</th>
                    <th class="text-left px-3 py-2.5">所属</th>
                    <th class="px-3 py-2.5">勝利数</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($trainers as $i => $t)
                <tr class="border-b dark:border-gray-700 hover:bg-turf-50/40 dark:hover:bg-gray-700/40 transition-colors">
                    <td class="px-3 py-2">
                        @php $rank = ($trainers->currentPage() - 1) * $trainers->perPage() + $i + 1; @endphp
                        @if ($rank === 1)
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300"><x-icon name="trophy" class="w-3.5 h-3.5" /></span>
                        @elseif ($rank === 2)
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 text-xs font-bold">2</span>
                        @elseif ($rank === 3)
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-sand-100 dark:bg-sand-900/40 text-sand-700 dark:text-sand-300 text-xs font-bold">3</span>
                        @else
                            <span class="text-gray-400 dark:text-gray-500 text-xs">{{ $rank }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <a href="{{ route('trainers.show', $t) }}" class="text-turf-700 dark:text-turf-400 hover:underline font-medium">{{ $t->name }}</a>
                    </td>
                    <td class="px-3 py-2 text-gray-500 dark:text-gray-400 text-xs">{{ $t->name_kana }}</td>
                    <td class="px-3 py-2 dark:text-gray-300">
                        @if ($t->belonging)
                            <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-2 py-0.5 rounded">{{ $t->belonging }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center font-bold text-gold-600 dark:text-gold-400">{{ $t->wins ?? 0 }}</td>
                </tr>
                @empty
                <tr><td colspan="5">
                    <x-empty-state icon="user" title="該当する調教師がいません" message="検索条件を変更してください" />
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $trainers->withQueryString()->links() }}</div>
</div>
@endsection
