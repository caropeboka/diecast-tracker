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
        // Geotag of where a diecast was bought — shown on the "Peta jelajah" map next to sightings.
        Schema::table('diecasts', function (Blueprint $table) {
            $table->decimal('purchase_latitude', 10, 7)->nullable()->after('purchase_place');
            $table->decimal('purchase_longitude', 10, 7)->nullable()->after('purchase_latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diecasts', function (Blueprint $table) {
            $table->dropColumn(['purchase_latitude', 'purchase_longitude']);
        });
    }
};
