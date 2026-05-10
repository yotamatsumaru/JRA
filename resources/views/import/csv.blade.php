@extends('layouts.app')
@section('title', 'CSV取込')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="inline-flex items-center gap-2 text-2xl font-bold text-gray-800 dark:text-gray-100">
        <x-icon name="document" class="w-7 h-7 text-emerald-600 dark:text-emerald-400" />
        <span>CSV取込</span>
    </h1>

    <form method="POST" action="{{ route('import.csv.store') }}" enctype="multipart/form-data" class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CSVファイル <span class="text-red-500">*</span></label>
            <input type="file" name="csv_file" accept=".csv,.txt" required class="w-full border dark:border-gray-600 rounded px-3 py-2 bg-gray-50 dark:bg-gray-900 dark:text-gray-100">
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">最大10MBまで。UTF-8 推奨。</p>
        </div>

        <div class="flex justify-end space-x-2">
            <a href="{{ route('import.index') }}" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 px-4 py-2">戻る</a>
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded">取込開始</button>
        </div>
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4 text-sm">
        <h3 class="inline-flex items-center gap-1.5 font-semibold text-gray-700 dark:text-gray-200 mb-2">
            <x-icon name="list" class="w-4 h-4 text-gray-500 dark:text-gray-400" />
            <span>CSVフォーマット仕様</span>
        </h3>
        <p class="mb-2 text-gray-600 dark:text-gray-300">1行目はヘッダ行。以下の列が利用可能です（必須:race_date, venue_code, race_number, horse_number, horse_name）:</p>
        <div class="overflow-x-auto">
            <table class="w-full text-xs border dark:border-gray-700">
                <thead class="bg-gray-100 dark:bg-gray-800">
                    <tr>
                        <th class="px-2 py-1 text-left border-r">列名</th>
                        <th class="px-2 py-1 text-left border-r">必須</th>
                        <th class="px-2 py-1 text-left">説明・例</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="px-2 py-1 border-r border-t">race_date</td><td class="px-2 py-1 border-r border-t text-red-500">●</td><td class="px-2 py-1 border-t">レース日 YYYY-MM-DD</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">venue_code</td><td class="px-2 py-1 border-r border-t text-red-500">●</td><td class="px-2 py-1 border-t">2桁 (01=札幌〜10=小倉)</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">race_number</td><td class="px-2 py-1 border-r border-t text-red-500">●</td><td class="px-2 py-1 border-t">1〜12</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">race_name</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">レース名</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">grade</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">G1, G2, G3, OP等</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">track_type</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">芝/ダート/障害</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">distance</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">800〜4250(m)</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">course_condition</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">良/稍重/重/不良</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">finish_position</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">着順 (1〜18 or 中止/失格)</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">frame_number</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">1〜8</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">horse_number</td><td class="px-2 py-1 border-r border-t text-red-500">●</td><td class="px-2 py-1 border-t">1〜18</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">horse_name</td><td class="px-2 py-1 border-r border-t text-red-500">●</td><td class="px-2 py-1 border-t">馬名</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">sex</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">牡/牝/セ</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">age</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">2〜12</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">weight_carried</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">斤量(kg)</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">jockey_name</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">騎手名</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">trainer_name</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">調教師名</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">time</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">タイム 1:23.4</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">margin</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">着差</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">last_3f</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">上り3F</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">corner_positions</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">通過順 (3-3-3)</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">popularity</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">人気</td></tr>
                    <tr><td class="px-2 py-1 border-r border-t">win_odds</td><td class="px-2 py-1 border-r border-t"></td><td class="px-2 py-1 border-t">単勝オッズ</td></tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">※ (race_id, horse_number) で重複判定。同じ組み合わせのレコードは更新されます。</p>
    </div>
</div>
@endsection
