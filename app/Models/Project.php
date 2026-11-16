<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'projects';

    protected $fillable = [
        'tenant_id',
        'project_template_id',
        'source',
        'source_id',
        'source_info',
        'code',
        'name',
        'desc',
        'current_step',
        'started_at',
        'ended_at',
        'status',
    ];

    public function projectTemplate()
    {
        return $this->belongsTo(ProjectTemplate::class);
    }

    public function projectSteps()
    {
        return $this->hasMany(ProjectStep::class);
    }
}
