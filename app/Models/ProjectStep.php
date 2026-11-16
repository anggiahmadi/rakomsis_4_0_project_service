<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;

class ProjectStep extends Model
{
    protected $table = 'project_steps';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'project_template_step_id',
        'name',
        'desc',
        'order_no',
        'need_approval',
        'term',
        'estimation_time_in_term',
        'started_at',
        'ended_at',
    ];

    public function forms()
    {
        return DB::table('form_project_step')->where('project_step_id', $this->id)->get();
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
