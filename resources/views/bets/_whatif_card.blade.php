{{--
    What-if シナリオの結果カード
    引数:
      - $s ['stake', 'return', 'profit', 'roi']
      - $baseline (実績 profit)
--}}
@php
    $diff = ($s['profit'] ?? 0) - ($baseline ?? 0);
@endphp
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
    <div class="bg-gray-50 dark:bg-gray-700/30 rounded p-3">
        <div class="text-xs text-gray-500 dark:text-gray-400">投資</div>
        <div class="text-lg font-bold tabular-nums">¥{{ number_format($s['stake'] ?? 0) }}</div>
    </div>
    <div class="bg-gray-50 dark:bg-gray-700/30 rounded p-3">
        <div class="text-xs text-gray-500 dark:text-gray-400">払戻</div>
        <div class="text-lg font-bold tabular-nums">¥{{ number_format($s['return'] ?? 0) }}</div>
    </div>
    <div class="rounded p-3 {{ ($s['profit'] ?? 0) >= 0 ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-rose-50 dark:bg-rose-900/20' }}">
        <div class="text-xs {{ ($s['profit'] ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">収支</div>
        <div class="text-lg font-bold tabular-nums {{ ($s['profit'] ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
            {{ ($s['profit'] ?? 0) >= 0 ? '+' : '' }}¥{{ number_format($s['profit'] ?? 0) }}
        </div>
    </div>
    <div class="rounded p-3 {{ ($s['roi'] ?? 0) >= 100 ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-rose-50 dark:bg-rose-900/20' }}">
        <div class="text-xs {{ ($s['roi'] ?? 0) >= 100 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">ROI</div>
        <div class="text-lg font-bold tabular-nums {{ ($s['roi'] ?? 0) >= 100 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
            {{ ($s['roi'] ?? null) !== null ? $s['roi'].'%' : '-' }}
        </div>
    </div>
    <div class="rounded p-3 {{ $diff >= 0 ? 'bg-sky-50 dark:bg-sky-900/20' : 'bg-amber-50 dark:bg-amber-900/20' }}">
        <div class="text-xs {{ $diff >= 0 ? 'text-sky-700 dark:text-sky-300' : 'text-amber-700 dark:text-amber-300' }}">実績との差</div>
        <div class="text-lg font-bold tabular-nums {{ $diff >= 0 ? 'text-sky-700 dark:text-sky-300' : 'text-amber-700 dark:text-amber-300' }}">
            {{ $diff >= 0 ? '+' : '' }}¥{{ number_format($diff) }}
        </div>
    </div>
</div>
