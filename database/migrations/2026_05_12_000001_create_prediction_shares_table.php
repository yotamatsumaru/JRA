<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 予想スナップショット共有 (Phase 4-S)
     *  - レースごとに自分の印・スコア・メモをスナップショットして公開URLで共有
     *  - token 経由でログイン不要の read-only 閲覧を提供
     */
    public function up(): void
    {
        Schema::create('prediction_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();             // 公開URL のトークン
            $table->string('title', 200)->nullable();
            $table->text('comment')->nullable();
            $table->json('snapshot');                          // 印/スコア/メモのスナップショット
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('view_count')->default(0);
            $table->dateTime('last_viewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'race_id']);
            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_shares');
    }
};
