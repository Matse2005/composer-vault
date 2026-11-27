<?php

use App\Models\AuditEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The package creates subject_id as a big integer, but the subjects of the audit log have ULID keys (projects,
     * credentials, tokens, invitations) next to integer keys (users). The column becomes a string, so both fit on
     * every database. The chain columns are added by this migration and always set by AuditLogger.
     */
    public function up(): void
    {
        $table = (new AuditEntry)->getTable();

        Schema::table($table, function (Blueprint $table) {
            $table->string('subject_id', 36)->nullable()->change();
        });

        Schema::table($table, function (Blueprint $table) {
            $table->unsignedBigInteger('sequence')->nullable()->unique();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table((new AuditEntry)->getTable(), function (Blueprint $table) {
            $table->dropUnique(['sequence']);
            $table->dropColumn(['sequence', 'previous_hash', 'hash', 'ip']);
        });
    }
};
