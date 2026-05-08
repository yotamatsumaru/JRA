@extends('layouts.app')
@section('title', 'プロフィール')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">プロフィール設定</h1>

    {{-- 情報更新 --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-700 mb-4">アカウント情報</h2>
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf @method('PATCH')
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ユーザー名</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}" required class="w-full border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">メールアドレス</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="w-full border rounded px-3 py-2">
            </div>
            <div class="flex justify-end">
                <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded">更新</button>
            </div>
        </form>
    </div>

    {{-- アカウント削除 --}}
    <div class="bg-white rounded-lg shadow p-6 border-l-4 border-red-400">
        <h2 class="font-semibold text-red-700 mb-2">アカウント削除</h2>
        <p class="text-sm text-gray-600 mb-4">アカウントを削除すると、関連するメモやお気に入りが完全に失われます。レース・馬・騎手等の共有データは残ります。</p>
        <form method="POST" action="{{ route('profile.destroy') }}" x-data="{ confirming: false }">
            @csrf @method('DELETE')
            <template x-if="!confirming">
                <button type="button" @click="confirming = true" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm">アカウントを削除する</button>
            </template>
            <template x-if="confirming">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-red-700 mb-1">確認のため現在のパスワードを入力</label>
                        <input type="password" name="password" required class="w-full border rounded px-3 py-2">
                    </div>
                    <div class="flex space-x-2">
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm">本当に削除</button>
                        <button type="button" @click="confirming = false" class="text-gray-500 hover:text-gray-700 px-4 py-2">キャンセル</button>
                    </div>
                </div>
            </template>
        </form>
    </div>
</div>
@endsection
