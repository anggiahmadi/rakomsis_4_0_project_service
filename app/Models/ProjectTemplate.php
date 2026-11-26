<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTemplate extends Model
{
    protected $table = 'project_templates';

    protected $fillable = [
        'tenant_id',
        'document_workflow_detail_id',
        'code',
        'name',
        'desc',
        'has_print',
    ];

    public function projectTemplateSteps()
    {
        return $this->hasMany(ProjectTemplateStep::class);
    }
}
