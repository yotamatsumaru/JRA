@extends('layouts.app')
@section('title', 'netkeibaから取込')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">🌐 netkeiba.com から取込</h1>

    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded text-sm text-blue-800">
        <p class="font-semibold mb-1">使い方</p>
        <ul class="list-disc list-inside space-y-1">
            <li>netkeibaのレース詳細ページのURL、または12桁のrace_idを入力してください</li>
            <li>例: <code class="bg-white px-1 rounded">https://db.netkeiba.com/race/202405040811/</code></li>
            <li>例: <code class="bg-white px-1 rounded">202405040811</code></li>
            <li>レース情報・出走馬・着順・通過順等を一括取得します</li>
            <li>連続取込時は自動で5秒間隔を空けます（サーバー負荷軽減）</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('import.netkeiba.store') }}" class="bg-white rounded-lg shadow p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">レースURL</label>
            <input type="url" name="race_url" placeholder="https://db.netkeiba.com/race/202405040811/"
                   class="w-full border rounded px-3 py-2" value="{{ old('race_url') }}">
        </div>

        <div class="text-center text-xs text-gray-500">または</div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">race_id（12桁）</label>
            <input type="text" name="race_id" pattern="\d{12}" placeholder="202405040811"
                   class="w-full border rounded px-3 py-2 font-mono" value="{{ old('race_id') }}">
        </div>

        <div class="flex justify-end space-x-2">
            <a href="{{ route('import.index') }}" class="text-gray-500 hover:text-gray-700 px-4 py-2">戻る</a>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded">取込開始</button>
        </div>
    </form>

    <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded text-sm text-amber-800">
        <p class="font-semibold mb-1">📌 5年分の一括取込について</p>
        <p>大量のレースを取り込む場合はコマンドラインからの実行が推奨です:</p>
        <pre class="bg-white p-2 rounded mt-2 text-xs overflow-x-auto"><code>php artisan netkeiba:race {race_id}     # 単一レース
php artisan netkeiba:date 2024-05-04   # 指定日のレース全部
php artisan netkeiba:date 2024-01-01 --to=2024-12-31  # 期間指定</code></pre>
    </div>
</div>
@endsection
