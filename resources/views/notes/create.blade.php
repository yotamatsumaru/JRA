@extends('layouts.app')
@section('title', 'メモを書く')

@section('content')
<div class="max-w-3xl mx-auto space-y-4">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">メモを書く</h1>
    <form method="POST" action="{{ route('notes.store') }}" class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-6 space-y-4">
        @csrf
        @include('notes._form', ['note' => null])
        <div class="flex justify-end space-x-2">
            <a href="{{ route('notes.index') }}" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 px-4 py-2">キャンセル</a>
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded">保存</button>
        </div>
    </form>
</div>
@endsection
