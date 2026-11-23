<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTemplateStep extends Model
{
    protected $table = 'project_template_steps';

    protected $fillable = [
        'tenant_id',
        'project_template_id',
        'name',
        'desc',
        'order_no',
        'need_approval',
        'has_print',
        'term',
        'estimation_time_in_term',
    ];

    public function projectTemplate()
    {
        return $this->belongsTo(ProjectTemplate::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(ProjectTemplateStepForm::class, 'project_template_step_id');
    }
}
