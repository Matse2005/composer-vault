<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A critical change waits here for a second Owner when change approval is on.
     */
    public function up(): void
    {
        Schema::create('pending_setting_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 20);
            $table->string('subject', 100);
            $table->json('payload');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_setting_changes');
    }
};
