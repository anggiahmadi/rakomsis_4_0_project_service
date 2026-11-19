<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectStepForm extends Model
{
    protected $table = 'form_project_step';

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];
}
