@extends('layouts.app')
@section('title', 'データ取込')

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">データ取込</h1>
    <p class="text-sm text-gray-600">レースデータを4つの方法で取り込めます。用途に応じて使い分けてください。</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="{{ route('import.netkeiba') }}" class="bg-white rounded-lg shadow hover:shadow-lg transition p-6 block">
            <div class="text-3xl mb-2">🌐</div>
            <h2 class="text-lg font-bold text-primary-700">netkeiba から取込</h2>
            <p class="text-sm text-gray-600 mt-2">netkeiba.com のレースID（12桁）またはURLを指定してレース結果を自動取得します。5年分の過去データ取得にも対応。</p>
            <p class="text-xs text-gray-500 mt-2">※ 5秒間隔のレートリミットを自動適用</p>
        </a>

        <a href="{{ route('import.csv') }}" class="bg-white rounded-lg shadow hover:shadow-lg transition p-6 block">
            <div class="text-3xl mb-2">📄</div>
            <h2 class="text-lg font-bold text-primary-700">CSV取込</h2>
            <p class="text-sm text-gray-600 mt-2">CSVファイルから一括登録。Excel等で整形したデータを取り込めます。</p>
            <p class="text-xs text-gray-500 mt-2">※ ヘッダ行必須（race_date, venue_code, ...）</p>
        </a>

        <a href="{{ route('import.image') }}" class="bg-white rounded-lg shadow hover:shadow-lg transition p-6 block">
            <div class="text-3xl mb-2">📸</div>
            <h2 class="text-lg font-bold text-primary-700">画像取込（GPT-4o Vision）</h2>
            <p class="text-sm text-gray-600 mt-2">出馬表・結果票のスクリーンショット画像をAIが解析してデータ化します。</p>
            <p class="text-xs text-gray-500 mt-2">※ OPENAI_API_KEY 必要</p>
        </a>

        <a href="{{ route('races.create') }}" class="bg-white rounded-lg shadow hover:shadow-lg transition p-6 block">
            <div class="text-3xl mb-2">✍️</div>
            <h2 class="text-lg font-bold text-primary-700">手入力</h2>
            <p class="text-sm text-gray-600 mt-2">レース情報や結果を直接フォームから登録します。</p>
            <p class="text-xs text-gray-500 mt-2">※ 1レース単位</p>
        </a>
    </div>

    {{-- 直近の取込ログ --}}
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex justify-between items-center mb-3">
            <h2 class="font-semibold text-gray-700">直近の取込ログ</h2>
            <a href="{{ route('import.logs') }}" class="text-sm text-primary-600 hover:underline">すべて表示 →</a>
        </div>
        @if ($recentLogs->isEmpty())
            <p class="text-sm text-gray-500">まだ取込ログがありません</p>
        @else
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 border-b">
                <tr>
                    <th class="text-left py-1">日時</th>
                    <th class="text-left py-1">種別</th>
                    <th class="text-left py-1">参照</th>
                    <th class="py-1">ステータス</th>
                    <th class="py-1">取込/総</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recentLogs as $log)
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-1 text-xs text-gray-600">{{ $log->created_at->format('Y/m/d H:i') }}</td>
                    <td class="py-1"><span class="text-xs bg-gray-200 px-2 py-0.5 rounded">{{ $log->source }}</span></td>
                    <td class="py-1 text-xs">{{ \Str::limit($log->reference, 40) }}</td>
                    <td class="py-1 text-center">
                        @php
                            $colors = ['success' => 'green', 'failed' => 'red', 'partial' => 'amber', 'processing' => 'blue'];
                            $c = $colors[$log->status] ?? 'gray';
                        @endphp
                        <span class="text-xs bg-{{ $c }}-100 text-{{ $c }}-700 px-2 py-0.5 rounded">{{ $log->status }}</span>
                    </td>
                    <td class="py-1 text-center">{{ $log->records_imported ?? 0 }}/{{ $log->records_total ?? 0 }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endsection
