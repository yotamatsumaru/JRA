@extends('layouts.app')
@section('title', '出馬表ベース推奨 - ' . $race->name)

@section('content')
<div class="space-y-4">
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('analytics.recommend.race') }}" class="text-xs text-gray-500 hover:text-gray-700">← レース選択に戻る</a>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-800">🐎 推奨印 - {{ $race->name }}</h1>
    </div>

    @include('analytics.recommend._nav', ['active' => 'race'])

    {{-- レース情報サマリ --}}
    <div class="bg-white rounded-lg shadow p-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs sm:text-sm">
        <span class="font-bold text-gray-700">{{ $race->race_date?->format('Y/m/d') }}</span>
        <span>{{ $race->venue?->name }} {{ $race->race_number }}R</span>
        @if ($race->grade)
            @php
                $gradeCls = match ($race->grade) {
                    'G1' => 'bg-rose-500 text-white',
                    'G2' => 'bg-amber-500 text-white',
                    'G3' => 'bg-emerald-500 text-white',
                    'OP','L' => 'bg-sky-500 text-white',
                    default => 'bg-gray-200 text-gray-700',
                };
            @endphp
            <span class="px-2 py-0.5 rounded text-xs {{ $gradeCls }}">{{ $race->grade }}</span>
        @endif
        <span class="px-2 py-0.5 rounded text-xs {{ $race->track_type === '芝' ? 'bg-emerald-100 text-emerald-800' : ($race->track_type === 'ダート' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700') }}">
            {{ $race->track_type }}
        </span>
        <span>{{ $race->distance }}m</span>
        @if ($race->course_condition)
            <span class="px-2 py-0.5 bg-gray-100 rounded">馬場: {{ $race->course_condition }}</span>
        @endif
        <span>頭数: {{ count($evaluations) }}</span>
        <a href="{{ route('races.show', $race) }}" class="ml-auto text-xs text-rose-600 hover:underline">レース詳細 →</a>
    </div>

    {{-- 重み&適用条件のバナー --}}
    @php $w = $settings['weights']; @endphp
    <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900 flex flex-wrap items-center gap-x-4 gap-y-1">
        <span>適用重み:</span>
        <span class="px-2 py-0.5 bg-purple-100 text-purple-800 rounded">🧬 {{ $w['pedigree'] }}</span>
        <span class="px-2 py-0.5 bg-sky-100 text-sky-800 rounded">👤 {{ $w['jockey'] }}</span>
        <span class="px-2 py-0.5 bg-rose-100 text-rose-800 rounded">🐎 {{ $w['horse'] }}</span>
        <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded">💰 {{ $w['roi'] }}</span>
        <span>最低出走数 ≥ {{ $settings['min_runs'] }}</span>
        <a href="{{ route('analytics.recommend.settings') }}" class="ml-auto text-amber-700 hover:underline font-bold">⚙️ 重みを変える →</a>
    </div>

    {{-- 推奨馬券 --}}
    @if (count($recommended_bets) > 0)
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-2.5 bg-rose-50 border-b border-rose-100">
                <h2 class="font-bold text-rose-800">🎫 推奨馬券組み合わせ</h2>
                <p class="text-xs text-rose-700 mt-0.5">印の付与に基づく機械的な提案です。最終判断は自己責任で。</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 p-4">
                @foreach ($recommended_bets as $bet)
                    @php
                        $riskCls = match ($bet['risk']) {
                            'low'  => 'border-emerald-300 bg-emerald-50',
                            'mid'  => 'border-amber-300 bg-amber-50',
                            'high' => 'border-rose-300 bg-rose-50',
                            'speculative' => 'border-purple-300 bg-purple-50',
                            default => 'border-gray-200 bg-gray-50',
                        };
                        $riskLabel = match ($bet['risk']) {
                            'low'  => '堅実',
                            'mid'  => '標準',
                            'high' => '点数多',
                            'speculative' => '穴狙い',
                            default => '',
                        };
                    @endphp
                    <div class="border-2 rounded p-3 {{ $riskCls }}">
                        <div class="flex items-center justify-between mb-1">
                            <span class="font-bold text-gray-800">{{ $bet['type'] }}</span>
                            <span class="text-[10px] text-gray-600">{{ $riskLabel }}</span>
                        </div>
                        <div class="font-mono text-base font-bold text-gray-900">{{ $bet['combo'] }}</div>
                        <div class="text-[11px] text-gray-600 mt-1">{{ $bet['detail'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- スコアランキング(印付与) --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-2.5 bg-gray-50 border-b">
            <h2 class="font-bold text-gray-800">📊 スコア順ランキング(印付与)</h2>
            <p class="text-xs text-gray-600 mt-0.5">
                印は <strong>◎</strong>=1位70+ / <strong>○</strong>=2位60+ / <strong>▲</strong>=3位55+ / <strong>△</strong>=4-5位50+ / <strong>☆</strong>=ROIサブスコア50+
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs whitespace-nowrap">
                <thead class="bg-gray-50 text-gray-600 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 text-center">印</th>
                        <th class="px-2 py-1.5 text-right">順</th>
                        <th class="px-2 py-1.5 text-right">馬番</th>
                        <th class="px-2 py-1.5 text-left">馬名</th>
                        <th class="px-2 py-1.5 text-left">騎手</th>
                        <th class="px-2 py-1.5 text-left">父</th>
                        <th class="px-2 py-1.5 text-left">母父</th>
                        <th class="px-2 py-1.5 text-right">🧬血統</th>
                        <th class="px-2 py-1.5 text-right">👤騎手</th>
                        <th class="px-2 py-1.5 text-right">🐎馬</th>
                        <th class="px-2 py-1.5 text-right">💰ROI</th>
                        <th class="px-2 py-1.5 text-right">合計</th>
                        <th class="px-2 py-1.5 text-right">着順</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($evaluations as $e)
                        @php
                            $sub = $e->eval['sub'];
                            $total = $e->eval['total'];
                            $bgRow = match (true) {
                                $e->mark === '◎' => 'bg-rose-100',
                                $e->mark === '○' => 'bg-amber-100',
                                $e->mark === '▲' => 'bg-purple-100',
                                $e->mark === '△' => 'bg-sky-50',
                                $e->mark === '☆' => 'bg-emerald-50',
                                default => '',
                            };
                            $markCls = match ($e->mark) {
                                '◎' => 'text-rose-700',
                                '○' => 'text-amber-700',
                                '▲' => 'text-purple-700',
                                '△' => 'text-sky-700',
                                '☆' => 'text-emerald-700',
                                default => 'text-gray-300',
                            };
                            $finishInt = $e->result->finish_position_int;
                            $finishCls = match (true) {
                                $finishInt === 1 => 'bg-rose-500 text-white',
                                $finishInt === 2 => 'bg-amber-500 text-white',
                                $finishInt === 3 => 'bg-emerald-500 text-white',
                                default => 'text-gray-500',
                            };
                        @endphp
                        <tr class="border-t hover:bg-gray-50 {{ $bgRow }}">
                            <td class="px-2 py-1.5 text-center">
                                <span class="text-2xl font-bold {{ $markCls }}">{{ $e->mark ?: '・' }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right text-gray-500">{{ $e->rank }}</td>
                            <td class="px-2 py-1.5 text-right font-bold">{{ $e->result->horse_number }}</td>
                            <td class="px-2 py-1.5">
                                <a href="{{ route('horses.show', $e->horse) }}" class="font-bold text-gray-800 hover:text-rose-600 hover:underline">{{ $e->horse->name }}</a>
                            </td>
                            <td class="px-2 py-1.5">
                                @if ($e->jockey)
                                    <a href="{{ route('jockeys.show', $e->jockey) }}" class="text-gray-700 hover:text-sky-600 hover:underline">{{ $e->jockey->name }}</a>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-2 py-1.5 text-purple-700" title="{{ $e->horse->father }}">{{ \Illuminate\Support\Str::limit($e->horse->father ?? '-', 12) }}</td>
                            <td class="px-2 py-1.5 text-purple-700" title="{{ $e->horse->mother_father }}">{{ \Illuminate\Support\Str::limit($e->horse->mother_father ?? '-', 12) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ number_format($sub['pedigree'], 1) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ number_format($sub['jockey'],   1) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ number_format($sub['horse'],    1) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ number_format($sub['roi'],      1) }}</td>
                            <td class="px-2 py-1.5 text-right">
                                <span class="font-bold text-base">{{ number_format($total, 1) }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right">
                                @if ($finishInt)
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-bold {{ $finishCls }}">{{ $finishInt }}着</span>
                                @else
                                    <span class="text-gray-400 text-[11px]">{{ $e->result->finish_position ?: '-' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- 馬ごとの内訳カード(◎○▲△☆ がついた馬のみ) --}}
    @php $marked = array_filter($evaluations, fn($e) => $e->mark !== ''); @endphp
    @if (count($marked) > 0)
        <div class="space-y-3">
            <h2 class="text-base font-bold text-gray-800">🔍 印付き馬の内訳と推奨理由</h2>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                @foreach ($marked as $e)
                    @php
                        $sub = $e->eval['sub'];
                        $total = $e->eval['total'];
                        $borderCls = match ($e->mark) {
                            '◎' => 'border-rose-500',
                            '○' => 'border-amber-500',
                            '▲' => 'border-purple-500',
                            '△' => 'border-sky-400',
                            '☆' => 'border-emerald-500',
                            default => 'border-gray-300',
                        };
                        $markCls = match ($e->mark) {
                            '◎' => 'text-rose-700',
                            '○' => 'text-amber-700',
                            '▲' => 'text-purple-700',
                            '△' => 'text-sky-700',
                            '☆' => 'text-emerald-700',
                            default => 'text-gray-400',
                        };
                        $bars = [
                            ['key' => 'pedigree', 'label' => '🧬 血統', 'color' => 'bg-purple-500'],
                            ['key' => 'jockey',   'label' => '👤 騎手', 'color' => 'bg-sky-500'],
                            ['key' => 'horse',    'label' => '🐎 馬',   'color' => 'bg-rose-500'],
                            ['key' => 'roi',      'label' => '💰 ROI',  'color' => 'bg-amber-500'],
                        ];
                    @endphp
                    <div class="bg-white rounded-lg shadow border-l-4 {{ $borderCls }} p-4">
                        <div class="flex items-start gap-3 mb-3">
                            <div class="text-4xl font-bold {{ $markCls }} leading-none">{{ $e->mark }}</div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[11px] text-gray-500">#{{ $e->result->horse_number }}</span>
                                    <a href="{{ route('horses.show', $e->horse) }}" class="font-bold text-gray-800 hover:underline">{{ $e->horse->name }}</a>
                                    @if ($e->jockey)
                                        <span class="text-xs text-gray-500">/ {{ $e->jockey->name }}</span>
                                    @endif
                                </div>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    父: {{ $e->horse->father ?? '-' }} / 母父: {{ $e->horse->mother_father ?? '-' }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] text-gray-500">合計</div>
                                <div class="text-2xl font-bold text-gray-800">{{ number_format($total, 1) }}</div>
                            </div>
                        </div>

                        {{-- サブスコア進捗バー --}}
                        <div class="space-y-1.5">
                            @foreach ($bars as $b)
                                @php $val = (float) $sub[$b['key']]; $pct = max(0, min(100, $val)); @endphp
                                <div>
                                    <div class="flex justify-between text-[10px] text-gray-600 mb-0.5">
                                        <span>{{ $b['label'] }}</span>
                                        <span class="font-bold">{{ number_format($val, 1) }}</span>
                                    </div>
                                    <div class="h-1.5 bg-gray-100 rounded overflow-hidden">
                                        <div class="h-full {{ $b['color'] }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- 推奨理由 --}}
                        @if (count($e->reasons) > 0)
                            <div class="mt-3 pt-3 border-t">
                                <div class="text-[11px] font-bold text-gray-600 mb-1">💡 推奨理由</div>
                                <ul class="text-[11px] text-gray-700 space-y-0.5 list-disc list-inside">
                                    @foreach ($e->reasons as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 注意書き --}}
    <div class="bg-gray-50 border border-gray-200 rounded p-3 text-xs text-gray-600 space-y-1">
        <div class="font-bold text-gray-700">📖 ご利用上の注意</div>
        <ul class="list-disc list-inside space-y-0.5">
            <li>本機能は過去走と血統からの統計的な傾向に基づく機械的な提案です。レースの最新状況(馬場・天候・パドック等)は反映されません。</li>
            <li>サンプル不足(同条件で {{ $settings['min_runs'] }} 走未満)のサブスコアは 0 として扱われます。</li>
            <li>キャッシュは最大5分間保持されます。重みを変えた直後は古いスコアが表示されることがあります。</li>
            <li>最終判断はご自身で。馬券は計画的に。</li>
        </ul>
    </div>
</div>
@endsection
