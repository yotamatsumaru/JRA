<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 競馬場マスタ
 * JRA中央競馬場 10場
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique()->comment('JRA場コード 01-10');
            $table->string('name', 20)->comment('競馬場名 (例: 東京)');
            $table->string('name_kana', 40)->nullable()->comment('カナ名');
            $table->string('region', 20)->nullable()->comment('地域 (関東/関西/その他)');
            $table->string('direction', 10)->nullable()->comment('右回り/左回り');
            $table->integer('turf_straight')->nullable()->comment('芝直線距離(m)');
            $table->integer('dirt_straight')->nullable()->comment('ダート直線距離(m)');
            $table->text('characteristics')->nullable()->comment('コース特徴メモ');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
