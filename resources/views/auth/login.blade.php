@extends('layouts.guest')
@section('title', 'ログイン')

@section('content')
<h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-6 text-center">ログイン</h2>

@if ($errors->any())
<div class="mb-4 bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-200 px-3 py-2 rounded text-sm">
    @foreach ($errors->all() as $error)
        <div>{{ $error }}</div>
    @endforeach
</div>
@endif

<form method="POST" action="{{ route('login') }}" class="space-y-4">
    @csrf

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">メールアドレス</label>
        <input type="email" name="email" value="{{ old('email') }}" required autofocus
               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">パスワード</label>
        <input type="password" name="password" required
               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent">
    </div>

    <div class="flex items-center justify-between">
        <label class="flex items-center text-sm text-gray-600 dark:text-gray-400">
            <input type="checkbox" name="remember" class="mr-2"> ログイン状態を保持
        </label>
    </div>

    <button type="submit" class="w-full bg-primary-600 hover:bg-primary-700 text-white py-2 rounded-lg font-medium transition">
        ログイン
    </button>

    <p class="text-center text-sm text-gray-600 dark:text-gray-400">
        アカウントをお持ちでない方は
        <a href="{{ route('register') }}" class="text-primary-600 hover:underline">新規登録</a>
    </p>
</form>
@endsection
