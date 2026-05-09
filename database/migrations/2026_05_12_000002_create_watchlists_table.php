<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ウォッチリスト (Phase 4-W)
     *  - 注目馬・騎手・厩舎を登録し、出走予定を一覧表示
     *  - Favorites と異なり、メモやアラート設定を持つ
     *  - target_type は 'horse' | 'jockey' | 'trainer'
     */
    public function up(): void
    {
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20);                  // horse / jockey / trainer
            $table->unsignedBigInteger('target_id');
            $table->string('label', 200)->nullable();           // 表示名キャッシュ
            $table->text('memo')->nullable();
            $table->boolean('alert_on_entry')->default(true);   // 出走時にダッシュボードでハイライト
            $table->dateTime('last_alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlists');
    }
};
