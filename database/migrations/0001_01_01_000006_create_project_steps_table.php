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
            $table->timestamps();
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('project_template_step_id')->references('id')->on('project_template_steps');
        });

        Schema::table('form_project_step', function (Blueprint $table) {
            $table->bigInteger('project_step_id')->unsigned()->index();
            $table->bigInteger('template_form_id')->unsigned()->index();
            $table->bigInteger('form_id')->unsigned()->index()->nullable();
            $table->integer('order_no')->default(0);
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
