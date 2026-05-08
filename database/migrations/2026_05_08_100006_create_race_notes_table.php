<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * レース・出走馬メモ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('race_id')->nullable()->constrained('races')->cascadeOnDelete();
            $table->foreignId('horse_id')->nullable()->constrained('horses')->cascadeOnDelete();
            $table->string('title', 100)->nullable();
            $table->text('body');
            $table->string('tag', 50)->nullable()->comment('パドック/返し馬/結果分析等');
            $table->timestamps();

            $table->index(['user_id', 'race_id']);
            $table->index(['user_id', 'horse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_notes');
    }
};
