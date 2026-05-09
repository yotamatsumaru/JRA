@extends('layouts.app')
@section('title', '取込進捗ダッシュボード')

@section('content')
<div class="space-y-6"
     x-data="progressDashboard()"
     x-init="init()">

    <x-page-header title="取込進捗ダッシュボード" subtitle="バックグラウンド取込の状況を監視" icon="activity" />

    {{-- 自動更新コントロール --}}
    <div class="flex items-center justify-between bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 px-4 py-3">
        <div class="text-sm text-gray-600 dark:text-gray-400">
            最終更新: <span x-text="lastUpdated">{{ $generatedAt }}</span>
            <span x-show="autoRefresh" class="ml-3 inline-flex items-center text-xs text-emerald-600 dark:text-emerald-400">
                <span class="w-2 h-2 bg-emerald-500 rounded-full mr-1 animate-pulse"></span>
                10秒ごとに自動更新中
            </span>
        </div>
        <div class="flex items-center space-x-2">
            <button @click="refresh()"
                    class="px-3 py-1.5 text-sm bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 rounded hover:bg-sky-200 dark:hover:bg-sky-900/60">
                手動更新
            </button>
            <label class="flex items-center space-x-1 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" x-model="autoRefresh" @change="toggleAutoRefresh()" class="rounded">
                <span>自動更新</span>
            </label>
        </div>
    </div>

    {{-- DB側の年別レース数 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
            <x-icon name="database" class="w-5 h-5 mr-2 text-indigo-600 dark:text-indigo-400" />
            DB内レース数(年別)
        </h3>
        @if (empty($racesByYear))
            <p class="text-sm text-gray-500 dark:text-gray-400">レースデータがまだありません。</p>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                @foreach ($racesByYear as $year => $count)
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-center bg-gray-50 dark:bg-gray-900/50">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $year }}年</div>
                        <div class="text-xl font-bold text-gray-800 dark:text-gray-200 mt-1">{{ number_format($count) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- netkeiba:year 進捗 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
            <x-icon name="calendar" class="w-5 h-5 mr-2 text-emerald-600 dark:text-emerald-400" />
            netkeiba:year 取込進捗
        </h3>
        <template x-if="yearProgress.length === 0">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                バックグラウンド取込ジョブはまだ実行されていません。<br>
                サーバーで <code class="font-mono bg-gray-100 dark:bg-gray-900 px-1.5 py-0.5 rounded text-xs">php artisan netkeiba:year 2025 --interval=2</code> を実行すると進捗がここに表示されます。
            </p>
        </template>
        <template x-if="yearProgress.length > 0">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">年</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-300">処理済</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-300">成功</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-300">失敗</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-300">エラー数</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">最終更新</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <template x-for="row in yearProgress" :key="row.year">
                            <tr>
                                <td class="px-3 py-2 font-bold" x-text="row.year + '年'"></td>
                                <td class="px-3 py-2 text-right tabular-nums" x-text="row.done_count.toLocaleString()"></td>
                                <td class="px-3 py-2 text-right tabular-nums text-emerald-600 dark:text-emerald-400" x-text="row.success.toLocaleString()"></td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="row.failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500'" x-text="row.failed.toLocaleString()"></td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="row.errors > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-500'" x-text="row.errors.toLocaleString()"></td>
                                <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400" x-text="row.updated_at || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    {{-- netkeiba:fill-pedigree 進捗 + 馬データ統計 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-6">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
                <x-icon name="git-branch" class="w-5 h-5 mr-2 text-purple-600 dark:text-purple-400" />
                血統補完(netkeiba:fill-pedigree)進捗
            </h3>
            <template x-if="!pedigreeProgress">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    血統補完ジョブはまだ実行されていません。<br>
                    <code class="font-mono bg-gray-100 dark:bg-gray-900 px-1.5 py-0.5 rounded text-xs">php artisan netkeiba:fill-pedigree --interval=2</code>
                </p>
            </template>
            <template x-if="pedigreeProgress">
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600 dark:text-gray-400">処理済</span>
                        <span class="text-2xl font-bold text-purple-600 dark:text-purple-400 tabular-nums" x-text="pedigreeProgress.done_count.toLocaleString() + ' 頭'"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600 dark:text-gray-400">失敗</span>
                        <span class="text-lg font-semibold tabular-nums" :class="pedigreeProgress.failed_count > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500'" x-text="pedigreeProgress.failed_count.toLocaleString() + ' 頭'"></span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 pt-2 border-t border-gray-200 dark:border-gray-700" x-show="pedigreeProgress.updated_at">
                        最終更新: <span x-text="pedigreeProgress.updated_at"></span>
                    </div>
                </div>
            </template>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-6">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
                <x-icon name="users" class="w-5 h-5 mr-2 text-amber-600 dark:text-amber-400" />
                馬データ・血統入力状況
            </h3>
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600 dark:text-gray-400">登録馬総数</span>
                    <span class="text-2xl font-bold text-gray-800 dark:text-gray-200 tabular-nums" x-text="horseStats.total.toLocaleString() + ' 頭'"></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600 dark:text-gray-400">血統入力済</span>
                    <span class="text-lg font-semibold text-emerald-600 dark:text-emerald-400 tabular-nums" x-text="horseStats.pedigree_filled.toLocaleString() + ' 頭'"></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-600 dark:text-gray-400">血統未入力</span>
                    <span class="text-lg font-semibold tabular-nums" :class="horseStats.pedigree_missing > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-500'" x-text="horseStats.pedigree_missing.toLocaleString() + ' 頭'"></span>
                </div>
                {{-- 進捗バー --}}
                <div class="pt-2">
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 overflow-hidden">
                        <div class="bg-emerald-500 h-2 transition-all duration-500"
                             :style="{ width: (horseStats.total > 0 ? Math.round(horseStats.pedigree_filled / horseStats.total * 100) : 0) + '%' }"></div>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 text-right">
                        <span x-text="horseStats.total > 0 ? Math.round(horseStats.pedigree_filled / horseStats.total * 100) + '%' : '0%'"></span>
                        完了
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 直近のImportLog --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
            <x-icon name="clock" class="w-5 h-5 mr-2 text-sky-600 dark:text-sky-400" />
            直近の取込ログ
        </h3>
        <template x-if="recentLogs.length === 0">
            <p class="text-sm text-gray-500 dark:text-gray-400">取込ログはまだありません。</p>
        </template>
        <template x-if="recentLogs.length > 0">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">ID</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">ソース</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">参照</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">状態</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-300">取込</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-700 dark:text-gray-300">失敗</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">開始</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-700 dark:text-gray-300">終了</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <template x-for="log in recentLogs" :key="log.id">
                            <tr>
                                <td class="px-3 py-2 text-gray-500 tabular-nums" x-text="log.id"></td>
                                <td class="px-3 py-2" x-text="log.source"></td>
                                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400 truncate max-w-xs" x-text="log.reference"></td>
                                <td class="px-3 py-2">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium"
                                          :class="{
                                              'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300': log.status === 'success',
                                              'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300': log.status === 'partial' || log.status === 'processing',
                                              'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300': log.status === 'failed',
                                              'bg-gray-100 text-gray-700 dark:bg-gray-900/40 dark:text-gray-300': !['success','partial','processing','failed'].includes(log.status),
                                          }"
                                          x-text="log.status"></span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums" x-text="log.imported ?? '—'"></td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="log.failed > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500'" x-text="log.failed ?? '—'"></td>
                                <td class="px-3 py-2 text-xs text-gray-500" x-text="log.started || '—'"></td>
                                <td class="px-3 py-2 text-xs text-gray-500" x-text="log.finished || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    {{-- リンク --}}
    <div class="flex flex-wrap gap-2 text-sm">
        <a href="{{ route('import.index') }}" class="px-3 py-1.5 rounded bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300">
            ← 取込トップ
        </a>
        <a href="{{ route('import.logs') }}" class="px-3 py-1.5 rounded bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300">
            ログ一覧
        </a>
    </div>
</div>

<script>
function progressDashboard() {
    return {
        autoRefresh: true,
        timer: null,
        lastUpdated: @json($generatedAt),
        yearProgress: @json($yearProgress),
        pedigreeProgress: @json($pedigreeProgress),
        racesByYear: @json($racesByYear),
        horseStats: @json($horseStats),
        recentLogs: @json($recentLogs),

        init() {
            this.toggleAutoRefresh();
        },
        toggleAutoRefresh() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
            if (this.autoRefresh) {
                this.timer = setInterval(() => this.refresh(), 10000);
            }
        },
        async refresh() {
            try {
                const res = await fetch(@json(route('import.progress.json')), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data = await res.json();
                this.yearProgress = data.yearProgress || [];
                this.pedigreeProgress = data.pedigreeProgress || null;
                this.racesByYear = data.racesByYear || {};
                this.horseStats = data.horseStats || { total: 0, pedigree_filled: 0, pedigree_missing: 0 };
                this.recentLogs = data.recentLogs || [];
                this.lastUpdated = data.generatedAt;
            } catch (e) {
                console.warn('progress refresh failed', e);
            }
        },
    };
}
</script>
@endsection
