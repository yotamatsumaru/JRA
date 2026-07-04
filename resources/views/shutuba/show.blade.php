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
<div class="space-y-4" x-data="shutubaBoard()" x-init="init()">

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

    {{-- ヘッダー (Phase EV-3: 発走時刻を subtitle に含める) --}}
    @php
        $subtitleParts = [];
        $subtitleParts[] = $race->race_date?->format('Y/m/d');
        if ($race->post_time) {
            $subtitleParts[] = '🕒 ' . $race->post_time->format('H:i') . '発走';
        }
        $subtitleParts[] = $race->venue?->name . ' ' . $race->race_number . 'R';
        $subtitleParts[] = $race->track_type . $race->distance . 'm' . ($race->course_condition ? ' ' . $race->course_condition : '');
        $subtitle = implode(' ', array_filter($subtitleParts));
    @endphp
    <x-page-header
        title="{{ $race->name }}"
        subtitle="{{ $subtitle }}"
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
            {{-- Phase 4-S: 予想を共有 --}}
            <button type="button" @click="shareDialog.open = true"
                class="inline-flex items-center space-x-1.5 bg-sky-500 hover:bg-sky-600 text-white px-3 py-2 rounded-md text-xs font-medium"
                title="現在の印・メモを公開URLで共有">
                <x-icon name="share" class="w-4 h-4" />
                <span>予想を共有</span>
            </button>
        </x-slot>
    </x-page-header>

    {{-- JRA 公式風レースナビゲーター (Phase NAV-1) --}}
    @isset($navigator)
        <x-race-navigator :navigator="$navigator" />
    @endisset

    {{-- Phase 4-S: 共有ダイアログ --}}
    <div x-show="shareDialog.open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
         @keydown.escape.window="shareDialog.open = false">
        <form method="POST" action="{{ route('shares.store', $race) }}"
              class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-5 space-y-3"
              @click.outside="shareDialog.open = false">
            @csrf
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">予想を共有 (読み取り専用URL)</h3>
                <button type="button" @click="shareDialog.open = false" class="text-gray-400 hover:text-gray-600">
                    <x-icon name="x-mark" class="w-5 h-5" />
                </button>
            </div>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">
                現在の印・スコア・メモのスナップショットを生成し、ログイン不要で閲覧できる公開URLを発行します。
            </p>
            <label class="block text-xs">
                <span class="text-gray-600 dark:text-gray-300">タイトル (省略可)</span>
                <input type="text" name="title" maxlength="120"
                    placeholder="{{ $race->name }} 予想"
                    class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600 text-sm">
            </label>
            <label class="block text-xs">
                <span class="text-gray-600 dark:text-gray-300">コメント (省略可)</span>
                <textarea name="comment" rows="3" maxlength="2000"
                    class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600 text-sm"></textarea>
            </label>
            <label class="block text-xs">
                <span class="text-gray-600 dark:text-gray-300">有効期限</span>
                <select name="expires_in" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600 text-sm">
                    <option value="">無期限</option>
                    <option value="1">1日</option>
                    <option value="7" selected>7日</option>
                    <option value="30">30日</option>
                    <option value="90">90日</option>
                </select>
            </label>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" @click="shareDialog.open = false"
                    class="px-3 py-1.5 text-xs text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">キャンセル</button>
                <button type="submit"
                    class="px-3 py-1.5 text-xs font-medium bg-sky-500 hover:bg-sky-600 text-white rounded">URLを発行</button>
            </div>
        </form>
    </div>

    {{-- Phase 3-I: リアルタイムオッズ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4"
         x-show="liveOdds.enabled" x-cloak>
        <div class="flex items-center justify-between mb-2">
            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200 flex items-center space-x-2">
                <x-icon name="bolt" class="w-4 h-4 text-amber-500" />
                <span>リアルタイムオッズ</span>
                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono"
                      :class="liveOdds.fresh ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'"
                      x-text="liveOdds.lastAt ? ('最終取得: ' + liveOdds.lastAt) : '未取得'"></span>
                <span class="text-[10px] text-gray-400" x-text="'スナップショット ' + liveOdds.count + ' 件'"></span>
            </div>
            <div class="flex items-center gap-2">
                <label class="inline-flex items-center gap-1 text-[11px] text-gray-600 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" x-model="liveOdds.autoRefresh" @change="toggleAutoRefresh()"
                        class="rounded border-gray-300 text-turf-600 focus:ring-turf-500">
                    <span>自動更新 (30秒)</span>
                </label>
                <button type="button" @click="captureOdds()"
                    :disabled="liveOdds.loading"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded text-[11px] font-medium bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white">
                    <x-icon name="refresh" class="w-3.5 h-3.5" />
                    <span x-text="liveOdds.loading ? '取得中...' : '今すぐ取得'"></span>
                </button>
            </div>
        </div>
        <div x-show="liveOdds.message" class="text-[11px] text-gray-500 mb-2" x-text="liveOdds.message"></div>
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-9 gap-1.5"
             x-show="liveOdds.horses && liveOdds.horses.length > 0">
            <template x-for="h in liveOdds.horses" :key="h.horse_number">
                <div class="rounded ring-1 ring-gray-200 dark:ring-gray-700 px-2 py-1.5 text-center"
                     :class="h.popularity === 1 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-white dark:bg-gray-900'">
                    <div class="text-[10px] text-gray-400">#<span x-text="h.horse_number"></span></div>
                    <div class="font-mono text-sm font-bold"
                         :class="h.win_odds && h.win_odds < 5 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-200'"
                         x-text="h.win_odds !== null ? h.win_odds.toFixed(1) : '-'"></div>
                    <div class="text-[10px] text-gray-500" x-text="h.popularity ? (h.popularity + '人気') : ''"></div>
                    <div class="text-[9px] mt-0.5" x-show="h.delta !== null && h.delta !== 0"
                         :class="h.delta < 0 ? 'text-emerald-600' : 'text-red-500'"
                         x-text="(h.delta > 0 ? '▲' : '▼') + Math.abs(h.delta).toFixed(1)"></div>
                </div>
            </template>
        </div>
    </div>

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

            {{-- 強制再計算: 既存スコアキャッシュを破棄して全頭を再集計 --}}
            @php
                $recalcParams = ['recompute' => 1, 'sort' => $sort];
                if ($filter_mark) $recalcParams['filter_mark'] = $filter_mark;
            @endphp
            <a href="{{ route('shutuba.show', array_merge([$race], $recalcParams)) }}"
                class="ml-3 px-2 py-1 rounded border bg-amber-500 text-white border-amber-500 hover:bg-amber-600"
                title="全頭のスコアキャッシュを破棄して再計算(0 が直らないときに使う)">
                ↻ 再計算
            </a>

            {{-- 最新オッズ取得 (Phase EV-2) --}}
            @if ($race->netkeiba_id)
                <button type="button" id="btn-capture-odds"
                    data-url="{{ route('shutuba.capture-odds', $race) }}"
                    class="ml-2 px-2 py-1 rounded border bg-sky-600 text-white border-sky-600 hover:bg-sky-700 inline-flex items-center gap-1"
                    title="netkeiba から最新オッズを取得し、期待値(EV)を再計算します">
                    <span>📊 最新オッズ取得</span>
                </button>

                {{-- 自動更新トグル (Phase EV-3) --}}
                @php $autoSec = (int) config('jra.odds_capture.auto_refresh_seconds', 60); @endphp
                <label id="lbl-auto-capture-odds"
                    class="ml-2 inline-flex items-center gap-1 text-[11px] text-gray-700 dark:text-gray-300 px-2 py-1 rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 cursor-pointer select-none"
                    title="ONの間、{{ $autoSec }}秒ごとに最新オッズを取得しページを更新します (タブがアクティブなときのみ)">
                    <input type="checkbox" id="chk-auto-capture-odds"
                        data-interval-seconds="{{ $autoSec }}"
                        class="align-middle" />
                    <span>🔄 自動更新 ({{ $autoSec }}秒)</span>
                    <span id="auto-capture-countdown" class="text-gray-400 ml-1"></span>
                </label>

                <span id="capture-odds-status" class="ml-1 text-[11px] text-gray-500"></span>

                @if ($has_live_odds && $live_odds_at)
                    <span class="ml-2 inline-flex items-center gap-1 text-[11px] text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-900/30 px-2 py-1 rounded"
                        title="現在表示中の EV は {{ $live_odds_at->format('Y/m/d H:i') }} 時点のライブオッズで計算されています">
                        ● ライブEV ({{ $live_odds_at->format('H:i') }})
                    </span>
                @elseif (!$has_live_odds)
                    <span class="ml-2 inline-flex items-center gap-1 text-[11px] text-gray-500 bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded"
                        title="現在の EV は出馬表時点の単勝オッズで計算されています。最新オッズ取得を押すとライブオッズに更新されます">
                        ○ 出馬表時EV
                    </span>
                @endif
            @endif
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
                    <th class="text-right px-2 py-2 w-[80px]" title="単勝オッズ (ライブ取得時はその値を表示)">単オッズ</th>
                    <th class="text-right px-2 py-2 w-[80px]" title="単勝期待値 = 推定勝率 × 単勝オッズ - 1">単EV</th>
                    <th class="text-right px-2 py-2 w-[80px]" title="複勝期待値 (オッズ推定値ベース)">複EV</th>
                    <th class="text-right px-2 py-2 w-[54px]" title="血統(父60%/母父40%)">血統</th>
                    <th class="text-right px-2 py-2 w-[54px]" title="騎手×条件 複勝率">騎手</th>
                    <th class="text-right px-2 py-2 w-[54px]" title="馬の過去走 複勝率(直近5走補正)">馬</th>
                    <th class="text-right px-2 py-2 w-[54px]" title="父複勝回収率の妙味">回収</th>
                    <th class="text-right px-2 py-2 w-[54px]" title="枠順 × 同コース">枠</th>
                    <th class="text-right px-2 py-2 w-[54px]" title="同馬の同方向(右/左) 複勝率">ｺｰｽ</th>
                    <th class="text-right px-2 py-2 w-[54px]" title="脚質 × 想定ペース">脚質</th>
                    <th class="text-right px-2 py-2 w-[80px]">合計</th>
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
                        $sFr       = $r->mark_obj?->score_frame;
                        $sCo       = $r->mark_obj?->score_course;
                        $sSt       = $r->mark_obj?->score_style;
                        // 設定の重み(タイトル用)
                        $W         = $settings['weights'];
                        $subTip = sprintf(
                            "血統 %s × %d%%\n騎手 %s × %d%%\n馬   %s × %d%%\n回収 %s × %d%%\n枠   %s × %d%%\nコース %s × %d%%\n脚質 %s × %d%%",
                            $sP !== null ? number_format((float)$sP, 1) : '-', (int)($W['pedigree'] ?? 0),
                            $sJ !== null ? number_format((float)$sJ, 1) : '-', (int)($W['jockey']   ?? 0),
                            $sH !== null ? number_format((float)$sH, 1) : '-', (int)($W['horse']    ?? 0),
                            $sR !== null ? number_format((float)$sR, 1) : '-', (int)($W['roi']      ?? 0),
                            $sFr !== null ? number_format((float)$sFr, 1) : '-', (int)($W['frame']  ?? 0),
                            $sCo !== null ? number_format((float)$sCo, 1) : '-', (int)($W['course'] ?? 0),
                            $sSt !== null ? number_format((float)$sSt, 1) : '-', (int)($W['style']  ?? 0),
                        );
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

                        {{-- 人気 (ライブ最優先, なければ出馬表) --}}
                        <td class="px-2 py-2 text-right">
                            @php
                                $displayPop    = $r->live_popularity ?? $rr->popularity ?? null;
                                $popIsLive     = $r->live_popularity !== null;
                            @endphp
                            @if ($displayPop)
                                <div class="flex flex-col items-end leading-tight">
                                    <span class="text-xs {{ $displayPop <= 3 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">{{ $displayPop }}</span>
                                    @if ($popIsLive)
                                        <span class="text-[9px] text-emerald-600 dark:text-emerald-400" title="ライブ人気 (取得: {{ optional($r->live_captured_at)->format('H:i') }})">📊</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        {{-- 単勝オッズ (ライブ最優先) (Phase EV-2) --}}
                        <td class="px-2 py-2 text-right">
                            @php
                                // 表示優先: 1) ライブオッズ 2) EV配列内オッズ 3) 出馬表オッズ
                                if ($r->live_win_odds !== null) {
                                    $displayOdds = $r->live_win_odds;
                                    $oddsSrc     = 'live';
                                    $oddsCapAt   = $r->live_captured_at;
                                } elseif (isset($r->ev['win_odds'])) {
                                    $displayOdds = $r->ev['win_odds'];
                                    $oddsSrc     = $r->ev['source'] ?? null;
                                    $oddsCapAt   = $r->ev['captured_at'] ?? null;
                                } else {
                                    $displayOdds = $rr->win_odds ?? null;
                                    $oddsSrc     = $displayOdds ? 'static' : null;
                                    $oddsCapAt   = null;
                                }
                            @endphp
                            @if ($displayOdds)
                                <div class="flex flex-col items-end leading-tight">
                                    <span class="text-xs font-medium {{ (float)$displayOdds < 5 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-100' }}">{{ number_format((float)$displayOdds, 1) }}</span>
                                    @if ($oddsSrc === 'live')
                                        <span class="text-[9px] text-emerald-600 dark:text-emerald-400" title="ライブオッズ (取得: {{ optional($oddsCapAt)->format('H:i') }})">📊 ライブ</span>
                                    @elseif ($oddsSrc === 'static')
                                        <span class="text-[9px] text-gray-400" title="出馬表時点のオッズ">📋 出馬表</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>

                        {{-- 単勝期待値(EV) (Phase 1-B) --}}
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
                                    title="推定単勝勝率 {{ $ev['prob'] }}% × 単勝オッズ {{ number_format((float)$ev['win_odds'], 1) }} - 1 = {{ number_format($ev['ev'], 2) }}">
                                    <span class="font-bold text-[11px]">{{ $ev['label'] }}</span>
                                    <span class="text-[10px] opacity-80">{{ number_format($ev['ev'], 2) }}</span>
                                </div>
                            @else
                                <span class="text-gray-400 text-xs">-</span>
                            @endif
                        </td>

                        {{-- 複勝期待値 (Phase EV-2) --}}
                        <td class="px-2 py-2 text-right">
                            @if ($r->ev && isset($r->ev['place_ev']))
                                @php
                                    $pev = (float) $r->ev['place_ev'];
                                    $pevColor = $pev >= 0.30 ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                        : ($pev >= 0.10 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                                        : ($pev >= -0.10 ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
                                        : 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300'));
                                @endphp
                                <div class="inline-flex flex-col items-end px-1.5 py-0.5 rounded {{ $pevColor }}"
                                    title="推定複勝率 {{ $r->ev['place_prob'] }}% × 推定複勝オッズ {{ number_format((float)$r->ev['place_odds'], 1) }} - 1 = {{ number_format($pev, 2) }} (※複勝オッズは単勝オッズから推定)">
                                    <span class="font-bold text-[11px]">{{ $r->ev['place_label'] }}</span>
                                    <span class="text-[10px] opacity-80">{{ number_format($pev, 2) }}</span>
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

                        {{-- 枠スコア --}}
                        <td class="px-2 py-2 text-right">
                            <span class="text-xs {{ $sFr !== null && $sFr >= 60 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}"
                                  title="枠{{ $rr->frame_number ?? '?' }} × このコース類似条件 の過去複勝率">
                                {{ $sFr !== null ? number_format((float)$sFr, 1) : '-' }}
                            </span>
                        </td>
                        {{-- コーススコア --}}
                        <td class="px-2 py-2 text-right">
                            <span class="text-xs {{ $sCo !== null && $sCo >= 60 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}"
                                  title="この馬の同方向({{ $race->direction ?? '?' }}回り) 過去複勝率">
                                {{ $sCo !== null ? number_format((float)$sCo, 1) : '-' }}
                            </span>
                        </td>
                        {{-- 脚質スコア --}}
                        <td class="px-2 py-2 text-right">
                            <span class="text-xs {{ $sSt !== null && $sSt >= 60 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}"
                                  title="脚質「{{ $r->running_style }}」× 想定{{ $pace_forecast['label'] ?? '' }}">
                                {{ $sSt !== null ? number_format((float)$sSt, 1) : '-' }}
                            </span>
                        </td>

                        {{-- 合計(ツールチップで内訳表示) --}}
                        <td class="px-2 py-2 text-right">
                            @if ($score !== null)
                                <span class="text-sm font-bold cursor-help {{ $score >= 70 ? 'text-red-600 dark:text-red-400' : ($score >= 55 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-700 dark:text-gray-300') }}"
                                      title="{{ $subTip }}">
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
                    <tr><td colspan="18" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                        該当する出走馬がありません
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- オッズ推移グラフ (Phase EV-3) --}}
    @if ($race->netkeiba_id)
        <details id="odds-timeline-details" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 mt-4"
            data-timeline-url="{{ route('shutuba.odds-timeline', $race) }}">
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-700 dark:text-gray-200 flex items-center gap-2 select-none">
                <x-icon name="chart-bar" class="w-4 h-4 text-sky-500" />
                <span>📈 オッズ推移グラフ</span>
                <span id="odds-timeline-count" class="text-[11px] text-gray-500 dark:text-gray-400 font-normal ml-2"></span>
                <span class="ml-auto text-[11px] text-gray-400">(クリックで展開)</span>
            </summary>
            <div class="px-4 pb-4 border-t border-gray-100 dark:border-gray-700 pt-3">
                {{-- 馬選択 チェックボックス --}}
                <div id="odds-timeline-picker" class="flex flex-wrap gap-1 mb-3 text-[11px]">
                    <span class="text-gray-500 dark:text-gray-400 mr-1">馬選択:</span>
                    <button type="button" id="odds-timeline-select-top8"
                        class="px-1.5 py-0.5 rounded border border-sky-400 bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 hover:bg-sky-100">
                        上位8頭
                    </button>
                    <button type="button" id="odds-timeline-select-all"
                        class="px-1.5 py-0.5 rounded border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100">
                        全頭
                    </button>
                    <button type="button" id="odds-timeline-select-none"
                        class="px-1.5 py-0.5 rounded border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100">
                        クリア
                    </button>
                    <span class="mx-2 text-gray-300">|</span>
                    <div id="odds-timeline-horses" class="flex flex-wrap gap-1"></div>
                </div>
                {{-- チャート本体 --}}
                <div id="odds-timeline-chart" style="min-height: 340px;">
                    <div class="text-center py-8 text-xs text-gray-400">
                        読み込み中...
                    </div>
                </div>
                <div id="odds-timeline-message" class="text-[11px] text-gray-500 mt-1 text-right"></div>
            </div>
        </details>
    @endif

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

            // Phase 4-S: 共有ダイアログ
            shareDialog: { open: false },

            // Phase 3-I: リアルタイムオッズ
            liveOdds: {
                enabled: true,
                autoRefresh: false,
                loading: false,
                fresh: false,
                lastAt: null,
                count: 0,
                horses: [],
                message: '',
                _timer: null,
                _prev: {},
            },

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

            // Phase 3-I: リアルタイムオッズ取得
            async refreshOdds() {
                try {
                    const res = await fetch(@json(route('operations.odds', $race)), {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    this.liveOdds.count = data.snapshot_count || 0;
                    this.liveOdds.lastAt = data.latest_at
                        ? new Date(data.latest_at).toLocaleTimeString('ja-JP', { hour:'2-digit', minute:'2-digit', second:'2-digit' })
                        : null;
                    const fresh = data.latest_at && (Date.now() - new Date(data.latest_at).getTime()) < 15 * 60 * 1000;
                    this.liveOdds.fresh = !!fresh;

                    const payload = data.latest_payload || [];
                    const prev = this.liveOdds._prev || {};
                    const horses = payload.map(h => {
                        const num = h.horse_number;
                        const odds = h.win_odds !== null && h.win_odds !== undefined ? Number(h.win_odds) : null;
                        let delta = null;
                        if (odds !== null && prev[num] !== undefined && prev[num] !== null) {
                            delta = +(odds - prev[num]).toFixed(1);
                        }
                        return {
                            horse_number: num,
                            win_odds:     odds,
                            popularity:   h.popularity ? Number(h.popularity) : null,
                            delta:        delta,
                        };
                    }).sort((a, b) => a.horse_number - b.horse_number);

                    // 次回比較用に保存
                    const next = {};
                    horses.forEach(h => { if (h.win_odds !== null) next[h.horse_number] = h.win_odds; });
                    this.liveOdds._prev = next;
                    this.liveOdds.horses = horses;
                    this.liveOdds.message = horses.length === 0 ? 'まだスナップショットがありません。「今すぐ取得」を押してください。' : '';
                } catch (e) {
                    this.liveOdds.message = 'オッズ取得エラー: ' + e.message;
                }
            },

            async captureOdds() {
                this.liveOdds.loading = true;
                try {
                    const res = await fetch(@json(route('operations.odds.capture', $race)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                    });
                    const data = await res.json();
                    if (!data.ok) {
                        this.liveOdds.message = data.message || '取得に失敗しました';
                    } else {
                        this.liveOdds.message = '取得完了';
                        await this.refreshOdds();
                    }
                } catch (e) {
                    this.liveOdds.message = '取得エラー: ' + e.message;
                } finally {
                    this.liveOdds.loading = false;
                }
            },

            toggleAutoRefresh() {
                if (this.liveOdds._timer) {
                    clearInterval(this.liveOdds._timer);
                    this.liveOdds._timer = null;
                }
                if (this.liveOdds.autoRefresh) {
                    this.liveOdds._timer = setInterval(() => this.refreshOdds(), 30000);
                    this.refreshOdds();
                }
            },

            init() {
                this.initKeyboard();
                // 初回ロードでオッズを表示(スナップショットがあれば)
                this.refreshOdds();
            },
        };
    }

    // ========================================================
    // 最新オッズ取得ボタン + 自動更新 (Phase EV-2 / EV-3)
    //   - 手動: 「📊 最新オッズ取得」ボタン click で 1 回取得 → リロード
    //   - 自動: 「🔄 自動更新 (Nsec)」ON の間、N秒ごとに取得 → リロード
    //           * タブが非表示のときは停止 (netkeiba 負荷軽減)
    //           * localStorage で ON/OFF を永続化 (ページ遷移しても継続)
    //           * 直近取得時刻を基準に「あと N 秒」カウントダウン表示
    // ========================================================
    (function () {
        const btn        = document.getElementById('btn-capture-odds');
        if (!btn) return;

        const statusEl   = document.getElementById('capture-odds-status');
        const chk        = document.getElementById('chk-auto-capture-odds');
        const countdown  = document.getElementById('auto-capture-countdown');
        const csrfToken  = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const url        = btn.dataset.url;
        const raceId     = @json($race->id);
        const AUTO_KEY   = 'shutuba.auto_capture_odds';
        const intervalMs = Math.max(15, Number(chk?.dataset.intervalSeconds || 60)) * 1000;

        let timer     = null;   // メイン発火タイマー
        let tickTimer = null;   // カウントダウン表示更新タイマー
        let nextAt    = 0;      // 次回発火予定 (epoch ms)

        // ---- 実 fetch (手動/自動 共通) ----
        async function doCapture({ reloadOnSuccess = true, silent = false } = {}) {
            if (!url) return false;
            if (!silent) {
                btn.disabled = true;
                btn.dataset._orig = btn.dataset._orig || btn.innerHTML;
                btn.innerHTML = '<span class="animate-pulse">📡 取得中...</span>';
            }
            if (statusEl) statusEl.textContent = '';

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json().catch(() => ({}));

                if (res.ok && data.ok) {
                    if (statusEl) {
                        const t = new Date().toLocaleTimeString('ja-JP', { hour12: false });
                        statusEl.textContent = (data.message || `取得完了 (${data.count}頭)`) + ` [${t}]`;
                        statusEl.className = 'ml-1 text-[11px] text-emerald-600';
                    }
                    if (reloadOnSuccess) {
                        // 少し遅延してリロード (ユーザーがトーストを目視できるように)
                        setTimeout(() => window.location.reload(), 600);
                    } else {
                        if (!silent) {
                            btn.disabled = false;
                            btn.innerHTML = btn.dataset._orig;
                        }
                    }
                    return true;
                } else {
                    const msg = (data && data.message) ? data.message : `HTTP ${res.status}`;
                    if (statusEl) {
                        statusEl.textContent = '❌ ' + msg;
                        statusEl.className = 'ml-1 text-[11px] text-rose-600';
                    }
                    btn.disabled = false;
                    btn.innerHTML = btn.dataset._orig || btn.innerHTML;
                    return false;
                }
            } catch (e) {
                if (statusEl) {
                    statusEl.textContent = '❌ ' + e.message;
                    statusEl.className = 'ml-1 text-[11px] text-rose-600';
                }
                btn.disabled = false;
                btn.innerHTML = btn.dataset._orig || btn.innerHTML;
                return false;
            }
        }

        // ---- 手動 click ----
        btn.addEventListener('click', () => doCapture({ reloadOnSuccess: true }));

        // ---- 自動更新の start/stop ----
        function stopAuto() {
            if (timer)     { clearTimeout(timer); timer = null; }
            if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
            if (countdown) countdown.textContent = '';
        }

        function scheduleNext() {
            stopAuto();
            nextAt = Date.now() + intervalMs;
            timer = setTimeout(runOnce, intervalMs);
            tickTimer = setInterval(updateCountdown, 1000);
            updateCountdown();
        }

        function updateCountdown() {
            if (!countdown) return;
            const remain = Math.max(0, Math.ceil((nextAt - Date.now()) / 1000));
            countdown.textContent = document.hidden ? '(タブ非表示中)' : `次: ${remain}s`;
        }

        async function runOnce() {
            // タブが非表示なら発火せずに次のスロットへ (netkeiba 負荷軽減)
            if (document.hidden) {
                scheduleNext();
                return;
            }
            // silent モードで捕捉 → 成功したらリロード (Alpine を含む全表示を最新化)
            const ok = await doCapture({ reloadOnSuccess: true, silent: true });
            if (!ok) {
                // 失敗時も次回試行はスケジュール (ネットワーク瞬断等を吸収)
                scheduleNext();
            }
            // 成功時はリロードするので何もしない (次のページで再度 auto=on を読む)
        }

        // ---- チェックボックス handler ----
        if (chk) {
            // 初期状態: localStorage から復元
            try {
                if (localStorage.getItem(AUTO_KEY) === '1') {
                    chk.checked = true;
                }
            } catch (e) {}

            chk.addEventListener('change', () => {
                try {
                    localStorage.setItem(AUTO_KEY, chk.checked ? '1' : '0');
                } catch (e) {}
                if (chk.checked) {
                    scheduleNext();
                } else {
                    stopAuto();
                }
            });

            // タブ可視性変化 → カウントダウン表示を即更新
            document.addEventListener('visibilitychange', updateCountdown);

            // ページ表示時に auto=on ならタイマー開始
            if (chk.checked) {
                scheduleNext();
            }
        }
    })();

    // ========================================================
    // オッズ推移グラフ (Phase EV-3)
    //   - <details id="odds-timeline-details"> が open されたタイミングで初回ロード
    //   - "上位8頭" ボタンで人気上位8頭を選択 (デフォルト初回オープン時)
    //   - チェックで任意の馬を追加/削除
    //   - ApexCharts (既存プロジェクトで使用中) で描画
    //   - 自動更新 (毎分キャプチャ) が ON のときはグラフも都度リロードされる (ページreload)
    // ========================================================
    (function () {
        const details = document.getElementById('odds-timeline-details');
        if (!details) return;

        const url          = details.dataset.timelineUrl;
        const chartEl      = document.getElementById('odds-timeline-chart');
        const horseWrap    = document.getElementById('odds-timeline-horses');
        const countEl      = document.getElementById('odds-timeline-count');
        const messageEl    = document.getElementById('odds-timeline-message');
        const btnTop8      = document.getElementById('odds-timeline-select-top8');
        const btnAll       = document.getElementById('odds-timeline-select-all');
        const btnNone      = document.getElementById('odds-timeline-select-none');
        const OPEN_KEY     = 'shutuba.odds_timeline.open';
        const SELECT_KEY   = 'shutuba.odds_timeline.selected.' + @json($race->id);
        const AUTO_KEY     = 'shutuba.auto_capture_odds';

        let chart = null;
        let lastData = null; // { horses, series, snapshot_count, ... }
        let loaded = false;

        // 色パレット (馬番順)
        const PALETTE = [
            '#f87171', '#fb923c', '#fbbf24', '#a3e635', '#34d399', '#22d3ee',
            '#60a5fa', '#a78bfa', '#f472b6', '#fb7185', '#facc15', '#4ade80',
            '#38bdf8', '#818cf8', '#e879f9', '#fda4af', '#65a30d', '#0ea5e9',
        ];

        function getSelected() {
            try {
                const raw = localStorage.getItem(SELECT_KEY);
                if (raw) {
                    const arr = JSON.parse(raw);
                    if (Array.isArray(arr)) return new Set(arr.map(Number));
                }
            } catch (e) {}
            return null;
        }
        function setSelected(set) {
            try {
                localStorage.setItem(SELECT_KEY, JSON.stringify([...set]));
            } catch (e) {}
        }

        function renderPicker(horses, selected) {
            horseWrap.innerHTML = '';
            horses.forEach(h => {
                const label = document.createElement('label');
                label.className = 'inline-flex items-center gap-1 px-1.5 py-0.5 rounded border cursor-pointer select-none ' +
                    (selected.has(h.horse_number)
                        ? 'border-sky-500 bg-sky-50 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300'
                        : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50');
                const chk = document.createElement('input');
                chk.type = 'checkbox';
                chk.className = 'align-middle';
                chk.checked = selected.has(h.horse_number);
                chk.addEventListener('change', () => {
                    if (chk.checked) selected.add(h.horse_number);
                    else selected.delete(h.horse_number);
                    setSelected(selected);
                    renderPicker(horses, selected);
                    renderChart(lastData, selected);
                });
                label.appendChild(chk);
                const span = document.createElement('span');
                const popStr = h.latest_popularity ? ` (${h.latest_popularity}人気)` : '';
                span.textContent = `${h.horse_number}. ${h.horse_name}${popStr}`;
                label.appendChild(span);
                horseWrap.appendChild(label);
            });
        }

        function pickDefaultSelection(horses) {
            // 人気上位8頭 (人気が null の馬は末尾)
            const withPop = horses.filter(h => h.latest_popularity !== null && h.latest_popularity !== undefined);
            withPop.sort((a, b) => a.latest_popularity - b.latest_popularity);
            const top8 = withPop.slice(0, 8).map(h => h.horse_number);
            if (top8.length > 0) return new Set(top8);
            // 人気情報が無ければ馬番先頭8頭
            return new Set(horses.slice(0, 8).map(h => h.horse_number));
        }

        function renderChart(data, selected) {
            if (!data) return;
            const seriesArr = [];
            (data.horses || []).forEach((h) => {
                if (!selected.has(h.horse_number)) return;
                const pts = (data.series && data.series[String(h.horse_number)]) || [];
                seriesArr.push({
                    name: `${h.horse_number}. ${h.horse_name}`,
                    data: pts.map(p => ({
                        x: new Date(p.t).getTime(),
                        y: p.odds !== null && p.odds !== undefined ? Number(p.odds) : null,
                    })),
                    color: PALETTE[(h.horse_number - 1) % PALETTE.length],
                });
            });

            if (seriesArr.length === 0) {
                chartEl.innerHTML = '<div class="text-center py-8 text-xs text-gray-400">選択された馬がありません。上のチェックで馬を選んでください。</div>';
                if (chart) { chart.destroy(); chart = null; }
                return;
            }

            const options = {
                chart: {
                    type: 'line',
                    height: 340,
                    animations: { enabled: false },
                    toolbar: { show: false },
                    zoom: { enabled: true },
                },
                stroke: { curve: 'straight', width: 2 },
                markers: { size: 3 },
                series: seriesArr,
                xaxis: {
                    type: 'datetime',
                    labels: {
                        datetimeUTC: false,
                        format: 'HH:mm',
                    },
                    title: { text: '取得時刻' },
                },
                yaxis: {
                    title: { text: '単勝オッズ (倍)' },
                    labels: {
                        formatter: (v) => v === null ? '' : Number(v).toFixed(1),
                    },
                    // 上限を自動 (人気薄で1000超もありうるが Apex に任せる)
                },
                legend: {
                    position: 'top',
                    horizontalAlign: 'left',
                },
                tooltip: {
                    shared: true,
                    x: { format: 'HH:mm:ss' },
                    y: { formatter: (v) => v === null ? '-' : Number(v).toFixed(1) + ' 倍' },
                },
                grid: { borderColor: 'rgba(156,163,175,0.2)' },
                dataLabels: { enabled: false },
            };

            chartEl.innerHTML = '';
            if (chart) { chart.destroy(); chart = null; }
            chart = new ApexCharts(chartEl, options);
            chart.render();
        }

        async function load() {
            try {
                messageEl.textContent = '読込中...';
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                lastData = data;
                const horses = data.horses || [];
                if (countEl) {
                    countEl.textContent = `スナップショット ${data.snapshot_count || 0}件`
                        + (data.first_at ? ` (${new Date(data.first_at).toLocaleTimeString('ja-JP',{hour:'2-digit',minute:'2-digit'})} - ${new Date(data.last_at).toLocaleTimeString('ja-JP',{hour:'2-digit',minute:'2-digit'})})` : '');
                }
                messageEl.textContent = '';
                if (horses.length === 0 || (data.snapshot_count || 0) === 0) {
                    horseWrap.innerHTML = '';
                    chartEl.innerHTML = '<div class="text-center py-8 text-xs text-gray-400">まだオッズスナップショットがありません。「📊 最新オッズ取得」ボタンで取得してください。</div>';
                    return;
                }
                let selected = getSelected();
                if (!selected || selected.size === 0) {
                    selected = pickDefaultSelection(horses);
                    setSelected(selected);
                }
                // 現存しない馬番を除外
                const validNumbers = new Set(horses.map(h => h.horse_number));
                selected = new Set([...selected].filter(n => validNumbers.has(n)));
                renderPicker(horses, selected);
                renderChart(data, selected);
            } catch (e) {
                messageEl.textContent = '❌ 読込エラー: ' + e.message;
                chartEl.innerHTML = '<div class="text-center py-8 text-xs text-rose-500">エラー: ' + e.message + '</div>';
            }
        }

        // details の open/close で状態永続化 + 初回ロード
        details.addEventListener('toggle', () => {
            try {
                localStorage.setItem(OPEN_KEY, details.open ? '1' : '0');
            } catch (e) {}
            if (details.open && !loaded) {
                loaded = true;
                load();
            }
        });

        // 初期状態: localStorage に応じて開く
        try {
            if (localStorage.getItem(OPEN_KEY) === '1') {
                details.open = true;
                if (!loaded) { loaded = true; load(); }
            }
        } catch (e) {}

        // 自動更新 ON の場合、details が open ならリロードのたびグラフも自動再取得される
        // (auto-capture-odds が fetch → 成功 → window.location.reload() するため)

        // 選択ボタン
        btnTop8?.addEventListener('click', () => {
            if (!lastData) return;
            const sel = pickDefaultSelection(lastData.horses || []);
            setSelected(sel);
            renderPicker(lastData.horses || [], sel);
            renderChart(lastData, sel);
        });
        btnAll?.addEventListener('click', () => {
            if (!lastData) return;
            const sel = new Set((lastData.horses || []).map(h => h.horse_number));
            setSelected(sel);
            renderPicker(lastData.horses || [], sel);
            renderChart(lastData, sel);
        });
        btnNone?.addEventListener('click', () => {
            const sel = new Set();
            setSelected(sel);
            renderPicker(lastData?.horses || [], sel);
            renderChart(lastData, sel);
        });
    })();
</script>
@endsection
