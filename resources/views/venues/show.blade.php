@extends('layouts.app')
@section('title', $venue->name)

@section('content')
<div class="space-y-6">

    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-xs text-gray-500">{{ $venue->code }} / {{ $venue->region }}</div>
        <h1 class="text-2xl font-bold text-gray-800 mt-1">{{ $venue->name }}</h1>
        <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><span class="text-gray-500">回り:</span> {{ $venue->direction ?? '-' }}</div>
            <div><span class="text-gray-500">芝直線:</span> {{ $venue->turf_straight ?? '-' }}m</div>
            <div><span class="text-gray-500">ダート直線:</span> {{ $venue->dirt_straight ?? '-' }}m</div>
            <div><a href="{{ route('analytics.venue', ['venue_id' => $venue->id]) }}" class="text-primary-600 hover:underline">→ 詳細傾向分析</a></div>
        </div>
        @if ($venue->characteristics)
            <p class="mt-4 text-sm text-gray-700 whitespace-pre-wrap">{{ $venue->characteristics }}</p>
        @endif
    </div>

    {{-- 枠順×勝率 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">枠番別 勝率</h2>
            <div id="frame-chart"></div>
            <table class="w-full text-sm mt-3">
                <thead class="text-xs text-gray-500 border-b">
                    <tr><th class="text-left py-1">枠</th><th class="py-1">出走</th><th class="py-1">勝利</th><th class="py-1">勝率</th></tr>
                </thead>
                <tbody>
                    @forelse ($frameStats as $fs)
                    <tr class="border-b">
                        <td class="py-1">{{ $fs->frame_number }}枠</td>
                        <td class="text-center">{{ $fs->cnt }}</td>
                        <td class="text-center">{{ $fs->wins }}</td>
                        <td class="text-center font-bold">{{ $fs->cnt > 0 ? round($fs->wins / $fs->cnt * 100, 1) : 0 }}%</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-gray-400 py-3">データなし</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">脚質別 勝率</h2>
            <div id="style-chart"></div>
            <table class="w-full text-sm mt-3">
                <thead class="text-xs text-gray-500 border-b">
                    <tr><th class="text-left py-1">脚質</th><th class="py-1">出走</th><th class="py-1">勝利</th><th class="py-1">勝率</th></tr>
                </thead>
                <tbody>
                    @forelse ($styleStats as $ss)
                    <tr class="border-b">
                        <td class="py-1">{{ $ss->running_style }}</td>
                        <td class="text-center">{{ $ss->cnt }}</td>
                        <td class="text-center">{{ $ss->wins }}</td>
                        <td class="text-center font-bold">{{ $ss->cnt > 0 ? round($ss->wins / $ss->cnt * 100, 1) : 0 }}%</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-gray-400 py-3">データなし</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 距離別 --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">距離別レース数</h2>
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 border-b">
                <tr><th class="text-left py-1">トラック</th><th class="text-left py-1">距離</th><th class="py-1">レース数</th></tr>
            </thead>
            <tbody>
                @forelse ($byDistance as $d)
                <tr class="border-b">
                    <td class="py-1">{{ $d->track_type }}</td>
                    <td class="py-1">{{ $d->distance }}m</td>
                    <td class="text-center">{{ $d->cnt }}</td>
                </tr>
                @empty
                <tr><td colspan="3" class="text-center text-gray-400 py-3">データなし</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 直近レース --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">直近のレース</h2>
        @if ($recentRaces->isEmpty())
            <p class="text-sm text-gray-500">レースが登録されていません</p>
        @else
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 border-b">
                <tr><th class="text-left py-1">日付</th><th class="text-left py-1">R</th><th class="text-left py-1">レース名</th><th class="py-1">距離</th></tr>
            </thead>
            <tbody>
                @foreach ($recentRaces as $r)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-1">{{ $r->race_date?->format('Y/m/d') }}</td>
                    <td class="py-1">{{ $r->race_number }}R</td>
                    <td class="py-1"><a href="{{ route('races.show', $r) }}" class="text-primary-600 hover:underline">{{ $r->name }}</a></td>
                    <td class="text-center text-xs text-gray-500">{{ $r->track_type }}{{ $r->distance }}m</td>
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
    const frameStats = @json($frameStats);
    if (frameStats.length > 0) {
        new ApexCharts(document.querySelector('#frame-chart'), {
            chart: { type: 'bar', height: 220, toolbar: { show: false } },
            series: [{
                name: '勝率(%)',
                data: frameStats.map(f => f.cnt > 0 ? Math.round(f.wins / f.cnt * 1000) / 10 : 0)
            }],
            xaxis: { categories: frameStats.map(f => f.frame_number + '枠') },
            colors: ['#0284c7'],
            plotOptions: { bar: { borderRadius: 4 } },
        }).render();
    }

    const styleStats = @json($styleStats);
    if (styleStats.length > 0) {
        new ApexCharts(document.querySelector('#style-chart'), {
            chart: { type: 'bar', height: 220, toolbar: { show: false } },
            series: [{
                name: '勝率(%)',
                data: styleStats.map(s => s.cnt > 0 ? Math.round(s.wins / s.cnt * 1000) / 10 : 0)
            }],
            xaxis: { categories: styleStats.map(s => s.running_style) },
            colors: ['#10b981'],
            plotOptions: { bar: { borderRadius: 4 } },
        }).render();
    }
});
</script>
@endpush
@endsection
