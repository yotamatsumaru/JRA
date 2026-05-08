{{-- メモ共通フォーム --}}
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">タイトル</label>
        <input type="text" name="title" value="{{ old('title', $note?->title) }}" maxlength="100" class="w-full border rounded px-3 py-2">
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">関連レース</label>
            <select name="race_id" class="w-full border rounded px-3 py-2">
                <option value="">なし</option>
                @foreach ($races as $r)
                    <option value="{{ $r->id }}" @selected(old('race_id', $note?->race_id) == $r->id)>
                        {{ $r->race_date?->format('Y/m/d') }} {{ $r->venue?->name }} {{ $r->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">関連馬</label>
            <select name="horse_id" class="w-full border rounded px-3 py-2">
                <option value="">なし</option>
                @foreach ($horses as $h)
                    <option value="{{ $h->id }}" @selected(old('horse_id', $note?->horse_id) == $h->id)>{{ $h->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">タグ</label>
        <input type="text" name="tag" value="{{ old('tag', $note?->tag) }}" placeholder="例: 注目馬, 反省, 予想" maxlength="50" class="w-full border rounded px-3 py-2">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">本文 <span class="text-red-500">*</span></label>
        <textarea name="body" rows="8" required class="w-full border rounded px-3 py-2 font-mono text-sm">{{ old('body', $note?->body) }}</textarea>
    </div>
</div>
