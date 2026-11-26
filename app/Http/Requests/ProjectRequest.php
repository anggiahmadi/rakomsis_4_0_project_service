<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\ProjectStep;
use App\Models\ProjectTemplateStep;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProjectRequest extends FormRequest
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
            'project_template_id' => 'required|exists:project_templates,id',
            'document_workflow_detail_id' => 'nullable|integer',
            'source' => 'required|string|max:255',
            'source_id' => 'nullable|integer',
            'source_info' => 'nullable|string|max:255',
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'desc' => 'nullable|string|max:500',
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date|gte:started_at',
            'status' => 'required|in:draft,in progress,completed,on hold,cancelled',
            'project_steps' => 'required|array',
            'project_steps.*.id' => 'nullable|integer',
            'project_steps.*.project_template_step_id' => 'nullable|integer|exists:project_template_steps,id',
            'project_steps.*.document_workflow_detail_id' => 'nullable|integer',
            'project_steps.*.name' => 'required|string|max:255',
            'project_steps.*.desc' => 'nullable|string',
            'project_steps.*.order_no' => 'required|integer',
            'project_steps.*.need_approval' => 'required|boolean',
            'project_steps.*.has_print' => 'required|boolean',
            'project_steps.*.term' => 'required|in:no term,hour,day,week,month,year',
            'project_steps.*.estimation_time_in_term' => 'nullable|integer',
            'project_steps.*.started_at' => 'nullable|date',
            'project_steps.*.ended_at' => 'nullable|date|gte:started_at',
            'project_steps.*.forms' => 'nullable|array',
            'project_steps.*.forms.*.form_template_id' => 'required|integer|distinct',
        ];
    }

    public function store()
    {
        $this->validate([
            'code' => Rule::unique('projects')->where('tenant_id', $this->tenant_id),
        ]);

        $data = $this->validated();

        $submittedSteps = $data['project_steps'];

        unset($data['project_steps']);

        DB::beginTransaction();

        try {
            $project = Project::create($data);

            $templateSteps = ProjectTemplateStep::query()
                ->where('project_template_id', $project->project_template_id)
                ->get()
                ->keyBy('order_no');

            foreach ($submittedSteps as $stepData) {
                $step = $this->saveProjectStep($project, $stepData, $templateSteps);

                $this->syncStepForms($project, $step, data_get($stepData, 'forms', []));
            }

            $this->calculateEstimationEndDates($project);

            DB::commit();

            return $project->load('projectSteps');
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function calculateEstimationEndDates(Project $project): void
    {
        $estimatedEnd = Carbon::parse($project->started_at ?? $project->created_at);

        foreach ($project->projectSteps()->orderBy('order_no')->orderBy('id')->get() as $step) {
            $duration = (int) $step->estimation_time_in_term;

            match ($step->term) {
                'hour' => $estimatedEnd->addHours($duration),
                'day' => $estimatedEnd->addDays($duration),
                'week' => $estimatedEnd->addWeeks($duration),
                'month' => $estimatedEnd->addMonths($duration),
                'year' => $estimatedEnd->addYears($duration),
                'no term' => null,
            };

            $step->estimation_ended_at = $estimatedEnd->toDateTimeString();
            $step->save();
        }

        $project->estimation_ended_at = $estimatedEnd->toDateTimeString();
        $project->save();
    }

    public function update(int $id)
    {
        $this->validate([
            'code' => Rule::unique('projects')
                ->where('tenant_id', $this->tenant_id)
                ->ignore($id),
        ]);

        $data = $this->validated();

        $submittedSteps = $data['project_steps'];

        unset($data['project_steps']);

        DB::beginTransaction();

        try {
            $project = Project::query()
                ->where('tenant_id', $this->tenant_id)
                ->findOrFail($id);

            $project->fill($data);

            $project->save();

            $templateSteps = ProjectTemplateStep::query()
                ->where('project_template_id', $project->project_template_id)
                ->get()
                ->keyBy('order_no');
            $submittedStepIds = [];

            foreach ($submittedSteps as $stepData) {
                $step = $this->saveProjectStep($project, $stepData, $templateSteps);
                $submittedStepIds[] = $step->id;
                $this->syncStepForms($project, $step, data_get($stepData, 'forms', []));
            }

            $removedSteps = ProjectStep::query()
                ->where('project_id', $project->id)
                ->when($submittedStepIds, fn ($query) => $query->whereNotIn('id', $submittedStepIds));

            foreach ($removedSteps->get() as $removedStep) {
                DB::table('form_project_step')
                    ->where('project_step_id', $removedStep->id)
                    ->delete();
                $removedStep->delete();
            }

            DB::commit();

            return $project->load('projectSteps');
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function saveProjectStep(Project $project, array $stepData, Collection $templateSteps): ProjectStep
    {
        $templateStepId = data_get($stepData, 'project_template_step_id')
            ?? data_get($templateSteps->get(data_get($stepData, 'order_no')), 'id');

        if (! $templateStepId || ! $templateSteps->contains('id', $templateStepId)) {
            throw ValidationException::withMessages([
                'project_steps' => 'Each project step must match a step in the selected project template.',
            ]);
        }

        $stepId = data_get($stepData, 'id');

        $step = $stepId
            ? ProjectStep::query()
                ->where('project_id', $project->id)
                ->findOrFail($stepId)
            : new ProjectStep;

        $step->fill([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'project_template_step_id' => $templateStepId,
            'name' => data_get($stepData, 'name'),
            'desc' => data_get($stepData, 'desc'),
            'order_no' => data_get($stepData, 'order_no'),
            'need_approval' => data_get($stepData, 'need_approval'),
            'has_print' => data_get($stepData, 'has_print'),
            'term' => data_get($stepData, 'term'),
            'estimation_time_in_term' => data_get($stepData, 'estimation_time_in_term') ?? 0,
            'started_at' => data_get($stepData, 'started_at'),
            'ended_at' => data_get($stepData, 'ended_at'),
        ]);
        $step->save();

        return $step;
    }

    private function syncStepForms(Project $project, ProjectStep $step, array $forms): void
    {
        $existingForms = DB::table('form_project_step')
            ->where('project_step_id', $step->id)
            ->get()
            ->keyBy('template_form_id');
        $submittedFormIds = [];

        foreach ($forms as $form) {
            $formTemplateId = (int) data_get($form, 'form_template_id');
            $submittedFormIds[] = $formTemplateId;
            $existingForm = $existingForms->get($formTemplateId);

            if ($existingForm && $existingForm->custom_form_id !== null) {
                continue;
            }

            if (! $existingForm) {
                DB::table('form_project_step')->insert([
                    'project_step_id' => $step->id,
                    'template_form_id' => $formTemplateId,
                    'custom_form_id' => null,
                ]);
            }

            $customFormId = $this->createCustomForm(
                (int) $project->tenant_id,
                $formTemplateId,
                (int) $step->id
            );

            DB::table('form_project_step')
                ->where('project_step_id', $step->id)
                ->where('template_form_id', $formTemplateId)
                ->update(['custom_form_id' => $customFormId]);
        }

        DB::table('form_project_step')
            ->where('project_step_id', $step->id)
            ->when($submittedFormIds, fn ($query) => $query->whereNotIn('template_form_id', $submittedFormIds))
            ->delete();
    }

    private function createCustomForm(int $tenantId, int $formTemplateId, int $projectStepId): int
    {
        $baseUri = config('services.files.base_uri');
        $secret = config('services.files.secret');

        if (! $baseUri || ! $secret) {
            throw new RuntimeException('File service URL and secret must be configured.');
        }

        $template = Http::timeout(15)
            ->withHeaders(['Authorization' => $secret])
            ->get(rtrim($baseUri, '/').'/api/form_templates/'.$formTemplateId)
            ->throw()
            ->json('data');

        if (! is_array($template) || ! in_array($template['type'] ?? null, ['single', 'multiple', 'matrix'], true)) {
            throw new RuntimeException('File service did not return a valid form template type.');
        }

        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => $secret])
            ->post(rtrim($baseUri, '/').'/api/custom_forms', [
                'tenant_id' => $tenantId,
                'form_template_id' => $formTemplateId,
                'source' => 'project_step',
                'source_id' => $projectStepId,
                'type' => $template['type'],
            ])
            ->throw();

        $responseBody = $response->json();
        $customFormId = data_get($responseBody, 'data.id')
            ?? data_get($responseBody, 'id');

        if (! is_numeric($customFormId) || (int) $customFormId < 1) {
            throw new RuntimeException('File service did not return a custom form ID.');
        }

        return (int) $customFormId;
    }
}
