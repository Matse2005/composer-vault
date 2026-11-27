<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The table holds one row, the head of the audit log hash chain. Phase 4 locks it to append entries.
     */
    public function up(): void
    {
        Schema::create('audit_log_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('sequence');
            $table->char('last_hash', 64);
        });

        DB::table('audit_log_head')->insert([
            'id' => 1,
            'sequence' => 0,
            'last_hash' => str_repeat('0', 64),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log_head');
    }
};
