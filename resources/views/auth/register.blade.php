@extends('layouts.guest')
@section('title', '新規登録')

@section('content')
<h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-6 text-center">新規登録</h2>

@if ($errors->any())
<div class="mb-4 bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-200 px-3 py-2 rounded text-sm">
    @foreach ($errors->all() as $error)
        <div>{{ $error }}</div>
    @endforeach
</div>
@endif

<form method="POST" action="{{ route('register') }}" class="space-y-4">
    @csrf

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">名前</label>
        <input type="text" name="name" value="{{ old('name') }}" required autofocus
               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">メールアドレス</label>
        <input type="email" name="email" value="{{ old('email') }}" required
               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">パスワード（8文字以上）</label>
        <input type="password" name="password" required
               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">パスワード（確認）</label>
        <input type="password" name="password_confirmation" required
               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500">
    </div>

    <button type="submit" class="w-full bg-primary-600 hover:bg-primary-700 text-white py-2 rounded-lg font-medium transition">
        登録
    </button>

    <p class="text-center text-sm text-gray-600 dark:text-gray-400">
        既にアカウントをお持ちの方は
        <a href="{{ route('login') }}" class="text-primary-600 hover:underline">ログイン</a>
    </p>
</form>
@endsection
