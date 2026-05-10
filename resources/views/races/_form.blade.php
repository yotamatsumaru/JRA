{{-- レース共通フォーム --}}
<div class="grid grid-cols-2 md:grid-cols-3 gap-4">
    <div class="col-span-2 md:col-span-3">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">レース名 <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $race?->name) }}" required class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">競馬場 <span class="text-red-500">*</span></label>
        <select name="venue_id" required class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
            <option value="">選択</option>
            @foreach ($venues as $v)
                <option value="{{ $v->id }}" @selected(old('venue_id', $race?->venue_id) == $v->id)>{{ $v->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">開催日 <span class="text-red-500">*</span></label>
        <input type="date" name="race_date" value="{{ old('race_date', $race?->race_date?->format('Y-m-d')) }}" required class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">R番号 <span class="text-red-500">*</span></label>
        <input type="number" name="race_number" min="1" max="12" value="{{ old('race_number', $race?->race_number) }}" required class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">回</label>
        <input type="number" name="kaisai_kai" min="1" max="10" value="{{ old('kaisai_kai', $race?->kaisai_kai) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">日</label>
        <input type="number" name="kaisai_day" min="1" max="12" value="{{ old('kaisai_day', $race?->kaisai_day) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">グレード</label>
        <select name="grade" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
            <option value="">なし</option>
            @foreach (['G1','G2','G3','OP','L','3勝','2勝','1勝','未勝利','新馬'] as $g)
                <option value="{{ $g }}" @selected(old('grade', $race?->grade) == $g)>{{ $g }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">クラス</label>
        <input type="text" name="race_class" value="{{ old('race_class', $race?->race_class) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">トラック <span class="text-red-500">*</span></label>
        <select name="track_type" required class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
            @foreach (['芝','ダート','障害'] as $t)
                <option value="{{ $t }}" @selected(old('track_type', $race?->track_type) == $t)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">距離(m) <span class="text-red-500">*</span></label>
        <input type="number" name="distance" min="800" max="4250" step="100" value="{{ old('distance', $race?->distance) }}" required class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">回り</label>
        <select name="direction" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
            <option value="">-</option>
            @foreach (['右','左','直線'] as $d)
                <option value="{{ $d }}" @selected(old('direction', $race?->direction) == $d)>{{ $d }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">コース詳細</label>
        <input type="text" name="course_detail" value="{{ old('course_detail', $race?->course_detail) }}" placeholder="A・B・Cコース等" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">馬場</label>
        <select name="course_condition" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
            <option value="">-</option>
            @foreach (['良','稍重','重','不良'] as $c)
                <option value="{{ $c }}" @selected(old('course_condition', $race?->course_condition) == $c)>{{ $c }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">天候</label>
        <input type="text" name="weather" value="{{ old('weather', $race?->weather) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ペース</label>
        <select name="pace" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
            <option value="">-</option>
            @foreach (['H'=>'H(ハイ)','M'=>'M(ミドル)','S'=>'S(スロー)'] as $k=>$v)
                <option value="{{ $k }}" @selected(old('pace', $race?->pace) == $k)>{{ $v }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">頭数</label>
        <input type="number" name="horses_count" min="1" max="18" value="{{ old('horses_count', $race?->horses_count) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">前半3F</label>
        <input type="text" name="first_3f" value="{{ old('first_3f', $race?->first_3f) }}" placeholder="例: 34.5" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">上がり3F</label>
        <input type="text" name="last_3f" value="{{ old('last_3f', $race?->last_3f) }}" placeholder="例: 33.8" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">1着賞金(万円)</label>
        <input type="number" name="first_prize" value="{{ old('first_prize', $race?->first_prize) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <div class="col-span-2 md:col-span-3">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">備考</label>
        <textarea name="notes" rows="2" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">{{ old('notes', $race?->notes) }}</textarea>
    </div>
</div>
