@extends('layouts.app')
@section('title', '予想共有')

@section('content')
<div class="space-y-5">
    <x-page-header title="予想スナップショット共有" subtitle="印・スコア・メモを read-only URL で公開" icon="share" />

    @if (session('status'))
        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 rounded px-4 py-2 text-sm break-all">{{ session('status') }}</div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">作成済み共有</h2>
        @if ($shares->isEmpty())
            <p class="text-xs text-gray-500">共有はまだありません。出馬表ボードの「予想を共有」ボタンから作成できます。</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/30">
                    <tr>
                        <th class="px-3 py-2 text-left">作成日時</th>
                        <th class="px-3 py-2 text-left">レース / タイトル</th>
                        <th class="px-3 py-2 text-right">閲覧</th>
                        <th class="px-3 py-2 text-left">期限 / 状態</th>
                        <th class="px-3 py-2 text-left">URL</th>
                        <th class="px-3 py-2 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($shares as $s)
                    <tr>
                        <td class="px-3 py-2 text-xs tabular-nums whitespace-nowrap">{{ $s->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $s->title ?: $s->race?->name }}</div>
                            <div class="text-[11px] text-gray-500">
                                {{ $s->race?->race_date?->format('Y/m/d') }} {{ $s->race?->venue?->name }} {{ $s->race?->race_number }}R
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right text-xs tabular-nums">
                            {{ number_format($s->view_count) }}
                            @if ($s->last_viewed_at)
                                <div class="text-[10px] text-gray-400">最終: {{ $s->last_viewed_at->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if (!$s->is_active)
                                <span class="inline-block px-2 py-0.5 rounded bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300">停止中</span>
                            @elseif ($s->expires_at && $s->expires_at->isPast())
                                <span class="inline-block px-2 py-0.5 rounded bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">期限切れ</span>
                            @else
                                <span class="inline-block px-2 py-0.5 rounded bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">公開中</span>
                                @if ($s->expires_at)
                                    <div class="text-[10px] text-gray-500 mt-0.5">{{ $s->expires_at->format('Y-m-d') }} まで</div>
                                @endif
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <div class="flex items-center gap-2" x-data="{ copied: false }">
                                <input type="text" readonly value="{{ $s->public_url }}"
                                    class="w-64 max-w-full text-[11px] font-mono bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded px-2 py-1"
                                    onclick="this.select()">
                                <button type="button"
                                    @click="navigator.clipboard.writeText('{{ $s->public_url }}'); copied=true; setTimeout(()=>copied=false, 1500)"
                                    class="text-[11px] text-turf-600 hover:underline whitespace-nowrap">
                                    <span x-show="!copied">コピー</span>
                                    <span x-show="copied" x-cloak>コピー済</span>
                                </button>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <a href="{{ $s->public_url }}" target="_blank" class="text-xs text-sky-600 hover:underline">表示</a>
                            <form method="POST" action="{{ route('shares.toggle', $s) }}" class="inline">
                                @csrf
                                <button class="text-xs ml-2 {{ $s->is_active ? 'text-amber-600' : 'text-emerald-600' }} hover:underline">
                                    {{ $s->is_active ? '停止' : '再開' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('shares.destroy', $s) }}" class="inline" onsubmit="return confirm('削除しますか?')">
                                @csrf @method('DELETE')
                                <button class="text-xs ml-2 text-rose-600 hover:underline">削除</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $shares->links() }}</div>
        @endif
    </div>
</div>
@endsection
