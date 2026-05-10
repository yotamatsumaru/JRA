@extends('layouts.app')
@section('title', '血統分析トップ')

@section('content')
<div class="space-y-4">
    <h1 class="inline-flex items-center gap-2 text-xl sm:text-2xl font-bold text-gray-800">
        <x-icon name="beaker" class="w-6 h-6 text-purple-600" />
        <span>血統分析</span>
    </h1>
    <p class="text-xs sm:text-sm text-gray-600">
        血統データ(父・母・母父)を使って種牡馬の傾向を見える化します。
        最低出走数 {{ $minRuns }} 回以上の系統のみ表示しています。
    </p>

    @include('analytics._pedigree_nav', ['active' => 'overview'])

    {{-- 期間絞り込み --}}
    <form method="GET" class="bg-white rounded-lg shadow p-3 flex flex-wrap gap-3 items-end text-xs sm:text-sm">
        <div>
            <label class="block text-gray-600 text-xs mb-1">開催日 from</label>
            <input type="date" name="from" value="{{ $from }}" class="border rounded px-2 py-1">
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">開催日 to</label>
            <input type="date" name="to" value="{{ $to }}" class="border rounded px-2 py-1">
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">最小出走数</label>
            <input type="number" min="1" name="min_runs" value="{{ $minRuns }}" class="border rounded px-2 py-1 w-24">
        </div>
        <button class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-1.5 rounded">適用</button>
        @if ($from || $to || $minRuns != 20)
            <a href="{{ route('analytics.pedigree.overview') }}" class="text-gray-500 hover:text-gray-700 underline">クリア</a>
        @endif
    </form>

    {{-- 血統カバー率 KPI --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
            <div class="text-xs text-gray-500">登録馬数</div>
            <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($kpi['total_horses']) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
            <div class="text-xs text-gray-500">父データあり</div>
            <div class="text-2xl font-bold text-purple-600 mt-1">{{ $kpi['father_pct'] }}%</div>
            <div class="text-xs text-gray-500 mt-1">{{ number_format($kpi['father_filled']) }} 頭 / 種牡馬 {{ number_format($kpi['unique_fathers']) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
            <div class="text-xs text-gray-500">母データあり</div>
            <div class="text-2xl font-bold text-rose-500 mt-1">{{ $kpi['mother_pct'] }}%</div>
            <div class="text-xs text-gray-500 mt-1">{{ number_format($kpi['mother_filled']) }} 頭</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
            <div class="text-xs text-gray-500">母父データあり</div>
            <div class="text-2xl font-bold text-amber-500 mt-1">{{ $kpi['m_father_pct'] }}%</div>
            <div class="text-xs text-gray-500 mt-1">{{ number_format($kpi['m_father_filled']) }} 頭 / {{ number_format($kpi['unique_m_fathers']) }} 系統</div>
        </div>
    </div>

    {{-- 父TOP20 (ApexChart) --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="inline-flex items-center gap-1.5 font-semibold text-gray-700">
                <x-icon name="crown" class="w-5 h-5 text-purple-600" />
                <span>父系 TOP20 (出走数)</span>
            </h2>
            <a href="{{ route('analytics.pedigree.sires') }}" class="text-xs text-purple-600 hover:underline">全件を見る →</a>
        </div>

        @if (count($topFathers) === 0)
            <p class="text-sm text-gray-500">該当データなし。血統取込が完了しているか確認してください。</p>
        @else
            <div id="chart-fathers" style="min-height:520px;"></div>
            <div class="table-scroll mt-4">
                <table class="w-full text-xs sm:text-sm min-w-[720px]">
                    <thead class="bg-gray-100 text-gray-600">
                        <tr>
                            <th class="text-left px-3 py-2">#</th>
                            <th class="text-left px-3 py-2">父</th>
                            <th class="px-3 py-2">出走</th>
                            <th class="px-3 py-2">勝</th>
                            <th class="px-3 py-2">勝率</th>
                            <th class="px-3 py-2">連対率</th>
                            <th class="px-3 py-2">複勝率</th>
                            <th class="px-3 py-2">単回</th>
                            <th class="px-3 py-2">複回</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topFathers as $i => $r)
                            <tr class="border-b hover:bg-purple-50">
                                <td class="px-3 py-1.5 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-3 py-1.5">
                                    <a href="{{ route('analytics.pedigree', ['father' => $r->name]) }}"
                                       class="text-purple-700 hover:underline font-medium">{{ $r->name }}</a>
                                </td>
                                <td class="px-3 py-1.5 text-center">{{ number_format($r->runs) }}</td>
                                <td class="px-3 py-1.5 text-center text-yellow-700 font-bold">{{ number_format($r->wins) }}</td>
                                <td class="px-3 py-1.5 text-center">{{ $r->win_rate }}%</td>
                                <td class="px-3 py-1.5 text-center">{{ $r->place_rate }}%</td>
                                <td class="px-3 py-1.5 text-center font-bold {{ $r->show_rate >= 30 ? 'text-emerald-700' : '' }}">{{ $r->show_rate }}%</td>
                                <td class="px-3 py-1.5 text-center {{ $r->roi_win   >= 100 ? 'text-emerald-600 font-bold' : 'text-gray-600' }}">{{ $r->roi_win }}%</td>
                                <td class="px-3 py-1.5 text-center {{ $r->roi_place >= 100 ? 'text-emerald-600 font-bold' : 'text-gray-600' }}">{{ $r->roi_place }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 母父TOP20 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="inline-flex items-center gap-1.5 font-semibold text-gray-700">
                <x-icon name="flower" class="w-5 h-5 text-rose-500" />
                <span>母父系 TOP20 (出走数)</span>
            </h2>
            <a href="{{ route('analytics.pedigree.broodmares') }}" class="text-xs text-purple-600 hover:underline">全件を見る →</a>
        </div>

        @if (count($topBroodmares) === 0)
            <p class="text-sm text-gray-500">該当データなし</p>
        @else
            <div id="chart-broodmares" style="min-height:520px;"></div>
            <div class="table-scroll mt-4">
                <table class="w-full text-xs sm:text-sm min-w-[720px]">
                    <thead class="bg-gray-100 text-gray-600">
                        <tr>
                            <th class="text-left px-3 py-2">#</th>
                            <th class="text-left px-3 py-2">母父</th>
                            <th class="px-3 py-2">出走</th>
                            <th class="px-3 py-2">勝</th>
                            <th class="px-3 py-2">勝率</th>
                            <th class="px-3 py-2">連対率</th>
                            <th class="px-3 py-2">複勝率</th>
                            <th class="px-3 py-2">単回</th>
                            <th class="px-3 py-2">複回</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topBroodmares as $i => $r)
                            <tr class="border-b hover:bg-rose-50">
                                <td class="px-3 py-1.5 text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-3 py-1.5 text-rose-700 font-medium">{{ $r->name }}</td>
                                <td class="px-3 py-1.5 text-center">{{ number_format($r->runs) }}</td>
                                <td class="px-3 py-1.5 text-center text-yellow-700 font-bold">{{ number_format($r->wins) }}</td>
                                <td class="px-3 py-1.5 text-center">{{ $r->win_rate }}%</td>
                                <td class="px-3 py-1.5 text-center">{{ $r->place_rate }}%</td>
                                <td class="px-3 py-1.5 text-center font-bold {{ $r->show_rate >= 30 ? 'text-emerald-700' : '' }}">{{ $r->show_rate }}%</td>
                                <td class="px-3 py-1.5 text-center {{ $r->roi_win   >= 100 ? 'text-emerald-600 font-bold' : 'text-gray-600' }}">{{ $r->roi_win }}%</td>
                                <td class="px-3 py-1.5 text-center {{ $r->roi_place >= 100 ? 'text-emerald-600 font-bold' : 'text-gray-600' }}">{{ $r->roi_place }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if (count($topFathers) > 0 || count($topBroodmares) > 0)
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const fathers     = @json(array_map(fn($r) => $r->name,     $topFathers));
            const fatherRuns  = @json(array_map(fn($r) => $r->runs,     $topFathers));
            const fatherShow  = @json(array_map(fn($r) => $r->show_rate,$topFathers));

            if (fathers.length > 0 && document.querySelector('#chart-fathers')) {
                new ApexCharts(document.querySelector('#chart-fathers'), {
                    chart: { type: 'bar', height: 520, toolbar: { show: false } },
                    series: [
                        { name: '出走数',  data: fatherRuns },
                        { name: '複勝率(%)', data: fatherShow },
                    ],
                    plotOptions: { bar: { horizontal: true, dataLabels: { position: 'top' } } },
                    xaxis: { categories: fathers },
                    colors: ['#a855f7', '#10b981'],
                    legend: { position: 'top' },
                    dataLabels: { enabled: true, style: { fontSize: '10px' } },
                }).render();
            }

            const broodmares    = @json(array_map(fn($r) => $r->name,     $topBroodmares));
            const broodmareRuns = @json(array_map(fn($r) => $r->runs,     $topBroodmares));
            const broodmareShow = @json(array_map(fn($r) => $r->show_rate,$topBroodmares));

            if (broodmares.length > 0 && document.querySelector('#chart-broodmares')) {
                new ApexCharts(document.querySelector('#chart-broodmares'), {
                    chart: { type: 'bar', height: 520, toolbar: { show: false } },
                    series: [
                        { name: '出走数',  data: broodmareRuns },
                        { name: '複勝率(%)', data: broodmareShow },
                    ],
                    plotOptions: { bar: { horizontal: true, dataLabels: { position: 'top' } } },
                    xaxis: { categories: broodmares },
                    colors: ['#f43f5e', '#10b981'],
                    legend: { position: 'top' },
                    dataLabels: { enabled: true, style: { fontSize: '10px' } },
                }).render();
            }
        });
    </script>
@endif
@endsection
