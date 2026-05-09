<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * レースの公式払戻データ
 *
 *  - 1レース × 1券種 × 1的中組合せ = 1レコード
 *  - netkeibaの払戻表から取り込む（手動入力も可）
 *  - 自分が買った/買ってないに関係なく蓄積。傾向分析（券種別平均配当・人気別払戻分布）の母集団
 *  - bet_legs の的中判定・払戻金額算出にも参照
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();

            // 券種コード（bets.kind と同じ）
            $table->string('kind', 16)->index();

            // 的中組合せ（bet_legs.combination と同じフォーマットで正規化）
            $table->string('combination', 32)->comment('例: "3" "3-7" "3-7-1"');

            // 払戻金額（100円あたり / 円）
            $table->unsignedInteger('amount')->comment('払戻金額(100円あたり)');

            // 人気順位（公式払戻に併記される人気）
            $table->unsignedSmallInteger('popularity')->nullable();

            $table->timestamps();

            $table->unique(['race_id', 'kind', 'combination']);
            $table->index(['kind', 'amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
