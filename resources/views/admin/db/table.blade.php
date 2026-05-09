@extends('layouts.app')
@section('title', 'DB: '.$tableMeta['name'])

@section('content')
<div class="flex flex-col lg:flex-row gap-4">
    @include('admin.db._sidebar')

    <div class="flex-1 min-w-0 space-y-4">
        {{-- パンくず + タイトル --}}
        <div>
            <div class="text-xs text-gray-500">
                <a href="{{ route('admin.db.index') }}" class="hover:underline">DBビューア</a>
                / <span class="text-gray-400">{{ $tableMeta['group'] }}</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">
                {{ $tableMeta['label'] }}
                <span class="text-sm font-mono text-gray-500 ml-2">{{ $tableMeta['name'] }}</span>
            </h1>
        </div>

        {{-- KPI --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
                <div class="text-xs text-gray-500">該当行数</div>
                <div class="text-xl font-bold text-primary-700">{{ number_format($totalRows) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
                <div class="text-xs text-gray-500">表示行</div>
                <div class="text-xl font-bold">{{ $items->count() }} / {{ $perPage }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
                <div class="text-xs text-gray-500">列数</div>
                <div class="text-xl font-bold">{{ count($tableMeta['columns']) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
                <div class="text-xs text-gray-500">並び</div>
                <div class="text-sm font-mono">{{ $orderBy }} {{ $orderDir }}</div>
            </div>
        </div>

        {{-- フィルタ --}}
        <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow p-3 flex flex-wrap gap-2 items-end text-sm">
            <div class="flex-1 min-w-[180px]">
                <label class="block text-xs text-gray-500 mb-1">キーワード(テキスト列を OR LIKE)</label>
                <input type="text" name="q" value="{{ $keyword }}" class="w-full border rounded px-2 py-1 dark:bg-gray-700 dark:border-gray-600" placeholder="検索...">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">並び替え</label>
                <select name="order_by" class="border rounded px-2 py-1 dark:bg-gray-700 dark:border-gray-600">
                    @foreach ($tableMeta['columns'] as $col)
                        <option value="{{ $col }}" @selected($orderBy === $col)>{{ $col }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">方向</label>
                <select name="order_dir" class="border rounded px-2 py-1 dark:bg-gray-700 dark:border-gray-600">
                    <option value="desc" @selected($orderDir === 'desc')>降順</option>
                    <option value="asc"  @selected($orderDir === 'asc')>昇順</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">表示行</label>
                <select name="per_page" class="border rounded px-2 py-1 dark:bg-gray-700 dark:border-gray-600">
                    @foreach ([20,50,100,200] as $n)
                        <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-primary-600 text-white px-4 py-1.5 rounded">適用</button>
            <a href="{{ route('admin.db.table', $tableMeta['name']) }}" class="text-xs text-gray-500 hover:underline">クリア</a>
        </form>

        {{-- カラムスキーマ --}}
        <details class="bg-white dark:bg-gray-800 rounded-lg shadow p-3" open>
            <summary class="cursor-pointer font-semibold text-gray-700 dark:text-gray-200">📋 カラム定義 ({{ count($tableMeta['col_detail']) }})</summary>
            <div class="table-scroll mt-3">
                <table class="w-full text-xs min-w-[600px]">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-2 py-1.5 text-left">カラム</th>
                            <th class="px-2 py-1.5 text-left">型</th>
                            <th class="px-2 py-1.5 text-center">NULL</th>
                            <th class="px-2 py-1.5 text-center">KEY</th>
                            <th class="px-2 py-1.5 text-left">デフォルト</th>
                            <th class="px-2 py-1.5 text-left">コメント</th>
                            <th class="px-2 py-1.5 text-left">FK→</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($tableMeta['col_detail'] as $col => $d)
                            <tr>
                                <td class="px-2 py-1 font-mono font-semibold text-gray-800 dark:text-gray-100">{{ $col }}</td>
                                <td class="px-2 py-1 font-mono text-gray-600 dark:text-gray-300">{{ $d['type'] }}</td>
                                <td class="px-2 py-1 text-center">
                                    @if ($d['nullable'])
                                        <span class="text-gray-400">YES</span>
                                    @else
                                        <span class="text-rose-600 font-bold">NO</span>
                                    @endif
                                </td>
                                <td class="px-2 py-1 text-center">
                                    @if ($d['key'] === 'PRI')
                                        <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px] font-bold">PK</span>
                                    @elseif ($d['key'] === 'UNI')
                                        <span class="px-1.5 py-0.5 rounded bg-purple-100 text-purple-800 text-[10px]">UNI</span>
                                    @elseif ($d['key'] === 'MUL')
                                        <span class="px-1.5 py-0.5 rounded bg-sky-100 text-sky-800 text-[10px]">IDX</span>
                                    @endif
                                </td>
                                <td class="px-2 py-1 font-mono text-gray-500">{{ $d['default'] !== null ? $d['default'] : '-' }}</td>
                                <td class="px-2 py-1 text-gray-600 dark:text-gray-300">{{ $d['comment'] ?: '' }}</td>
                                <td class="px-2 py-1">
                                    @if (isset($tableMeta['fk_links'][$col]))
                                        <a href="{{ route('admin.db.table', $tableMeta['fk_links'][$col]) }}" class="text-primary-600 hover:underline font-mono text-[11px]">{{ $tableMeta['fk_links'][$col] }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        {{-- データプレビュー --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-2">🔍 データプレビュー</h2>
            @if ($items->isEmpty())
                <p class="text-sm text-gray-500 p-4 text-center">該当するデータがありません。</p>
            @else
            <div class="table-scroll">
                <table class="w-full text-xs">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 sticky top-0">
                        <tr>
                            @foreach ($tableMeta['columns'] as $col)
                                <th class="px-2 py-1.5 text-left whitespace-nowrap">
                                    <a href="{{ request()->fullUrlWithQuery(['order_by' => $col, 'order_dir' => ($orderBy === $col && $orderDir === 'desc') ? 'asc' : 'desc']) }}"
                                       class="hover:text-primary-600 {{ $orderBy === $col ? 'text-primary-700 font-bold' : '' }}">
                                        {{ $col }}
                                        @if ($orderBy === $col)
                                            {!! $orderDir === 'desc' ? '↓' : '↑' !!}
                                        @endif
                                    </a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700 font-mono">
                        @foreach ($items as $row)
                            <tr class="hover:bg-primary-50/40 dark:hover:bg-primary-900/20">
                                @foreach ($tableMeta['columns'] as $col)
                                    @php
                                        $val = $row->{$col} ?? null;
                                        $disp = is_null($val) ? '' : (string) $val;
                                        $fk   = $tableMeta['fk_links'][$col] ?? null;
                                        $isLong = mb_strlen($disp) > 60;
                                    @endphp
                                    <td class="px-2 py-1 align-top whitespace-nowrap max-w-[280px] overflow-hidden text-ellipsis"
                                        title="{{ $disp }}">
                                        @if (is_null($val))
                                            <span class="text-gray-300 italic">NULL</span>
                                        @elseif ($fk && $val)
                                            <a href="{{ route('admin.db.table', $fk).'?q='.urlencode((string)$val) }}"
                                               class="text-primary-600 hover:underline">→{{ $val }}</a>
                                        @elseif ($isLong)
                                            {{ mb_substr($disp, 0, 60) }}…
                                        @else
                                            {{ $disp }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="text-xs text-gray-500 mt-2">
                {{ number_format($totalRows) }} 行中 上位 {{ $items->count() }} 行を表示中
                @if ($totalRows > $items->count())
                    <span class="text-amber-600">（並び替え/検索で絞り込んでください）</span>
                @endif
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
