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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->bigInteger('project_template_id')->unsigned()->index();
            $table->string('source'); // To determine the source of the project, e.g., sales order, customer request, internal project, etc.
            $table->bigInteger('source_id')->unsigned()->index()->nullable(); // To store the ID of the source, e.g., sales order ID, customer request ID, etc.
            $table->string('source_info')->nullable(); // To store additional information about the source, e.g., sales order number, customer request details, etc.
            $table->string('code');
            $table->string('name');
            $table->string('desc', 500)->nullable();
            $table->string('current_step')->default('On Draft'); // To store the current step of the project, e.g., step 1, step 2, step 3, etc.
            $table->double('progress')->default(0); // To store the progress of the project in percentage, e.g., 0%, 25%, 50%, 75%, 100%
            $table->dateTime('started_at')->nullable(); // To store the start date and time of the project
            $table->dateTime('ended_at')->nullable(); // To store the end date and time of the project
            $table->enum('status', ['draft', 'in progress', 'completed', 'on hold', 'cancelled'])->default('draft'); // To determine the status of the project,
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->foreign('project_template_id')->references('id')->on('project_templates');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
