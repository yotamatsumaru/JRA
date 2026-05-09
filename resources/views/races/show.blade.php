@extends('layouts.app')
@section('title', $race->name)

@section('content')
<div class="space-y-6">

    {{-- ヘッダー --}}
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-start justify-between">
            <div>
                <div class="text-sm text-gray-500">{{ $race->race_date?->format('Y年m月d日') }} {{ $race->venue?->name }} {{ $race->race_number }}R</div>
                <h1 class="text-2xl font-bold text-gray-800 mt-1">
                    {{ $race->name }}
                    @if ($race->grade) <span class="ml-2 text-sm bg-amber-500 text-white px-2 py-0.5 rounded">{{ $race->grade }}</span> @endif
                </h1>
                <div class="mt-2 text-sm text-gray-600 space-x-3">
                    <span>{{ $race->track_type }}{{ $race->distance }}m</span>
                    @if ($race->direction) <span>・{{ $race->direction }}</span> @endif
                    @if ($race->course_detail) <span>・{{ $race->course_detail }}</span> @endif
                    @if ($race->course_condition) <span>・馬場:{{ $race->course_condition }}</span> @endif
                    @if ($race->weather) <span>・天候:{{ $race->weather }}</span> @endif
                    @if ($race->pace) <span>・ペース:{{ $race->pace }}</span> @endif
                </div>
            </div>
            <div class="flex space-x-2">
                <a href="{{ route('races.edit', $race) }}" class="text-sm text-gray-500 hover:text-primary-600 px-3 py-1 border rounded">編集</a>
            </div>
        </div>
    </div>

    {{-- ガード: リレーションが null になっていても落ちないよう全て collect() に統一 --}}
    @php
        $results       = $race->results  ?? collect();
        $payouts       = $race->payouts  ?? collect();
        $notes         = $race->notes    ?? collect();
        $myBets        = $myBets         ?? collect();
        $payoutsByKind = $payoutsByKind  ?? collect();
    @endphp

    {{-- 出走結果 --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-700 mb-3">出走結果（{{ $results->count() }}頭）</h2>

        @if ($results->isEmpty())
            <p class="text-sm text-gray-500 mb-4">まだ結果が登録されていません。下のフォームから入力してください。</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                        <tr>
                            <th class="px-2 py-2">着</th>
                            <th class="px-2 py-2">枠</th>
                            <th class="px-2 py-2">馬番</th>
                            <th class="text-left px-2 py-2">馬名</th>
                            <th class="px-2 py-2">性齢</th>
                            <th class="px-2 py-2">斤量</th>
                            <th class="text-left px-2 py-2">騎手</th>
                            <th class="text-left px-2 py-2">厩舎</th>
                            <th class="px-2 py-2">馬体重</th>
                            <th class="px-2 py-2">タイム</th>
                            <th class="px-2 py-2">着差</th>
                            <th class="px-2 py-2">通過</th>
                            <th class="px-2 py-2">脚質</th>
                            <th class="px-2 py-2">上り</th>
                            <th class="px-2 py-2">人気</th>
                            <th class="px-2 py-2">単勝</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results->sortBy(fn($r) => $r->finish_position_int ?? 99) as $r)
                        <tr class="border-b hover:bg-gray-50 {{ $r->finish_position_int == 1 ? 'bg-yellow-50' : '' }}">
                            <td class="px-2 py-2 text-center font-bold">{{ $r->finish_position }}</td>
                            <td class="px-2 py-2 text-center">{{ $r->frame_number }}</td>
                            <td class="px-2 py-2 text-center">{{ $r->horse_number }}</td>
                            <td class="px-2 py-2">
                                @if ($r->horse)
                                    <a href="{{ route('horses.show', $r->horse) }}" class="text-primary-600 hover:underline">{{ $r->horse->name }}</a>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-center text-xs">{{ $r->sex }}{{ $r->age }}</td>
                            <td class="px-2 py-2 text-center">{{ $r->weight_carried }}</td>
                            <td class="px-2 py-2">
                                @if ($r->jockey)
                                    <a href="{{ route('jockeys.show', $r->jockey) }}" class="text-primary-600 hover:underline">{{ $r->jockey->name }}</a>
                                @else
                                    <span class="text-gray-400 text-xs">-</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-xs text-gray-700">
                                {{ $r->trainer?->name ?? '-' }}
                            </td>
                            <td class="px-2 py-2 text-center text-xs">
                                @if ($r->horse_weight)
                                    {{ $r->horse_weight }}
                                    @if ($r->horse_weight_diff !== null)
                                        <span class="text-gray-500">({{ $r->horse_weight_diff > 0 ? '+' : '' }}{{ $r->horse_weight_diff }})</span>
                                    @endif
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-2 py-2 text-center font-mono">{{ $r->time }}</td>
                            <td class="px-2 py-2 text-center">{{ $r->margin }}</td>
                            <td class="px-2 py-2 text-center text-xs text-gray-600">{{ $r->corner_positions }}</td>
                            <td class="px-2 py-2 text-center">
                                @if ($r->running_style)
                                    <span class="text-xs bg-blue-100 text-blue-700 px-1 rounded">{{ $r->running_style }}</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-center">{{ $r->last_3f }}</td>
                            <td class="px-2 py-2 text-center">{{ $r->popularity }}</td>
                            <td class="px-2 py-2 text-center">{{ $r->win_odds }}</td>
                            <td class="px-2 py-2 text-right">
                                <form method="POST" action="{{ route('races.results.destroy', [$race, $r]) }}" class="inline" onsubmit="return confirm('削除しますか？');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-red-500 hover:text-red-700">×</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ラップタイム --}}
        @php
            $lapTimes = [];
            try {
                $lt = $race->lap_times;
                if (is_array($lt)) $lapTimes = $lt;
                elseif (is_string($lt) && $lt !== '') {
                    $decoded = json_decode($lt, true);
                    if (is_array($decoded)) $lapTimes = $decoded;
                }
            } catch (\Throwable $e) { $lapTimes = []; }
        @endphp
        @if (!empty($lapTimes))
            <div class="mt-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-2">ラップタイム</h3>
                <div class="flex flex-wrap gap-2 text-xs font-mono">
                    @foreach ($lapTimes as $i => $lap)
                        <span class="bg-gray-100 border rounded px-2 py-1">
                            <span class="text-gray-500">{{ ($i + 1) * 200 }}m</span>
                            <span class="ml-1 text-gray-800 font-bold">{{ $lap }}</span>
                        </span>
                    @endforeach
                </div>
                @if ($race->first_3f || $race->last_3f)
                    <div class="mt-2 text-xs text-gray-600">
                        @if ($race->first_3f) <span class="mr-3">前3F: <span class="font-mono font-bold text-gray-800">{{ $race->first_3f }}</span></span> @endif
                        @if ($race->last_3f) <span>上り3F: <span class="font-mono font-bold text-gray-800">{{ $race->last_3f }}</span></span> @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- 結果追加フォーム --}}
        <details class="mt-6">
            <summary class="cursor-pointer text-sm text-primary-600 hover:underline">＋ 出走馬の結果を追加</summary>
            <form method="POST" action="{{ route('races.results.store', $race) }}" class="mt-4 grid grid-cols-2 md:grid-cols-6 gap-3 text-sm bg-gray-50 p-4 rounded">
                @csrf
                <div>
                    <label class="block text-xs text-gray-600 mb-1">着順</label>
                    <input type="text" name="finish_position" placeholder="1, 中止 等" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">枠</label>
                    <input type="number" name="frame_number" min="1" max="8" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">馬番 *</label>
                    <input type="number" name="horse_number" min="1" max="18" required class="w-full border rounded px-2 py-1">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">馬名 *</label>
                    <input type="text" name="horse_name" required class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">性</label>
                    <select name="sex" class="w-full border rounded px-2 py-1">
                        <option value="">-</option>
                        <option value="牡">牡</option><option value="牝">牝</option><option value="セ">セ</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">齢</label>
                    <input type="number" name="age" min="2" max="12" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">斤量</label>
                    <input type="number" name="weight_carried" step="0.5" min="30" max="70" class="w-full border rounded px-2 py-1">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">騎手</label>
                    <input type="text" name="jockey_name" class="w-full border rounded px-2 py-1">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">調教師</label>
                    <input type="text" name="trainer_name" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">タイム</label>
                    <input type="text" name="time" placeholder="1:23.4" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">着差</label>
                    <input type="text" name="margin" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">上り3F</label>
                    <input type="text" name="last_3f" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">通過順</label>
                    <input type="text" name="corner_positions" placeholder="3-3-3" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">脚質</label>
                    <select name="running_style" class="w-full border rounded px-2 py-1">
                        <option value="">自動判定</option>
                        @foreach (['逃','先','差','追'] as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">人気</label>
                    <input type="number" name="popularity" min="1" max="18" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">単勝オッズ</label>
                    <input type="number" name="win_odds" step="0.1" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">賞金(万円)</label>
                    <input type="number" name="prize_money" class="w-full border rounded px-2 py-1">
                </div>
                <div class="col-span-2 md:col-span-6 flex justify-end">
                    <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-1 rounded">追加</button>
                </div>
            </form>
        </details>
    </div>

    {{-- 公式払戻 --}}
    @if ($payouts->isNotEmpty())
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-700 mb-3">公式払戻</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                    <tr>
                        <th class="text-left px-3 py-2">券種</th>
                        <th class="text-left px-3 py-2">組合せ</th>
                        <th class="text-right px-3 py-2">払戻金</th>
                        <th class="text-right px-3 py-2">人気</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $kindOrder = ['tan','fuku','waku-ren','uma-ren','uma-tan','wide','san-fuku','san-tan'];
                        $sortedKinds = collect($kindOrder)->filter(fn($k) => $payoutsByKind->has($k));
                    @endphp
                    @foreach ($sortedKinds as $kind)
                        @foreach ($payoutsByKind[$kind] as $i => $p)
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-3 py-2">
                                    @if ($i === 0)
                                        <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded font-bold">{{ $p->kind_label }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono">{{ $p->combination }}</td>
                                <td class="px-3 py-2 text-right font-bold {{ $p->amount >= 10000 ? 'text-rose-600' : 'text-gray-800' }}">
                                    ¥{{ number_format($p->amount) }}
                                    @if ($p->amount >= 1000000)
                                        <span class="ml-1 text-xs bg-purple-600 text-white px-1 rounded">百万</span>
                                    @elseif ($p->amount >= 100000)
                                        <span class="ml-1 text-xs bg-rose-600 text-white px-1 rounded">十万</span>
                                    @elseif ($p->amount >= 10000)
                                        <span class="ml-1 text-xs bg-amber-500 text-white px-1 rounded">万馬</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right text-xs text-gray-600">{{ $p->popularity ? $p->popularity.'人気' : '-' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- 自分の馬券 --}}
    @if ($myBets->isNotEmpty())
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-700">このレースの自分の馬券</h2>
            <a href="{{ route('bets.create', ['race_id' => $race->id]) }}" class="text-xs text-primary-600 hover:underline">＋ 追加</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-sm">
            <div class="bg-gray-50 rounded p-3">
                <div class="text-xs text-gray-500">点数</div>
                <div class="font-bold text-gray-800">{{ $myBetSummary['count'] }}件</div>
            </div>
            <div class="bg-gray-50 rounded p-3">
                <div class="text-xs text-gray-500">投資</div>
                <div class="font-bold text-gray-800">¥{{ number_format($myBetSummary['stake']) }}</div>
            </div>
            <div class="bg-gray-50 rounded p-3">
                <div class="text-xs text-gray-500">払戻</div>
                <div class="font-bold text-emerald-600">¥{{ number_format($myBetSummary['payout']) }}</div>
            </div>
            <div class="bg-gray-50 rounded p-3">
                <div class="text-xs text-gray-500">収支</div>
                <div class="font-bold {{ $myBetSummary['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                    {{ $myBetSummary['profit'] >= 0 ? '+' : '' }}¥{{ number_format($myBetSummary['profit']) }}
                </div>
            </div>
            <div class="bg-gray-50 rounded p-3">
                <div class="text-xs text-gray-500">回収率</div>
                <div class="font-bold {{ ($myBetSummary['roi'] ?? 0) >= 100 ? 'text-emerald-600' : 'text-gray-800' }}">
                    {{ $myBetSummary['roi'] !== null ? $myBetSummary['roi'].'%' : '-' }}
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                    <tr>
                        <th class="text-left px-3 py-2">券種</th>
                        <th class="text-left px-3 py-2">買い方</th>
                        <th class="text-right px-3 py-2">点数</th>
                        <th class="text-right px-3 py-2">投資</th>
                        <th class="text-right px-3 py-2">払戻</th>
                        <th class="text-right px-3 py-2">収支</th>
                        <th class="text-center px-3 py-2">状態</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($myBets as $b)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-3 py-2">{{ $b->kind_label }}</td>
                        <td class="px-3 py-2 text-xs text-gray-600">{{ $b->method_label }}</td>
                        <td class="px-3 py-2 text-right">{{ $b->points }}</td>
                        <td class="px-3 py-2 text-right">¥{{ number_format($b->total_stake) }}</td>
                        <td class="px-3 py-2 text-right text-emerald-600">¥{{ number_format($b->total_return) }}</td>
                        <td class="px-3 py-2 text-right font-bold {{ $b->profit >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $b->profit >= 0 ? '+' : '' }}¥{{ number_format($b->profit) }}
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if (!$b->is_settled)
                                <span class="text-xs bg-gray-200 text-gray-700 px-2 py-0.5 rounded">未確定</span>
                            @elseif ($b->hit_count > 0)
                                <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded">的中</span>
                            @else
                                <span class="text-xs bg-rose-100 text-rose-700 px-2 py-0.5 rounded">不的中</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right">
                            <a href="{{ route('bets.show', $b) }}" class="text-xs text-primary-600 hover:underline">詳細</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div class="text-sm text-gray-500">このレースの馬券はまだ登録されていません。</div>
            <a href="{{ route('bets.create', ['race_id' => $race->id]) }}" class="text-sm bg-primary-600 hover:bg-primary-700 text-white px-3 py-1 rounded">＋ 馬券を登録</a>
        </div>
    </div>
    @endif

    {{-- メモ --}}
    @if ($notes->isNotEmpty())
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-700 mb-3">レースメモ</h2>
        <ul class="space-y-3">
            @foreach ($notes as $note)
                <li class="border-l-4 border-primary-300 pl-3 py-1">
                    <div class="text-xs text-gray-500">{{ $note->user?->name }} - {{ $note->created_at?->format('Y/m/d H:i') }}</div>
                    @if ($note->title) <div class="font-bold">{{ $note->title }}</div> @endif
                    <div class="text-sm whitespace-pre-wrap">{{ $note->body }}</div>
                </li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
@endsection
