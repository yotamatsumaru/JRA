@extends('layouts.app')
@section('title', 'ウォッチリスト')

@section('content')
<div class="space-y-5">
    <x-page-header title="ウォッチリスト" subtitle="注目している馬・騎手・厩舎の出走予定をチェック" icon="star" />

    @if (session('status'))
        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 rounded px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 text-rose-800 dark:text-rose-200 rounded px-4 py-2 text-sm">
            @foreach ($errors->all() as $msg)<div>{{ $msg }}</div>@endforeach
        </div>
    @endif

    {{-- 出走予定 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-2">
            <x-icon name="bell" class="w-4 h-4 text-amber-500" />
            <span>出走予定 (今日〜7日後)</span>
            <span class="text-xs text-gray-400 font-normal">{{ count($upcoming) }} レース</span>
        </h2>
        @if (empty($upcoming))
            <p class="text-xs text-gray-500">該当する出走予定はありません。ウォッチリストに馬・騎手・厩舎を追加すると、ここに出走するレースが表示されます。</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/30">
                    <tr>
                        <th class="px-3 py-2 text-left">日付</th>
                        <th class="px-3 py-2 text-left">レース</th>
                        <th class="px-3 py-2 text-left">対象 (ヒット)</th>
                        <th class="px-3 py-2 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($upcoming as $u)
                    @php $race = $u['race']; @endphp
                    <tr>
                        <td class="px-3 py-2 text-xs whitespace-nowrap tabular-nums">{{ $race->race_date?->format('m/d (D)') }}</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('shutuba.show', $race) }}" class="text-turf-700 dark:text-turf-300 hover:underline font-medium">{{ $race->name }}</a>
                            <div class="text-[11px] text-gray-500">{{ $race->venue?->name }} {{ $race->race_number }}R / {{ $race->track_type }}{{ $race->distance }}m</div>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($u['hits'] as $h)
                                    @php
                                        $col = match ($h['type']) {
                                            'horse'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                            'jockey'  => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
                                            'trainer' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                                            default   => 'bg-gray-100 text-gray-700',
                                        };
                                        $label = ['horse'=>'馬','jockey'=>'騎','trainer'=>'厩'][$h['type']] ?? $h['type'];
                                    @endphp
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] {{ $col }}">
                                        <span class="font-bold">{{ $label }}</span>
                                        <span>#{{ $h['horse_no'] ?? '-' }} {{ $h['name'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <a href="{{ route('shutuba.show', $race) }}" class="text-xs text-turf-600 hover:underline">予想ボード →</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- リスト 3 列 --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach (['horse' => '馬', 'jockey' => '騎手', 'trainer' => '厩舎'] as $type => $label)
            @php $list = $items[$type . 's'] ?? collect(); @endphp
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-1">
                    <x-icon name="star" class="w-4 h-4 text-amber-500" />
                    <span>{{ $label }} ({{ $list->count() }})</span>
                </h3>
                @if ($list->isEmpty())
                    <p class="text-xs text-gray-400">登録されていません</p>
                @else
                    <ul class="space-y-1.5">
                        @foreach ($list as $w)
                        <li class="flex items-center justify-between gap-2 text-xs">
                            <div class="flex-1 min-w-0">
                                <div class="font-medium truncate">{{ $w->label ?? '#'.$w->target_id }}</div>
                                @if ($w->memo)
                                    <div class="text-[11px] text-gray-500 truncate">{{ $w->memo }}</div>
                                @endif
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                @if ($w->alert_on_entry)
                                    <span title="出走時アラート ON" class="text-amber-500"><x-icon name="bell" class="w-3 h-3" /></span>
                                @endif
                                <form method="POST" action="{{ route('watchlist.destroy', $w) }}" onsubmit="return confirm('削除しますか?')">
                                    @csrf @method('DELETE')
                                    <button class="text-rose-500 hover:text-rose-700">×</button>
                                </form>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    <div class="text-[11px] text-gray-500">
        ※ ウォッチリストへの追加は <a class="text-turf-600 hover:underline" href="{{ route('horses.index') }}">馬一覧</a> /
        <a class="text-turf-600 hover:underline" href="{{ route('jockeys.index') }}">騎手一覧</a> の各詳細ページから行えます。
    </div>
</div>
@endsection
