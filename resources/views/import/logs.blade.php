@extends('layouts.app')
@section('title', '取込ログ')

@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-bold text-gray-800">取込ログ</h1>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                <tr>
                    <th class="text-left px-3 py-2">日時</th>
                    <th class="text-left px-3 py-2">ユーザー</th>
                    <th class="text-left px-3 py-2">種別</th>
                    <th class="text-left px-3 py-2">参照</th>
                    <th class="px-3 py-2">ステータス</th>
                    <th class="px-3 py-2">取込</th>
                    <th class="px-3 py-2">スキップ</th>
                    <th class="px-3 py-2">失敗</th>
                    <th class="text-left px-3 py-2">エラー</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-3 py-2 text-xs">{{ $log->created_at->format('Y/m/d H:i:s') }}</td>
                    <td class="px-3 py-2 text-xs">{{ $log->user?->name }}</td>
                    <td class="px-3 py-2"><span class="text-xs bg-gray-200 px-2 py-0.5 rounded">{{ $log->source }}</span></td>
                    <td class="px-3 py-2 text-xs">{{ \Str::limit($log->reference, 50) }}</td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $colors = ['success' => 'green', 'failed' => 'red', 'partial' => 'amber', 'processing' => 'blue'];
                            $c = $colors[$log->status] ?? 'gray';
                        @endphp
                        <span class="text-xs bg-{{ $c }}-100 text-{{ $c }}-700 px-2 py-0.5 rounded">{{ $log->status }}</span>
                    </td>
                    <td class="px-3 py-2 text-center text-emerald-700">{{ $log->records_imported ?? 0 }}</td>
                    <td class="px-3 py-2 text-center text-gray-500">{{ $log->records_skipped ?? 0 }}</td>
                    <td class="px-3 py-2 text-center text-red-600">{{ $log->records_failed ?? 0 }}</td>
                    <td class="px-3 py-2 text-xs text-red-600">{{ \Str::limit($log->error_message, 60) }}</td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-gray-500 py-8">ログがありません</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $logs->links() }}</div>
</div>
@endsection
