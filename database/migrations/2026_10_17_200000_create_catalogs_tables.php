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
        // "Dex": reference lists of every release (a wiki year list, or one the user builds),
        // so the collection can be measured against them.
        Schema::create('catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('brand');
            $table->string('name');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('source', 32); // hotwheels_wiki | matchbox_wiki | custom
            $table->string('source_page')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source', 'year', 'name']);
        });

        // One row per variant; rows sharing a number are one dex entry.
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedTinyInteger('variant')->default(1);
            $table->string('variant_label')->nullable();
            $table->string('name');
            $table->string('series')->nullable();
            $table->string('series_number', 32)->nullable();
            $table->string('toy_code', 32)->nullable();
            $table->json('flags')->nullable();
            $table->string('image_file')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->index(['catalog_id', 'sort']);
            $table->index(['catalog_id', 'number']);
        });

        Schema::table('diecasts', function (Blueprint $table) {
            $table->foreignId('catalog_item_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // auto = linked by the matcher, confirmed = checked by the user
            $table->string('catalog_match', 16)->nullable()->after('catalog_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diecasts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_item_id');
            $table->dropColumn('catalog_match');
        });

        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalogs');
    }
};
