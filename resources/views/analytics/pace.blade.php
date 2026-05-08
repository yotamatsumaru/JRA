@extends('layouts.app')
@section('title', 'ペース分析')

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">ペース分析</h1>
    <p class="text-sm text-gray-600">レースのペース（H=ハイ／M=ミドル／S=スロー）と上位入線（3着以内）の脚質の関係を分析します。</p>

    @php
        $styles = ['逃','先','差','追'];
        $paces = ['H','M','S'];
    @endphp

    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
        <h2 class="font-semibold text-gray-700 mb-3">ペース × 脚質ピボット（上位3着以内）</h2>
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-xs text-gray-600">
                <tr>
                    <th class="px-3 py-2">ペース</th>
                    @foreach ($styles as $s)
                        <th class="px-3 py-2">{{ $s }}</th>
                    @endforeach
                    <th class="px-3 py-2">合計</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($paces as $p)
                    @php
                        $rowTotal = array_sum($pivot[$p] ?? []);
                    @endphp
                    <tr class="border-b">
                        <td class="px-3 py-2 font-bold">
                            {{ $p }}
                            <span class="text-xs text-gray-500 ml-1">
                                @if ($p === 'H') ハイ
                                @elseif ($p === 'M') ミドル
                                @else スロー
                                @endif
                            </span>
                        </td>
                        @foreach ($styles as $s)
                            @php
                                $cnt = $pivot[$p][$s] ?? 0;
                                $pct = $rowTotal > 0 ? round($cnt / $rowTotal * 100, 1) : 0;
                                $bgIntensity = min(100, $pct * 2);
                            @endphp
                            <td class="px-3 py-2 text-center relative">
                                <div class="absolute inset-0 bg-blue-500 opacity-{{ $bgIntensity > 50 ? '30' : '10' }}" style="opacity: {{ $bgIntensity / 200 }};"></div>
                                <div class="relative">
                                    <div class="text-lg font-bold">{{ $cnt }}</div>
                                    <div class="text-xs text-gray-500">{{ $pct }}%</div>
                                </div>
                            </td>
                        @endforeach
                        <td class="px-3 py-2 text-center font-bold">{{ $rowTotal }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">グラフで見る</h2>
        <div id="pace-chart"></div>
    </div>

    <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded">
        <h3 class="font-semibold text-amber-800 mb-2">読み方のヒント</h3>
        <ul class="text-sm text-amber-700 list-disc list-inside space-y-1">
            <li><b>ハイペース(H)</b>: 前半が速いとスタミナ消耗→差し・追込が好走しやすい</li>
            <li><b>スローペース(S)</b>: 前半が遅いと前残り→逃げ・先行が好走しやすい</li>
            <li><b>ミドル(M)</b>: 標準的なペース。各脚質バランスよく</li>
        </ul>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const styles = @json($styles);
    const paces = @json($paces);
    const pivot = @json($pivot);

    const series = styles.map(style => ({
        name: style,
        data: paces.map(pace => (pivot[pace] && pivot[pace][style]) || 0)
    }));

    new ApexCharts(document.querySelector('#pace-chart'), {
        chart: { type: 'bar', height: 320, stacked: true, stackType: '100%', toolbar: { show: false } },
        series: series,
        xaxis: { categories: paces.map(p => p === 'H' ? 'ハイ(H)' : p === 'M' ? 'ミドル(M)' : 'スロー(S)') },
        colors: ['#dc2626', '#f59e0b', '#0284c7', '#10b981'],
        plotOptions: { bar: { borderRadius: 0, horizontal: false } },
        legend: { position: 'top' },
    }).render();
});
</script>
@endpush
@endsection
