@extends('layouts.app')
@section('title', '競馬場一覧')

@section('content')
<div class="space-y-4">

    <x-page-header title="JRA中央競馬場" subtitle="全10場のコース特性と登録レース" icon="map" />

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($venues as $v)
            <a href="{{ route('venues.show', $v) }}" class="group bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 hover:shadow-lg hover:ring-turf-300 dark:hover:ring-turf-700 transition-all p-5 block">
                <div class="flex justify-between items-start">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 rounded-lg bg-turf-100 dark:bg-turf-900/40 ring-1 ring-turf-200 dark:ring-turf-800 flex items-center justify-center group-hover:scale-105 transition-transform">
                            <x-icon name="map" class="w-6 h-6 text-turf-700 dark:text-turf-300" />
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $v->code }} / {{ $v->region }}</div>
                            <h2 class="text-xl font-bold text-turf-700 dark:text-turf-300">{{ $v->name }}</h2>
                        </div>
                    </div>
                    <span class="text-xs bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300 px-2 py-0.5 rounded font-medium">
                        {{ $v->races_count }}R
                    </span>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-2 text-xs">
                    @if ($v->direction)
                        <div class="bg-gray-50 dark:bg-gray-700/60 rounded px-2 py-1.5">
                            <div class="text-gray-500 dark:text-gray-400">回り</div>
                            <div class="font-semibold text-gray-700 dark:text-gray-200">{{ $v->direction }}</div>
                        </div>
                    @endif
                    @if ($v->turf_straight)
                        <div class="bg-turf-50 dark:bg-turf-900/30 rounded px-2 py-1.5">
                            <div class="text-turf-600 dark:text-turf-400">芝直線</div>
                            <div class="font-semibold text-turf-700 dark:text-turf-300">{{ $v->turf_straight }}m</div>
                        </div>
                    @endif
                    @if ($v->dirt_straight)
                        <div class="bg-sand-50 dark:bg-sand-900/30 rounded px-2 py-1.5">
                            <div class="text-sand-600 dark:text-sand-400">砂直線</div>
                            <div class="font-semibold text-sand-700 dark:text-sand-300">{{ $v->dirt_straight }}m</div>
                        </div>
                    @endif
                </div>

                @if ($v->characteristics)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ $v->characteristics }}</p>
                @endif

                <div class="mt-3 flex items-center justify-end text-xs text-turf-600 dark:text-turf-400 group-hover:translate-x-1 transition-transform">
                    <span>詳細を見る</span>
                    <x-icon name="arrow-right" class="w-3 h-3 ml-1" />
                </div>
            </a>
        @endforeach
    </div>
</div>
@endsection
