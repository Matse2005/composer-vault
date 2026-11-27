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
        Schema::create('access_token_project', function (Blueprint $table) {
            $table->foreignUlid('access_token_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['access_token_id', 'project_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_token_project');
    }
};
