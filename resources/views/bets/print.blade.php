<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>馬券一覧 印刷用 - {{ now()->format('Y/m/d') }}</title>
    <style>
        body { font-family: 'Hiragino Kaku Gothic ProN', 'Meiryo', sans-serif; font-size: 11px; color: #222; padding: 16px; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        .summary { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; padding: 8px 12px; background: #f4f4f4; border-radius: 4px; }
        .summary div { font-size: 12px; }
        .summary strong { font-size: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #eee; font-size: 10px; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .hit { color: #047857; font-weight: bold; }
        .miss { color: #be123c; }
        .footer { margin-top: 16px; font-size: 10px; color: #888; }
        .actions { margin-bottom: 12px; }
        .actions button { padding: 4px 12px; font-size: 12px; cursor: pointer; }
        @media print {
            .actions { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button onclick="window.print()">印刷 / PDF保存</button>
        <button onclick="history.back()">戻る</button>
    </div>

    <h1>馬券一覧（印刷用）— {{ now()->format('Y/m/d H:i') }}</h1>

    <div class="summary">
        <div>件数: <strong>{{ number_format($summary['count']) }}</strong></div>
        <div>投資: <strong>¥{{ number_format($summary['stake']) }}</strong></div>
        <div>払戻: <strong>¥{{ number_format($summary['return']) }}</strong></div>
        <div>収支: <strong>{{ $summary['profit'] >= 0 ? '+' : '' }}¥{{ number_format($summary['profit']) }}</strong></div>
        <div>ROI: <strong>{{ $summary['roi'] !== null ? $summary['roi'].'%' : '-' }}</strong></div>
        <div>的中率: <strong>{{ $summary['hit_rate'] !== null ? $summary['hit_rate'].'%' : '-' }}</strong> ({{ $summary['hits'] }}/{{ $summary['count'] }})</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>日付</th>
                <th>レース</th>
                <th>券種/方式</th>
                <th>組合せ</th>
                <th class="num">点数</th>
                <th class="num">投資</th>
                <th class="num">払戻</th>
                <th class="num">収支</th>
                <th>判定</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bets as $b)
            <tr>
                <td>{{ optional($b->race?->race_date)->format('m/d') }}</td>
                <td>{{ $b->race?->venue?->name }} {{ $b->race?->race_number }}R<br><span style="color:#888;font-size:9px">{{ Str::limit($b->race?->name ?? '', 18) }}</span></td>
                <td>
                    {{ \App\Models\Bet::KIND_LABELS[$b->kind] ?? $b->kind }}<br>
                    <span style="color:#888;font-size:9px">{{ \App\Models\Bet::METHOD_LABELS[$b->method] ?? $b->method }}</span>
                </td>
                <td style="font-family: monospace; font-size: 9px;">{{ Str::limit($b->legs->pluck('combination')->implode(' '), 60) }}</td>
                <td class="num">{{ $b->points }}</td>
                <td class="num">¥{{ number_format($b->total_stake) }}</td>
                <td class="num">¥{{ number_format($b->total_return) }}</td>
                @php $p = $b->total_return - $b->total_stake; @endphp
                <td class="num {{ $p >= 0 ? 'hit' : 'miss' }}">{{ $p >= 0 ? '+' : '' }}¥{{ number_format($p) }}</td>
                <td>
                    @if (!$b->is_settled)
                        未精算
                    @elseif ($b->hit_count > 0)
                        <span class="hit">的中×{{ $b->hit_count }}</span>
                    @else
                        <span class="miss">不的中</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        生成日時: {{ now()->format('Y/m/d H:i:s') }} / JRA Analytics
        @if ($bets->count() >= 2000)<br>※ 最大 2000 件まで表示しています。フィルタで件数を絞ってください。@endif
    </div>
</body>
</html>
