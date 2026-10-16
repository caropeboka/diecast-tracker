<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // "Ditemui": each time a diecast was seen somewhere (a store, a friend's shelf, an event), with the price.
        Schema::create('sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('diecast_id')->constrained()->cascadeOnDelete();
            $table->string('place')->nullable();
            $table->date('seen_on');
            $table->decimal('price', 14, 2)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();

            $table->index(['diecast_id', 'seen_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sightings');
    }
};
