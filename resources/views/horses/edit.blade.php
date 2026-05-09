@extends('layouts.app')
@section('title', $horse->name . ' 編集')

@section('content')
<div class="max-w-3xl mx-auto space-y-4">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $horse->name }} 編集</h1>

    {{-- 更新フォーム本体 --}}
    <form method="POST" action="{{ route('horses.update', $horse) }}" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-4">
        @csrf @method('PUT')
        @include('horses._form', ['horse' => $horse])
        <div class="flex justify-end space-x-2">
            <a href="{{ route('horses.show', $horse) }}" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 px-4 py-2">キャンセル</a>
            <button type="submit" class="bg-turf-600 hover:bg-turf-700 text-white px-6 py-2 rounded inline-flex items-center space-x-1">
                <x-icon name="check" class="w-4 h-4" />
                <span>更新</span>
            </button>
        </div>
    </form>

    {{-- 削除アクション（更新フォームの外側に配置：HTMLのform入れ子禁止） --}}
    <div class="flex justify-start">
        <x-confirm-delete
            :action="route('horses.destroy', $horse)"
            title="馬の削除確認"
            message="この馬とその出走結果をすべて削除します。この操作は取り消せません。"
            label="この馬を削除" />
    </div>
</div>
@endsection
