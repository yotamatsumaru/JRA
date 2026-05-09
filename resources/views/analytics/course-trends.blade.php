@extends('layouts.app')
@section('title', 'コース別傾向(競馬場×距離)')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl sm:text-2xl font-bold text-gray-800">コース別傾向(競馬場×距離)</h1>
        <div class="text-xs text-gray-500">
            JRA公式コース図準拠の静的マスタ + 蓄積データの実績傾向
        </div>
    </div>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 text-sm items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">競馬場</label>
            <select name="venue_id" class="border rounded px-2 py-1" onchange="this.form.submit()">
                <option value="">すべて</option>
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected($venueId == $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">トラック</label>
            <select name="track_type" class="border rounded px-2 py-1" onchange="this.form.submit()">
                <option value="">すべて</option>
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected($trackType === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">距離カテゴリ</label>
            <select name="distance_cat" class="border rounded px-2 py-1" onchange="this.form.submit()">
                <option value="">すべて</option>
                @foreach ($distCats as $c)
                    <option value="{{ $c }}" @selected($distCat === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="bg-primary-600 text-white px-4 py-1 rounded">適用</button></noscript>
        <a href="{{ route('analytics.course-trends') }}" class="text-xs text-gray-500 hover:text-gray-800 underline">フィルタクリア</a>
    </form>

    {{-- サマリ --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-lg shadow p-3">
            <div class="text-xs text-gray-500">表示コース数</div>
            <div class="text-2xl font-bold text-primary-700">{{ $summary['course_count'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-3">
            <div class="text-xs text-gray-500">実績データあり</div>
            <div class="text-2xl font-bold text-emerald-600">{{ $summary['with_data'] }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-3">
            <div class="text-xs text-gray-500">対象レース数</div>
            <div class="text-2xl font-bold text-gray-700">{{ number_format($summary['total_races']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-3 text-xs text-gray-600 leading-relaxed">
            <span class="font-semibold text-gray-700">凡例:</span><br>
            実績傾向の%は<strong>勝ち馬</strong>の枠/脚質構成、ペース欄はそのコースの開催ペース分布。
        </div>
    </div>

    {{-- メインテーブル --}}
    <div class="bg-white rounded-lg shadow p-2 sm:p-4">
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 p-4">該当するコースがありません。フィルタを変更してください。</p>
        @else
        <div class="table-scroll">
            <table class="w-full text-xs sm:text-sm min-w-[1200px]">
                <thead class="bg-gray-100 text-gray-600 sticky top-0">
                    <tr>
                        <th class="px-2 py-2 text-left">場</th>
                        <th class="px-2 py-2 text-left">トラック</th>
                        <th class="px-2 py-2 text-right">距離</th>
                        <th class="px-2 py-2 text-center">区分</th>
                        <th class="px-2 py-2 text-right">直線</th>
                        <th class="px-2 py-2 text-right">高低差</th>
                        <th class="px-2 py-2 text-center">コーナー</th>
                        <th class="px-2 py-2 text-left">スタート</th>
                        <th class="px-2 py-2 text-center">想定脚質</th>
                        <th class="px-2 py-2 text-center">想定枠</th>
                        <th class="px-2 py-2 text-center">想定P</th>
                        <th class="px-2 py-2 text-right">レース</th>
                        <th class="px-2 py-2 text-right">勝ち平均上り</th>
                        <th class="px-2 py-2 text-center">枠別勝(内/中/外%)</th>
                        <th class="px-2 py-2 text-center">脚質別勝(逃/先/差/追%)</th>
                        <th class="px-2 py-2 text-center">実ペース(H/M/S%)</th>
                        <th class="px-2 py-2 text-left min-w-[280px]">コース特徴(コメント)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($rows as $r)
                        <tr class="hover:bg-primary-50/40">
                            <td class="px-2 py-2 font-medium text-gray-800 whitespace-nowrap">
                                <span class="text-[10px] text-gray-400">{{ $r->venue_code }}</span>
                                {{ $r->venue_name }}
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                @if ($r->track_type === '芝')
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-emerald-100 text-emerald-700">芝</span>
                                @elseif ($r->track_type === 'ダート')
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-amber-100 text-amber-800">ダ</span>
                                @else
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-gray-200 text-gray-700">{{ $r->track_type }}</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($r->distance) }}</td>
                            <td class="px-2 py-2 text-center text-gray-500">{{ $r->course_variation ?? '-' }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ $r->straight_length !== null ? $r->straight_length.'m' : '-' }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ $r->elevation_diff !== null ? $r->elevation_diff.'m' : '-' }}</td>
                            <td class="px-2 py-2 text-center">{{ $r->corner_count !== null ? $r->corner_count : '-' }}</td>
                            <td class="px-2 py-2 text-gray-600 whitespace-nowrap">{{ $r->start_position ?? '-' }}</td>
                            <td class="px-2 py-2 text-center">
                                @if ($r->favored_style)
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-blue-100 text-blue-700">{{ $r->favored_style }}</span>
                                @else - @endif
                            </td>
                            <td class="px-2 py-2 text-center">
                                @if ($r->favored_frame)
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-purple-100 text-purple-700">{{ $r->favored_frame }}</span>
                                @else - @endif
                            </td>
                            <td class="px-2 py-2 text-center">
                                @if ($r->pace_tendency === 'ハイ')
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-rose-100 text-rose-700">H</span>
                                @elseif ($r->pace_tendency === 'ミドル')
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-yellow-100 text-yellow-700">M</span>
                                @elseif ($r->pace_tendency === 'スロー')
                                    <span class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-sky-100 text-sky-700">S</span>
                                @else - @endif
                            </td>
                            <td class="px-2 py-2 text-right font-mono {{ $r->race_cnt === 0 ? 'text-gray-300' : '' }}">
                                {{ $r->race_cnt > 0 ? number_format($r->race_cnt) : '-' }}
                            </td>
                            <td class="px-2 py-2 text-right font-mono">
                                {{ $r->avg_win_last3f !== null ? number_format($r->avg_win_last3f, 2) : '-' }}
                            </td>
                            <td class="px-2 py-2 text-center text-[11px] whitespace-nowrap font-mono">
                                @if ($r->inner_pct !== null)
                                    <span class="text-blue-600">{{ $r->inner_pct }}</span> /
                                    <span class="text-gray-700">{{ $r->middle_pct }}</span> /
                                    <span class="text-rose-600">{{ $r->outer_pct }}</span>
                                @else
                                    <span class="text-gray-300">-</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-center text-[11px] whitespace-nowrap font-mono">
                                @if ($r->nige_pct !== null)
                                    <span class="text-rose-600">{{ $r->nige_pct }}</span> /
                                    <span class="text-orange-600">{{ $r->senko_pct }}</span> /
                                    <span class="text-emerald-700">{{ $r->sashi_pct }}</span> /
                                    <span class="text-blue-700">{{ $r->oikomi_pct }}</span>
                                @else
                                    <span class="text-gray-300">-</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-center text-[11px] whitespace-nowrap font-mono">
                                @if ($r->pace_h_pct !== null)
                                    <span class="text-rose-600">{{ $r->pace_h_pct }}</span> /
                                    <span class="text-yellow-700">{{ $r->pace_m_pct }}</span> /
                                    <span class="text-sky-700">{{ $r->pace_s_pct }}</span>
                                @else
                                    <span class="text-gray-300">-</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-gray-700 leading-relaxed">{{ $r->notes }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 注釈 --}}
    <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900 leading-relaxed">
        <p class="font-semibold mb-1">📌 データの読み方</p>
        <ul class="list-disc list-inside space-y-0.5">
            <li><strong>直線/高低差/コーナー数/スタート位置</strong>: JRA公式コース図に準拠した静的情報</li>
            <li><strong>想定脚質/想定枠/想定P</strong>: 過去5年程度の傾向を踏まえた中粒度の事前期待値(管理者が執筆)</li>
            <li><strong>枠別勝(内/中/外%)</strong>: 勝ち馬の枠を 1-3=内, 4-5=中, 6-8=外 に分類した構成比</li>
            <li><strong>脚質別勝(逃/先/差/追%)</strong>: 勝ち馬の脚質構成比 (running_style 列より集計)</li>
            <li><strong>実ペース(H/M/S%)</strong>: そのコースで開催されたレースの判定ペース分布</li>
            <li>レース欄が「-」の場合、まだ蓄積データがありません。インポートを進めると徐々に埋まります。</li>
        </ul>
    </div>
</div>
@endsection
