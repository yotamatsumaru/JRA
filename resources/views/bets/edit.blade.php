@extends('layouts.app')
@section('title', '馬券編集')

@section('content')
<div class="max-w-4xl mx-auto space-y-4">
    <x-page-header title="馬券編集" subtitle="買い目を変更すると組合せが再展開されます" icon="edit" />

    {{-- 更新フォーム --}}
    <form method="POST" action="{{ route('bets.update', $bet) }}" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-4">
        @csrf
        @method('PUT')
        @include('bets._form', ['bet' => $bet, 'race' => $bet->race])

        <div class="flex justify-end space-x-2 pt-3 border-t border-gray-100 dark:border-gray-700">
            <a href="{{ route('bets.show', $bet) }}" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 px-4 py-2">キャンセル</a>
            <button type="submit" class="bg-turf-600 hover:bg-turf-700 text-white px-6 py-2 rounded inline-flex items-center space-x-1">
                <x-icon name="check" class="w-4 h-4" /><span>更新する</span>
            </button>
        </div>
    </form>

    {{-- 削除（フォーム外に独立配置） --}}
    <div class="flex justify-start">
        <x-confirm-delete
            :action="route('bets.destroy', $bet)"
            title="馬券削除確認"
            message="この馬券（{{ $bet->points }}点 / ¥{{ number_format($bet->total_stake) }}）を削除します。"
            label="この馬券を削除" />
    </div>
</div>
@endsection
