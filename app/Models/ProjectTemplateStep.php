<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;

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
        'term',
        'estimation_time_in_term',
    ];

    public function projectTemplate()
    {
        return $this->belongsTo(ProjectTemplate::class);
    }

    public function forms()
    {
        return DB::table('form_project_step_template')->where('project_template_step_id', $this->id)->get();
    }
}
