@props([
    'type'      => 'horse',  // horse | jockey | trainer
    'targetId'  => null,
    'label'     => null,     // 既定値表示用 (省略可)
])

@php
    $userId = auth()->id();
    $exists = false;
    $current = null;
    if ($userId && $targetId) {
        $current = \App\Models\Watchlist::where('user_id', $userId)
            ->where('target_type', $type)
            ->where('target_id', $targetId)
            ->first();
        $exists = (bool) $current;
    }
    $typeLabels = [
        'horse'   => '馬',
        'jockey'  => '騎手',
        'trainer' => '厩舎',
    ];
    $tLabel = $typeLabels[$type] ?? $type;
@endphp

@if ($userId && $targetId)
<div x-data="{ open: false }" class="inline-block relative">
    @if ($exists)
        {{-- 削除フォーム --}}
        <form method="POST" action="{{ route('watchlist.destroy', $current) }}" class="inline">
            @csrf
            @method('DELETE')
            <button type="submit"
                class="inline-flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium bg-amber-100 hover:bg-amber-200 dark:bg-amber-900/40 dark:hover:bg-amber-900/60 text-amber-700 dark:text-amber-300"
                title="ウォッチリストから外す">
                <x-icon name="star" class="w-4 h-4" />
                <span>ウォッチ中</span>
            </button>
        </form>
    @else
        {{-- 追加ボタン (ポップオーバーでメモ・ラベルを入力) --}}
        <button type="button" @click="open = !open"
            class="inline-flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium bg-gray-100 hover:bg-amber-100 dark:bg-gray-700 dark:hover:bg-amber-900/40 text-gray-700 dark:text-gray-200 hover:text-amber-700 dark:hover:text-amber-300">
            <x-icon name="star" class="w-4 h-4" />
            <span>ウォッチに追加</span>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false"
            class="absolute right-0 mt-2 w-72 bg-white dark:bg-gray-800 rounded-lg shadow-xl ring-1 ring-black/5 z-40 p-3">
            <form method="POST" action="{{ route('watchlist.store') }}" class="space-y-2">
                @csrf
                <input type="hidden" name="target_type" value="{{ $type }}">
                <input type="hidden" name="target_id" value="{{ $targetId }}">
                <div class="text-xs font-semibold text-gray-700 dark:text-gray-200">
                    {{ $tLabel }}「{{ $label ?: ('#'.$targetId) }}」をウォッチ
                </div>
                <label class="block text-[11px] text-gray-500">
                    ラベル (省略可)
                    <input type="text" name="label" maxlength="200"
                        class="mt-0.5 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600 text-xs">
                </label>
                <label class="block text-[11px] text-gray-500">
                    メモ (省略可)
                    <textarea name="memo" rows="2" maxlength="2000"
                        class="mt-0.5 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600 text-xs"></textarea>
                </label>
                <label class="flex items-center gap-1 text-[11px] text-gray-500">
                    <input type="checkbox" name="alert_on_entry" value="1" checked
                        class="rounded border-gray-300 text-amber-500">
                    <span>出走時にアラート</span>
                </label>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" @click="open = false"
                        class="px-2.5 py-1 text-[11px] text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                        キャンセル
                    </button>
                    <button type="submit"
                        class="px-2.5 py-1 text-[11px] font-medium bg-amber-500 hover:bg-amber-600 text-white rounded">
                        追加
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
@endif
