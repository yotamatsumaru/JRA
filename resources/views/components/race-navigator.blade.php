@props(['navigator'])

{{--
  JRA 公式風レースナビゲーター
  - 上段: 開催日 (土/日) × 競馬場チップ
  - 下段: 選択中会場の 1R 〜 12R ボタン
  - クリックでそのレースの shutuba.show へ遷移
  - 現在表示中のレースはハイライト

  期待される $navigator の形:
    [
      'dates' => [
        'YYYY-MM-DD' => [
          'date'       => 'YYYY-MM-DD',
          'label'      => '6月27日',
          'weekday'    => '土曜',
          'is_current' => bool,
          'venues'     => [
            [
              'venue_id'      => int,
              'venue_name'    => string,
              'kaisai_label'  => '2回福島1日',
              'is_current'    => bool,
              'first_race_id' => int,
              'races'         => [['id'=>..,'race_number'=>..,'name'=>..,'is_current'=>..], ...],
            ], ...
          ],
        ], ...
      ],
      'current_race_id'  => int,
      'current_date'     => 'YYYY-MM-DD',
      'current_venue_id' => int,
    ]
--}}

@php
    $hasData = !empty($navigator['dates']);
    // 初期表示用: 現在のレースが属する日付+会場をデフォルト選択
    $initDate    = $navigator['current_date']     ?? '';
    $initVenueId = $navigator['current_venue_id'] ?? 0;
@endphp

@if ($hasData)
<div
    class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 overflow-hidden text-sm"
    x-data="{
        selectedDate: @js($initDate),
        selectedVenueId: {{ (int) $initVenueId }},
        selectVenue(date, venueId) {
            this.selectedDate = date;
            this.selectedVenueId = venueId;
        }
    }"
>
    {{-- 上段: 開催日 × 競馬場 --}}
    <div class="divide-y divide-gray-200 dark:divide-gray-700">
        @foreach ($navigator['dates'] as $dateKey => $dateBlock)
            <div class="flex items-stretch">
                {{-- 日付ラベル --}}
                <div class="flex-shrink-0 w-28 px-3 py-2 bg-gray-50 dark:bg-gray-900/40 border-r border-gray-200 dark:border-gray-700 flex items-center">
                    <div class="text-xs leading-tight">
                        <div class="font-semibold text-gray-800 dark:text-gray-200">{{ $dateBlock['label'] }}</div>
                        <div class="text-gray-500 dark:text-gray-400">({{ $dateBlock['weekday'] }})</div>
                    </div>
                </div>
                {{-- 競馬場チップ --}}
                <div class="flex-1 px-2 py-2 flex flex-wrap gap-1.5">
                    @forelse ($dateBlock['venues'] as $venueBlock)
                        @php
                            $isCurrentVenue = $venueBlock['is_current'];
                        @endphp
                        <button
                            type="button"
                            @click="selectVenue(@js($dateKey), {{ (int) $venueBlock['venue_id'] }})"
                            :class="(selectedDate === @js($dateKey) && selectedVenueId === {{ (int) $venueBlock['venue_id'] }})
                                ? 'bg-gray-900 text-white border-gray-900 dark:bg-gray-100 dark:text-gray-900 dark:border-gray-100'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-600'"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded border text-xs font-medium transition whitespace-nowrap"
                            title="{{ $venueBlock['kaisai_label'] }} を選択"
                        >
                            <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                            <span>{{ $venueBlock['kaisai_label'] }}</span>
                        </button>
                    @empty
                        <span class="text-xs text-gray-400">開催なし</span>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    {{-- 下段: R番号ボタン (選択中会場のみ表示) --}}
    <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-900/30 px-2 py-2">
        @foreach ($navigator['dates'] as $dateKey => $dateBlock)
            @foreach ($dateBlock['venues'] as $venueBlock)
                <div
                    x-show="selectedDate === @js($dateKey) && selectedVenueId === {{ (int) $venueBlock['venue_id'] }}"
                    x-cloak
                    class="flex flex-wrap gap-1"
                >
                    @php
                        // 1R〜12R を漏れなく描画するため、race_number => race のマップを作る
                        $racesByNumber = [];
                        foreach ($venueBlock['races'] as $rr) {
                            $racesByNumber[(int) $rr['race_number']] = $rr;
                        }
                    @endphp
                    @for ($n = 1; $n <= 12; $n++)
                        @php $r = $racesByNumber[$n] ?? null; @endphp
                        @if ($r)
                            <a
                                href="{{ route('shutuba.show', $r['id']) }}"
                                class="inline-flex items-center justify-center min-w-[44px] px-2 py-1.5 rounded border text-xs font-semibold transition
                                    {{ $r['is_current']
                                        ? 'bg-gray-900 text-white border-gray-900 dark:bg-gray-100 dark:text-gray-900 dark:border-gray-100'
                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-600' }}"
                                title="{{ $r['race_number'] }}R {{ $r['name'] }}"
                            >
                                {{ $r['race_number'] }}<span class="text-[10px] ml-0.5">R</span>
                            </a>
                        @else
                            <span
                                class="inline-flex items-center justify-center min-w-[44px] px-2 py-1.5 rounded border text-xs font-semibold bg-gray-100 text-gray-300 border-gray-200 dark:bg-gray-800 dark:text-gray-600 dark:border-gray-700 cursor-not-allowed"
                                title="未登録"
                            >
                                {{ $n }}<span class="text-[10px] ml-0.5">R</span>
                            </span>
                        @endif
                    @endfor
                </div>
            @endforeach
        @endforeach
    </div>
</div>
@endif
