<?php

use App\Enums\AdminRole;
use App\Vault\Authorization\Ability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations. The three roles of phase 8 become system roles: Owner holds every permission and cannot be
     * edited, Admin and Auditor start with the permissions of the phase 8 matrix and can be edited. Every existing
     * admin keeps the role they have, so nobody gains or loses access.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->boolean('is_system')->default(false);
            $table->json('permissions');
            $table->json('sso_groups')->nullable();
            $table->timestamps();
        });

        foreach ([AdminRole::Owner, AdminRole::Admin, AdminRole::Auditor] as $role) {
            DB::table('roles')->insert([
                'id' => (string) Str::ulid(),
                'name' => $role->label(),
                'slug' => $role->value,
                'is_system' => true,
                'permissions' => json_encode(Ability::defaultsFor($role)),
                'sso_groups' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('custom_role_id')->nullable()->after('role')->constrained('roles')->restrictOnDelete();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignUlid('custom_role_id')->nullable()->after('role')->constrained('roles')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_role_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_role_id');
        });

        Schema::dropIfExists('roles');
    }
};
