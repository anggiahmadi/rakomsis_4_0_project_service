<?php

namespace App\Http\Requests;

use App\Models\ProjectTemplate;
use App\Models\ProjectTemplateStep;
use DB;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Arr;

class ProjectTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => 'required|integer',
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'desc' => 'nullable|string',
            'project_template_steps' => 'required|array',
            'project_template_steps.*.name' => 'required|string|max:255',
            'project_template_steps.*.desc' => 'nullable|string',
            'project_template_steps.*.order_no' => 'required|integer',
            'project_template_steps.*.need_approval' => 'required|boolean',
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
            $this->only(['tenant_id', 'code', 'name', 'desc'])
        );

        foreach ($this->input('project_template_steps', []) as $stepData) {
            $step = ProjectTemplateStep::create(array_merge(
                Arr::except($stepData, ['id', 'forms']),
                [
                    'tenant_id' => $this->tenant_id,
                    'project_template_id' => $projectTemplate->id,
                ]
            ));

            foreach (data_get($stepData, 'forms', []) as $form) {
                $formTemplateId = data_get($form, 'form_template_id');

                if ($formTemplateId === null) {
                    continue;
                }

                DB::table('form_project_step_template')->insert([
                    'project_template_step_id' => $step->id,
                    'form_template_id' => $formTemplateId,
                    'order_no' => data_get($form, 'order_no', 0),
                ]);
            }
        }

        return $projectTemplate;
    });
}

    public function update($id)
    {
        $this->validate([
            'code' => Rule::unique('project_templates')->where('tenant_id', $this->tenant_id)->ignore($id),
        ]);

        DB::beginTransaction();

        $projectTemplate = ProjectTemplate::findOrFail($id);
        $projectTemplate->fill($this->all());
        $projectTemplate->save();

        $submittedSteps = $this->input('project_template_steps', []);
        $existingSteps = ProjectTemplateStep::query()
            ->where('project_template_id', $projectTemplate->id)
            ->get()
            ->keyBy('id');

        $submittedStepIds = [];

        foreach ($submittedSteps as $stepData) {
            $stepId = data_get($stepData, 'id');
            $step = $stepId ? ProjectTemplateStep::query()
                ->where('id', $stepId)
                ->where('project_template_id', $projectTemplate->id)
                ->first() : null;

            if (!$step) {
                $step = new ProjectTemplateStep();
            }

            $step->fill([
                'tenant_id' => $this->tenant_id,
                'project_template_id' => $projectTemplate->id,
                'name' => data_get($stepData, 'name'),
                'desc' => data_get($stepData, 'desc'),
                'order_no' => data_get($stepData, 'order_no'),
                'need_approval' => data_get($stepData, 'need_approval'),
                'term' => data_get($stepData, 'term'),
                'estimation_time_in_term' => data_get($stepData, 'estimation_time_in_term', 0),
            ]);
            $step->save();

            $submittedStepIds[] = $step->id;

            $forms = data_get($stepData, 'forms', []);

            if (empty($forms) && $step->exists) {
                DB::table('form_project_step_template')->where('project_template_step_id', $step->id)->delete();
                continue;
            }

            DB::table('form_project_step_template')->where('project_template_step_id', $step->id)->delete();

            foreach ($forms as $form) {
                $formTemplateId = data_get($form, 'form_template_id');

                if ($formTemplateId === null) {
                    continue;
                }

                DB::table('form_project_step_template')->updateOrInsert(
                    [
                        'project_template_step_id' => $step->id,
                        'form_template_id' => $formTemplateId,
                    ],
                    [
                        'order_no' => data_get($form, 'order_no', 0),
                    ]
                );
            }
        }

        foreach ($existingSteps as $existingStep) {
            if (in_array($existingStep->id, $submittedStepIds, true)) {
                continue;
            }

            DB::table('form_project_step_template')->where('project_template_step_id', $existingStep->id)->delete();
            $existingStep->delete();
        }

        DB::commit();

        return $projectTemplate->load('projectTemplateSteps');
    }
}
