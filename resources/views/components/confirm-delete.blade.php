@props([
    'action',
    'message' => 'この項目を削除しますか？',
    'title' => '削除確認',
    'label' => '削除',
    'method' => 'DELETE',
])

<div x-data="{ open: false }" class="inline-block">
    <button type="button" @click="open = true"
        {{ $attributes->merge(['class' => 'inline-flex items-center space-x-1 text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 text-sm']) }}>
        <x-icon name="trash" class="w-4 h-4" />
        <span>{{ $label }}</span>
    </button>

    {{-- モーダル --}}
    <div x-show="open" x-cloak
         x-transition.opacity
         class="fixed inset-0 z-[90] flex items-center justify-center p-4"
         @keydown.escape.window="open = false"
    >
        {{-- バックドロップ --}}
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="open = false"></div>

        {{-- ダイアログ --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="relative bg-white dark:bg-gray-800 rounded-lg shadow-2xl max-w-md w-full p-6"
        >
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center">
                    <x-icon name="warning" class="w-6 h-6 text-red-600 dark:text-red-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $title }}</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ $message }}</p>
                </div>
            </div>

            <div class="flex justify-end space-x-2 mt-6">
                <button type="button" @click="open = false"
                    class="px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600">
                    キャンセル
                </button>
                <form action="{{ $action }}" method="POST">
                    @csrf
                    @method($method)
                    <button type="submit"
                        class="px-4 py-2 rounded-md text-sm font-medium text-white bg-red-600 hover:bg-red-700 inline-flex items-center space-x-1">
                        <x-icon name="trash" class="w-4 h-4" />
                        <span>削除する</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
