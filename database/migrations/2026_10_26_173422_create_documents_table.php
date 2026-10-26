<?php

use App\Enums\DocumentStatus;
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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type');
            $table->char('checksum', 64);
            $table->string('status')->default(DocumentStatus::Pending->value)->index();
            $table->text('error_message')->nullable();
            $table->string('embedding_provider')->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'checksum'])->nullsNotDistinct();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
