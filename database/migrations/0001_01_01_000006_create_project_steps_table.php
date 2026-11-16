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
        Schema::create('project_steps', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->bigInteger('project_id')->unsigned()->index();
            $table->bigInteger('project_template_step_id')->unsigned()->index();
            $table->string('name');
            $table->text('desc')->nullable();
            $table->integer('order_no')->default(0);
            $table->boolean('need_approval')->default(false);
            $table->enum('term', ['no term', 'hour', 'day', 'week', 'month', 'year'])->default('no term'); // To determine the term of the step, e.g., hour, day, week, month, year
            $table->integer('estimation_time_in_term')->default(0); // To determine the estimation time in the specified term, e.g., 2 hours, 3 days, 1 week, 2 months, 1 year
            $table->dateTime('started_at')->nullable(); // To store the start date and time of the project step
            $table->dateTime('ended_at')->nullable(); // To store the end date and time of the project step
            $table->timestamps();
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('project_template_step_id')->references('id')->on('project_template_steps');
        });

        Schema::create('form_project_step', function (Blueprint $table) {
            $table->bigInteger('project_step_id')->unsigned()->index();
            $table->bigInteger('template_form_id')->unsigned()->index();
            $table->bigInteger('custom_form_id')->unsigned()->index()->nullable();
            $table->unique(['project_step_id', 'template_form_id']);
            $table->foreign('project_step_id')->references('id')->on('project_steps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_steps');
        Schema::dropIfExists('form_project_step');
    }
};
