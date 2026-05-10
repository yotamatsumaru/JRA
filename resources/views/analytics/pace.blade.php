@extends('layouts.app')
@section('title', 'ペース分析')

@section('content')
<div class="space-y-6">
    <div class="flex items-end justify-between flex-wrap gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">ペース分析</h1>
            <p class="text-sm text-gray-600">レースのペース（H=ハイ／M=ミドル／S=スロー）と上位入線（3着以内）の脚質の関係を分析します。</p>
        </div>
        <div class="text-xs text-gray-500">対象レース総数(pace 入力済): <b>{{ number_format($totalRaces) }}</b> レース</div>
    </div>

    {{-- ============ フィルター ============ --}}
    <form method="get" class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-500 mb-1">競馬場</label>
                <select name="venue_id" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected($f['venue_id'] == $v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">トラック</label>
                <select name="track_type" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    <option value="芝" @selected($f['track_type']==='芝')>芝</option>
                    <option value="ダート" @selected($f['track_type']==='ダート')>ダート</option>
                    <option value="障害" @selected($f['track_type']==='障害')>障害</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">距離カテゴリ</label>
                <select name="distance_cat" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    @foreach (array_keys($distCats) as $cat)
                        <option value="{{ $cat }}" @selected($f['distance_cat']===$cat)>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">距離(個別)</label>
                <select name="distance" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    @foreach ($availableDistances as $d)
                        <option value="{{ $d }}" @selected((string)$f['distance']===(string)$d)>{{ $d }}m</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">馬場</label>
                <select name="course_condition" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    @foreach (['良','稍重','重','不良'] as $c)
                        <option value="{{ $c }}" @selected($f['course_condition']===$c)>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">開催日 from</label>
                <input type="date" name="from" value="{{ $f['from'] }}" class="w-full border rounded px-2 py-1">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">開催日 to</label>
                <input type="date" name="to" value="{{ $f['to'] }}" class="w-full border rounded px-2 py-1">
            </div>
        </div>
        <div class="mt-3 flex gap-2">
            <button class="px-4 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700 text-sm">適用</button>
            <a href="{{ route('analytics.pace') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm">リセット</a>
        </div>
    </form>

    @php
        $styles = ['逃','先','差','追'];
        $paces = ['H','M','S'];
        $paceLabel = ['H'=>'ハイ','M'=>'ミドル','S'=>'スロー'];
        $paceColor = ['H'=>'#dc2626', 'M'=>'#f59e0b', 'S'=>'#0284c7'];
    @endphp

    {{-- ============ ペース × 脚質 ピボット ============ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">ペース × 脚質ピボット（上位3着以内）</h2>
        <div class="table-scroll">
        <table class="w-full text-sm min-w-[480px]">
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
                    @php $rowTotal = array_sum($pivot[$p] ?? []); @endphp
                    <tr class="border-b">
                        <td class="px-3 py-2 font-bold">
                            {{ $p }}
                            <span class="text-xs text-gray-500 ml-1">{{ $paceLabel[$p] }}</span>
                        </td>
                        @foreach ($styles as $s)
                            @php
                                $cnt = $pivot[$p][$s] ?? 0;
                                $pct = $rowTotal > 0 ? round($cnt / $rowTotal * 100, 1) : 0;
                            @endphp
                            <td class="px-3 py-2 text-center relative">
                                <div class="absolute inset-0 bg-blue-500" style="opacity: {{ min(0.5, $pct / 200) }};"></div>
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
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">グラフで見る (脚質構成比)</h2>
        <div id="pace-chart"></div>
    </div>

    {{-- ============ 距離カテゴリ別 ペース分布 ============ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">距離カテゴリ別 ペース分布</h2>
            <div id="dist-chart"></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 table-scroll">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">距離カテゴリ別 (件数)</h2>
            <table class="w-full text-sm min-w-[520px]">
                <thead class="bg-gray-100 text-xs text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">距離</th>
                        @foreach ($paces as $p)
                            <th class="px-3 py-2">{{ $p }} {{ $paceLabel[$p] }}</th>
                        @endforeach
                        <th class="px-3 py-2">合計</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byDistance as $cat => $row)
                        @php $sum = array_sum($row); @endphp
                        <tr class="border-b">
                            <td class="px-3 py-2 font-semibold">{{ $cat }}</td>
                            @foreach ($paces as $p)
                                @php
                                    $cnt = $row[$p] ?? 0;
                                    $pct = $sum > 0 ? round($cnt / $sum * 100, 1) : 0;
                                @endphp
                                <td class="px-3 py-2 text-center">
                                    <div class="font-bold">{{ $cnt }}</div>
                                    <div class="text-xs text-gray-500">{{ $pct }}%</div>
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-center font-bold">{{ $sum }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ============ コース別 ペース分布 ============ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">コース別 ペース分布 (競馬場×トラック)</h2>
        @if (empty($byCoursePivot))
            <p class="text-sm text-gray-500">データがありません</p>
        @else
            <div class="table-scroll">
            <table class="w-full text-sm min-w-[520px]">
                <thead class="bg-gray-100 text-xs text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">コース</th>
                        @foreach ($paces as $p)
                            <th class="px-3 py-2">{{ $p }} {{ $paceLabel[$p] }}</th>
                        @endforeach
                        <th class="px-3 py-2">合計</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byCoursePivot as $label => $row)
                        @php $sum = array_sum($row); @endphp
                        <tr class="border-b">
                            <td class="px-3 py-2">{{ $label }}</td>
                            @foreach ($paces as $p)
                                @php
                                    $cnt = $row[$p] ?? 0;
                                    $pct = $sum > 0 ? round($cnt / $sum * 100, 1) : 0;
                                @endphp
                                <td class="px-3 py-2 text-center">
                                    <div class="font-bold">{{ $cnt }}</div>
                                    <div class="text-xs text-gray-500">{{ $pct }}%</div>
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-center font-bold">{{ $sum }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    {{-- ============ 馬場状態別 + 平均タイム ============ --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-4 table-scroll">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">馬場状態別 ペース分布</h2>
            <table class="w-full text-sm min-w-[420px]">
                <thead class="bg-gray-100 text-xs text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">馬場</th>
                        @foreach ($paces as $p)
                            <th class="px-3 py-2">{{ $p }}</th>
                        @endforeach
                        <th class="px-3 py-2">合計</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($byCondition as $cond => $row)
                        @php $sum = array_sum($row); @endphp
                        <tr class="border-b">
                            <td class="px-3 py-2 font-semibold">{{ $cond }}</td>
                            @foreach ($paces as $p)
                                @php
                                    $cnt = $row[$p] ?? 0;
                                    $pct = $sum > 0 ? round($cnt / $sum * 100, 1) : 0;
                                @endphp
                                <td class="px-3 py-2 text-center">
                                    <div class="font-bold">{{ $cnt }}</div>
                                    <div class="text-xs text-gray-500">{{ $pct }}%</div>
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-center font-bold">{{ $sum }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="bg-white rounded-lg shadow p-4 table-scroll">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">ペース別 平均勝ちタイム / 上がり3F (1着のみ)</h2>
            <table class="w-full text-sm min-w-[480px]">
                <thead class="bg-gray-100 text-xs text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">ペース</th>
                        <th class="px-3 py-2">対象</th>
                        <th class="px-3 py-2">平均勝ちタイム(秒)</th>
                        <th class="px-3 py-2">平均上がり3F(秒)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paces as $p)
                        @php $row = $paceTime[$p] ?? null; @endphp
                        <tr class="border-b">
                            <td class="px-3 py-2 font-semibold">{{ $p }} <span class="text-xs text-gray-500">{{ $paceLabel[$p] }}</span></td>
                            <td class="px-3 py-2 text-center">{{ $row ? number_format($row->cnt) : 0 }}</td>
                            <td class="px-3 py-2 text-center">{{ $row && $row->avg_time ? number_format($row->avg_time, 2) : '—' }}</td>
                            <td class="px-3 py-2 text-center">{{ $row && $row->avg_last3f ? number_format($row->avg_last3f, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-gray-500 mt-2">※ ハイペースは前傾で勝ちタイム速め、スローは上がり勝負になりがち</p>
        </div>
    </div>

    {{-- ============ 距離カテゴリ × ペース × 脚質 (3次元) ============ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">距離 × ペース × 決着脚質 (3着以内)</h2>
        <p class="text-xs text-gray-500 mb-3">距離カテゴリごとにペース別の決着脚質を見ます。「同じスローでも短距離と長距離では決まり方が違う」を可視化。</p>
        <div class="table-scroll">
        <table class="w-full text-sm min-w-[560px]">
            <thead class="bg-gray-100 text-xs text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">距離</th>
                    <th class="px-3 py-2">ペース</th>
                    @foreach ($styles as $s)
                        <th class="px-3 py-2">{{ $s }}</th>
                    @endforeach
                    <th class="px-3 py-2">合計</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($distCats as $cat => $_range)
                    @foreach ($paces as $p)
                        @php
                            $row = $cube[$cat][$p] ?? [];
                            $sum = array_sum($row);
                        @endphp
                        @if ($sum > 0)
                            <tr class="border-b">
                                @if ($loop->first)
                                    <td class="px-3 py-2 font-semibold align-top" rowspan="{{ collect($paces)->filter(fn($pp) => array_sum($cube[$cat][$pp] ?? []) > 0)->count() }}">{{ $cat }}</td>
                                @endif
                                <td class="px-3 py-2">
                                    <span class="inline-block px-2 py-0.5 rounded text-white text-xs"
                                        style="background-color: {{ $paceColor[$p] }}">{{ $p }}</span>
                                    <span class="text-xs text-gray-500">{{ $paceLabel[$p] }}</span>
                                </td>
                                @foreach ($styles as $s)
                                    @php
                                        $cnt = $row[$s] ?? 0;
                                        $pct = $sum > 0 ? round($cnt / $sum * 100, 1) : 0;
                                    @endphp
                                    <td class="px-3 py-2 text-center">
                                        <div class="font-bold">{{ $cnt }}</div>
                                        <div class="text-xs text-gray-500">{{ $pct }}%</div>
                                    </td>
                                @endforeach
                                <td class="px-3 py-2 text-center font-bold">{{ $sum }}</td>
                            </tr>
                        @endif
                    @endforeach
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded">
        <h3 class="font-semibold text-amber-800 mb-2">読み方のヒント</h3>
        <ul class="text-sm text-amber-700 list-disc list-inside space-y-1">
            <li><b>ハイペース(H)</b>: 前半が速いとスタミナ消耗→差し・追込が好走しやすい</li>
            <li><b>スローペース(S)</b>: 前半が遅いと前残り→逃げ・先行が好走しやすい</li>
            <li><b>ミドル(M)</b>: 標準的なペース。各脚質バランスよく</li>
            <li>距離やコースで傾向が変わるので、フィルターを切り替えて見比べてみてください</li>
        </ul>
    </div>
</div>

@push('scripts')
@php
    $paceChartData = [
        'styles' => $styles,
        'paces'  => $paces,
        'pivot'  => $pivot,
    ];
    $distChartData = [
        'cats'     => array_keys($byDistance),
        'h_series' => array_map(fn($r) => $r['H'], array_values($byDistance)),
        'm_series' => array_map(fn($r) => $r['M'], array_values($byDistance)),
        's_series' => array_map(fn($r) => $r['S'], array_values($byDistance)),
    ];
@endphp
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = () => document.documentElement.classList.contains('dark');
    const themeMode = () => isDark() ? 'dark' : 'light';

    // ペース × 脚質
    const pc = @json($paceChartData);
    const series1 = pc.styles.map(style => ({
        name: style,
        data: pc.paces.map(pace => (pc.pivot[pace] && pc.pivot[pace][style]) || 0)
    }));
    new ApexCharts(document.querySelector('#pace-chart'), {
        chart: { type: 'bar', height: 320, stacked: true, stackType: '100%', toolbar: { show: false }, foreColor: isDark() ? '#cbd5e1' : '#334155' },
        theme: { mode: themeMode() },
        series: series1,
        xaxis: { categories: pc.paces.map(p => p === 'H' ? 'ハイ(H)' : p === 'M' ? 'ミドル(M)' : 'スロー(S)') },
        colors: ['#dc2626', '#f59e0b', '#0284c7', '#10b981'],
        plotOptions: { bar: { borderRadius: 0, horizontal: false } },
        legend: { position: 'top' },
    }).render();

    // 距離カテゴリ別
    const dc = @json($distChartData);
    new ApexCharts(document.querySelector('#dist-chart'), {
        chart: { type: 'bar', height: 320, stacked: true, toolbar: { show: false }, foreColor: isDark() ? '#cbd5e1' : '#334155' },
        theme: { mode: themeMode() },
        series: [
            { name: 'H ハイ',   data: dc.h_series },
            { name: 'M ミドル', data: dc.m_series },
            { name: 'S スロー', data: dc.s_series },
        ],
        xaxis: { categories: dc.cats },
        colors: ['#dc2626', '#f59e0b', '#0284c7'],
        plotOptions: { bar: { borderRadius: 4, horizontal: false } },
        legend: { position: 'top' },
    }).render();
});
</script>
@endpush
@endsection
