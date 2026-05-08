<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 騎手マスタ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jockeys', function (Blueprint $table) {
            $table->id();
            $table->string('netkeiba_id', 20)->nullable()->unique();
            $table->string('name', 50)->comment('騎手名');
            $table->string('name_kana', 100)->nullable();
            $table->string('belonging', 30)->nullable()->comment('所属(美浦/栗東/フリー/外国)');
            $table->date('birthday')->nullable();
            $table->date('debut_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jockeys');
    }
};
