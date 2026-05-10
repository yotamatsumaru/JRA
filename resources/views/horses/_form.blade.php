{{-- 馬共通フォーム --}}
<div class="grid grid-cols-2 md:grid-cols-3 gap-4">
    <div class="col-span-2 md:col-span-3">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">馬名 <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $horse?->name) }}" required class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div class="col-span-2">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">カナ</label>
        <input type="text" name="name_kana" value="{{ old('name_kana', $horse?->name_kana) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">性別</label>
        <select name="sex" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
            <option value="">-</option>
            @foreach (['牡','牝','セ'] as $s)
                <option value="{{ $s }}" @selected(old('sex', $horse?->sex) == $s)>{{ $s }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">生年月日</label>
        <input type="date" name="birthday" value="{{ old('birthday', $horse?->birthday?->format('Y-m-d')) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">毛色</label>
        <input type="text" name="color" value="{{ old('color', $horse?->color) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div></div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">父</label>
        <input type="text" name="father" value="{{ old('father', $horse?->father) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">母</label>
        <input type="text" name="mother" value="{{ old('mother', $horse?->mother) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">母父</label>
        <input type="text" name="mother_father" value="{{ old('mother_father', $horse?->mother_father) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>

    <div class="col-span-2 md:col-span-3">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">馬主</label>
        <input type="text" name="owner" value="{{ old('owner', $horse?->owner) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
    <div class="col-span-2 md:col-span-3">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">生産者</label>
        <input type="text" name="breeder" value="{{ old('breeder', $horse?->breeder) }}" class="w-full border dark:border-gray-600 rounded px-3 py-2 dark:bg-gray-900 dark:text-gray-100">
    </div>
</div>
