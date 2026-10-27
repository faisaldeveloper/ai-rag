<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record whether a document's vectors were embedded with task types, so
 * documents embedded with and without them can be told apart.
 *
 * Null means the document has not been embedded yet. Documents embedded
 * before this migration never used task types.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('embedding_task_types')->nullable()->after('embedding_model');
        });

        DB::table('documents')->whereNotNull('embedding_model')->update(['embedding_task_types' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('embedding_task_types');
        });
    }
};
