@extends('layouts.app')
@section('title', '推奨 重み設定')

@section('content')
<div class="space-y-4" x-data="weightForm({
    pedigree: {{ (int) $settings['weights']['pedigree'] }},
    jockey:   {{ (int) $settings['weights']['jockey'] }},
    horse:    {{ (int) $settings['weights']['horse'] }},
    roi:      {{ (int) $settings['weights']['roi'] }},
    frame:    {{ (int) ($settings['weights']['frame']  ?? 10) }},
    course:   {{ (int) ($settings['weights']['course'] ?? 10) }},
    style:    {{ (int) ($settings['weights']['style']  ?? 5) }},
})">
    <h1 class="inline-flex items-center gap-2 text-xl sm:text-2xl font-bold text-gray-800">
        <x-icon name="cog" class="w-6 h-6 text-amber-500" />
        <span>推奨スコアリング 重み設定</span>
    </h1>
    <p class="text-xs sm:text-sm text-gray-600">
        7つのサブスコア(血統/騎手/馬/回収/枠/コース/脚質)の重みと最低出走数を調整します。設定はあなたのセッションに保存され、推奨機能(A/B/C)全てに反映されます。
    </p>

    @include('analytics.recommend._nav', ['active' => 'settings'])

    @if (session('success'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-2 rounded text-sm inline-flex items-center gap-1.5">
            <x-icon name="badge-check" class="w-4 h-4" /><span>{{ session('success') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('analytics.recommend.settings.store') }}" class="bg-white rounded-lg shadow p-5 space-y-5">
        @csrf

        {{-- 重みプリセット --}}
        <div class="border-b pb-4">
            <div class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-gray-700 mb-2"><x-icon name="list" class="w-4 h-4" /><span>プリセット</span></div>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="applyPreset({pedigree:15,jockey:20,horse:30,roi:10,frame:10,course:10,style:5})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs bg-gray-100 hover:bg-amber-100 text-gray-700 hover:text-amber-700">
                    <x-icon name="target" class="w-3.5 h-3.5" />
                    <span>標準(7軸バランス)</span>
                </button>
                <button type="button" @click="applyPreset({pedigree:30,jockey:25,horse:35,roi:10,frame:0,course:0,style:0})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs bg-gray-100 hover:bg-gray-200 text-gray-700">
                    <x-icon name="list" class="w-3.5 h-3.5" />
                    <span>従来(血統30/騎手25/馬35/ROI10)</span>
                </button>
                <button type="button" @click="applyPreset({pedigree:45,jockey:10,horse:20,roi:10,frame:5,course:5,style:5})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs bg-gray-100 hover:bg-purple-100 text-gray-700 hover:text-purple-700">
                    <x-icon name="beaker" class="w-3.5 h-3.5" />
                    <span>血統重視(父系45)</span>
                </button>
                <button type="button" @click="applyPreset({pedigree:10,jockey:10,horse:50,roi:5,frame:10,course:10,style:5})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs bg-gray-100 hover:bg-rose-100 text-gray-700 hover:text-rose-700">
                    <x-icon name="horse" class="w-3.5 h-3.5" />
                    <span>個体重視(馬50)</span>
                </button>
                <button type="button" @click="applyPreset({pedigree:15,jockey:15,horse:15,roi:15,frame:15,course:15,style:10})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs bg-gray-100 hover:bg-sky-100 text-gray-700 hover:text-sky-700">
                    <x-icon name="scale" class="w-3.5 h-3.5" />
                    <span>均等(7軸)</span>
                </button>
                <button type="button" @click="applyPreset({pedigree:15,jockey:10,horse:15,roi:35,frame:10,course:10,style:5})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs bg-gray-100 hover:bg-emerald-100 text-gray-700 hover:text-emerald-700">
                    <x-icon name="cash" class="w-3.5 h-3.5" />
                    <span>穴狙い(ROI35)</span>
                </button>
                <button type="button" @click="applyPreset({pedigree:10,jockey:15,horse:15,roi:5,frame:20,course:20,style:15})"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded text-xs bg-gray-100 hover:bg-teal-100 text-gray-700 hover:text-teal-700">
                    <x-icon name="map" class="w-3.5 h-3.5" />
                    <span>展開重視(枠/コース/脚質55)</span>
                </button>
            </div>
        </div>

        {{-- スライダー4本 --}}
        <div class="space-y-5">
            @php
                $sliders = [
                    [
                        'key' => 'pedigree',
                        'icon' => 'beaker',
                        'label' => '血統(父60% / 母父40%)',
                        'desc' => '該当条件での父・母父の複勝率を合成。複勝率50%で100点。',
                        'color' => 'purple',
                    ],
                    [
                        'key' => 'jockey',
                        'icon' => 'user',
                        'label' => '騎手 × 競馬場×トラック',
                        'desc' => '騎手の同競馬場×同トラックでの複勝率。距離・馬場は加味しない(サンプル確保)。',
                        'color' => 'sky',
                    ],
                    [
                        'key' => 'horse',
                        'icon' => 'horse',
                        'label' => '馬の過去走',
                        'desc' => '同距離±200m or 同トラックの複勝率(0〜80点)+ 直近5走の3着内回数(最大+20点)。',
                        'color' => 'rose',
                    ],
                    [
                        'key' => 'roi',
                        'icon' => 'cash',
                        'label' => '回収率ボーナス',
                        'desc' => '父系の複勝回収率が100%を超えた分を加点。妙味血統馬を後押し。',
                        'color' => 'amber',
                    ],
                    [
                        'key' => 'frame',
                        'icon' => 'grid',
                        'label' => '枠順 × 同コース',
                        'desc' => '同枠×同コース類似条件(距離±200m)の過去複勝率。サンプル不足時は段階的に条件を緩和して評価。',
                        'color' => 'teal',
                    ],
                    [
                        'key' => 'course',
                        'icon' => 'map',
                        'label' => 'コース(右/左) × 同馬',
                        'desc' => 'この馬の同方向(右回り/左回り) での過去複勝率。最低3走で評価(若駒救済)。',
                        'color' => 'indigo',
                    ],
                    [
                        'key' => 'style',
                        'icon' => 'bolt',
                        'label' => '脚質 × 想定ペース',
                        'desc' => '想定ペースに脚質が合うかをマッピング。スロー×逃/先=高、ハイ×差/追=高。',
                        'color' => 'pink',
                    ],
                ];
            @endphp

            @foreach ($sliders as $s)
                @php
                    $colorClasses = [
                        'purple' => ['accent-purple-500','text-purple-700','bg-purple-500'],
                        'sky'    => ['accent-sky-500',   'text-sky-700',   'bg-sky-500'],
                        'rose'   => ['accent-rose-500',  'text-rose-700',  'bg-rose-500'],
                        'amber'  => ['accent-amber-500', 'text-amber-700', 'bg-amber-500'],
                        'teal'   => ['accent-teal-500',  'text-teal-700',  'bg-teal-500'],
                        'indigo' => ['accent-indigo-500','text-indigo-700','bg-indigo-500'],
                        'pink'   => ['accent-pink-500',  'text-pink-700',  'bg-pink-500'],
                    ][$s['color']];
                @endphp
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="inline-flex items-center gap-1.5 font-semibold text-sm text-gray-800"><x-icon :name="$s['icon']" class="w-4 h-4" /><span>{{ $s['label'] }}</span></label>
                        <div class="text-xs text-gray-500 flex items-center gap-3">
                            <span>重み <span class="{{ $colorClasses[1] }} font-bold text-lg" x-text="weights.{{ $s['key'] }}">{{ $settings['weights'][$s['key']] }}</span></span>
                            <span>合成比 <span class="{{ $colorClasses[1] }} font-bold" x-text="pct('{{ $s['key'] }}') + '%'">-</span></span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-2">{{ $s['desc'] }}</p>
                    <div class="flex items-center gap-3">
                        <input type="range" min="0" max="100" step="5"
                               name="weights[{{ $s['key'] }}]"
                               x-model.number="weights.{{ $s['key'] }}"
                               class="flex-1 {{ $colorClasses[0] }}">
                        <input type="number" min="0" max="100" step="1"
                               x-model.number="weights.{{ $s['key'] }}"
                               class="w-20 border rounded px-2 py-1 text-sm text-center">
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded mt-1.5 overflow-hidden">
                        <div class="h-full {{ $colorClasses[2] }} transition-all" :style="`width: ${pct('{{ $s['key'] }}')}%`"></div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- 合計表示 --}}
        <div class="bg-gray-50 rounded p-3 flex items-center justify-between text-sm">
            <span class="text-gray-700">合計重み</span>
            <span class="font-bold text-lg" :class="sum() === 0 ? 'text-rose-600' : 'text-gray-800'" x-text="sum()">-</span>
        </div>
        <p class="inline-flex items-center gap-1.5 text-xs text-gray-500 -mt-3" x-show="sum() === 0">
            <x-icon name="warning" class="w-4 h-4" /><span>合計が0だとスコアは常に0になります。最低でも1つは0より大きい値を設定してください。</span>
        </p>

        {{-- 最低出走数 --}}
        <div class="border-t pt-4">
            <label class="inline-flex items-center gap-1.5 font-semibold text-sm text-gray-800 mb-1"><x-icon name="chart" class="w-4 h-4" /><span>最低出走数</span></label>
            <p class="text-xs text-gray-500 mb-2">
                父・母父・騎手の集計で、この回数未満のサンプルはスコア0として扱います(信頼性確保のため)。
                血統データが少ない時期は5〜10程度、データが充実してきたら20〜30に上げると精度が上がります。
            </p>
            <div class="flex items-center gap-2">
                <input type="number" name="min_runs" min="1" max="500" value="{{ $settings['min_runs'] }}"
                       class="w-28 border rounded px-3 py-1.5 text-sm">
                <span class="text-sm text-gray-600">回以上</span>
                <div class="flex gap-1 ml-3">
                    @foreach ([5, 10, 20, 30, 50] as $preset)
                        <button type="button" onclick="document.querySelector('input[name=min_runs]').value={{ $preset }}"
                                class="px-2 py-1 text-xs rounded bg-gray-100 hover:bg-gray-200">{{ $preset }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- アクション --}}
        <div class="flex flex-wrap gap-3 items-center pt-3 border-t">
            <button type="submit" class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white px-5 py-2 rounded font-medium text-sm">
                <x-icon name="database" class="w-4 h-4" /><span>設定を保存</span>
            </button>
            <a href="{{ route('analytics.recommend.index') }}" class="text-sm text-gray-600 hover:text-gray-800">キャンセル</a>
        </div>
    </form>

    {{-- リセット --}}
    <form method="POST" action="{{ route('analytics.recommend.settings.reset') }}"
          onsubmit="return confirm('デフォルト値(血統20/騎手20/馬25/ROI10/枠10/コース10/脚質5、最低出走数10)に戻しますか?')">
        @csrf
        <button type="submit" class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-700 underline">
            <x-icon name="arrow-left" class="w-3.5 h-3.5" /><span>デフォルトに戻す</span>
        </button>
    </form>
</div>

<script>
function weightForm(initial) {
    const KEYS = ['pedigree','jockey','horse','roi','frame','course','style'];
    return {
        weights: { ...initial },
        sum() {
            let s = 0;
            for (const k of KEYS) s += (this.weights[k] | 0);
            return s;
        },
        pct(key) {
            const s = this.sum();
            if (s <= 0) return 0;
            return Math.round((this.weights[key] | 0) / s * 1000) / 10;
        },
        applyPreset(p) {
            // 既存値を残しつつ、未指定キーは 0 で埋める(従来プリセット対策)
            const next = { ...this.weights };
            for (const k of KEYS) next[k] = (p[k] ?? 0) | 0;
            this.weights = next;
        },
    };
}
</script>
@endsection
