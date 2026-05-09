@extends('layouts.app')

@section('title', '馬×コース優位性分析')

@section('content')
<div class="space-y-3 sm:space-y-4">
    {{-- ヘッダー --}}
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800">
                <span class="inline-flex items-center gap-1.5"><x-icon name="horse" class="w-6 h-6 text-rose-600" /><span>馬 × コース優位性分析</span></span>
            </h1>
            <p class="text-xs sm:text-sm text-gray-500">出走馬ごとに、競馬場・トラック・距離・馬場状態別の成績を比較できます。</p>
        </div>
    </div>

    {{-- KPI カード --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 sm:gap-3">
        <div class="bg-gradient-to-br from-rose-500 to-rose-600 text-white rounded-lg p-3 sm:p-4 shadow">
            <div class="text-[11px] sm:text-xs opacity-80">対象馬数</div>
            <div class="text-xl sm:text-2xl font-bold">{{ $summary['total_horses'] }} 頭</div>
        </div>
        <div class="bg-gradient-to-br from-sky-500 to-sky-600 text-white rounded-lg p-3 sm:p-4 shadow">
            <div class="text-[11px] sm:text-xs opacity-80">平均出走数</div>
            <div class="text-xl sm:text-2xl font-bold">{{ $summary['avg_runs'] }} 回</div>
        </div>
        <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 text-white rounded-lg p-3 sm:p-4 shadow">
            <div class="text-[11px] sm:text-xs opacity-80">平均複勝率</div>
            <div class="text-xl sm:text-2xl font-bold">{{ $summary['avg_show'] }}%</div>
        </div>
        <div class="bg-gradient-to-br from-amber-500 to-amber-600 text-white rounded-lg p-3 sm:p-4 shadow">
            <div class="text-[11px] sm:text-xs opacity-80">トップ馬</div>
            <div class="text-sm sm:text-base font-bold truncate">
                {{ $summary['best_horse']->name ?? '-' }}
                <span class="text-xs opacity-80">({{ $summary['best_horse']->show_rate ?? 0 }}%)</span>
            </div>
        </div>
    </div>

    {{-- フィルタフォーム (スマホは折り畳み) --}}
    <form method="GET" class="bg-white rounded-lg shadow p-3 sm:p-4" x-data="{ openFilter: window.innerWidth >= 640 }">
        <button type="button" @click="openFilter = !openFilter"
                class="sm:hidden w-full flex items-center justify-between text-sm font-semibold text-gray-700 mb-2">
            <span class="inline-flex items-center gap-1.5"><x-icon name="search" class="w-4 h-4" /><span>絞り込み・並び替え</span></span>
            <span x-text="openFilter ? '▲' : '▼'"></span>
        </button>
        <div x-show="openFilter" x-cloak class="grid grid-cols-2 md:grid-cols-6 gap-2 sm:gap-3 text-sm">
            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">馬名キーワード</label>
                <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                       placeholder="例: ディープ" class="w-full border rounded px-2 py-1.5">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">最低出走数</label>
                <input type="number" name="min_runs" min="1" value="{{ $filters['minRuns'] }}"
                       class="w-full border rounded px-2 py-1.5">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">並び順</label>
                <select name="sort" class="w-full border rounded px-2 py-1.5">
                    <option value="show_rate"  @selected($filters['sort']==='show_rate')>複勝率</option>
                    <option value="win_rate"   @selected($filters['sort']==='win_rate')>勝率</option>
                    <option value="place_rate" @selected($filters['sort']==='place_rate')>連対率</option>
                    <option value="runs"       @selected($filters['sort']==='runs')>出走数</option>
                    <option value="wins"       @selected($filters['sort']==='wins')>勝数</option>
                    <option value="avg_finish" @selected($filters['sort']==='avg_finish')>平均着順</option>
                    <option value="name"       @selected($filters['sort']==='name')>馬名</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">期間 From</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="w-full border rounded px-2 py-1.5">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">期間 To</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="w-full border rounded px-2 py-1.5">
            </div>
        </div>
        <div x-show="openFilter" x-cloak class="mt-3 flex justify-end gap-2">
            <a href="{{ route('analytics.horse') }}" class="flex-1 sm:flex-none text-center px-3 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 active:bg-gray-400 text-sm">リセット</a>
            <button class="flex-1 sm:flex-none px-4 py-2 bg-rose-600 text-white rounded hover:bg-rose-700 active:bg-rose-800 text-sm font-medium">絞り込む</button>
        </div>
    </form>

    {{-- 馬一覧テーブル --}}
    <div class="bg-white rounded-lg shadow">
        @if ($rows->isEmpty())
            <p class="p-6 text-center text-gray-500">該当する馬がいません。フィルタ条件を緩めてください。</p>
        @else
        <div class="px-2 pt-2 sm:hidden text-[11px] text-gray-500">→ 横スクロールで全列表示</div>
        <div class="table-scroll">
        <div class="min-w-[820px] sm:min-w-0">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-xs text-gray-600">
                <tr>
                    <th class="px-2 py-2 text-left">#</th>
                    <th class="px-2 py-2 text-left">馬名</th>
                    <th class="px-2 py-2">性</th>
                    <th class="px-2 py-2">父</th>
                    <th class="px-2 py-2">出走</th>
                    <th class="px-2 py-2">勝</th>
                    <th class="px-2 py-2">2着</th>
                    <th class="px-2 py-2">3着</th>
                    <th class="px-2 py-2">勝率</th>
                    <th class="px-2 py-2">連対率</th>
                    <th class="px-2 py-2">複勝率</th>
                    <th class="px-2 py-2">平均着順</th>
                    <th class="px-2 py-2 w-1/6">複勝率バー</th>
                    <th class="px-2 py-2">最終出走</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $r)
                    @php
                        $intensity = min(100, $r->show_rate * 1.5);
                        $isActive  = $horseName === $r->name;
                        $rowUrl    = route('analytics.horse', array_filter([
                            'horse'    => $r->name,
                            'keyword'  => $filters['keyword'] ?: null,
                            'min_runs' => $filters['minRuns'],
                            'sort'     => $filters['sort'],
                            'from'     => $filters['from'] ?: null,
                            'to'       => $filters['to'] ?: null,
                        ], fn($v) => $v !== null && $v !== ''));
                    @endphp
                    <tr class="border-b hover:bg-rose-50 cursor-pointer {{ $isActive ? 'bg-rose-100' : '' }}"
                        onclick="window.location.href='{{ $rowUrl }}'">
                        <td class="px-2 py-1.5 text-gray-400">{{ $i + 1 }}</td>
                        <td class="px-2 py-1.5 font-medium text-rose-700">
                            {{ $r->name }}
                            @if ($isActive) <span class="text-xs text-emerald-600">●</span> @endif
                        </td>
                        <td class="px-2 py-1.5 text-center">{{ $r->sex ?? '-' }}</td>
                        <td class="px-2 py-1.5 text-xs text-gray-500 truncate max-w-[120px]">{{ $r->father ?? '-' }}</td>
                        <td class="px-2 py-1.5 text-center">{{ $r->runs }}</td>
                        <td class="px-2 py-1.5 text-center text-yellow-600 font-bold">{{ $r->wins }}</td>
                        <td class="px-2 py-1.5 text-center">{{ $r->places - $r->wins }}</td>
                        <td class="px-2 py-1.5 text-center">{{ $r->shows - $r->places }}</td>
                        <td class="px-2 py-1.5 text-center">{{ $r->win_rate }}%</td>
                        <td class="px-2 py-1.5 text-center">{{ $r->place_rate }}%</td>
                        <td class="px-2 py-1.5 text-center font-bold text-emerald-700">{{ $r->show_rate }}%</td>
                        <td class="px-2 py-1.5 text-center">{{ $r->avg_finish ?? '-' }}</td>
                        <td class="px-2 py-1.5">
                            <div class="h-3 rounded relative overflow-hidden bg-gray-100">
                                <div class="absolute inset-y-0 left-0 bg-gradient-to-r from-blue-300 via-blue-600 to-red-500"
                                     style="width: {{ $intensity }}%;"></div>
                            </div>
                        </td>
                        <td class="px-2 py-1.5 text-xs text-gray-500">{{ $r->last_run }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>{{-- /min-w --}}
        </div>{{-- /table-scroll --}}
        @endif
    </div>

    {{-- ============ 個別馬の詳細 (右サイドパネル) ============ --}}
    @if ($horseName && $horseModel)
        @php
            $closeUrl = route('analytics.horse', array_filter([
                'keyword'  => $filters['keyword'] ?: null,
                'min_runs' => $filters['minRuns'],
                'sort'     => $filters['sort'],
                'from'     => $filters['from'] ?: null,
                'to'       => $filters['to'] ?: null,
            ], fn($v) => $v !== null && $v !== ''));
        @endphp
        {{-- スマホでは半透明オーバーレイでバックドロップ --}}
        <div
            x-data="{ open: true }"
            x-show="open"
            x-cloak
            x-transition.opacity
            class="sm:hidden fixed inset-0 bg-black/40 z-30"
            @click="window.location.href = @js($closeUrl)"
        ></div>
        <div
            id="horse-detail"
            x-data="{ open: true, closeUrl: @js($closeUrl) }"
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full opacity-0"
            x-transition:enter-end="translate-y-0 sm:translate-x-0 opacity-100"
            x-cloak
            @keydown.escape.window="open = false; window.location.href = closeUrl;"
            class="fixed inset-x-0 bottom-0 sm:inset-x-auto sm:top-0 sm:right-0 sm:h-screen
                   max-h-[85vh] sm:max-h-none h-auto sm:h-screen
                   w-full sm:w-[480px] lg:w-[560px]
                   z-40 bg-white shadow-2xl ring-2 ring-rose-300
                   rounded-t-2xl sm:rounded-none
                   flex flex-col safe-area-bottom"
        >
            {{-- スマホ用ドラッグハンドル --}}
            <div class="sm:hidden pt-2 pb-1 flex justify-center">
                <div class="w-10 h-1.5 bg-gray-300 rounded-full"></div>
            </div>

            {{-- ヘッダー --}}
            <div class="bg-rose-50 border-b px-4 py-3 flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-semibold text-gray-700 text-sm sm:text-base flex-1 min-w-0">
                    <span class="text-rose-700">▶</span>
                    <span class="truncate inline-block max-w-[55vw] align-middle">{{ $horseModel->name }}</span>
                    <span class="text-xs text-gray-500 hidden sm:inline">
                        @if ($horseModel->sex) ({{ $horseModel->sex }}) @endif
                        @if ($horseModel->father) / 父: {{ $horseModel->father }} @endif
                    </span>
                </h2>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                    <a href="{{ route('horses.show', $horseModel->id) }}"
                       class="text-xs px-2.5 py-1.5 bg-rose-600 text-white rounded hover:bg-rose-700 active:bg-rose-800 whitespace-nowrap">
                        個別 →
                    </a>
                    <a href="{{ $closeUrl }}"
                       class="text-xs px-2.5 py-1.5 bg-gray-200 rounded hover:bg-gray-300 active:bg-gray-400 whitespace-nowrap">× 閉じる</a>
                </div>
            </div>

            {{-- 本文 (スクロール可能) --}}
            <div class="flex-1 overflow-y-auto p-4 space-y-5">

                {{-- 競馬場別 --}}
                <section>
                    <h3 class="inline-flex items-center gap-1.5 font-semibold text-gray-700 text-sm mb-2 border-l-4 border-rose-400 pl-2"><x-icon name="map" class="w-4 h-4 text-rose-500" /><span>競馬場別</span></h3>
                    @if ($byVenue->isEmpty())
                        <p class="text-xs text-gray-400">データなし</p>
                    @else
                    <table class="w-full text-xs">
                        <thead class="bg-gray-100 text-gray-600">
                            <tr>
                                <th class="text-left px-2 py-1">競馬場</th>
                                <th class="px-2 py-1">出走</th>
                                <th class="px-2 py-1">勝</th>
                                <th class="px-2 py-1">3着内</th>
                                <th class="px-2 py-1">複勝率</th>
                                <th class="px-2 py-1">平均着</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byVenue as $v)
                                @php $sr = $v->runs > 0 ? round($v->shows / $v->runs * 100, 1) : 0; @endphp
                                <tr class="border-b">
                                    <td class="px-2 py-1">{{ $v->venue ?? '-' }}</td>
                                    <td class="px-2 py-1 text-center">{{ $v->runs }}</td>
                                    <td class="px-2 py-1 text-center text-yellow-600 font-bold">{{ $v->wins }}</td>
                                    <td class="px-2 py-1 text-center text-emerald-600">{{ $v->shows }}</td>
                                    <td class="px-2 py-1 text-center font-bold">{{ $sr }}%</td>
                                    <td class="px-2 py-1 text-center">{{ $v->avg_finish !== null ? round($v->avg_finish, 1) : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </section>

                {{-- トラック別 --}}
                <section>
                    <h3 class="inline-flex items-center gap-1.5 font-semibold text-gray-700 text-sm mb-2 border-l-4 border-emerald-400 pl-2"><x-icon name="flag" class="w-4 h-4 text-emerald-500" /><span>トラック別</span></h3>
                    @if ($byTrack->isEmpty())
                        <p class="text-xs text-gray-400">データなし</p>
                    @else
                    <table class="w-full text-xs">
                        <thead class="bg-gray-100 text-gray-600">
                            <tr>
                                <th class="text-left px-2 py-1">トラック</th>
                                <th class="px-2 py-1">出走</th>
                                <th class="px-2 py-1">勝</th>
                                <th class="px-2 py-1">3着内</th>
                                <th class="px-2 py-1">複勝率</th>
                                <th class="px-2 py-1">平均着</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byTrack as $t)
                                @php $sr = $t->runs > 0 ? round($t->shows / $t->runs * 100, 1) : 0; @endphp
                                <tr class="border-b">
                                    <td class="px-2 py-1">{{ $t->track ?? '-' }}</td>
                                    <td class="px-2 py-1 text-center">{{ $t->runs }}</td>
                                    <td class="px-2 py-1 text-center text-yellow-600 font-bold">{{ $t->wins }}</td>
                                    <td class="px-2 py-1 text-center text-emerald-600">{{ $t->shows }}</td>
                                    <td class="px-2 py-1 text-center font-bold">{{ $sr }}%</td>
                                    <td class="px-2 py-1 text-center">{{ $t->avg_finish !== null ? round($t->avg_finish, 1) : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </section>

                {{-- 距離別 --}}
                <section>
                    <h3 class="inline-flex items-center gap-1.5 font-semibold text-gray-700 text-sm mb-2 border-l-4 border-sky-400 pl-2"><x-icon name="scale" class="w-4 h-4 text-sky-500" /><span>距離別</span></h3>
                    @if ($byDistance->isEmpty())
                        <p class="text-xs text-gray-400">データなし</p>
                    @else
                    <table class="w-full text-xs">
                        <thead class="bg-gray-100 text-gray-600">
                            <tr>
                                <th class="text-left px-2 py-1">距離区分</th>
                                <th class="px-2 py-1">出走</th>
                                <th class="px-2 py-1">勝</th>
                                <th class="px-2 py-1">3着内</th>
                                <th class="px-2 py-1">複勝率</th>
                                <th class="px-2 py-1">平均着</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byDistance as $d)
                                @php $sr = $d->runs > 0 ? round($d->shows / $d->runs * 100, 1) : 0; @endphp
                                <tr class="border-b">
                                    <td class="px-2 py-1">{{ $d->dist_cat }}</td>
                                    <td class="px-2 py-1 text-center">{{ $d->runs }}</td>
                                    <td class="px-2 py-1 text-center text-yellow-600 font-bold">{{ $d->wins }}</td>
                                    <td class="px-2 py-1 text-center text-emerald-600">{{ $d->shows }}</td>
                                    <td class="px-2 py-1 text-center font-bold">{{ $sr }}%</td>
                                    <td class="px-2 py-1 text-center">{{ $d->avg_finish !== null ? round($d->avg_finish, 1) : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </section>

                {{-- 馬場状態別 --}}
                <section>
                    <h3 class="inline-flex items-center gap-1.5 font-semibold text-gray-700 text-sm mb-2 border-l-4 border-amber-400 pl-2"><x-icon name="sparkles" class="w-4 h-4 text-amber-500" /><span>馬場状態別</span></h3>
                    @if ($byCondition->isEmpty())
                        <p class="text-xs text-gray-400">データなし</p>
                    @else
                    <table class="w-full text-xs">
                        <thead class="bg-gray-100 text-gray-600">
                            <tr>
                                <th class="text-left px-2 py-1">馬場</th>
                                <th class="px-2 py-1">出走</th>
                                <th class="px-2 py-1">勝</th>
                                <th class="px-2 py-1">3着内</th>
                                <th class="px-2 py-1">複勝率</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byCondition as $c)
                                @php $sr = $c->runs > 0 ? round($c->shows / $c->runs * 100, 1) : 0; @endphp
                                <tr class="border-b">
                                    <td class="px-2 py-1">{{ $c->cond }}</td>
                                    <td class="px-2 py-1 text-center">{{ $c->runs }}</td>
                                    <td class="px-2 py-1 text-center text-yellow-600 font-bold">{{ $c->wins }}</td>
                                    <td class="px-2 py-1 text-center text-emerald-600">{{ $c->shows }}</td>
                                    <td class="px-2 py-1 text-center font-bold">{{ $sr }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </section>

                {{-- 直近10走 --}}
                <section>
                    <h3 class="inline-flex items-center gap-1.5 font-semibold text-gray-700 text-sm mb-2 border-l-4 border-purple-400 pl-2"><x-icon name="clock" class="w-4 h-4 text-purple-500" /><span>直近10走</span></h3>
                    @if ($recentRuns->isEmpty())
                        <p class="text-xs text-gray-400">データなし</p>
                    @else
                    <table class="w-full text-xs">
                        <thead class="bg-gray-100 text-gray-600">
                            <tr>
                                <th class="text-left px-2 py-1">日付</th>
                                <th class="text-left px-2 py-1">レース</th>
                                <th class="px-2 py-1">場</th>
                                <th class="px-2 py-1">距離</th>
                                <th class="px-2 py-1">馬場</th>
                                <th class="px-2 py-1">人気</th>
                                <th class="px-2 py-1">着</th>
                                <th class="px-2 py-1">上3F</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentRuns as $run)
                                <tr class="border-b">
                                    <td class="px-2 py-1 text-gray-500">{{ \Illuminate\Support\Carbon::parse($run->race_date)->format('m/d') }}</td>
                                    <td class="px-2 py-1 truncate max-w-[120px]">
                                        <a href="{{ route('races.show', $run->race_id) }}" class="text-rose-600 hover:underline">
                                            {{ $run->race_name }}
                                        </a>
                                    </td>
                                    <td class="px-2 py-1 text-center">{{ $run->venue ?? '-' }}</td>
                                    <td class="px-2 py-1 text-center">{{ $run->track_type }}{{ $run->distance }}</td>
                                    <td class="px-2 py-1 text-center">{{ $run->course_condition ?? '-' }}</td>
                                    <td class="px-2 py-1 text-center">{{ $run->popularity ?? '-' }}</td>
                                    <td class="px-2 py-1 text-center font-bold {{ $run->finish === 1 ? 'text-yellow-600' : ($run->finish <= 3 ? 'text-emerald-600' : 'text-gray-700') }}">
                                        {{ $run->finish }}
                                    </td>
                                    <td class="px-2 py-1 text-center">{{ $run->last_3f ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </section>
            </div>
        </div>

        {{-- ページ本体に右余白を追加して被らないように (sm以上のみ。スマホはボトムシート扱い) --}}
        <style>
            @media (min-width: 640px) {
                body { padding-right: 480px; }
            }
            @media (min-width: 1024px) {
                body { padding-right: 560px; }
            }
            /* スマホではサイドパネルが下から出るので右余白は不要。
               下に余白を入れて最下行が隠れないように */
            @media (max-width: 639px) {
                body { padding-bottom: 30vh; }
            }
        </style>
    @endif
</div>

@push('scripts')
<style>[x-cloak]{display:none!important;}</style>
@endpush
@endsection
