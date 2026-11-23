<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            $table->boolean('has_print')->default(false);
            $table->enum('term', ['no term', 'hour', 'day', 'week', 'month', 'year'])->default('no term');
            $table->integer('estimation_time_in_term')->default(0);
            $table->timestamps();
            $table->foreign('project_template_id')->references('id')->on('project_templates');
        });

        Schema::create('form_project_step_template', function (Blueprint $table) {
            $table->bigInteger('project_template_step_id')->unsigned()->index();
            $table->bigInteger('form_template_id')->unsigned()->index();
            $table->unique(['project_template_step_id', 'form_template_id'], 'fpst_step_form_unique');
            $table->foreign('project_template_step_id')->references('id')->on('project_template_steps');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_project_step_template');
        Schema::dropIfExists('project_template_steps');
    }
};
