<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'project_steps'] as $table) {
            if (! Schema::hasColumn($table, 'estimation_ended_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dateTime('estimation_ended_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // The column may have existed before this migration, so do not drop it on rollback.
    }
};
