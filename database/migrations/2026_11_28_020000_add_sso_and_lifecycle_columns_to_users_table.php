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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('deactivated_at');
            $table->string('sso_issuer')->nullable()->after('last_login_at');
            $table->string('sso_subject')->nullable()->after('sso_issuer');
            $table->boolean('is_break_glass')->default(false)->after('sso_subject');
            $table->timestamp('pseudonymised_at')->nullable()->after('is_break_glass');

            $table->unique(['sso_issuer', 'sso_subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_issuer', 'sso_subject']);
            $table->dropColumn(['last_login_at', 'sso_issuer', 'sso_subject', 'is_break_glass', 'pseudonymised_at']);
        });
    }
};
