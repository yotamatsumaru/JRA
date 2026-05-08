@extends('layouts.app')
@section('title', '馬一覧')

@section('content')
<div class="space-y-4">

    <x-page-header title="馬一覧" subtitle="登録馬の検索・管理" icon="sparkles">
        <x-slot name="actions">
            <a href="{{ route('horses.create') }}" class="inline-flex items-center space-x-1.5 bg-turf-600 hover:bg-turf-700 text-white px-4 py-2 rounded-md text-sm font-medium shadow-sm">
                <x-icon name="plus" class="w-4 h-4" />
                <span>馬を登録</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex items-center space-x-2 mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">
            <x-icon name="filter" class="w-4 h-4 text-turf-600 dark:text-turf-400" />
            <span>絞り込み</span>
        </div>
        <div class="flex flex-wrap gap-3 text-sm items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">キーワード（馬名 / 父 / 母）</label>
                <div class="relative">
                    <x-icon name="search" class="w-4 h-4 absolute left-2 top-2 text-gray-400" />
                    <input type="text" name="keyword" value="{{ request('keyword') }}"
                        class="w-full border dark:border-gray-600 rounded pl-7 pr-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                </div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">性別</label>
                <select name="sex" class="border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100">
                    <option value="">すべて</option>
                    <option value="牡" @selected(request('sex')=='牡')>牡</option>
                    <option value="牝" @selected(request('sex')=='牝')>牝</option>
                    <option value="セ" @selected(request('sex')=='セ')>セ</option>
                </select>
            </div>
            <button type="submit" class="inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded-md text-sm font-medium">
                <x-icon name="search" class="w-4 h-4" />
                <span>検索</span>
            </button>
            <a href="{{ route('horses.index') }}" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 px-3 py-1.5 text-xs">クリア</a>
        </div>
    </form>

    {{-- リスト --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700/60 text-xs text-gray-600 dark:text-gray-300 uppercase">
                <tr>
                    <th class="text-left px-3 py-2.5">馬名</th>
                    <th class="px-3 py-2.5">性</th>
                    <th class="text-left px-3 py-2.5">父</th>
                    <th class="text-left px-3 py-2.5">母</th>
                    <th class="text-left px-3 py-2.5">母父</th>
                    <th class="px-3 py-2.5">出走</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($horses as $h)
                <tr class="border-b dark:border-gray-700 hover:bg-turf-50/40 dark:hover:bg-gray-700/40 transition-colors">
                    <td class="px-3 py-2">
                        <a href="{{ route('horses.show', $h) }}" class="text-turf-700 dark:text-turf-400 hover:underline font-medium">{{ $h->name }}</a>
                    </td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $sexColor = match($h->sex) {
                                '牡' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
                                '牝' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
                                'セ' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                default => 'bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
                            };
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded font-medium {{ $sexColor }}">{{ $h->sex }}</span>
                    </td>
                    <td class="px-3 py-2 dark:text-gray-300">{{ $h->father }}</td>
                    <td class="px-3 py-2 dark:text-gray-300">{{ $h->mother }}</td>
                    <td class="px-3 py-2 dark:text-gray-300">{{ $h->mother_father }}</td>
                    <td class="px-3 py-2 text-center dark:text-gray-300">{{ $h->results_count ?? 0 }}</td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('horses.edit', $h) }}" class="inline-flex items-center space-x-1 text-xs text-gray-500 hover:text-turf-700 dark:text-gray-400 dark:hover:text-turf-400">
                            <x-icon name="edit" class="w-3 h-3" />
                            <span>編集</span>
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7">
                    <x-empty-state
                        icon="sparkles"
                        title="馬がまだ登録されていません"
                        message="新規に馬を登録するか、レース取込で自動登録しましょう"
                        actionLabel="馬を登録"
                        actionHref="{{ route('horses.create') }}"
                    />
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $horses->withQueryString()->links() }}</div>
</div>
@endsection
