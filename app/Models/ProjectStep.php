<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectStep extends Model
{
    protected $table = 'project_steps';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'project_template_step_id',
        'document_workflow_detail_id',
        'name',
        'desc',
        'order_no',
        'need_approval',
        'has_print',
        'term',
        'estimation_time_in_term',
        'started_at',
        'ended_at',
    ];

    public function forms(): HasMany
    {
        return $this->hasMany(ProjectStepForm::class, 'project_step_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
