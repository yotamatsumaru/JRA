@extends('layouts.app')
@section('title', '運用ダッシュボード')

@section('content')
<div class="space-y-5">
    <x-page-header title="運用ダッシュボード" subtitle="スケジューラ・監査ログ・手動ジョブ実行" icon="server">
        <x-slot name="actions">
            <a href="{{ route('operations.index') }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-1.5 rounded text-sm">
                <x-icon name="filter" class="w-4 h-4" /><span>クリア</span>
            </a>
        </x-slot>
    </x-page-header>

    @if (session('status'))
        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 rounded px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 text-rose-800 dark:text-rose-200 rounded px-4 py-2 text-sm">
            @foreach ($errors->all() as $msg) <div>{{ $msg }}</div> @endforeach
        </div>
    @endif

    {{-- KPI --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <x-kpi-card label="24h 成功" :value="number_format($schedSummary['success_24h'])" subtext="件" icon="check" color="turf" />
        <x-kpi-card label="24h 失敗" :value="number_format($schedSummary['failed_24h'])" subtext="件" icon="warning" :color="$schedSummary['failed_24h'] > 0 ? 'rose' : 'sand'" />
        <x-kpi-card label="実行中" :value="number_format($schedSummary['running'])" icon="bolt" color="purple" />
        <x-kpi-card
            label="最終実行"
            :value="$schedSummary['last_run']?->started_at?->format('H:i') ?? '-'"
            :subtext="$schedSummary['last_run']?->job ?? '未実行'"
            icon="clock"
            color="sky" />
    </div>

    {{-- 手動ジョブ実行 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="bolt" class="w-4 h-4 text-amber-500" /><span>手動ジョブ実行</span>
        </h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            cron が動かない環境でも、ここから手動でジョブを実行できます。実行結果は下の「スケジューラ実行ログ」に記録されます。
        </p>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
            @php
                $jobs = [
                    ['job' => 'odds:capture',          'label' => 'オッズ取得',          'icon' => 'bolt',     'color' => 'amber'],
                    ['job' => 'bets:resettle',         'label' => '一括精算',            'icon' => 'check',    'color' => 'emerald'],
                    ['job' => 'netkeiba:date',         'label' => '本日結果取込',        'icon' => 'download', 'color' => 'sky'],
                    ['job' => 'netkeiba:shutuba-date', 'label' => '翌日出馬表取込',      'icon' => 'list',     'color' => 'turf'],
                    ['job' => 'app:backup',            'label' => 'バックアップ',        'icon' => 'database', 'color' => 'gray'],
                    ['job' => 'jra:check',             'label' => '整合性チェック',      'icon' => 'badge-check', 'color' => 'rose'],
                ];
            @endphp
            @foreach ($jobs as $j)
            <form method="POST" action="{{ route('operations.run-job') }}" onsubmit="return confirm('{{ $j['label'] }} を実行します。よろしいですか?');">
                @csrf
                <input type="hidden" name="job" value="{{ $j['job'] }}">
                <button class="w-full flex items-center justify-center space-x-2 bg-{{ $j['color'] }}-100 hover:bg-{{ $j['color'] }}-200 dark:bg-{{ $j['color'] }}-900/30 dark:hover:bg-{{ $j['color'] }}-900/50 text-{{ $j['color'] }}-700 dark:text-{{ $j['color'] }}-300 px-3 py-2 rounded text-sm">
                    <x-icon :name="$j['icon']" class="w-4 h-4" />
                    <span>{{ $j['label'] }}</span>
                    <span class="text-xs text-gray-500 font-mono">{{ $j['job'] }}</span>
                </button>
            </form>
            @endforeach
        </div>
    </div>

    {{-- ジョブ別 最新ステータス --}}
    @if ($jobsSummary->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">ジョブ別 直近ステータス</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach ($jobsSummary as $j)
            <div class="border dark:border-gray-700 rounded p-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-xs">{{ $j->job }}</span>
                    @if ($j->status === 'success')
                        <span class="bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs px-2 py-0.5 rounded">成功</span>
                    @elseif ($j->status === 'failed')
                        <span class="bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 text-xs px-2 py-0.5 rounded">失敗</span>
                    @else
                        <span class="bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-xs px-2 py-0.5 rounded">実行中</span>
                    @endif
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    {{ $j->started_at?->format('m/d H:i') }}
                    @if ($j->duration_ms !== null) ({{ number_format($j->duration_ms) }}ms) @endif
                </div>
                @if ($j->error)
                    <div class="text-xs text-rose-600 mt-1 line-clamp-2">{{ \Illuminate\Support\Str::limit($j->error, 120) }}</div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- スケジューラ実行ログ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="activity" class="w-4 h-4 text-turf-600" /><span>スケジューラ実行ログ (直近30件)</span>
        </h2>
        @if ($schedulerLogs->isEmpty())
            <p class="text-sm text-gray-400">まだ実行ログがありません</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">開始</th>
                        <th class="px-3 py-2 text-left">ジョブ</th>
                        <th class="px-3 py-2 text-center">結果</th>
                        <th class="px-3 py-2 text-right">時間(ms)</th>
                        <th class="px-3 py-2 text-left">出力 / エラー</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($schedulerLogs as $log)
                    <tr>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $log->started_at?->format('m/d H:i:s') }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $log->job }}</td>
                        <td class="px-3 py-2 text-center">
                            @if ($log->status === 'success')
                                <span class="bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs px-2 py-0.5 rounded">OK</span>
                            @elseif ($log->status === 'failed')
                                <span class="bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 text-xs px-2 py-0.5 rounded">NG</span>
                            @else
                                <span class="bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-xs px-2 py-0.5 rounded">…</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs">{{ $log->duration_ms !== null ? number_format($log->duration_ms) : '-' }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if ($log->error)
                                <span class="text-rose-600">{{ \Illuminate\Support\Str::limit($log->error, 100) }}</span>
                            @elseif ($log->output)
                                <details>
                                    <summary class="cursor-pointer text-turf-600 hover:underline">出力 ({{ mb_strlen($log->output) }}文字)</summary>
                                    <pre class="mt-1 text-xs bg-gray-50 dark:bg-gray-900/50 p-2 rounded max-h-48 overflow-auto">{{ \Illuminate\Support\Str::limit($log->output, 4000) }}</pre>
                                </details>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 監査ログ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="document" class="w-4 h-4 text-sky-500" /><span>監査ログ</span>
            <span class="text-xs text-gray-400">直近30日: {{ $actionTotals->sum('cnt') }} 件</span>
        </h2>

        {{-- フィルタ --}}
        <form method="GET" class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-sm">
            <div>
                <label class="block text-xs text-gray-500 mb-1">アクション</label>
                <select name="action" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">すべて</option>
                    @foreach ($actions as $key => $label)
                        <option value="{{ $key }}" @selected(request('action') === $key)>{{ $label }} ({{ $key }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">ユーザID</label>
                <input type="number" name="user_id" value="{{ request('user_id') }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">From</label>
                <input type="date" name="from" value="{{ request('from') }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">To</label>
                <input type="date" name="to" value="{{ request('to') }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div class="flex items-end">
                <button class="w-full bg-turf-600 hover:bg-turf-700 text-white px-3 py-1.5 rounded text-sm">絞込</button>
            </div>
        </form>

        @if ($auditLogs->isEmpty())
            <p class="text-sm text-gray-400">該当ログなし</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">日時</th>
                        <th class="px-3 py-2 text-left">ユーザ</th>
                        <th class="px-3 py-2 text-left">アクション</th>
                        <th class="px-3 py-2 text-left">対象</th>
                        <th class="px-3 py-2 text-left">IP / Route</th>
                        <th class="px-3 py-2 text-left">Meta</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($auditLogs as $log)
                    <tr>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $log->created_at?->format('m/d H:i:s') }}</td>
                        <td class="px-3 py-2 text-xs">{{ $log->user?->name ?? '#'.($log->user_id ?? '-') }}</td>
                        <td class="px-3 py-2 text-xs">
                            <span class="font-mono">{{ $log->action }}</span>
                            @if (isset($actions[$log->action]))
                                <div class="text-gray-500">{{ $actions[$log->action] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs font-mono">
                            {{ $log->subject_type ? class_basename($log->subject_type) : '-' }}
                            @if ($log->subject_id) #{{ $log->subject_id }} @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-500">
                            <div>{{ $log->ip }}</div>
                            <div class="font-mono">{{ $log->route_name }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if (!empty($log->meta))
                            <details>
                                <summary class="cursor-pointer text-sky-600 hover:underline">表示</summary>
                                <pre class="mt-1 text-xs bg-gray-50 dark:bg-gray-900/50 p-2 rounded max-h-32 overflow-auto">{{ json_encode($log->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $auditLogs->links() }}</div>
        @endif
    </div>
</div>
@endsection
