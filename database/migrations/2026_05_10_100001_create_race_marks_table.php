<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 出馬表での印・メモ・キャッシュ済みスコアを保存
 *
 * 1ユーザー × 1出走馬(race_result) で1レコード。
 * UNIQUE(user_id, race_result_id) により upsert 可能。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('race_id')->constrained('races')->cascadeOnDelete();
            $table->foreignId('race_result_id')->constrained('race_results')->cascadeOnDelete();

            // 印 (◎○▲△☆✕ または NULL = 印なし)
            $table->string('mark', 4)->nullable()->index();

            // 自由メモ
            $table->text('memo')->nullable();

            // 推奨スコアのキャッシュ(出馬表表示時に計算済の値を保存)
            $table->decimal('score_total',     5, 2)->nullable();
            $table->decimal('score_pedigree',  5, 2)->nullable();
            $table->decimal('score_jockey',    5, 2)->nullable();
            $table->decimal('score_horse',     5, 2)->nullable();
            $table->decimal('score_roi',       5, 2)->nullable();
            $table->timestamp('scored_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'race_result_id']);
            $table->index(['user_id', 'race_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_marks');
    }
};
