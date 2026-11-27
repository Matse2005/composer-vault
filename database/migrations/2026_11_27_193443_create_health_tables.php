<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

return new class extends Migration
{
    /**
     * Run the migrations. The table holds the stored results of the health checks.
     */
    public function up(): void
    {
        $model = new HealthCheckResultHistoryItem;

        Schema::connection($model->getConnectionName())->create($model->getTable(), function (Blueprint $table) {
            $table->id();

            $table->string('check_name');
            $table->string('check_label');
            $table->string('status');
            $table->text('notification_message')->nullable();
            $table->string('short_summary')->nullable();
            $table->json('meta');
            $table->timestamp('ended_at');
            $table->uuid('batch');

            $table->timestamps();

            $table->index('created_at');
            $table->index('batch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $model = new HealthCheckResultHistoryItem;

        Schema::connection($model->getConnectionName())->dropIfExists($model->getTable());
    }
};
