@extends('layouts.app')
@section('title', '馬券登録')

@section('content')
<div class="max-w-4xl mx-auto space-y-4">
    <x-page-header title="馬券を登録" subtitle="券種・買い方を選んで馬番を選択するだけ" icon="plus" />

    <form method="POST" action="{{ route('bets.store') }}" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-4">
        @csrf
        @include('bets._form', ['bet' => null, 'race' => $race])

        <div class="flex justify-end space-x-2 pt-3 border-t border-gray-100 dark:border-gray-700">
            <a href="{{ route('bets.index') }}" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 px-4 py-2">キャンセル</a>
            <button type="submit" class="bg-turf-600 hover:bg-turf-700 text-white px-6 py-2 rounded inline-flex items-center space-x-1">
                <x-icon name="check" class="w-4 h-4" /><span>登録する</span>
            </button>
        </div>
    </form>
</div>
@endsection
