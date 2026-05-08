@extends('layouts.app')
@section('title', 'ダッシュボード')

@section('content')
<div class="space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">ダッシュボード</h1>
        <div class="space-x-2">
            <a href="{{ route('races.create') }}" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded text-sm">+ レース登録</a>
            <a href="{{ route('import.netkeiba') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm">netkeiba取込</a>
        </div>
    </div>

    {{-- KPI カード --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">登録レース</div>
            <div class="text-3xl font-bold text-primary-700">{{ number_format($stats['races_total']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">登録馬</div>
            <div class="text-3xl font-bold text-emerald-700">{{ number_format($stats['horses_total']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">現役騎手</div>
            <div class="text-3xl font-bold text-amber-700">{{ number_format($stats['jockeys_total']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">競馬場</div>
            <div class="text-3xl font-bold text-rose-700">{{ number_format($stats['venues_total']) }}</div>
        </div>
    </div>

    {{-- グラフ2列 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">月別レース数（直近12か月）</h2>
            <div id="chart-monthly"></div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">グレード別レース数</h2>
            <div id="chart-grade"></div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">競馬場別レース数</h2>
            <div id="chart-venue"></div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">トップ騎手（勝利数）</h2>
            @if ($topJockeys->isEmpty())
                <p class="text-sm text-gray-500">データがまだありません</p>
            @else
                <ol class="space-y-1 text-sm">
                    @foreach ($topJockeys as $i => $tj)
                        <li class="flex justify-between border-b py-1">
                            <span>{{ $i + 1 }}. {{ $tj->jockey?->name ?? '不明' }}</span>
                            <span class="font-bold text-primary-700">{{ $tj->wins }}勝</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    {{-- 最近のレース --}}
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex justify-between items-center mb-3">
            <h2 class="font-semibold text-gray-700">最近登録したレース</h2>
            <a href="{{ route('races.index') }}" class="text-sm text-primary-600 hover:underline">すべて表示 →</a>
        </div>
        @if ($stats['recent_races']->isEmpty())
            <p class="text-sm text-gray-500">まだレースが登録されていません。<a href="{{ route('races.create') }}" class="text-primary-600 hover:underline">最初のレースを登録</a>しましょう。</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase border-b">
                    <tr>
                        <th class="text-left py-2">日付</th>
                        <th class="text-left py-2">場</th>
                        <th class="text-left py-2">R</th>
                        <th class="text-left py-2">レース名</th>
                        <th class="text-left py-2">距離</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stats['recent_races'] as $r)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="py-2">{{ $r->race_date?->format('Y/m/d') }}</td>
                        <td class="py-2">{{ $r->venue?->name }}</td>
                        <td class="py-2">{{ $r->race_number }}R</td>
                        <td class="py-2">
                            <a href="{{ route('races.show', $r) }}" class="text-primary-600 hover:underline">{{ $r->name }}</a>
                            @if ($r->grade) <span class="ml-1 text-xs bg-amber-100 text-amber-700 px-1 rounded">{{ $r->grade }}</span> @endif
                        </td>
                        <td class="py-2 text-gray-600">{{ $r->track_type }}{{ $r->distance }}m</td>
                        <td class="py-2 text-right text-xs text-gray-500">{{ $r->results_count ?? 0 }}頭</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {

    // 月別レース数
    new ApexCharts(document.querySelector('#chart-monthly'), {
        chart: { type: 'area', height: 250, toolbar: { show: false } },
        series: [{
            name: 'レース数',
            data: @json($byMonth->pluck('cnt'))
        }],
        xaxis: { categories: @json($byMonth->pluck('ym')) },
        colors: ['#0284c7'],
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
        dataLabels: { enabled: false },
    }).render();

    // グレード別
    new ApexCharts(document.querySelector('#chart-grade'), {
        chart: { type: 'bar', height: 250, toolbar: { show: false } },
        series: [{
            name: 'レース数',
            data: @json($byGrade->pluck('cnt'))
        }],
        xaxis: { categories: @json($byGrade->pluck('grade')) },
        colors: ['#f59e0b'],
        plotOptions: { bar: { borderRadius: 4, horizontal: false } },
    }).render();

    // 競馬場別
    new ApexCharts(document.querySelector('#chart-venue'), {
        chart: { type: 'bar', height: 250, toolbar: { show: false } },
        series: [{
            name: 'レース数',
            data: @json($byVenue->pluck('cnt'))
        }],
        xaxis: { categories: @json($byVenue->pluck('name')) },
        colors: ['#10b981'],
        plotOptions: { bar: { borderRadius: 4, horizontal: true } },
    }).render();

});
</script>
@endpush

@endsection
