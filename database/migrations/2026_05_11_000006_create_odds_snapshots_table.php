<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * オッズスナップショット (Phase 3-I)
     *  - 出走前のレースの単勝/複勝オッズを定期取得して時系列保存
     *  - レース後の確定オッズと、推移グラフを描くために使用
     */
    public function up(): void
    {
        Schema::create('odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->dateTime('captured_at');           // スナップショット取得時刻
            $table->string('source', 32)->default('netkeiba');
            $table->json('payload');                   // { horse_number => { win, place_min, place_max, popularity } }
            $table->timestamps();

            $table->index(['race_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odds_snapshots');
    }
};
