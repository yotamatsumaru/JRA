@extends('layouts.app')
@section('title', 'DB スキーマ図')

@section('content')
<div class="flex flex-col lg:flex-row gap-4">
    @include('admin.db._sidebar')

    <div class="flex-1 min-w-0 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs text-gray-500">
                    <a href="{{ route('admin.db.index') }}" class="hover:underline">DBビューア</a>
                </div>
                <h1 class="inline-flex items-center gap-2 text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">
                    <x-icon name="map" class="w-6 h-6 text-primary-600" />
                    <span>ER図 (スキーマ可視化)</span>
                </h1>
            </div>
            <div class="text-xs text-gray-500">{{ count($entities) }} テーブル / {{ count($relations) }} リレーション</div>
        </div>

        {{-- Mermaid ER図 --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 overflow-auto">
            <pre class="mermaid text-xs">
erDiagram
@foreach ($entities as $name => $e)
    {{ $name }} {
@foreach ($e['columns'] as $col => $d)
@php
    $type = preg_replace('/\(.*$/', '', $d['type']);
    $type = $type ?: 'unknown';
    $marker = '';
    if ($d['key'] === 'PRI') $marker = ' PK';
    elseif ($d['key'] === 'UNI') $marker = ' UK';
    elseif ($d['key'] === 'MUL') $marker = ' FK';
@endphp
        {{ $type }} {{ $col }}{{ $marker }}
@endforeach
    }
@endforeach
@foreach ($relations as $rel)
    {{ $rel[2] }} ||--o{ {{ $rel[0] }} : "{{ $rel[1] }}"
@endforeach
            </pre>
        </div>

        {{-- リレーション一覧テーブル --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h2 class="inline-flex items-center gap-1.5 font-semibold text-gray-700 dark:text-gray-200 mb-3">
                <x-icon name="globe" class="w-5 h-5" />
                <span>リレーション一覧</span>
            </h2>
            <div class="table-scroll">
                <table class="w-full text-xs sm:text-sm min-w-[600px]">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-2 py-2 text-left">子テーブル</th>
                            <th class="px-2 py-2 text-left">FKカラム</th>
                            <th class="px-2 py-2 text-center">→</th>
                            <th class="px-2 py-2 text-left">親テーブル</th>
                            <th class="px-2 py-2 text-left">参照カラム</th>
                            <th class="px-2 py-2 text-left">種別</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700 font-mono">
                        @foreach ($relations as $rel)
                            @php [$ft, $fc, $tt, $tc, $kind] = $rel; @endphp
                            <tr class="hover:bg-primary-50/40">
                                <td class="px-2 py-1.5">
                                    <a href="{{ route('admin.db.table', $ft) }}" class="text-primary-600 hover:underline">{{ $ft }}</a>
                                </td>
                                <td class="px-2 py-1.5 text-gray-700 dark:text-gray-300">{{ $fc }}</td>
                                <td class="px-2 py-1.5 text-center text-gray-400">→</td>
                                <td class="px-2 py-1.5">
                                    <a href="{{ route('admin.db.table', $tt) }}" class="text-primary-600 hover:underline">{{ $tt }}</a>
                                </td>
                                <td class="px-2 py-1.5 text-gray-700 dark:text-gray-300">{{ $tc }}</td>
                                <td class="px-2 py-1.5 text-gray-500">{{ $kind }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900 leading-relaxed">
            <p class="inline-flex items-center gap-1.5 font-semibold mb-1">
                <x-icon name="info" class="w-4 h-4" />
                <span>ER図の凡例</span>
            </p>
            <ul class="list-disc list-inside space-y-0.5">
                <li><strong>PK</strong>: Primary Key (主キー)</li>
                <li><strong>UK</strong>: Unique Key (一意キー)</li>
                <li><strong>FK</strong>: 外部キーまたはインデックス</li>
                <li><strong>||--o{</strong>: 1対多 (1つの親 — 0以上の子)</li>
            </ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/mermaid@10.9.0/dist/mermaid.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = document.documentElement.classList.contains('dark');
    mermaid.initialize({
        startOnLoad: true,
        theme: isDark ? 'dark' : 'default',
        er: { useMaxWidth: true },
        securityLevel: 'loose',
    });
});
</script>
@endsection
