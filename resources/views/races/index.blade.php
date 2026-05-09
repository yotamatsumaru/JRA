@extends('layouts.app')
@section('title', 'レース一覧')

@section('content')
<div class="space-y-4">

    <x-page-header title="レース一覧" subtitle="登録済みレースの検索・管理" icon="flag">
        <x-slot name="actions">
            <a href="{{ route('races.create') }}" class="inline-flex items-center space-x-1.5 bg-turf-600 hover:bg-turf-700 text-white px-4 py-2 rounded-md text-sm font-medium shadow-sm">
                <x-icon name="plus" class="w-4 h-4" />
                <span>新規レース登録</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- フィルタ --}}
    <form method="GET" x-data="{ open: window.innerWidth >= 640 }" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <button type="button" @click="open = !open" class="w-full flex items-center justify-between mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200 sm:cursor-default">
            <span class="flex items-center space-x-2">
                <x-icon name="filter" class="w-4 h-4 text-turf-600 dark:text-turf-400" />
                <span>絞り込み</span>
            </span>
            <x-icon name="chevron-down" class="w-4 h-4 sm:hidden transition-transform" ::class="open ? 'rotate-180' : ''" />
        </button>
        <div x-show="open" x-transition.opacity class="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">競馬場</label>
                <select name="venue_id" class="w-full border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                    <option value="">すべて</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected(request('venue_id') == $v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">グレード</label>
                <select name="grade" class="w-full border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                    <option value="">すべて</option>
                    @foreach (['G1','G2','G3','OP','L','3勝','2勝','1勝','未勝利','新馬'] as $g)
                        <option value="{{ $g }}" @selected(request('grade') == $g)>{{ $g }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">トラック</label>
                <select name="track_type" class="w-full border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                    <option value="">すべて</option>
                    @foreach (['芝','ダート','障害'] as $t)
                        <option value="{{ $t }}" @selected(request('track_type') == $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">From</label>
                <input type="date" name="from" value="{{ request('from') }}" class="w-full border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">To</label>
                <input type="date" name="to" value="{{ request('to') }}" class="w-full border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">キーワード</label>
                <div class="relative">
                    <x-icon name="search" class="w-4 h-4 absolute left-2 top-2 text-gray-400" />
                    <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="レース名"
                        class="w-full border dark:border-gray-600 rounded pl-7 pr-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                </div>
            </div>
        </div>
        <div x-show="open" x-transition.opacity class="flex justify-end space-x-2 mt-3">
            <a href="{{ route('races.index') }}" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 px-3 py-1.5 text-xs">クリア</a>
            <button type="submit" class="inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded-md text-sm font-medium">
                <x-icon name="search" class="w-4 h-4" />
                <span>検索</span>
            </button>
        </div>
    </form>

    {{-- レーステーブル --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 table-scroll">
        <table class="w-full text-sm min-w-[760px]">
            <thead class="bg-gray-50 dark:bg-gray-700/60 text-xs text-gray-600 dark:text-gray-300 uppercase">
                <tr>
                    <th class="text-left px-3 py-2.5">日付</th>
                    <th class="text-left px-3 py-2.5">場</th>
                    <th class="text-left px-3 py-2.5">R</th>
                    <th class="text-left px-3 py-2.5">レース名</th>
                    <th class="text-left px-3 py-2.5">グレード</th>
                    <th class="text-left px-3 py-2.5">トラック</th>
                    <th class="text-left px-3 py-2.5">距離</th>
                    <th class="text-left px-3 py-2.5">頭数</th>
                    <th class="text-right px-3 py-2.5"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($races as $r)
                <tr class="border-b dark:border-gray-700 hover:bg-turf-50/40 dark:hover:bg-gray-700/40 transition-colors">
                    <td class="px-3 py-2 dark:text-gray-300">{{ $r->race_date?->format('Y/m/d') }}</td>
                    <td class="px-3 py-2 dark:text-gray-300">{{ $r->venue?->name }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $r->race_number }}R</td>
                    <td class="px-3 py-2">
                        <a href="{{ route('races.show', $r) }}" class="text-turf-700 dark:text-turf-400 hover:underline font-medium">{{ $r->name }}</a>
                    </td>
                    <td class="px-3 py-2">
                        @if ($r->grade) <span class="text-xs bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300 px-2 py-0.5 rounded font-medium">{{ $r->grade }}</span> @endif
                    </td>
                    <td class="px-3 py-2">
                        <span class="@if($r->track_type === '芝') text-turf-700 dark:text-turf-400 @elseif($r->track_type === 'ダート') text-sand-700 dark:text-sand-400 @endif">
                            {{ $r->track_type }}
                        </span>
                    </td>
                    <td class="px-3 py-2 dark:text-gray-300">{{ $r->distance }}m</td>
                    <td class="px-3 py-2 dark:text-gray-300">{{ $r->results_count ?? 0 }}</td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('races.edit', $r) }}" class="inline-flex items-center space-x-1 text-xs text-gray-500 hover:text-turf-700 dark:text-gray-400 dark:hover:text-turf-400">
                            <x-icon name="edit" class="w-3 h-3" />
                            <span>編集</span>
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9">
                    <x-empty-state
                        icon="flag"
                        title="レースがまだ登録されていません"
                        message="新規レースを登録するか、netkeibaから取込みましょう"
                        actionLabel="レースを登録"
                        actionHref="{{ route('races.create') }}"
                    />
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $races->withQueryString()->links() }}</div>
</div>
@endsection
