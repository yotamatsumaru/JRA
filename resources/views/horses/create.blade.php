@extends('layouts.app')
@section('title', '馬を新規登録')

@section('content')
<div class="max-w-3xl mx-auto space-y-4">
    <h1 class="text-2xl font-bold text-gray-800">馬を新規登録</h1>
    <form method="POST" action="{{ route('horses.store') }}" class="bg-white rounded-lg shadow p-6 space-y-4">
        @csrf
        @include('horses._form', ['horse' => null])
        <div class="flex justify-end space-x-2">
            <a href="{{ route('horses.index') }}" class="text-gray-500 hover:text-gray-700 px-4 py-2">キャンセル</a>
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded">登録</button>
        </div>
    </form>
</div>
@endsection
