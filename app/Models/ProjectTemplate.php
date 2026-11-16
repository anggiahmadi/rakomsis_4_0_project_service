<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectTemplate extends Model
{
    protected $table = 'project_templates';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'desc',
    ];

    public function projectTemplateSteps()
    {
        return $this->hasMany(ProjectTemplateStep::class);
    }
}
