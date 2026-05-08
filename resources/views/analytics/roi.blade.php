@extends('layouts.app')
@section('title', '回収率シミュレーション')

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">回収率シミュレーション（単勝）</h1>
    <p class="text-sm text-gray-600">人気・競馬場・トラック別の単勝回収率を計算します。100%超で利益が出る組み合わせを発見できます。</p>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">人気</label>
            <select name="popularity" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @for ($i = 1; $i <= 18; $i++)
                    <option value="{{ $i }}" @selected($popularity == $i)>{{ $i }}番人気</option>
                @endfor
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">競馬場</label>
            <select name="venue_id" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected($venueId == $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">トラック</label>
            <select name="track_type" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected($trackType == $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-1 rounded w-full">計算</button>
        </div>
    </form>

    {{-- 結果 --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">対象レース数（賭け数）</div>
            <div class="text-3xl font-bold text-primary-700 mt-1">{{ number_format($bets->bets ?? 0) }}</div>
            <div class="text-xs text-gray-500 mt-1">100円ずつ単勝を購入したと仮定</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">払戻総額</div>
            <div class="text-3xl font-bold text-emerald-700 mt-1">¥{{ number_format($bets->winnings ?? 0) }}</div>
            <div class="text-xs text-gray-500 mt-1">投資総額: ¥{{ number_format(($bets->bets ?? 0) * 100) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 {{ $roi >= 100 ? 'ring-2 ring-emerald-400' : '' }}">
            <div class="text-xs text-gray-500">回収率</div>
            <div class="text-4xl font-bold {{ $roi >= 100 ? 'text-emerald-600' : ($roi >= 80 ? 'text-amber-600' : 'text-rose-600') }} mt-1">
                {{ $roi }}%
            </div>
            <div class="text-xs text-gray-500 mt-1">
                @if ($roi >= 100) ✅ プラス収支
                @elseif ($roi >= 80) ⚠️ 控除率を考慮しても惜しい
                @else ❌ 控えめな結果
                @endif
            </div>
        </div>
    </div>

    <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded">
        <h3 class="font-semibold text-amber-800 mb-2">読み方のヒント</h3>
        <ul class="text-sm text-amber-700 list-disc list-inside space-y-1">
            <li>JRAの単勝控除率は約20%。理論上の平均回収率は <b>80%</b>。</li>
            <li>過去データが <b>100%超</b> の組み合わせは、その条件で買い続けると過去には利益が出ていた = 美味しい買い目候補。</li>
            <li>サンプル数が少ない場合（例: 30件未満）はブレが大きいので参考程度に。</li>
            <li>未来の成績は保証されないので、自己責任で。</li>
        </ul>
    </div>
</div>
@endsection
