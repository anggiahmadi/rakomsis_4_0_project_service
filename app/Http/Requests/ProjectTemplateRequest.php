<?php

namespace App\Http\Requests;

use App\Models\ProjectTemplate;
use App\Models\ProjectTemplateStep;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => 'required|integer',
            'document_workflow_detail_id' => 'nullable|integer',
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'desc' => 'nullable|string',
            'has_print' => 'required|boolean',
            'project_template_steps' => 'required|array|min:1',
            'project_template_steps.*.id' => 'nullable|integer',
            'project_template_steps.*.document_workflow_detail_id' => 'nullable|integer',
            'project_template_steps.*.name' => 'required|string|max:255',
            'project_template_steps.*.desc' => 'nullable|string',
            'project_template_steps.*.order_no' => 'required|integer',
            'project_template_steps.*.need_approval' => 'required|boolean',
            'project_template_steps.*.has_print' => 'required|boolean',
            'project_template_steps.*.term' => 'required|in:no term,hour,day,week,month,year',
            'project_template_steps.*.estimation_time_in_term' => 'nullable|integer',
            'project_template_steps.*.forms' => 'nullable|array',
            'project_template_steps.*.forms.*.form_template_id' => 'nullable|integer',
        ];
    }

    public function store()
    {
        $this->validate([
            'code' => Rule::unique('project_templates')->where('tenant_id', $this->tenant_id),
        ]);

        return DB::transaction(function () {
            $projectTemplate = ProjectTemplate::create(
                $this->only(['tenant_id', 'code', 'name', 'desc', 'document_workflow_detail_id', 'has_print'])
            );

            foreach ($this->input('project_template_steps', []) as $stepData) {
                $step = ProjectTemplateStep::create(array_merge(
                    Arr::only($stepData, [
                        'document_workflow_detail_id',
                        'name',
                        'desc',
                        'order_no',
                        'need_approval',
                        'has_print',
                        'term',
                        'estimation_time_in_term',
                    ]),
                    [
                        'tenant_id' => $this->tenant_id,
                        'project_template_id' => $projectTemplate->id,
                    ]
                ));

                $this->syncStepForms($step->id, data_get($stepData, 'forms', []));
            }

            return $projectTemplate;
        });
    }

    public function update(int $id)
    {
        $this->validate([
            'code' => Rule::unique('project_templates')
                ->where('tenant_id', $this->tenant_id)
                ->ignore($id),
        ]);

        return DB::transaction(function () use ($id) {
            $projectTemplate = ProjectTemplate::findOrFail($id);
            $projectTemplate->fill($this->only(['tenant_id', 'code', 'name', 'desc', 'document_workflow_detail_id', 'has_print']));
            $projectTemplate->save();

            $existingSteps = ProjectTemplateStep::query()
                ->where('project_template_id', $projectTemplate->id)
                ->get();

            $submittedStepIds = [];

            foreach ($this->input('project_template_steps', []) as $stepData) {
                $stepId = data_get($stepData, 'id');

                $step = $stepId
                    ? $existingSteps->firstWhere('id', $stepId)
                    : null;

                $step ??= new ProjectTemplateStep();

                $step->fill([
                    'tenant_id' => $this->tenant_id,
                    'project_template_id' => $projectTemplate->id,
                    'document_workflow_detail_id' => data_get($stepData, 'document_workflow_detail_id'),
                    'name' => data_get($stepData, 'name'),
                    'desc' => data_get($stepData, 'desc'),
                    'order_no' => data_get($stepData, 'order_no'),
                    'need_approval' => data_get($stepData, 'need_approval'),
                    'has_print' => data_get($stepData, 'has_print'),
                    'term' => data_get($stepData, 'term'),
                    'estimation_time_in_term' => data_get($stepData, 'estimation_time_in_term', 0),
                ]);
                $step->save();

                $submittedStepIds[] = $step->id;

                $this->syncStepForms($step->id, data_get($stepData, 'forms', []));
            }

            // step yang dihapus di form ikut dihapus
            foreach ($existingSteps as $existingStep) {
                if (in_array($existingStep->id, $submittedStepIds, true)) {
                    continue;
                }

                DB::table('form_project_step_template')
                    ->where('project_template_step_id', $existingStep->id)
                    ->delete();
                $existingStep->delete();
            }

            return $projectTemplate->load('projectTemplateSteps');
        });
    }

    /**
     * Ganti seluruh form milik satu step (pivot hanya berisi step_id + form_template_id).
     */
    private function syncStepForms(int $stepId, array $forms): void
    {
        DB::table('form_project_step_template')
            ->where('project_template_step_id', $stepId)
            ->delete();

        $formIds = collect($forms)
            ->pluck('form_template_id')
            ->filter()
            ->unique();

        foreach ($formIds as $formTemplateId) {
            DB::table('form_project_step_template')->insert([
                'project_template_step_id' => $stepId,
                'form_template_id' => $formTemplateId,
            ]);
        }
    }
}
