@extends('layouts.app')
@section('title', 'レース一覧')

@section('content')
<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">レース一覧</h1>
        <a href="{{ route('races.create') }}" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded text-sm">+ 新規レース登録</a>
    </div>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-4 grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
        <div>
            <label class="block text-xs text-gray-500 mb-1">競馬場</label>
            <select name="venue_id" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected(request('venue_id') == $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">グレード</label>
            <select name="grade" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach (['G1','G2','G3','OP','L','3勝','2勝','1勝','未勝利','新馬'] as $g)
                    <option value="{{ $g }}" @selected(request('grade') == $g)>{{ $g }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">トラック</label>
            <select name="track_type" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected(request('track_type') == $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">キーワード</label>
            <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="レース名" class="w-full border rounded px-2 py-1">
        </div>
        <div class="col-span-2 md:col-span-6 flex justify-end space-x-2">
            <a href="{{ route('races.index') }}" class="text-gray-500 hover:text-gray-700 px-3 py-1 text-xs">クリア</a>
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-1 rounded text-sm">検索</button>
        </div>
    </form>

    {{-- レーステーブル --}}
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                <tr>
                    <th class="text-left px-3 py-2">日付</th>
                    <th class="text-left px-3 py-2">場</th>
                    <th class="text-left px-3 py-2">R</th>
                    <th class="text-left px-3 py-2">レース名</th>
                    <th class="text-left px-3 py-2">グレード</th>
                    <th class="text-left px-3 py-2">トラック</th>
                    <th class="text-left px-3 py-2">距離</th>
                    <th class="text-left px-3 py-2">頭数</th>
                    <th class="text-right px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($races as $r)
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-3 py-2">{{ $r->race_date?->format('Y/m/d') }}</td>
                    <td class="px-3 py-2">{{ $r->venue?->name }}</td>
                    <td class="px-3 py-2">{{ $r->race_number }}R</td>
                    <td class="px-3 py-2">
                        <a href="{{ route('races.show', $r) }}" class="text-primary-600 hover:underline font-medium">{{ $r->name }}</a>
                    </td>
                    <td class="px-3 py-2">
                        @if ($r->grade) <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded">{{ $r->grade }}</span> @endif
                    </td>
                    <td class="px-3 py-2">{{ $r->track_type }}</td>
                    <td class="px-3 py-2">{{ $r->distance }}m</td>
                    <td class="px-3 py-2">{{ $r->results_count ?? 0 }}</td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('races.edit', $r) }}" class="text-xs text-gray-500 hover:text-primary-700">編集</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-gray-500 py-8">レースがまだ登録されていません</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $races->links() }}</div>
</div>
@endsection
