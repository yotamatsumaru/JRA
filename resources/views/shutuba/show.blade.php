@extends('layouts.app')
@section('title', $race->name . ' - 予想ボード')

@php
    use App\Models\RaceMark;
    use App\Models\Bet;

    $marks      = RaceMark::MARKS;        // ['◎','○','▲','△','☆','✕']
    $markColors = RaceMark::MARK_COLORS;  // mark => tailwind classes
    $userId     = auth()->id();
@endphp

@section('content')
<div class="space-y-4" x-data="shutubaBoard()">

    {{-- ステータスフラッシュ --}}
    @if (session('status'))
        <div class="bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 text-sm px-4 py-2 rounded">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-200 text-sm px-4 py-2 rounded">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    {{-- ヘッダー --}}
    <x-page-header
        title="{{ $race->name }}"
        subtitle="{{ $race->race_date?->format('Y/m/d') }} {{ $race->venue?->name }} {{ $race->race_number }}R / {{ $race->track_type }}{{ $race->distance }}m {{ $race->course_condition ?: '' }}"
        icon="target">
        <x-slot name="actions">
            <a href="{{ route('shutuba.index') }}" class="inline-flex items-center space-x-1.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-3 py-2 rounded-md text-xs font-medium">
                <x-icon name="arrow-left" class="w-4 h-4" />
                <span>一覧</span>
            </a>
            <a href="{{ route('races.show', $race) }}" class="inline-flex items-center space-x-1.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-3 py-2 rounded-md text-xs font-medium">
                <x-icon name="flag" class="w-4 h-4" />
                <span>レース詳細</span>
            </a>
            <a href="{{ route('shutuba.show', [$race, 'recompute' => 1]) }}" class="inline-flex items-center space-x-1.5 bg-turf-100 hover:bg-turf-200 dark:bg-turf-900/40 dark:hover:bg-turf-900/60 text-turf-700 dark:text-turf-300 px-3 py-2 rounded-md text-xs font-medium">
                <x-icon name="bolt" class="w-4 h-4" />
                <span>スコア再計算</span>
            </a>
            <button type="button" @click="autoMark(false)"
                class="inline-flex items-center space-x-1.5 bg-gold-500 hover:bg-gold-600 text-white px-3 py-2 rounded-md text-xs font-medium">
                <x-icon name="sparkles" class="w-4 h-4" />
                <span>印を自動提案</span>
            </button>
            <button type="button" @click="autoMark(true)"
                class="inline-flex items-center space-x-1.5 bg-amber-100 hover:bg-amber-200 dark:bg-amber-900/40 dark:hover:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-3 py-2 rounded-md text-xs font-medium"
                title="既存の印を上書きして再提案">
                <x-icon name="sparkles" class="w-4 h-4" />
                <span>上書き提案</span>
            </button>
        </x-slot>
    </x-page-header>

    {{-- ペース予想 + レース全体メモ (Phase 1-A, 1-T) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- ペース予想カード --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200 mb-2 flex items-center space-x-1">
                <x-icon name="pace" class="w-4 h-4 text-amber-500" />
                <span>ペース予想</span>
            </div>
            @php
                $pf = $pace_forecast;
                $paceColor = match($pf['pace']) {
                    'H' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                    'S' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                    default => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                };
            @endphp
            <div class="inline-flex items-center px-3 py-1.5 rounded font-bold text-sm {{ $paceColor }}">
                {{ $pf['label'] }}
            </div>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-2">{{ $pf['note'] }}</p>
            <div class="mt-2 flex flex-wrap gap-2 text-[11px]">
                @foreach (['逃' => 'red', '先' => 'amber', '差' => 'emerald', '追' => 'blue', '不' => 'gray'] as $st => $col)
                    <span class="px-2 py-0.5 rounded bg-{{ $col }}-100 dark:bg-{{ $col }}-900/40 text-{{ $col }}-700 dark:text-{{ $col }}-300">
                        {{ $st }}: {{ $pf['counts'][$st] ?? 0 }}
                    </span>
                @endforeach
            </div>
        </div>

        {{-- レース全体メモ --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4 lg:col-span-2">
            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200 mb-2 flex items-center justify-between">
                <span class="flex items-center space-x-1">
                    <x-icon name="document" class="w-4 h-4 text-turf-500" />
                    <span>レース全体メモ</span>
                </span>
                <label class="inline-flex items-center space-x-1 text-[11px] cursor-pointer">
                    <input type="checkbox" id="watch-next" @checked($race_note?->watch_next)
                        @change="saveRaceNote()"
                        class="rounded border-gray-300 text-turf-600 focus:ring-turf-500">
                    <span class="text-amber-600 dark:text-amber-400">★ 次走注目</span>
                </label>
            </div>
            <textarea
                id="race-note-text"
                rows="2"
                @blur="saveRaceNote()"
                placeholder="展開予想・全体所感・次走注目馬など"
                class="w-full text-xs border dark:border-gray-600 rounded px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100 focus:ring-1 focus:ring-turf-500 focus:border-turf-500 resize-none">{{ $race_note?->note }}</textarea>
        </div>
    </div>

    {{-- 印サマリ + コピー + フィルタ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200 flex items-center space-x-1">
                <x-icon name="star" class="w-4 h-4 text-gold-500" />
                <span>印サマリ</span>
            </div>
            @foreach ($marks as $m)
                <div class="inline-flex items-center space-x-1.5 px-2 py-1 rounded border {{ $markColors[$m] }}">
                    <span class="font-bold text-sm">{{ $m }}</span>
                    <span class="text-xs">
                        @if (!empty($mark_summary[$m]))
                            {{ implode(',', $mark_summary[$m]) }}
                        @else
                            <span class="opacity-50">-</span>
                        @endif
                    </span>
                </div>
            @endforeach

            <div class="ml-auto flex items-center space-x-2">
                <button type="button" @click="copyMarks()"
                    class="inline-flex items-center space-x-1 text-xs bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-3 py-1.5 rounded-md">
                    <x-icon name="document" class="w-4 h-4" />
                    <span x-text="copyLabel"></span>
                </button>
                <textarea id="marks-text" class="hidden">{{ collect($marks)->map(fn($m) => $m . ': ' . (empty($mark_summary[$m]) ? '-' : implode(',', $mark_summary[$m])))->implode("\n") }}</textarea>
            </div>
        </div>

        {{-- 印フィルタ + 並び替え --}}
        <div class="mt-3 flex flex-wrap gap-2 text-xs items-center">
            <span class="text-gray-500 dark:text-gray-400">印フィルタ:</span>
            <a href="{{ route('shutuba.show', [$race, 'sort' => $sort]) }}"
                class="px-2 py-1 rounded border {{ !$filter_mark ? 'bg-turf-600 text-white border-turf-600' : 'bg-white dark:bg-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600' }}">
                すべて
            </a>
            @foreach ($marks as $m)
                <a href="{{ route('shutuba.show', [$race, 'filter_mark' => $m, 'sort' => $sort]) }}"
                    class="px-2 py-1 rounded border {{ $filter_mark === $m ? 'ring-2 ring-offset-1 ring-turf-500 ' : '' }} {{ $markColors[$m] }}">
                    {{ $m }}
                </a>
            @endforeach
            <a href="{{ route('shutuba.show', [$race, 'filter_mark' => 'marked', 'sort' => $sort]) }}"
                class="px-2 py-1 rounded border {{ $filter_mark === 'marked' ? 'bg-turf-600 text-white border-turf-600' : 'bg-white dark:bg-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600' }}">
                印あり
            </a>
            <a href="{{ route('shutuba.show', [$race, 'filter_mark' => 'none', 'sort' => $sort]) }}"
                class="px-2 py-1 rounded border {{ $filter_mark === 'none' ? 'bg-turf-600 text-white border-turf-600' : 'bg-white dark:bg-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600' }}">
                印なし
            </a>

            <span class="ml-3 text-gray-500 dark:text-gray-400">並び替え:</span>
            @foreach (['horse_no' => '馬番', 'score' => 'スコア', 'popularity' => '人気', 'odds' => '単オッズ'] as $val => $label)
                @php
                    $sortParams = ['sort' => $val];
                    if ($filter_mark) $sortParams['filter_mark'] = $filter_mark;
                @endphp
                <a href="{{ route('shutuba.show', array_merge([$race], $sortParams)) }}"
                    class="px-2 py-1 rounded border {{ $sort === $val ? 'bg-turf-600 text-white border-turf-600' : 'bg-white dark:bg-gray-700 dark:text-gray-200 border-gray-300 dark:border-gray-600' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- メイン: 出馬表テーブル --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 table-scroll">
        <table class="w-full text-sm min-w-[1100px]">
            <thead class="bg-gray-50 dark:bg-gray-700/60 text-xs text-gray-600 dark:text-gray-300 uppercase">
                <tr>
                    <th class="text-left px-2 py-2 w-[150px]">印</th>
                    <th class="text-center px-1 py-2 w-[40px]">★</th>
                    <th class="text-left px-2 py-2">馬</th>
                    <th class="text-center px-1 py-2 w-[40px]">脚質</th>
                    <th class="text-left px-2 py-2">騎手 / 厩舎</th>
                    <th class="text-left px-2 py-2">血統 / コース傾向</th>
                    <th class="text-right px-2 py-2 w-[60px]">人気</th>
                    <th class="text-right px-2 py-2 w-[70px]">単オッズ</th>
                    <th class="text-right px-2 py-2 w-[70px]">EV</th>
                    <th class="text-right px-2 py-2 w-[60px]">血統</th>
                    <th class="text-right px-2 py-2 w-[60px]">騎手</th>
                    <th class="text-right px-2 py-2 w-[60px]">馬</th>
                    <th class="text-right px-2 py-2 w-[60px]">回収</th>
                    <th class="text-right px-2 py-2 w-[70px]">合計</th>
                    <th class="text-left px-2 py-2 w-[200px]">メモ</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    @php
                        $rr        = $r->result;
                        $hno       = $rr->horse_number ?? '?';
                        $rmId      = $r->mark_obj?->id;
                        $score     = $r->mark_obj?->score_total;
                        $sP        = $r->mark_obj?->score_pedigree;
                        $sJ        = $r->mark_obj?->score_jockey;
                        $sH        = $r->mark_obj?->score_horse;
                        $sR        = $r->mark_obj?->score_roi;
                        $styleColors = [
                            '逃' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                            '先' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                            '差' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                            '追' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                            '不' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
                        ];
                    @endphp
                    <tr class="border-b dark:border-gray-700 align-top hover:bg-turf-50/30 dark:hover:bg-gray-700/30 transition-colors {{ $r->is_favorite ? 'bg-amber-50/40 dark:bg-amber-900/10' : '' }}"
                        data-hno="{{ $hno }}"
                        data-rrid="{{ $rr->id }}">
                        {{-- 印ボタン --}}
                        <td class="px-2 py-2">
                            <div class="flex flex-wrap gap-1" data-rrid="{{ $rr->id }}">
                                @foreach ($marks as $m)
                                    <button type="button"
                                        @click="toggleMark({{ $rr->id }}, '{{ $m }}', $event)"
                                        class="mark-btn w-7 h-7 inline-flex items-center justify-center rounded border text-sm font-bold transition-all {{ $markColors[$m] }} {{ $r->mark === $m ? 'ring-2 ring-offset-1 ring-turf-500 scale-110' : 'opacity-70 hover:opacity-100' }}"
                                        title="{{ $m }}">
                                        {{ $m }}
                                    </button>
                                @endforeach
                                <button type="button"
                                    @click="clearMark({{ $rr->id }}, $event)"
                                    class="w-7 h-7 inline-flex items-center justify-center rounded border border-gray-300 dark:border-gray-600 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"
                                    title="印をクリア">
                                    <x-icon name="x-mark" class="w-3 h-3" />
                                </button>
                            </div>
                        </td>

                        {{-- お気に入りボタン (Phase 1-M) --}}
                        <td class="px-1 py-2 text-center">
                            @if ($r->horse)
                                <button type="button"
                                    @click="toggleFavorite('horse', {{ $r->horse->id }}, $event)"
                                    class="favorite-btn text-lg {{ $r->is_favorite ? 'text-amber-500' : 'text-gray-300 dark:text-gray-600 hover:text-amber-400' }}"
                                    title="お気に入り(馬)">★</button>
                            @endif
                        </td>

                        {{-- 馬 --}}
                        <td class="px-2 py-2">
                            <div class="flex items-center space-x-2">
                                <span class="inline-flex w-7 h-7 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs font-bold">{{ $hno }}</span>
                                <div>
                                    @if ($r->horse)
                                        <a href="{{ route('horses.show', $r->horse) }}" class="font-medium text-turf-700 dark:text-turf-400 hover:underline">{{ $r->horse->name }}</a>
                                    @else
                                        <span class="text-gray-500">-</span>
                                    @endif
                                    <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                        {{ $rr->sex_age ?: '' }}
                                        @if ($rr->jockey_weight) / {{ $rr->jockey_weight }}kg @endif
                                        @if ($rr->horse_weight) / {{ $rr->horse_weight }}kg @endif
                                    </div>
                                    {{-- 過去5走展開ボタン --}}
                                    @if (!empty($r->recent) && count($r->recent) > 0)
                                        <button type="button"
                                            @click="togglePast({{ $rr->id }})"
                                            class="text-[11px] text-gray-500 dark:text-gray-400 hover:text-turf-700 dark:hover:text-turf-400 inline-flex items-center space-x-1 mt-0.5">
                                            <x-icon name="chart" class="w-3 h-3" />
                                            <span>過去{{ count($r->recent) }}走</span>
                                            <x-icon name="chevron-down" class="w-3 h-3 transition-transform" ::class="past[{{ $rr->id }}] ? 'rotate-180' : ''" />
                                        </button>
                                    @endif
                                </div>
                            </div>
                            {{-- 過去5走の展開部 --}}
                            @if (!empty($r->recent) && count($r->recent) > 0)
                                <div x-show="past[{{ $rr->id }}]" x-cloak x-transition class="mt-2 pl-9 space-y-0.5 text-[11px]">
                                    @foreach ($r->recent as $past)
                                        <div class="flex items-center space-x-2 text-gray-600 dark:text-gray-400">
                                            <span class="w-16 text-gray-500">{{ $past->race?->race_date?->format('y/m/d') }}</span>
                                            <span class="w-16">{{ $past->race?->venue?->name }}</span>
                                            <span class="w-12">{{ $past->race?->track_type }}{{ $past->race?->distance }}m</span>
                                            <span class="w-8 text-right font-bold {{ ($past->finish_position_int ?? 99) <= 3 ? 'text-red-600 dark:text-red-400' : 'text-gray-500' }}">
                                                {{ $past->finish_position_int }}着
                                            </span>
                                            @if ($past->popularity)
                                                <span class="w-10 text-gray-500">{{ $past->popularity }}人</span>
                                            @endif
                                            <span class="text-gray-500 truncate">{{ $past->race?->name }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </td>

                        {{-- 脚質 (Phase 1-A) --}}
                        <td class="px-1 py-2 text-center">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded text-xs font-bold {{ $styleColors[$r->running_style] ?? $styleColors['不'] }}"
                                title="過去走の通過順位から推定">
                                {{ $r->running_style }}
                            </span>
                        </td>

                        {{-- 騎手・厩舎 --}}
                        <td class="px-2 py-2">
                            <div class="flex items-center space-x-1">
                                @if ($r->jockey)
                                    <a href="{{ route('jockeys.show', $r->jockey) }}" class="text-gray-800 dark:text-gray-200 hover:underline">{{ $r->jockey->name }}</a>
                                    <button type="button"
                                        @click="toggleFavorite('jockey', {{ $r->jockey->id }}, $event)"
                                        class="text-xs text-gray-300 hover:text-amber-400" title="お気に入り(騎手)">★</button>
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-gray-500 dark:text-gray-400 flex items-center space-x-1">
                                <span>{{ $r->trainer->name ?? '' }}</span>
                                @if ($r->trainer)
                                    <button type="button"
                                        @click="toggleFavorite('trainer', {{ $r->trainer->id }}, $event)"
                                        class="text-xs text-gray-300 hover:text-amber-400" title="お気に入り(厩舎)">★</button>
                                @endif
                            </div>
                        </td>

                        {{-- 血統+コース傾向 --}}
                        <td class="px-2 py-2 text-[11px]">
                            @if ($r->horse?->father)
                                <div class="text-gray-700 dark:text-gray-300" title="父: {{ $r->horse->father }}{{ $r->horse->mother_father ? ' / 母父: ' . $r->horse->mother_father : '' }}">
                                    <span class="text-gray-500">父:</span> {{ $r->horse->father }}
                                </div>
                            @endif
                            @if ($r->horse?->mother_father)
                                <div class="text-gray-500 dark:text-gray-400">
                                    <span class="text-gray-500">母父:</span> {{ $r->horse->mother_father }}
                                </div>
                            @endif
                            {{-- コース傾向ヒント --}}
                            @if ($r->sire_hint)
                                <div class="mt-1 inline-flex items-center space-x-1 text-[10px] px-1.5 py-0.5 rounded
                                    @if ($r->sire_hint['win_rate'] >= 10) bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300
                                    @elseif ($r->sire_hint['show_rate'] >= 30) bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300
                                    @else bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 @endif"
                                    title="父{{ $r->horse->father }}・このコース類似条件 (距離±200m): {{ $r->sire_hint['runs'] }}走 {{ $r->sire_hint['wins'] }}勝 / 3着内{{ $r->sire_hint['shows'] }}回">
                                    <x-icon name="chart-bar" class="w-3 h-3" />
                                    <span>父{{ $r->sire_hint['win_rate'] }}% / 複{{ $r->sire_hint['show_rate'] }}%</span>
                                </div>
                            @endif
                        </td>

                        {{-- 人気 --}}
                        <td class="px-2 py-2 text-right">
                            @if ($rr->popularity)
                                <span class="text-xs {{ $rr->popularity <= 3 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">{{ $rr->popularity }}</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-2 py-2 text-right">
                            @if ($rr->win_odds)
                                <span class="text-xs text-gray-700 dark:text-gray-300">{{ number_format((float)$rr->win_odds, 1) }}</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>

                        {{-- 期待値(EV) (Phase 1-B) --}}
                        <td class="px-2 py-2 text-right">
                            @if ($r->ev)
                                @php
                                    $ev = $r->ev;
                                    $evColor = $ev['ev'] >= 0.30 ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                        : ($ev['ev'] >= 0.10 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                        : ($ev['ev'] >= -0.10 ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
                                        : 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300'));
                                @endphp
                                <div class="inline-flex flex-col items-end px-1.5 py-0.5 rounded {{ $evColor }}"
                                    title="推定勝率 {{ $ev['prob'] }}% × オッズ - 1 = {{ number_format($ev['ev'], 2) }}">
                                    <span class="font-bold text-[11px]">{{ $ev['label'] }}</span>
                                    <span class="text-[10px] opacity-80">{{ number_format($ev['ev'], 2) }}</span>
                                </div>
                            @else
                                <span class="text-gray-400 text-xs">-</span>
                            @endif
                        </td>

                        {{-- スコア4列内訳 --}}
                        <td class="px-2 py-2 text-right">
                            <span class="text-xs {{ $sP !== null && $sP >= 60 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}">
                                {{ $sP !== null ? number_format((float)$sP, 1) : '-' }}
                            </span>
                        </td>
                        <td class="px-2 py-2 text-right">
                            <span class="text-xs {{ $sJ !== null && $sJ >= 60 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}">
                                {{ $sJ !== null ? number_format((float)$sJ, 1) : '-' }}
                            </span>
                        </td>
                        <td class="px-2 py-2 text-right">
                            <span class="text-xs {{ $sH !== null && $sH >= 60 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}">
                                {{ $sH !== null ? number_format((float)$sH, 1) : '-' }}
                            </span>
                        </td>
                        <td class="px-2 py-2 text-right">
                            <span class="text-xs {{ $sR !== null && $sR >= 60 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}">
                                {{ $sR !== null ? number_format((float)$sR, 1) : '-' }}
                            </span>
                        </td>

                        {{-- 合計 --}}
                        <td class="px-2 py-2 text-right">
                            @if ($score !== null)
                                <span class="text-sm font-bold {{ $score >= 70 ? 'text-red-600 dark:text-red-400' : ($score >= 55 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-700 dark:text-gray-300') }}">
                                    {{ number_format((float)$score, 1) }}
                                </span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>

                        {{-- メモ --}}
                        <td class="px-2 py-2">
                            <textarea
                                rows="1"
                                @blur="saveMemo({{ $rr->id }}, $event)"
                                class="w-full text-xs border dark:border-gray-600 rounded px-2 py-1 bg-white dark:bg-gray-700 dark:text-gray-100 focus:ring-1 focus:ring-turf-500 focus:border-turf-500 resize-none"
                                placeholder="メモ…">{{ $r->memo }}</textarea>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="15" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                        該当する出走馬がありません
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 推奨買い目 + 印別馬券生成 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- 推奨買い目プレビュー --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-2">
                <x-icon name="lightbulb" class="w-4 h-4 text-gold-500" />
                <span>推奨買い目（印ベース）</span>
            </h3>
            @if (empty($recommended_bets))
                <div class="text-xs text-gray-500 dark:text-gray-400 py-3">
                    印を付けると自動で買い目案が表示されます。<br>
                    <span class="opacity-75">◎→単複 / ◎○→馬連・馬単・ワイド / ◎○▲→3連複・3連単 / ◎○▲△→3連複ボックス（☆✕は除外）</span>
                </div>
            @else
                <div class="space-y-1.5 text-xs">
                    @foreach ($recommended_bets as $b)
                        <div class="flex items-start justify-between border-b dark:border-gray-700 py-1.5">
                            <div>
                                <span class="inline-block w-16 font-semibold text-turf-700 dark:text-turf-400">{{ $b['type'] }}</span>
                                <span class="font-mono text-gray-800 dark:text-gray-200">{{ $b['combo'] }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-gray-500 dark:text-gray-400">{{ $b['detail'] }}</span>
                                <span class="ml-2 text-[10px] bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 px-1.5 py-0.5 rounded">{{ $b['points'] }}点</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 印別馬券生成フォーム --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-2">
                <x-icon name="ticket" class="w-4 h-4 text-turf-500" />
                <span>印別 馬券一括生成</span>
            </h3>
            <form method="POST" action="{{ route('shutuba.generate-bets', $race) }}" class="space-y-3 text-xs">
                @csrf
                <div>
                    <div class="text-gray-600 dark:text-gray-300 mb-1">券種(複数選択可)</div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                        @foreach ([
                            'tan'      => '単勝 (◎)',
                            'fuku'     => '複勝 (◎)',
                            'uma-ren'  => '馬連 (◎-○)',
                            'uma-tan'  => '馬単 (◎→○)',
                            'wide'     => 'ワイド (◎-{○▲△})',
                            'san-fuku' => '3連複 (◎○▲ + △)',
                            'san-tan'  => '3連単 (◎→○→▲)',
                        ] as $code => $label)
                            <label class="inline-flex items-center space-x-1.5 px-2 py-1 border dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer">
                                <input type="checkbox" name="kinds[]" value="{{ $code }}"
                                    @checked(in_array($code, ['tan','fuku','uma-ren','wide','san-fuku']))
                                    class="rounded border-gray-300 text-turf-600 focus:ring-turf-500">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <label class="text-gray-600 dark:text-gray-300">単位金額:</label>
                    <select name="unit_stake" class="border dark:border-gray-600 rounded px-2 py-1 bg-white dark:bg-gray-700 dark:text-gray-100">
                        @foreach ([100, 200, 300, 500, 1000, 2000, 3000, 5000, 10000] as $u)
                            <option value="{{ $u }}" @selected($u === 100)>{{ number_format($u) }}円</option>
                        @endforeach
                    </select>
                    <button type="submit"
                        class="ml-auto inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-3 py-1.5 rounded-md font-medium">
                        <x-icon name="plus" class="w-4 h-4" />
                        <span>馬券を生成</span>
                    </button>
                </div>
                <p class="text-[10px] text-gray-500 dark:text-gray-400">
                    印が付いていない券種はスキップされます。生成された馬券は「馬券（収支管理）」に登録されます。
                </p>
            </form>
        </div>
    </div>

    {{-- キーボードショートカット早見表 (Phase 1-O) --}}
    <details class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-3 text-xs text-gray-600 dark:text-gray-300">
        <summary class="cursor-pointer font-semibold text-gray-700 dark:text-gray-200 flex items-center space-x-1">
            <x-icon name="bolt" class="w-4 h-4 text-amber-500" />
            <span>キーボードショートカット</span>
        </summary>
        <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">↓</kbd> / <kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">j</kbd> 次の馬</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">↑</kbd> / <kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">k</kbd> 前の馬</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">1</kbd> ◎本命</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">2</kbd> ○対抗</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">3</kbd> ▲単穴</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">4</kbd> △連下</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">5</kbd> ☆穴</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">6</kbd> ✕消し</div>
            <div><kbd class="px-1.5 py-0.5 bg-gray-100 dark:bg-gray-700 rounded font-mono">0</kbd> 印クリア</div>
        </div>
    </details>
</div>

{{-- Ajax 用 JS --}}
<script>
    function shutubaBoard() {
        return {
            past: {},
            copyLabel: '印をコピー',

            togglePast(rrid) {
                this.past[rrid] = !this.past[rrid];
            },

            async toggleMark(rrid, mark, event) {
                // 既に同じ印が付いているなら解除
                const btn = event.currentTarget;
                const wrap = btn.parentElement;
                const isActive = btn.classList.contains('ring-2');
                const newMark = isActive ? null : mark;
                await this._sendMark(rrid, newMark, wrap);
            },

            async clearMark(rrid, event) {
                const wrap = event.currentTarget.parentElement;
                await this._sendMark(rrid, null, wrap);
            },

            async _sendMark(rrid, mark, wrap) {
                try {
                    const res = await fetch(@json(route('shutuba.mark', $race)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ race_result_id: rrid, mark: mark }),
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    // 成功したら画面側のクラスを更新（リロードしないでも反映）
                    wrap.querySelectorAll('.mark-btn').forEach(b => {
                        b.classList.remove('ring-2', 'ring-offset-1', 'ring-turf-500', 'scale-110');
                        b.classList.add('opacity-70');
                        if (data.mark && b.title === data.mark) {
                            b.classList.add('ring-2', 'ring-offset-1', 'ring-turf-500', 'scale-110');
                            b.classList.remove('opacity-70');
                        }
                    });
                    // 印サマリ更新のため、リロードフラグは持たず、ユーザに任せる
                } catch (e) {
                    alert('印の更新に失敗しました: ' + e.message);
                }
            },

            async saveMemo(rrid, event) {
                const memo = event.currentTarget.value;
                try {
                    const res = await fetch(@json(route('shutuba.memo', $race)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ race_result_id: rrid, memo: memo }),
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                } catch (e) {
                    console.warn('memo save failed', e);
                }
            },

            async copyMarks() {
                const t = document.getElementById('marks-text').value;
                try {
                    await navigator.clipboard.writeText(t);
                    this.copyLabel = 'コピーしました!';
                    setTimeout(() => this.copyLabel = '印をコピー', 1500);
                } catch (e) {
                    // フォールバック
                    const ta = document.getElementById('marks-text');
                    ta.classList.remove('hidden');
                    ta.select();
                    document.execCommand('copy');
                    ta.classList.add('hidden');
                    this.copyLabel = 'コピーしました!';
                    setTimeout(() => this.copyLabel = '印をコピー', 1500);
                }
            },

            // Phase 1-C: 印自動提案
            async autoMark(overwrite) {
                const msg = overwrite
                    ? '既存の印を上書きして自動提案を実行します。よろしいですか?'
                    : '空欄の馬に対して印を自動提案します。';
                if (overwrite && !confirm(msg)) return;
                try {
                    const res = await fetch(@json(route('shutuba.auto-mark', $race)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ overwrite: !!overwrite }),
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    alert(data.message || '印を提案しました');
                    location.reload();
                } catch (e) {
                    alert('自動提案に失敗しました: ' + e.message);
                }
            },

            // Phase 1-M: お気に入りトグル
            async toggleFavorite(type, targetId, event) {
                const btn = event.currentTarget;
                try {
                    const res = await fetch(@json(route('shutuba.favorite')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ type: type, target_id: targetId }),
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    if (data.state === 'on') {
                        btn.classList.remove('text-gray-300', 'dark:text-gray-600');
                        btn.classList.add('text-amber-500');
                    } else {
                        btn.classList.add('text-gray-300', 'dark:text-gray-600');
                        btn.classList.remove('text-amber-500');
                    }
                } catch (e) {
                    console.warn('favorite toggle failed', e);
                }
            },

            // Phase 1-T: レース全体メモ保存
            async saveRaceNote() {
                const noteEl = document.getElementById('race-note-text');
                const wnEl   = document.getElementById('watch-next');
                if (!noteEl) return;
                try {
                    const res = await fetch(@json(route('shutuba.race-note', $race)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            note: noteEl.value || '',
                            watch_next: wnEl ? wnEl.checked : false,
                        }),
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                } catch (e) {
                    console.warn('race-note save failed', e);
                }
            },

            // Phase 1-O: キーボードショートカット
            kbCursor: 0,
            initKeyboard() {
                document.addEventListener('keydown', (e) => {
                    // 入力中は無視
                    const tag = (e.target.tagName || '').toLowerCase();
                    if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return;
                    if (e.metaKey || e.ctrlKey || e.altKey) return;

                    const rows = Array.from(document.querySelectorAll('tr[data-rrid]'));
                    if (rows.length === 0) return;

                    // 矢印キーで行移動
                    if (e.key === 'ArrowDown' || e.key === 'j') {
                        e.preventDefault();
                        this.kbCursor = Math.min(this.kbCursor + 1, rows.length - 1);
                        this._highlightRow(rows);
                        return;
                    }
                    if (e.key === 'ArrowUp' || e.key === 'k') {
                        e.preventDefault();
                        this.kbCursor = Math.max(this.kbCursor - 1, 0);
                        this._highlightRow(rows);
                        return;
                    }

                    // 数字キーで印付与
                    const map = { '1':'◎', '2':'○', '3':'▲', '4':'△', '5':'☆', '6':'✕', '0':null };
                    if (e.key in map) {
                        e.preventDefault();
                        const row = rows[this.kbCursor];
                        if (!row) return;
                        const rrid = parseInt(row.dataset.rrid);
                        const wrap = row.querySelector('[data-rrid] , .mark-btn')?.parentElement
                                    || row.querySelector('div[data-rrid]');
                        const target = row.querySelector('div[data-rrid]');
                        this._sendMark(rrid, map[e.key], target);
                    }
                });
            },
            _highlightRow(rows) {
                rows.forEach((r, i) => {
                    if (i === this.kbCursor) {
                        r.classList.add('ring-2', 'ring-turf-500', 'ring-inset');
                        r.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    } else {
                        r.classList.remove('ring-2', 'ring-turf-500', 'ring-inset');
                    }
                });
            },

            init() {
                this.initKeyboard();
            },
        };
    }
</script>
@endsection
