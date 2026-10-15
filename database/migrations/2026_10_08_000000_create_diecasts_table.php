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
        Schema::create('diecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('name');
            $table->string('brand')->nullable()->index();
            $table->string('make')->nullable();
            $table->string('series')->nullable()->index();
            $table->string('collection_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('scale')->nullable();
            $table->string('color')->nullable();

            // Variant & condition
            $table->string('rarity')->default('regular');
            $table->string('packaging')->default('carded');
            $table->string('condition')->nullable();

            // Ownership
            $table->string('status')->default('owned')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('purchase_place')->nullable();
            $table->string('storage_location')->nullable();
            $table->text('notes')->nullable();

            $table->string('photo_path')->nullable();
            $table->boolean('ai_scanned')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diecasts');
    }
};
