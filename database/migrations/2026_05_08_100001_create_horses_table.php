<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 馬マスタ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horses', function (Blueprint $table) {
            $table->id();
            $table->string('netkeiba_id', 20)->nullable()->unique()->comment('netkeibaの馬ID');
            $table->string('name', 50)->comment('馬名');
            $table->string('name_kana', 100)->nullable();
            $table->string('name_en', 100)->nullable();
            $table->enum('sex', ['牡', '牝', 'セ'])->nullable();
            $table->date('birthday')->nullable();
            $table->string('color', 20)->nullable()->comment('毛色');
            $table->string('father', 50)->nullable()->comment('父');
            $table->string('mother', 50)->nullable()->comment('母');
            $table->string('mother_father', 50)->nullable()->comment('母父');
            $table->string('owner', 100)->nullable()->comment('馬主');
            $table->string('breeder', 100)->nullable()->comment('生産者');
            $table->string('birth_place', 50)->nullable()->comment('産地');
            $table->bigInteger('total_prize')->default(0)->comment('総獲得賞金(万円)');
            $table->timestamps();

            $table->index('name');
            $table->index('father');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horses');
    }
};
