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
        Schema::create('credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 32);
            $table->string('host', 255);
            $table->string('username', 255)->nullable();
            $table->text('secret_ciphertext');
            $table->text('data_key_ciphertext');
            $table->unsignedSmallInteger('key_version');
            $table->timestamp('secret_changed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'host']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
