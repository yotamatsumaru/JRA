@extends('layouts.app')
@section('title', '画像取込（GPT-4o Vision）')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="inline-flex items-center gap-2 text-2xl font-bold text-gray-800 dark:text-gray-100">
        <x-icon name="camera" class="w-7 h-7 text-purple-600 dark:text-purple-400" />
        <span>画像取込（GPT-4o Vision）</span>
    </h1>

    <div class="bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-400 dark:border-blue-500 p-4 rounded text-sm text-blue-800 dark:text-blue-200">
        <p class="font-semibold mb-1">使い方</p>
        <ul class="list-disc list-inside space-y-1">
            <li>JRAサイトやnetkeibaの<b>出馬表</b>または<b>結果票</b>のスクリーンショットをアップロード</li>
            <li>OpenAI GPT-4o が画像を解析し、JSON化されたデータを返却</li>
            <li>解析結果は確認画面で内容をチェック後にインポート</li>
            <li>JPEG/PNG/WebP対応、最大10MBまで</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('import.image.store') }}" enctype="multipart/form-data" class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">画像ファイル <span class="text-red-500">*</span></label>
            <input type="file" name="image" accept="image/*" required class="w-full border dark:border-gray-600 rounded px-3 py-2 bg-gray-50 dark:bg-gray-900 dark:text-gray-100"
                   x-data x-on:change="
                       const file = $event.target.files[0];
                       if (file) {
                           const reader = new FileReader();
                           reader.onload = e => document.getElementById('preview').src = e.target.result;
                           reader.readAsDataURL(file);
                       }
                   ">
        </div>

        <div>
            <img id="preview" src="" class="max-w-full rounded border hidden" alt="プレビュー" onload="this.classList.remove('hidden')">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">画像種別 <span class="text-red-500">*</span></label>
            <div class="grid grid-cols-2 gap-3">
                <label class="border dark:border-gray-600 rounded p-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40">
                    <input type="radio" name="mode" value="race_card" required class="mr-2">
                    <span class="font-bold">出馬表</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">レース前の出走馬一覧</p>
                </label>
                <label class="border dark:border-gray-600 rounded p-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40">
                    <input type="radio" name="mode" value="race_result" required class="mr-2" checked>
                    <span class="font-bold">結果票</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">確定後の着順・タイム</p>
                </label>
            </div>
        </div>

        <div class="flex justify-end space-x-2">
            <a href="{{ route('import.index') }}" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 px-4 py-2">戻る</a>
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded">解析開始</button>
        </div>
    </form>

    <div class="bg-amber-50 dark:bg-amber-900/30 border-l-4 border-amber-400 dark:border-amber-500 p-4 rounded text-sm text-amber-800 dark:text-amber-200">
        <p class="inline-flex items-start gap-1.5">
            <x-icon name="warning" class="w-4 h-4 mt-0.5 flex-shrink-0" />
            <span><code>OPENAI_API_KEY</code> の設定が必要です。<code>.env</code> で設定してください。</span>
        </p>
        <p class="mt-1">画像1枚あたり数十円程度のAPIコストが発生します。</p>
    </div>
</div>
@endsection
