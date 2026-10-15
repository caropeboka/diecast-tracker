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
        // A market price check: marketplace screenshots read by AI, with the listings and the computed stats.
        Schema::create('price_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('diecast_id')->constrained()->cascadeOnDelete();
            $table->json('screenshots');
            $table->json('listings');
            $table->json('stats')->nullable();
            $table->string('accuracy', 12);
            $table->json('reasons');
            $table->json('tips');
            $table->text('ai_note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::table('diecasts', function (Blueprint $table) {
            $table->string('market_value_source', 20)->nullable()->after('market_value');
            $table->timestamp('market_value_updated_at')->nullable()->after('market_value_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diecasts', function (Blueprint $table) {
            $table->dropColumn(['market_value_source', 'market_value_updated_at']);
        });

        Schema::dropIfExists('price_checks');
    }
};
