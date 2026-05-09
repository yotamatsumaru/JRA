{{-- 馬券登録共通フォーム
    使い方:
        @include('bets._form', ['bet' => $bet ?? null, 'race' => $race ?? null])
--}}

@php
    $b = $bet ?? null;
    $sel = $b?->selection ?? [];
    $defKind   = old('kind',   $b?->kind   ?? 'tan');
    $defMethod = old('method', $b?->method ?? 'single');
    $defStake  = old('unit_stake', $b?->unit_stake ?? 100);
    $defRaceId = old('race_id', $b?->race_id ?? request('race_id') ?? $race?->id);
@endphp

<div
    x-data="betForm({
        kind: @js($defKind),
        method: @js($defMethod),
        numbers: @js(old('numbers', $sel['numbers'] ?? [])),
        axis:    @js(old('axis',    $sel['axis']    ?? [])),
        second:  @js(old('second',  $sel['second']  ?? [])),
        third:   @js(old('third',   $sel['third']   ?? [])),
        unitStake: @js((int) $defStake),
        horsesCount: @js(($race ?? $b?->race)?->horses_count ?? 18),
    })"
    class="space-y-5"
>
    {{-- レース選択 --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">レース <span class="text-red-500">*</span></label>
        <select name="race_id" required class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
            <option value="">選択してください</option>
            @foreach ($races as $r)
                <option value="{{ $r->id }}" @selected((int) $defRaceId === $r->id)>
                    {{ $r->race_date?->format('Y/m/d') }} {{ $r->venue?->name }} {{ $r->race_number }}R - {{ $r->name }}
                </option>
            @endforeach
        </select>
        <p class="text-xs text-gray-500 mt-1">表示は直近100レース。レースが無い場合は<a href="{{ route('races.create') }}" class="text-turf-600 hover:underline">先にレースを登録</a>してください。</p>
    </div>

    {{-- 券種 --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">券種 <span class="text-red-500">*</span></label>
        <div class="grid grid-cols-4 md:grid-cols-8 gap-2">
            @foreach ($kinds as $k => $label)
                <label class="cursor-pointer">
                    <input type="radio" name="kind" value="{{ $k }}" x-model="kind" class="peer hidden">
                    <div class="border-2 rounded-md py-2 text-center text-sm font-medium transition
                                border-gray-200 dark:border-gray-600
                                peer-checked:border-turf-600 peer-checked:bg-turf-50 peer-checked:text-turf-700
                                dark:peer-checked:bg-turf-900/30 dark:peer-checked:text-turf-300
                                hover:border-turf-300">
                        {{ $label }}
                    </div>
                </label>
            @endforeach
        </div>
    </div>

    {{-- 買い方 --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">買い方 <span class="text-red-500">*</span></label>
        <div class="grid grid-cols-3 gap-2 max-w-md">
            @foreach ($methods as $m => $label)
                <label class="cursor-pointer">
                    <input type="radio" name="method" value="{{ $m }}" x-model="method" class="peer hidden">
                    <div class="border-2 rounded-md py-2 text-center text-sm font-medium transition
                                border-gray-200 dark:border-gray-600
                                peer-checked:border-gold-500 peer-checked:bg-gold-50 peer-checked:text-gold-700
                                dark:peer-checked:bg-gold-900/30 dark:peer-checked:text-gold-300
                                hover:border-gold-300">
                        {{ $label }}
                    </div>
                </label>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 mt-1">
            <span x-show="method === 'single'">単発: 馬番をそのまま選択（例: 馬連 3-7）</span>
            <span x-show="method === 'box'" x-cloak>ボックス: 選んだ頭数の中で全組合せ自動展開</span>
            <span x-show="method === 'formation'" x-cloak>フォーメーション: 軸 → 相手 → (3着) を指定して流す</span>
        </p>
    </div>

    {{-- 馬番選択UI（券種・買い方で切替） --}}
    <div class="bg-gray-50 dark:bg-gray-900/40 rounded-lg p-4 space-y-3">

        {{-- single --}}
        <div x-show="method === 'single'" x-cloak>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">
                馬番選択
                <span class="text-xs text-gray-500">(<span x-text="sizeOf(kind)"></span>頭、<span x-text="orderedOf(kind) ? '順序あり' : '順不同'"></span>)</span>
            </label>
            <div class="flex flex-wrap gap-1.5" x-show="!orderedOf(kind)">
                <template x-for="n in horseRange()" :key="n">
                    <label class="cursor-pointer">
                        <input type="checkbox" :name="'numbers[]'" :value="n" x-model.number="numbers" class="peer hidden">
                        <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center text-sm font-bold transition
                                    border-gray-300 bg-white text-gray-700
                                    peer-checked:border-turf-600 peer-checked:bg-turf-600 peer-checked:text-white"
                             x-text="n"></div>
                    </label>
                </template>
            </div>

            {{-- 順序あり券種（単勝/複勝/馬単/3連単）は順序保持セレクト --}}
            <div x-show="orderedOf(kind)" x-cloak class="space-y-2">
                <template x-for="i in sizeOf(kind)" :key="i">
                    <div class="flex items-center space-x-2">
                        <span class="text-xs text-gray-500 w-12" x-text="(i)+'着'"></span>
                        <select :name="'numbers[]'" class="border rounded px-2 py-1 dark:bg-gray-700 dark:border-gray-600 w-32"
                                x-init="$el.value = numbers[i-1] ?? ''"
                                @change="numbers[i-1] = parseInt($event.target.value, 10) || 0; numbers = [...numbers]">
                            <option value="">-</option>
                            <template x-for="n in horseRange()" :key="n">
                                <option :value="n" :selected="numbers[i-1] === n" x-text="n"></option>
                            </template>
                        </select>
                    </div>
                </template>
            </div>
        </div>

        {{-- box --}}
        <div x-show="method === 'box'" x-cloak>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">
                ボックス選択 <span class="text-xs text-gray-500">(<span x-text="sizeOf(kind)"></span>頭以上を選択)</span>
            </label>
            <div class="flex flex-wrap gap-1.5">
                <template x-for="n in horseRange()" :key="n">
                    <label class="cursor-pointer">
                        <input type="checkbox" :name="'numbers[]'" :value="n" x-model.number="numbers" class="peer hidden">
                        <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center text-sm font-bold transition
                                    border-gray-300 bg-white text-gray-700
                                    peer-checked:border-turf-600 peer-checked:bg-turf-600 peer-checked:text-white"
                             x-text="n"></div>
                    </label>
                </template>
            </div>
        </div>

        {{-- formation --}}
        <div x-show="method === 'formation'" x-cloak class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-turf-700 dark:text-turf-300 mb-2">
                    <span x-text="orderedOf(kind) ? '1着 (軸)' : '軸馬'"></span>
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="n in horseRange()" :key="n">
                        <label class="cursor-pointer">
                            <input type="checkbox" :name="'axis[]'" :value="n" x-model.number="axis" class="peer hidden">
                            <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center text-sm font-bold transition
                                        border-gray-300 bg-white text-gray-700
                                        peer-checked:border-turf-600 peer-checked:bg-turf-600 peer-checked:text-white"
                                 x-text="n"></div>
                        </label>
                    </template>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-sky-700 dark:text-sky-300 mb-2">
                    <span x-text="orderedOf(kind) ? '2着 (相手)' : '相手'"></span>
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="n in horseRange()" :key="n">
                        <label class="cursor-pointer">
                            <input type="checkbox" :name="'second[]'" :value="n" x-model.number="second" class="peer hidden">
                            <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center text-sm font-bold transition
                                        border-gray-300 bg-white text-gray-700
                                        peer-checked:border-sky-600 peer-checked:bg-sky-600 peer-checked:text-white"
                                 x-text="n"></div>
                        </label>
                    </template>
                </div>
            </div>
            <div x-show="sizeOf(kind) === 3" x-cloak>
                <label class="block text-sm font-medium text-rose-700 dark:text-rose-300 mb-2">
                    <span x-text="orderedOf(kind) ? '3着' : '3列目'"></span>
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="n in horseRange()" :key="n">
                        <label class="cursor-pointer">
                            <input type="checkbox" :name="'third[]'" :value="n" x-model.number="third" class="peer hidden">
                            <div class="w-10 h-10 rounded-full border-2 flex items-center justify-center text-sm font-bold transition
                                        border-gray-300 bg-white text-gray-700
                                        peer-checked:border-rose-600 peer-checked:bg-rose-600 peer-checked:text-white"
                                 x-text="n"></div>
                        </label>
                    </template>
                </div>
            </div>
        </div>

        {{-- プレビュー --}}
        <div class="mt-3 p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-600 dark:text-gray-400">点数 / 投資額</span>
                <div class="text-right">
                    <span class="text-2xl font-bold text-turf-700 dark:text-turf-300" x-text="previewPoints()"></span>
                    <span class="text-sm text-gray-500">点</span>
                    <span class="ml-3 text-xl font-bold text-gold-600" x-text="'¥' + (previewPoints() * unitStake).toLocaleString()"></span>
                </div>
            </div>
            <div class="text-xs text-gray-500 mt-2 break-all" x-show="previewSample().length > 0">
                <span class="text-gray-400">サンプル:</span>
                <span x-text="previewSample().slice(0, 8).join(' / ') + (previewSample().length > 8 ? ' ... 他 ' + (previewSample().length - 8) + '点' : '')"></span>
            </div>
        </div>
    </div>

    {{-- 1点単価・購入日時・メモ --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">1点単価 (円) <span class="text-red-500">*</span></label>
            <select name="unit_stake" x-model.number="unitStake" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
                @foreach ([100, 200, 300, 500, 1000, 2000, 3000, 5000, 10000] as $s)
                    <option value="{{ $s }}">¥{{ number_format($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">購入日時</label>
            <input type="datetime-local" name="purchased_at"
                value="{{ old('purchased_at', $b?->purchased_at?->format('Y-m-d\TH:i')) }}"
                class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">メモ</label>
        <textarea name="memo" rows="2" placeholder="買った理由・反省など"
            class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">{{ old('memo', $b?->memo) }}</textarea>
    </div>
</div>

<script>
function betForm(init) {
    return {
        kind: init.kind,
        method: init.method,
        numbers: Array.isArray(init.numbers) ? init.numbers.map(Number) : [],
        axis: Array.isArray(init.axis) ? init.axis.map(Number) : [],
        second: Array.isArray(init.second) ? init.second.map(Number) : [],
        third: Array.isArray(init.third) ? init.third.map(Number) : [],
        unitStake: init.unitStake || 100,
        horsesCount: init.horsesCount || 18,

        sizeOf(k) {
            return ({ 'tan':1,'fuku':1,'waku-ren':2,'uma-ren':2,'uma-tan':2,'wide':2,'san-fuku':3,'san-tan':3 })[k] ?? 1;
        },
        orderedOf(k) {
            return ['tan','fuku','uma-tan','san-tan'].includes(k);
        },
        horseRange() {
            const max = Math.min(Math.max(this.horsesCount || 18, 8), 18);
            return Array.from({length: max}, (_, i) => i + 1);
        },

        // プレビュー組合せ計算
        previewSample() {
            try {
                if (this.method === 'single') {
                    const nums = (this.numbers || []).filter(n => n > 0);
                    if (nums.length !== this.sizeOf(this.kind)) return [];
                    return [this.norm(nums)];
                }
                if (this.method === 'box') {
                    const pool = uniq(this.numbers || []);
                    const size = this.sizeOf(this.kind);
                    if (pool.length < size) return [];
                    const tuples = this.orderedOf(this.kind) ? perm(pool, size) : comb(pool, size);
                    return tuples.map(t => this.norm(t));
                }
                if (this.method === 'formation') {
                    const size = this.sizeOf(this.kind);
                    const a = uniq(this.axis), b = uniq(this.second), c = uniq(this.third);
                    if (!a.length || !b.length) return [];
                    if (size === 3 && !c.length) return [];

                    const out = [];
                    if (size === 2) {
                        for (const x of a) for (const y of b) if (x !== y) out.push(this.norm([x, y]));
                    } else if (size === 3) {
                        for (const x of a) for (const y of b) for (const z of c) {
                            if (x === y || x === z || y === z) continue;
                            out.push(this.norm([x, y, z]));
                        }
                    } else {
                        for (const x of a) out.push(this.norm([x]));
                    }
                    return [...new Set(out)];
                }
            } catch (e) { console.error(e); }
            return [];
        },
        previewPoints() {
            return this.previewSample().length;
        },
        norm(arr) {
            const a = arr.map(Number).filter(n => n > 0);
            if (!this.orderedOf(this.kind)) {
                return [...new Set(a)].sort((x, y) => x - y).join('-');
            }
            return a.join('-');
        }
    };

    function uniq(a) { return [...new Set((a || []).map(Number).filter(n => n > 0))]; }
    function comb(arr, r) {
        if (r === 0) return [[]];
        if (arr.length < r) return [];
        const [h, ...rest] = arr;
        return [...comb(rest, r - 1).map(c => [h, ...c]), ...comb(rest, r)];
    }
    function perm(arr, r) {
        if (r === 0) return [[]];
        const out = [];
        for (let i = 0; i < arr.length; i++) {
            const rest = [...arr.slice(0, i), ...arr.slice(i + 1)];
            for (const tail of perm(rest, r - 1)) out.push([arr[i], ...tail]);
        }
        return out;
    }
}

// グローバル関数（Alpineスコープ外でも使えるように）
function uniq(a) { return [...new Set((a || []).map(Number).filter(n => n > 0))]; }
function comb(arr, r) {
    if (r === 0) return [[]];
    if (arr.length < r) return [];
    const [h, ...rest] = arr;
    return [...comb(rest, r - 1).map(c => [h, ...c]), ...comb(rest, r)];
}
function perm(arr, r) {
    if (r === 0) return [[]];
    const out = [];
    for (let i = 0; i < arr.length; i++) {
        const rest = [...arr.slice(0, i), ...arr.slice(i + 1)];
        for (const tail of perm(rest, r - 1)) out.push([arr[i], ...tail]);
    }
    return out;
}
</script>
