@extends('layouts.app')
@section('title', 'レース新規登録')

@section('content')
<div class="max-w-3xl mx-auto space-y-4">
    <h1 class="text-2xl font-bold text-gray-800">レース新規登録</h1>

    <form method="POST" action="{{ route('races.store') }}" class="bg-white rounded-lg shadow p-6 space-y-4">
        @csrf
        @include('races._form', ['race' => null])
        <div class="flex justify-end space-x-2">
            <a href="{{ route('races.index') }}" class="text-gray-500 hover:text-gray-700 px-4 py-2">キャンセル</a>
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded">登録する</button>
        </div>
    </form>
</div>
@endsection
