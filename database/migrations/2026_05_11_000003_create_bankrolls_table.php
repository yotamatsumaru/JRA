<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * バンクロール (資金管理) — 月次予算/実残高の追跡
     *  - user_id × ym (例: '2026-05') 単位で予算を保存
     *  - target_stake : 月の投資予算
     *  - target_profit: 月の収支目標（プラス値で目標利益）
     *  - notes        : メモ
     *  - 実績 (実投資/実収支) は bets テーブルから動的集計するためここには持たない
     */
    public function up(): void
    {
        Schema::create('bankrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ym', 7);   // 'YYYY-MM'
            $table->integer('target_stake')->default(0);
            $table->integer('target_profit')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ym']);
            $table->index(['user_id', 'ym']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bankrolls');
    }
};
