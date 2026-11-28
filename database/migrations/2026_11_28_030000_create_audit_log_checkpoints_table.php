<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A checkpoint records the last audit entry that retention removed, so the hash chain can be
     * verified from the first entry that is left.
     */
    public function up(): void
    {
        Schema::create('audit_log_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sequence')->unique();
            $table->char('hash', 64);
            $table->unsignedInteger('pruned_count');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log_checkpoints');
    }
};
