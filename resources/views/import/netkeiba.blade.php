@extends('layouts.app')
@section('title', 'netkeibaから取込')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="inline-flex items-center gap-2 text-2xl font-bold text-gray-800 dark:text-gray-100">
        <x-icon name="globe" class="w-7 h-7 text-blue-600 dark:text-blue-400" />
        <span>netkeiba.com から取込</span>
    </h1>

    <div class="bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-400 dark:border-blue-500 p-4 rounded text-sm text-blue-800 dark:text-blue-200">
        <p class="font-semibold mb-1">使い方</p>
        <ul class="list-disc list-inside space-y-1">
            <li>netkeibaのレース詳細ページのURL、または12桁のrace_idを入力してください</li>
            <li>例(結果): <code class="bg-white px-1 rounded">https://db.netkeiba.com/race/202405040811/</code></li>
            <li>例(出馬表): <code class="bg-white px-1 rounded">https://race.netkeiba.com/race/shutuba.html?race_id=202405040811</code></li>
            <li>例: <code class="bg-white px-1 rounded">202405040811</code></li>
            <li>結果モード: レース情報・出走馬・着順・通過順・払戻を一括取得</li>
            <li>出馬表モード: レース確定前のエントリー情報を取得（着順は空欄）。レース後に結果モードで再取込すると同じ行に結果がUPSERTされます</li>
            <li>連続取込時は自動で5秒間隔を空けます（サーバー負荷軽減）</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('import.netkeiba.store') }}" class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">取込モード</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex items-start gap-2 border dark:border-gray-600 rounded px-3 py-3 cursor-pointer hover:bg-emerald-50 dark:hover:bg-emerald-900/30 has-[:checked]:bg-emerald-50 dark:has-[:checked]:bg-emerald-900/30 has-[:checked]:border-emerald-400">
                    <input type="radio" name="mode" value="result" class="mt-1"
                           {{ old('mode', 'result') === 'result' ? 'checked' : '' }}>
                    <span class="text-sm">
                        <span class="font-semibold inline-flex items-center gap-1.5">
                            <x-icon name="trophy" class="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
                            結果取込
                        </span>
                        <span class="block text-gray-500 dark:text-gray-400 mt-0.5">レース確定後。着順・タイム・払戻まで全部取込</span>
                    </span>
                </label>
                <label class="flex items-start gap-2 border dark:border-gray-600 rounded px-3 py-3 cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/30 has-[:checked]:border-blue-400">
                    <input type="radio" name="mode" value="shutuba" class="mt-1"
                           {{ old('mode') === 'shutuba' ? 'checked' : '' }}>
                    <span class="text-sm">
                        <span class="font-semibold inline-flex items-center gap-1.5">
                            <x-icon name="document" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                            出馬表取込
                        </span>
                        <span class="block text-gray-500 dark:text-gray-400 mt-0.5">レース前。出走馬・騎手・斤量を取込（着順は空）</span>
                    </span>
                </label>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">レースURL</label>
            <input type="url" name="race_url" placeholder="https://race.netkeiba.com/race/shutuba.html?race_id=202405040811"
                   class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100" value="{{ old('race_url') }}">
        </div>

        <div class="text-center text-xs text-gray-500 dark:text-gray-400">または</div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">race_id（12桁）</label>
            <input type="text" name="race_id" pattern="\d{12}" placeholder="202405040811"
                   class="w-full border dark:border-gray-600 rounded px-3 py-2 font-mono dark:bg-gray-900 dark:text-gray-100" value="{{ old('race_id') }}">
        </div>

        <div class="flex justify-end space-x-2">
            <a href="{{ route('import.index') }}" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 px-4 py-2">戻る</a>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded">取込開始</button>
        </div>
    </form>

    <div class="bg-amber-50 dark:bg-amber-900/30 border-l-4 border-amber-400 dark:border-amber-500 p-4 rounded text-sm text-amber-800 dark:text-amber-200">
        <p class="inline-flex items-center gap-1.5 font-semibold mb-1">
            <x-icon name="info" class="w-4 h-4" />
            <span>5年分の一括取込について</span>
        </p>
        <p>大量のレースを取り込む場合はコマンドラインからの実行が推奨です:</p>
        <pre class="bg-white dark:bg-gray-900 dark:text-gray-100 p-2 rounded mt-2 text-xs overflow-x-auto"><code>php artisan netkeiba:race {race_id}     # 単一レース
php artisan netkeiba:date 2024-05-04   # 指定日のレース全部
php artisan netkeiba:date 2024-01-01 --to=2024-12-31  # 期間指定</code></pre>
    </div>
</div>
@endsection
