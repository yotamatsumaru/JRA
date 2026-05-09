@extends('layouts.app')

@section('title', '通知')

@section('content')
<div class="container mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <x-icon name="bell" class="w-6 h-6 text-amber-500" />
                通知センター
                @if($unread > 0)
                    <span class="ml-1 inline-flex items-center justify-center px-2 py-0.5 text-xs font-semibold rounded-full bg-rose-500 text-white">{{ $unread }}</span>
                @endif
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">ウォッチリスト出走予定・共有予想期限などをまとめて確認</p>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('notifications.scan') }}">
                @csrf
                <button type="submit" class="px-3 py-1.5 text-sm rounded bg-sky-500 hover:bg-sky-600 text-white inline-flex items-center gap-1.5">
                    <x-icon name="bolt" class="w-4 h-4" /> 再スキャン
                </button>
            </form>
            @if($unread > 0)
                <form method="POST" action="{{ route('notifications.readAll') }}">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 text-sm rounded bg-emerald-500 hover:bg-emerald-600 text-white inline-flex items-center gap-1.5">
                        <x-icon name="check" class="w-4 h-4" /> すべて既読
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- フィルタ --}}
    <div class="mb-4 flex items-center gap-2 text-sm">
        <a href="{{ route('notifications.index', ['filter' => 'all']) }}"
           class="px-3 py-1 rounded {{ $filter === 'all' ? 'bg-turf-600 text-white' : 'bg-gray-100 dark:bg-gray-700 hover:bg-gray-200' }}">すべて</a>
        <a href="{{ route('notifications.index', ['filter' => 'unread']) }}"
           class="px-3 py-1 rounded {{ $filter === 'unread' ? 'bg-turf-600 text-white' : 'bg-gray-100 dark:bg-gray-700 hover:bg-gray-200' }}">未読のみ</a>
    </div>

    @if(session('success'))
        <div class="mb-3 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">{{ session('success') }}</div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded shadow divide-y divide-gray-200 dark:divide-gray-700">
        @forelse($notifications as $n)
            @php
                $isUnread = $n->read_at === null;
                $typeBadge = match($n->type) {
                    'watchlist_entry' => ['出走予定', 'bg-emerald-100 text-emerald-700'],
                    'share_expiring'  => ['共有期限', 'bg-amber-100 text-amber-700'],
                    'system'          => ['システム', 'bg-sky-100 text-sky-700'],
                    default           => ['通知', 'bg-gray-100 text-gray-700'],
                };
            @endphp
            <div class="p-4 flex items-start gap-3 {{ $isUnread ? 'bg-amber-50/40 dark:bg-amber-900/10' : '' }}">
                <div class="pt-1">
                    @if($isUnread)
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                    @else
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-gray-300"></span>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs px-1.5 py-0.5 rounded {{ $typeBadge[1] }}">{{ $typeBadge[0] }}</span>
                        <a href="{{ route('notifications.read', $n->id) }}" class="font-semibold text-gray-900 dark:text-gray-100 hover:underline truncate">
                            {{ $n->title }}
                        </a>
                        <span class="text-xs text-gray-400 ml-auto whitespace-nowrap">{{ $n->created_at?->diffForHumans() }}</span>
                    </div>
                    @if($n->body)
                        <div class="text-sm text-gray-700 dark:text-gray-300 mt-1 break-words">{{ $n->body }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('notifications.destroy', $n->id) }}" onsubmit="return confirm('削除しますか?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="p-1.5 text-gray-400 hover:text-rose-500" title="削除">
                        <x-icon name="trash" class="w-4 h-4" />
                    </button>
                </form>
            </div>
        @empty
            <div class="p-8 text-center text-gray-500">
                通知はありません。
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
</div>
@endsection
