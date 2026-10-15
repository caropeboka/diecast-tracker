<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Several photos per diecast (front of card, back of card, loose car...). First one is the cover.
        Schema::create('diecast_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diecast_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('diecasts')->whereNotNull('photo_path')->orderBy('id')->each(function ($diecast) use ($now) {
            DB::table('diecast_photos')->insert([
                'diecast_id' => $diecast->id,
                'path' => $diecast->photo_path,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        Schema::table('diecasts', function (Blueprint $table) {
            $table->dropColumn('photo_path');
            $table->decimal('market_value', 14, 2)->nullable()->after('purchase_price');
            $table->decimal('target_price', 14, 2)->nullable()->after('market_value');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('diecast_tag', function (Blueprint $table) {
            $table->foreignId('diecast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['diecast_id', 'tag_id']);
        });

        // Photos waiting for AI / review: bulk scan queue and the in-store "check collection" mode.
        Schema::create('scan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('bulk');
            $table->string('photo_path');
            $table->string('status', 12)->default('pending')->index();
            $table->json('fields')->nullable();
            $table->string('error')->nullable();
            $table->foreignId('diecast_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_items');
        Schema::dropIfExists('diecast_tag');
        Schema::dropIfExists('tags');

        Schema::table('diecasts', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
            $table->dropColumn(['market_value', 'target_price']);
        });

        DB::table('diecast_photos')->where('position', 0)->orderBy('id')->each(function ($photo) {
            DB::table('diecasts')->where('id', $photo->diecast_id)->update(['photo_path' => $photo->path]);
        });

        Schema::dropIfExists('diecast_photos');
    }
};
