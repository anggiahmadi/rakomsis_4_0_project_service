<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTemplateStepForm extends Model
{
    protected $table = 'form_project_step_template';

    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'project_template_step_id',
        'form_template_id',
    ];
}
