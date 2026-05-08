@extends('layouts.app')
@section('title', '騎手一覧')

@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-bold text-gray-800">騎手一覧</h1>

    <form method="GET" class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 text-sm items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs text-gray-500 mb-1">キーワード</label>
            <input type="text" name="keyword" value="{{ request('keyword') }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">所属</label>
            <select name="belonging" class="border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach (['美浦','栗東','フリー','外国'] as $b)
                    <option value="{{ $b }}" @selected(request('belonging') == $b)>{{ $b }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-1 rounded">検索</button>
        <a href="{{ route('jockeys.index') }}" class="text-gray-500 hover:text-gray-700 px-3 py-1">クリア</a>
    </form>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                <tr>
                    <th class="text-left px-3 py-2">騎手</th>
                    <th class="text-left px-3 py-2">カナ</th>
                    <th class="text-left px-3 py-2">所属</th>
                    <th class="px-3 py-2">勝利数</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($jockeys as $j)
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-3 py-2">
                        <a href="{{ route('jockeys.show', $j) }}" class="text-primary-600 hover:underline font-medium">{{ $j->name }}</a>
                    </td>
                    <td class="px-3 py-2 text-gray-500 text-xs">{{ $j->name_kana }}</td>
                    <td class="px-3 py-2">{{ $j->belonging }}</td>
                    <td class="px-3 py-2 text-center font-bold text-amber-700">{{ $j->wins ?? 0 }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-gray-500 py-8">該当する騎手がいません</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $jockeys->links() }}</div>
</div>
@endsection
