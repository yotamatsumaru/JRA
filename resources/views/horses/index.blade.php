@extends('layouts.app')
@section('title', '馬一覧')

@section('content')
<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">馬一覧</h1>
        <a href="{{ route('horses.create') }}" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded text-sm">+ 馬を登録</a>
    </div>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 text-sm items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs text-gray-500 mb-1">キーワード（馬名 / 父 / 母）</label>
            <input type="text" name="keyword" value="{{ request('keyword') }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">性別</label>
            <select name="sex" class="border rounded px-2 py-1">
                <option value="">すべて</option>
                <option value="牡" @selected(request('sex')=='牡')>牡</option>
                <option value="牝" @selected(request('sex')=='牝')>牝</option>
                <option value="セ" @selected(request('sex')=='セ')>セ</option>
            </select>
        </div>
        <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-1 rounded">検索</button>
        <a href="{{ route('horses.index') }}" class="text-gray-500 hover:text-gray-700 px-3 py-1">クリア</a>
    </form>

    {{-- リスト --}}
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                <tr>
                    <th class="text-left px-3 py-2">馬名</th>
                    <th class="px-3 py-2">性</th>
                    <th class="text-left px-3 py-2">父</th>
                    <th class="text-left px-3 py-2">母</th>
                    <th class="text-left px-3 py-2">母父</th>
                    <th class="px-3 py-2">出走</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($horses as $h)
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-3 py-2"><a href="{{ route('horses.show', $h) }}" class="text-primary-600 hover:underline font-medium">{{ $h->name }}</a></td>
                    <td class="px-3 py-2 text-center">{{ $h->sex }}</td>
                    <td class="px-3 py-2">{{ $h->father }}</td>
                    <td class="px-3 py-2">{{ $h->mother }}</td>
                    <td class="px-3 py-2">{{ $h->mother_father }}</td>
                    <td class="px-3 py-2 text-center">{{ $h->results_count ?? 0 }}</td>
                    <td class="px-3 py-2 text-right"><a href="{{ route('horses.edit', $h) }}" class="text-xs text-gray-500 hover:text-primary-700">編集</a></td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-gray-500 py-8">馬がまだ登録されていません</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $horses->links() }}</div>
</div>
@endsection
