<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * race_results の文字列カラム長を拡張（安全網）
 *
 * netkeibaの取込で稀にHTML残渣やエンコーディング由来の長い文字列が
 * 入り込み、Data too long エラーになるケースの再発防止。
 * NetkeibaScraper側でも切り詰めているが、二重防御として桁を広げる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            // 着順: 5 -> 16 （取消/除外/失格などの文言＋安全マージン）
            $table->string('finish_position', 16)->nullable()->change();
            // タイム: 10 -> 16
            $table->string('time', 16)->nullable()->change();
            // 着差: 10 -> 32 （長めの全角文字混入も吸収）
            $table->string('margin', 32)->nullable()->change();
            // 上がり3F: 6 -> 16
            $table->string('last_3f', 16)->nullable()->change();
            // 通過順: 30 -> 64
            $table->string('corner_positions', 64)->nullable()->change();
            // 脚質: 10 -> 16
            $table->string('running_style', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('race_results', function (Blueprint $table) {
            $table->string('finish_position', 5)->nullable()->change();
            $table->string('time', 10)->nullable()->change();
            $table->string('margin', 10)->nullable()->change();
            $table->string('last_3f', 6)->nullable()->change();
            $table->string('corner_positions', 30)->nullable()->change();
            $table->string('running_style', 10)->nullable()->change();
        });
    }
};
