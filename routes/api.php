<?php

use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTemplateController;
use Illuminate\Support\Facades\Route;

// P

Route::controller(ProjectController::class)->group(function () {
    Route::get('projects', 'index')->middleware('auth.access');
    Route::get('projects-dashboard', 'dashboard')->middleware('auth.access');
    Route::get('projects/{id}', 'show')->middleware('auth.access');
    Route::get('projects-get-code', 'getCode')->middleware('auth.access');
    Route::get('projects-get-by-code', 'getByCode')->middleware('auth.access');
    Route::get('datatables/projects', 'datatables')->middleware('auth.access');
    Route::post('projects', 'store')->middleware('auth.access');
    Route::put('projects/{id}', 'update')->middleware('auth.access');
    Route::post('projects/{id}/steps/{stepId}/finish', 'finishStep')->middleware('auth.access');
    Route::post('projects/{id}/steps/{stepId}/unlock', 'unlockStep')->middleware('auth.access');
    Route::put('projects/{id}/forms/{formId}', 'saveForm')->middleware('auth.access');
    Route::put('projects/{id}/restore', 'restore')->middleware('auth.access');
    Route::delete('projects/{id}', 'destroy')->middleware('auth.access');
});

Route::controller(ProjectTemplateController::class)->group(function () {
    Route::get('project_templates', 'index')->middleware('auth.access');
    Route::get('project_templates/{id}', 'show')->middleware('auth.access');
    Route::get('project_templates-get-code', 'getCode')->middleware('auth.access');
    Route::get('project_templates-get-by-code', 'getByCode')->middleware('auth.access');
    Route::get('datatables/project_templates', 'datatables')->middleware('auth.access');
    Route::post('project_templates', 'store')->middleware('auth.access');
    Route::put('project_templates/{id}', 'update')->middleware('auth.access');
    Route::put('project_templates/{id}/restore', 'restore')->middleware('auth.access');
    Route::delete('project_templates/{id}', 'destroy')->middleware('auth.access');
});
