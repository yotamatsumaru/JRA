@extends('layouts.guest')
@section('title', 'JRA Analyzer - 中央競馬データ分析アプリ')

@section('content')
<div class="text-center space-y-6">
    <h2 class="text-2xl font-bold text-gray-800">中央競馬を、もっと深く。</h2>
    <p class="text-sm text-gray-600 leading-relaxed">
        レース結果を蓄積し、競馬場別傾向・脚質・血統・騎手×コース相性を可視化。<br>
        netkeibaスクレイピング・CSV・スクショ画像（GPT-4o）から取込可能。
    </p>

    <div class="space-y-3">
        <a href="{{ route('login') }}"
           class="block w-full bg-primary-600 hover:bg-primary-700 text-white py-2 rounded-lg font-medium transition">
            ログイン
        </a>
        <a href="{{ route('register') }}"
           class="block w-full bg-white border border-primary-600 text-primary-600 hover:bg-primary-50 py-2 rounded-lg font-medium transition">
            新規登録
        </a>
    </div>

    <div class="border-t pt-4 mt-6 text-xs text-gray-500 grid grid-cols-2 gap-2 text-left">
        <div>🏟 全10競馬場対応</div>
        <div>🐎 出走全頭データ管理</div>
        <div>📊 ApexChartsで可視化</div>
        <div>🤖 GPT-4o画像解析</div>
        <div>🔁 netkeiba自動取込</div>
        <div>📥 CSV一括インポート</div>
    </div>
</div>
@endsection
