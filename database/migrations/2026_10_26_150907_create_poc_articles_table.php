<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Throwaway proof of concept for vector search. Skipped on non-PostgreSQL
 * connections, which have no vector column type.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::create('poc_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title')->unique();
            $table->text('body');
            $table->vector('embedding', dimensions: config('rag.embedding_dimensions'));
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poc_articles');
    }
};
