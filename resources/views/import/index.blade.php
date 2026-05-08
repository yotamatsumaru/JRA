@extends('layouts.app')
@section('title', 'データ取込')

@section('content')
<div class="space-y-6">

    <x-page-header title="データ取込" subtitle="4つの方法でレースデータを取り込めます" icon="upload" />

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="{{ route('import.netkeiba') }}" class="group bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 hover:shadow-lg hover:ring-sky-300 dark:hover:ring-sky-700 transition-all p-6 block">
            <div class="flex items-start space-x-4">
                <div class="w-12 h-12 rounded-lg bg-sky-100 dark:bg-sky-900/40 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <x-icon name="globe" class="w-6 h-6 text-sky-600 dark:text-sky-400" />
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-sky-700 dark:text-sky-300">netkeibaから取込</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">レースID（12桁）またはURLを指定してレース結果を自動取得</p>
                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-2 flex items-center space-x-1">
                        <x-icon name="info" class="w-3 h-3" />
                        <span>5秒間隔のレートリミットを自動適用</span>
                    </p>
                </div>
            </div>
        </a>

        <a href="{{ route('import.csv') }}" class="group bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 hover:shadow-lg hover:ring-emerald-300 dark:hover:ring-emerald-700 transition-all p-6 block">
            <div class="flex items-start space-x-4">
                <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <x-icon name="document" class="w-6 h-6 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-emerald-700 dark:text-emerald-300">CSV取込</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">CSVファイルから一括登録。Excel等で整形したデータを取込</p>
                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-2 flex items-center space-x-1">
                        <x-icon name="info" class="w-3 h-3" />
                        <span>ヘッダ行必須（race_date, venue_code...）</span>
                    </p>
                </div>
            </div>
        </a>

        <a href="{{ route('import.image') }}" class="group bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 hover:shadow-lg hover:ring-purple-300 dark:hover:ring-purple-700 transition-all p-6 block">
            <div class="flex items-start space-x-4">
                <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <x-icon name="camera" class="w-6 h-6 text-purple-600 dark:text-purple-400" />
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-purple-700 dark:text-purple-300">画像取込（GPT-4o Vision）</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">出馬表・結果票のスクリーンショット画像をAIが解析</p>
                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-2 flex items-center space-x-1">
                        <x-icon name="info" class="w-3 h-3" />
                        <span>OPENAI_API_KEY 必要</span>
                    </p>
                </div>
            </div>
        </a>

        <a href="{{ route('races.create') }}" class="group bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 hover:shadow-lg hover:ring-turf-300 dark:hover:ring-turf-700 transition-all p-6 block">
            <div class="flex items-start space-x-4">
                <div class="w-12 h-12 rounded-lg bg-turf-100 dark:bg-turf-900/40 flex items-center justify-center group-hover:scale-110 transition-transform">
                    <x-icon name="pencil" class="w-6 h-6 text-turf-600 dark:text-turf-400" />
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-bold text-turf-700 dark:text-turf-300">手入力</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">レース情報や結果を直接フォームから登録</p>
                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-2 flex items-center space-x-1">
                        <x-icon name="info" class="w-3 h-3" />
                        <span>1レース単位の登録</span>
                    </p>
                </div>
            </div>
        </a>
    </div>

    {{-- 直近の取込ログ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex justify-between items-center mb-3">
            <div class="flex items-center space-x-2">
                <x-icon name="clock" class="w-5 h-5 text-turf-600 dark:text-turf-400" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">直近の取込ログ</h2>
            </div>
            <a href="{{ route('import.logs') }}" class="text-sm text-turf-600 dark:text-turf-400 hover:underline flex items-center space-x-1">
                <span>すべて表示</span>
                <x-icon name="arrow-right" class="w-3 h-3" />
            </a>
        </div>
        @if ($recentLogs->isEmpty())
            <x-empty-state icon="list" title="まだ取込ログがありません" message="上のメニューから取込を開始しましょう" />
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400 border-b dark:border-gray-700 uppercase">
                    <tr>
                        <th class="text-left py-2 px-2">日時</th>
                        <th class="text-left py-2 px-2">種別</th>
                        <th class="text-left py-2 px-2">参照</th>
                        <th class="py-2 px-2">ステータス</th>
                        <th class="py-2 px-2">取込/総</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentLogs as $log)
                    <tr class="border-b dark:border-gray-700 hover:bg-turf-50/40 dark:hover:bg-gray-700/40">
                        <td class="py-2 px-2 text-xs text-gray-600 dark:text-gray-400">{{ $log->created_at->format('Y/m/d H:i') }}</td>
                        <td class="py-2 px-2">
                            <span class="text-xs bg-gray-200 dark:bg-gray-700 dark:text-gray-200 px-2 py-0.5 rounded">{{ $log->source }}</span>
                        </td>
                        <td class="py-2 px-2 text-xs dark:text-gray-300">{{ \Str::limit($log->reference, 40) }}</td>
                        <td class="py-2 px-2 text-center">
                            @php
                                $statusClass = match($log->status) {
                                    'success'    => 'bg-turf-100 text-turf-700 dark:bg-turf-900/40 dark:text-turf-300',
                                    'failed'     => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                    'partial'    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                    'processing' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
                                    default      => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                };
                            @endphp
                            <span class="text-xs px-2 py-0.5 rounded font-medium {{ $statusClass }}">{{ $log->status }}</span>
                        </td>
                        <td class="py-2 px-2 text-center text-xs dark:text-gray-300">{{ $log->records_imported ?? 0 }}/{{ $log->records_total ?? 0 }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
