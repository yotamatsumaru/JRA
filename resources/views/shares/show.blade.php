<!DOCTYPE html>
<html lang="ja" class="bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $share->title ?? '予想スナップショット' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif; }
        .mark-cell { font-weight: 700; font-size: 1.05rem; }
    </style>
</head>
<body class="text-gray-800">
@php
    $snap = $share->snapshot ?? [];
    $r = $snap['race'] ?? [];
    $rows = $snap['rows'] ?? [];
@endphp

<div class="max-w-5xl mx-auto p-4 sm:p-6">
    {{-- ヘッダー --}}
    <div class="bg-gradient-to-r from-emerald-700 to-emerald-600 text-white rounded-lg p-5 shadow">
        <div class="flex items-start justify-between flex-wrap gap-2">
            <div>
                <div class="text-xs uppercase tracking-wider opacity-80">JRA Analyzer · 予想スナップショット</div>
                <h1 class="text-xl sm:text-2xl font-bold mt-1">{{ $share->title ?? ($r['name'] ?? 'Race') }}</h1>
                <div class="text-sm opacity-90 mt-2">
                    {{ $r['race_date'] ?? '' }}
                    {{ $r['venue'] ?? '' }}
                    @if (!empty($r['race_no'])) {{ $r['race_no'] }}R @endif
                    / {{ $r['track_type'] ?? '' }}{{ $r['distance'] ?? '' }}m
                    @if (!empty($r['condition'])) ({{ $r['condition'] }}) @endif
                    @if (!empty($r['grade'])) <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-400 text-amber-900 text-xs font-bold">{{ $r['grade'] }}</span> @endif
                </div>
            </div>
            <div class="text-xs text-right opacity-80">
                <div>共有: {{ $share->user?->name ?? '-' }}</div>
                <div>{{ $share->created_at?->format('Y-m-d H:i') }} 作成</div>
                @if ($share->expires_at)
                    <div>{{ $share->expires_at->format('Y-m-d') }} まで</div>
                @endif
            </div>
        </div>

        @if ($share->comment)
            <div class="mt-3 bg-white/10 backdrop-blur rounded p-3 text-sm whitespace-pre-line">{{ $share->comment }}</div>
        @endif
    </div>

    {{-- 印サマリ --}}
    @php
        $summary = ['◎'=>[], '○'=>[], '▲'=>[], '△'=>[], '☆'=>[], '✕'=>[]];
        foreach ($rows as $row) {
            $m = $row['mark'] ?? null;
            if ($m && isset($summary[$m])) $summary[$m][] = $row['horse_no'];
        }
    @endphp
    <div class="bg-white rounded-lg shadow p-4 mt-4">
        <h2 class="text-sm font-semibold text-gray-700 mb-2">印サマリ</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 text-sm">
            @foreach ($summary as $m => $nums)
                @php
                    $col = match($m) {
                        '◎' => 'bg-red-100 text-red-700',
                        '○' => 'bg-blue-100 text-blue-700',
                        '▲' => 'bg-amber-100 text-amber-700',
                        '△' => 'bg-emerald-100 text-emerald-700',
                        '☆' => 'bg-purple-100 text-purple-700',
                        '✕' => 'bg-gray-200 text-gray-600',
                    };
                @endphp
                <div class="rounded {{ $col }} px-2 py-1.5">
                    <span class="font-bold">{{ $m }}</span>
                    <span class="text-xs ml-2 font-mono">{{ count($nums) > 0 ? implode(',', $nums) : '-' }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 出走馬テーブル --}}
    <div class="bg-white rounded-lg shadow mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500">
                <tr>
                    <th class="px-2 py-2 text-center">枠</th>
                    <th class="px-2 py-2 text-center">馬番</th>
                    <th class="px-2 py-2 text-center">印</th>
                    <th class="px-2 py-2 text-left">馬名</th>
                    <th class="px-2 py-2 text-left">性齢/斤量</th>
                    <th class="px-2 py-2 text-left">騎手</th>
                    <th class="px-2 py-2 text-right">単勝/人気</th>
                    <th class="px-2 py-2 text-right">スコア</th>
                    <th class="px-2 py-2 text-left">メモ</th>
                    <th class="px-2 py-2 text-center">着</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $row)
                @php
                    $m = $row['mark'] ?? null;
                    $markCol = match($m) {
                        '◎' => 'bg-red-50',
                        '○' => 'bg-blue-50',
                        '▲' => 'bg-amber-50',
                        '△' => 'bg-emerald-50',
                        '☆' => 'bg-purple-50',
                        default => '',
                    };
                @endphp
                <tr class="{{ $markCol }}">
                    <td class="px-2 py-2 text-center text-xs">{{ $row['frame_no'] ?? '-' }}</td>
                    <td class="px-2 py-2 text-center font-mono font-bold">{{ $row['horse_no'] ?? '-' }}</td>
                    <td class="px-2 py-2 text-center mark-cell">{{ $m ?: '' }}</td>
                    <td class="px-2 py-2 font-medium">{{ $row['horse_name'] ?? '-' }}</td>
                    <td class="px-2 py-2 text-xs text-gray-600">{{ $row['sex_age'] ?? '' }} / {{ $row['weight'] ?? '-' }}</td>
                    <td class="px-2 py-2 text-xs">{{ $row['jockey_name'] ?? '-' }}</td>
                    <td class="px-2 py-2 text-right text-xs tabular-nums">
                        {{ $row['win_odds'] !== null ? $row['win_odds'] : '-' }}
                        @if (!empty($row['popularity']))
                            <span class="text-gray-400">({{ $row['popularity'] }}人)</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 text-right text-xs tabular-nums">{{ $row['score_total'] ?? '-' }}</td>
                    <td class="px-2 py-2 text-xs text-gray-700 max-w-[16rem] truncate" title="{{ $row['memo'] ?? '' }}">{{ $row['memo'] ?? '' }}</td>
                    <td class="px-2 py-2 text-center font-bold {{ ($row['finish_position'] ?? null) == 1 ? 'text-amber-600' : '' }}">
                        {{ $row['finish_position'] ?? '-' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="text-xs text-gray-400 text-center mt-6">
        Generated by <span class="font-semibold">JRA Analyzer</span> ·
        閲覧数: {{ number_format($share->view_count) }} ·
        この URL は read-only の予想スナップショットです
    </div>
</div>
</body>
</html>
