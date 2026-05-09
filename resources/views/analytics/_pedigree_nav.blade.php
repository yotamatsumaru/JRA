{{--
    血統分析ページ共通のサブナビゲーション
    使い方: @include('analytics._pedigree_nav', ['active' => 'overview'])
--}}
@php
    $tabs = [
        ['key' => 'overview',   'route' => 'analytics.pedigree.overview',   'icon' => 'home',     'label' => 'トップ'],
        ['key' => 'sires',      'route' => 'analytics.pedigree.sires',      'icon' => 'crown',    'label' => '父ランキング'],
        ['key' => 'broodmares', 'route' => 'analytics.pedigree.broodmares', 'icon' => 'flower',   'label' => '母父ランキング'],
        ['key' => 'heatmap',    'route' => 'analytics.pedigree.heatmap',    'icon' => 'fire',     'label' => 'ヒートマップ'],
        ['key' => 'detail',     'route' => 'analytics.pedigree',            'icon' => 'search',   'label' => '父詳細(ドリルダウン)'],
    ];
    $active = $active ?? 'overview';
@endphp
<div class="bg-white rounded-lg shadow border border-gray-100 p-2 flex flex-wrap gap-1.5">
    @foreach ($tabs as $t)
        <a href="{{ route($t['route']) }}"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs sm:text-sm transition
                  {{ $active === $t['key']
                       ? 'bg-purple-600 text-white font-bold'
                       : 'bg-gray-50 text-gray-700 hover:bg-purple-50 hover:text-purple-700' }}">
            <x-icon :name="$t['icon']" class="w-4 h-4" />
            <span>{{ $t['label'] }}</span>
        </a>
    @endforeach
</div>
