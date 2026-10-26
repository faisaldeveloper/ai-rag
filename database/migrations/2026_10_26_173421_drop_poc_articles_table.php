<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Milestone 1 vector search proof of concept from databases that
 * already ran its (now deleted) create migration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('poc_articles');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
