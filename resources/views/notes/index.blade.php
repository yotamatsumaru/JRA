@extends('layouts.app')
@section('title', 'メモ一覧')

@section('content')
<div class="space-y-4">

    <x-page-header title="マイメモ" subtitle="レース・馬に紐づくメモ" icon="pencil">
        <x-slot name="actions">
            <a href="{{ route('notes.create') }}" class="inline-flex items-center space-x-1.5 bg-turf-600 hover:bg-turf-700 text-white px-4 py-2 rounded-md text-sm font-medium shadow-sm">
                <x-icon name="plus" class="w-4 h-4" />
                <span>メモを書く</span>
            </a>
        </x-slot>
    </x-page-header>

    @if ($notes->isEmpty())
        <x-empty-state
            icon="pencil"
            title="まだメモがありません"
            message="気になるレースや馬についてメモを残しましょう"
            actionLabel="最初のメモを書く"
            actionHref="{{ route('notes.create') }}"
        />
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($notes as $note)
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 hover:shadow-lg hover:ring-turf-300 dark:hover:ring-turf-700 transition-all p-4">
                    <div class="flex justify-between items-start">
                        <div class="flex-1 min-w-0">
                            @if ($note->title)
                                <h3 class="font-bold text-gray-800 dark:text-gray-100 truncate">{{ $note->title }}</h3>
                            @endif
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex items-center space-x-2 flex-wrap">
                                <span class="inline-flex items-center space-x-1">
                                    <x-icon name="clock" class="w-3 h-3" />
                                    <span>{{ $note->created_at->format('Y/m/d H:i') }}</span>
                                </span>
                                @if ($note->tag)
                                    <span class="inline-flex items-center bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300 px-2 py-0.5 rounded font-medium">
                                        #{{ $note->tag }}
                                    </span>
                                @endif
                            </div>
                            @if ($note->race)
                                <div class="text-xs text-turf-600 dark:text-turf-400 mt-1.5 flex items-center space-x-1">
                                    <x-icon name="flag" class="w-3 h-3" />
                                    <a href="{{ route('races.show', $note->race) }}" class="hover:underline truncate">{{ $note->race->venue?->name }} {{ $note->race->name }}</a>
                                </div>
                            @endif
                            @if ($note->horse)
                                <div class="text-xs text-sand-600 dark:text-sand-400 mt-0.5 flex items-center space-x-1">
                                    <x-icon name="horse" class="w-3 h-3" />
                                    <a href="{{ route('horses.show', $note->horse) }}" class="hover:underline truncate">{{ $note->horse->name }}</a>
                                </div>
                            @endif
                        </div>
                        <div class="flex space-x-1 flex-shrink-0 ml-2">
                            <a href="{{ route('notes.edit', $note) }}" class="inline-flex items-center space-x-1 text-xs text-gray-500 hover:text-turf-700 dark:text-gray-400 dark:hover:text-turf-400 px-2 py-1 rounded hover:bg-turf-50 dark:hover:bg-gray-700">
                                <x-icon name="edit" class="w-3 h-3" />
                                <span>編集</span>
                            </a>
                            <x-confirm-delete
                                :action="route('notes.destroy', $note)"
                                title="メモの削除"
                                message="このメモを削除します。よろしいですか？"
                                label="削除"
                                class="px-2 py-1 rounded hover:bg-red-50 dark:hover:bg-red-900/30"
                            />
                        </div>
                    </div>
                    <div class="mt-3 text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap line-clamp-6">{{ $note->body }}</div>
                </div>
            @endforeach
        </div>
        <div>{{ $notes->withQueryString()->links() }}</div>
    @endif
</div>
@endsection
