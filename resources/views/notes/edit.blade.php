@extends('layouts.app')
@section('title', 'メモ編集')

@section('content')
<div class="max-w-3xl mx-auto space-y-4">
    <h1 class="text-2xl font-bold text-gray-800">メモ編集</h1>
    <form method="POST" action="{{ route('notes.update', $note) }}" class="bg-white rounded-lg shadow p-6 space-y-4">
        @csrf @method('PUT')
        @include('notes._form', ['note' => $note])
        <div class="flex justify-between">
            <form method="POST" action="{{ route('notes.destroy', $note) }}" onsubmit="return confirm('削除しますか？');">
                @csrf @method('DELETE')
                <button type="submit" class="text-red-600 hover:text-red-800 text-sm">このメモを削除</button>
            </form>
            <div class="space-x-2">
                <a href="{{ route('notes.index') }}" class="text-gray-500 hover:text-gray-700 px-4 py-2">キャンセル</a>
                <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded">更新</button>
            </div>
        </div>
    </form>
</div>
@endsection
