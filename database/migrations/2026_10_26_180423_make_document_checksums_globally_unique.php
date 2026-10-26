<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledge base is shared, so a file may only be uploaded once by anyone.
 *
 * This fails if two users already uploaded the same file; remove one copy first.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'checksum']);
            $table->unique('checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['checksum']);
            $table->unique(['user_id', 'checksum'])->nullsNotDistinct();
        });
    }
};
