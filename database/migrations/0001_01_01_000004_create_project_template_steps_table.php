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
        Schema::create('project_template_steps', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->index();
            $table->bigInteger('project_template_id')->unsigned()->index();
            $table->string('name');
            $table->text('desc')->nullable();
            $table->integer('order_no')->default(0);
            $table->boolean('need_approval')->default(false);
            $table->enum('term', ['no term', 'hour', 'day', 'week', 'month', 'year'])->default('no term'); // To determine the term of the step, e.g., hour, day, week, month, year
            $table->integer('estimation_time_in_term')->default(0); // To determine the estimation time in the specified term, e.g., 2 hours, 3 days, 1 week, 2 months, 1 year
            $table->timestamps();
            $table->foreign('project_template_id')->references('id')->on('project_templates');
        });

        Schema::table('form_project_step_template', function (Blueprint $table) {
            $table->bigInteger('project_template_step_id')->unsigned()->index();
            $table->bigInteger('form_template_id')->unsigned()->index();
            $table->integer('order_no')->default(0);
            $table->unique(['project_template_step_id', 'form_template_id']);
            $table->foreign('project_template_step_id')->references('id')->on('project_template_steps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_template_steps');
    }
};
