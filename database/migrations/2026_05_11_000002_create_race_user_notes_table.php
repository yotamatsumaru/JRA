<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * レース全体メモ (Phase 1-T)
 *
 * 出走馬個別のメモ(race_marks.memo)とは別に、
 * 「このレースのレース展開予想」「次走注目」などレース単位のメモを保存。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('race_user_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('race_id')->constrained('races')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->boolean('watch_next')->default(false);   // 次走注目フラグ
            $table->timestamps();

            $table->unique(['user_id', 'race_id']);
            $table->index(['user_id', 'watch_next']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_user_notes');
    }
};
