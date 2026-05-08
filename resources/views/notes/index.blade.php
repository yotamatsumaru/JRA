@extends('layouts.app')
@section('title', 'メモ一覧')

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">マイメモ</h1>
        <a href="{{ route('notes.create') }}" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded text-sm">+ メモを書く</a>
    </div>

    @if ($notes->isEmpty())
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <p class="text-gray-500">まだメモがありません。</p>
            <a href="{{ route('notes.create') }}" class="text-primary-600 hover:underline mt-2 inline-block">最初のメモを書く →</a>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($notes as $note)
                <div class="bg-white rounded-lg shadow p-4 hover:shadow-lg transition">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            @if ($note->title)
                                <h3 class="font-bold text-gray-800">{{ $note->title }}</h3>
                            @endif
                            <div class="text-xs text-gray-500 mt-1 space-x-2">
                                <span>{{ $note->created_at->format('Y/m/d H:i') }}</span>
                                @if ($note->tag) <span class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded">#{{ $note->tag }}</span> @endif
                            </div>
                            @if ($note->race)
                                <div class="text-xs text-primary-600 mt-1">
                                    🏇 <a href="{{ route('races.show', $note->race) }}" class="hover:underline">{{ $note->race->venue?->name }} {{ $note->race->name }}</a>
                                </div>
                            @endif
                            @if ($note->horse)
                                <div class="text-xs text-emerald-600 mt-1">
                                    🐎 <a href="{{ route('horses.show', $note->horse) }}" class="hover:underline">{{ $note->horse->name }}</a>
                                </div>
                            @endif
                        </div>
                        <div class="flex space-x-2">
                            <a href="{{ route('notes.edit', $note) }}" class="text-xs text-gray-500 hover:text-primary-600">編集</a>
                            <form method="POST" action="{{ route('notes.destroy', $note) }}" onsubmit="return confirm('削除しますか？');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-500 hover:text-red-700">削除</button>
                            </form>
                        </div>
                    </div>
                    <div class="mt-3 text-sm text-gray-700 whitespace-pre-wrap line-clamp-6">{{ $note->body }}</div>
                </div>
            @endforeach
        </div>
        <div>{{ $notes->links() }}</div>
    @endif
</div>
@endsection
