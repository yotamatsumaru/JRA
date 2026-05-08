@extends('layouts.app')
@section('title', '競馬場一覧')

@section('content')
<div class="space-y-4">
    <h1 class="text-2xl font-bold text-gray-800">JRA中央競馬場（10場）</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($venues as $v)
            <a href="{{ route('venues.show', $v) }}" class="bg-white rounded-lg shadow hover:shadow-lg transition p-5 block">
                <div class="flex justify-between items-start">
                    <div>
                        <div class="text-xs text-gray-500">{{ $v->code }} / {{ $v->region }}</div>
                        <h2 class="text-xl font-bold text-primary-700 mt-1">{{ $v->name }}</h2>
                    </div>
                    <span class="text-xs bg-primary-100 text-primary-700 px-2 py-0.5 rounded">{{ $v->races_count }}R登録</span>
                </div>
                <div class="mt-3 text-sm text-gray-600 space-y-1">
                    @if ($v->direction) <div>回り: {{ $v->direction }}</div> @endif
                    @if ($v->turf_straight) <div>芝直線: {{ $v->turf_straight }}m</div> @endif
                    @if ($v->dirt_straight) <div>ダート直線: {{ $v->dirt_straight }}m</div> @endif
                </div>
                @if ($v->characteristics)
                    <p class="mt-3 text-xs text-gray-500 line-clamp-3">{{ $v->characteristics }}</p>
                @endif
            </a>
        @endforeach
    </div>
</div>
@endsection
