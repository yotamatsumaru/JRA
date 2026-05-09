{{--
    推奨機能 共通サブナビゲーション
    使い方: @include('analytics.recommend._nav', ['active' => 'index'])
--}}
@php
    $tabs = [
        ['key' => 'index',      'route' => 'analytics.recommend.index',      'icon' => 'home',     'label' => '推奨トップ',         'enabled' => true],
        ['key' => 'race',       'route' => 'analytics.recommend.race',       'icon' => 'horse',    'label' => '出馬表推奨(A)',      'enabled' => true],
        ['key' => 'conditions', 'route' => 'analytics.recommend.conditions', 'icon' => 'target',   'label' => '条件指定(B)',        'enabled' => true],
        ['key' => 'scan',       'route' => 'analytics.recommend.scan',       'icon' => 'search',   'label' => '全条件スキャン(C)',  'enabled' => true],
        ['key' => 'settings',   'route' => 'analytics.recommend.settings',   'icon' => 'cog',      'label' => '重み設定',           'enabled' => true],
    ];
    $active = $active ?? 'index';
@endphp
<div class="bg-white rounded-lg shadow border border-gray-100 p-2 flex flex-wrap gap-1.5">
    @foreach ($tabs as $t)
        @if ($t['enabled'])
            <a href="{{ route($t['route']) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs sm:text-sm transition
                      {{ $active === $t['key']
                           ? 'bg-amber-500 text-white font-bold'
                           : 'bg-gray-50 text-gray-700 hover:bg-amber-50 hover:text-amber-700' }}">
                <x-icon :name="$t['icon']" class="w-4 h-4" />
                <span>{{ $t['label'] }}</span>
            </a>
        @else
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs sm:text-sm bg-gray-100 text-gray-400 cursor-not-allowed"
                  title="未実装">
                <x-icon :name="$t['icon']" class="w-4 h-4" />
                <span>{{ $t['label'] }}</span>
                <span class="text-[10px] bg-gray-300 text-white px-1 rounded">{{ $t['badge'] ?? '' }}</span>
            </span>
        @endif
    @endforeach
</div>
