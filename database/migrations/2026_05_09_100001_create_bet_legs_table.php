<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 馬券買い目（組合せ1点）
 *
 *  - bets を Box / Formation 展開した結果を1点1レコードで保持
 *  - combination は馬番(or枠番)を "-" 区切りで格納
 *      順不同券種(馬連/3連複/ワイド/枠連): 昇順ソート済み "3-7" "1-5-9"
 *      順序あり券種(単勝/複勝/馬単/3連単): 入力順 "3-1-5"
 *  - レース結果が確定したら is_hit / payout を更新
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_id')->constrained()->cascadeOnDelete();

            // 組合せ表現: 馬番(or枠番)を "-" で連結
            $table->string('combination', 32)->index()->comment('例: "3" "3-7" "3-7-1"');

            // 1点あたり投資額（通常は bets.unit_stake と同じ。将来の傾斜配分に備えて持つ）
            $table->unsignedInteger('stake')->default(100);

            // 結果
            $table->boolean('is_hit')->default(false);
            $table->unsignedInteger('payout')->default(0)->comment('この1点の払戻金額(円)');

            // 払戻人気（公式払戻表の人気順）— 結果から自動補完
            $table->unsignedSmallInteger('payout_popularity')->nullable();

            $table->timestamps();

            $table->index(['bet_id', 'is_hit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_legs');
    }
};
