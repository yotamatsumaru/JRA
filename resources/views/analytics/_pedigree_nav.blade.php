{{--
    血統分析ページ共通のサブナビゲーション
    使い方: @include('analytics._pedigree_nav', ['active' => 'overview'])
--}}
@php
    $tabs = [
        ['key' => 'overview',   'route' => 'analytics.pedigree.overview',   'label' => '🏠 トップ'],
        ['key' => 'sires',      'route' => 'analytics.pedigree.sires',      'label' => '👑 父ランキング'],
        ['key' => 'broodmares', 'route' => 'analytics.pedigree.broodmares', 'label' => '🌸 母父ランキング'],
        ['key' => 'heatmap',    'route' => 'analytics.pedigree.heatmap',    'label' => '🔥 ヒートマップ'],
        ['key' => 'detail',     'route' => 'analytics.pedigree',            'label' => '🔎 父詳細(ドリルダウン)'],
    ];
    $active = $active ?? 'overview';
@endphp
<div class="bg-white rounded-lg shadow border border-gray-100 p-2 flex flex-wrap gap-1.5">
    @foreach ($tabs as $t)
        <a href="{{ route($t['route']) }}"
           class="px-3 py-1.5 rounded text-xs sm:text-sm transition
                  {{ $active === $t['key']
                       ? 'bg-purple-600 text-white font-bold'
                       : 'bg-gray-50 text-gray-700 hover:bg-purple-50 hover:text-purple-700' }}">
            {{ $t['label'] }}
        </a>
    @endforeach
</div>
