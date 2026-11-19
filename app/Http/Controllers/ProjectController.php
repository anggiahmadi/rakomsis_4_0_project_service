<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\Project;
use App\Traits\ApiResponser;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yajra\DataTables\Facades\DataTables;
class ProjectController extends Controller
{
    use ApiResponser;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $this->getData($request);

        $request->has('page')
            ? $query = $query->paginate($this->getPerPage($request->total_per_page))
            : $query = $query->get();

        return $this->successResponse($query);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProjectRequest $request)
    {
        return $this->successResponse($request->store(), Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $project = Project::with([
            'projectSteps.forms',
            'projectTemplate',
        ])->findOrFail($id);

        $formsById = $this->loadCustomForms($project->projectSteps);
        $project->projectSteps->each(function ($step) use ($formsById) {
            $step->forms->each(function ($form) use ($formsById) {
                $customForm = $formsById->get((int) $form->custom_form_id, []);
                unset($customForm['id']);

                $form->setRawAttributes(array_merge(
                    $form->getAttributes(),
                    $customForm,
                ));
            });
        });

        return $this->successResponse($project);
    }

    public function finishStep(Request $request, string $id, string $stepId)
    {
        $project = Project::findOrFail($id);
        $step = $project->projectSteps()->findOrFail($stepId);

        abort_if(
            $project->projectSteps()
                ->where('order_no', '<', $step->order_no)
                ->whereNull('ended_at')
                ->exists(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Selesaikan step sebelumnya terlebih dahulu.',
        );

        $step->started_at ??= now();
        $step->ended_at = now();
        $step->save();

        $this->refreshProjectProgress($project);

        return $this->show($project->id);
    }

    public function unlockStep(Request $request, string $id, string $stepId)
    {
        $request->validate(['reason' => 'required|string|max:1000']);

        $project = Project::findOrFail($id);
        $step = $project->projectSteps()->findOrFail($stepId);
        abort_unless(
            $step->ended_at,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Step belum terkunci.',
        );

        $step->ended_at = null;
        $step->save();

        $this->refreshProjectProgress($project);

        return $this->show($project->id);
    }

    public function saveForm(Request $request, string $id, string $formId)
    {
        $validated = $request->validate([
            'answers' => 'nullable|array',
            'rows' => 'nullable|array',
            'rows.*' => 'array',
            'columns' => 'nullable|array',
            'columns.*.key' => 'required|string',
            'columns.*.name' => 'required|string|max:255',
            'columns.*.type' => 'required|in:text,number,date',
            'columns.*.required' => 'required|boolean',
            'columns.*.options' => 'nullable|array',
        ]);
        $project = Project::findOrFail($id);
        $stepIds = $project->projectSteps()->pluck('id');
        $pivot = DB::table('form_project_step')
            ->whereIn('project_step_id', $stepIds)
            ->where('custom_form_id', $formId)
            ->first();

        abort_unless($pivot, Response::HTTP_NOT_FOUND, 'Form project tidak ditemukan.');

        $baseUri = config('services.files.base_uri');
        $secret = config('services.files.secret');
        if (! $baseUri || ! $secret) {
            throw new RuntimeException('File service URL and secret must be configured.');
        }

        $form = Http::timeout(15)
            ->withHeaders(['Authorization' => $secret])
            ->get(rtrim($baseUri, '/').'/api/custom_forms/'.$formId)
            ->throw()
            ->json('data');

        if (! is_array($form)) {
            throw new RuntimeException('File service returned an invalid custom form.');
        }

        if (! empty($form['form_template_id'])) {
            $template = Http::timeout(15)
                ->withHeaders(['Authorization' => $secret])
                ->get(rtrim($baseUri, '/').'/api/form_templates/'.$form['form_template_id'])
                ->throw()
                ->json('data');

            $form['type'] = $template['type'] ?? $form['type'];
            if (empty($form['custom_form_results'])) {
                $form['custom_form_results'] = collect($template['fields'] ?? [])
                    ->map(fn ($field) => [
                        'id' => $field['id'],
                        'field_template_id' => $field['id'],
                        'is_required' => (bool) data_get($field, 'pivot.is_required'),
                        'is_abnormal' => false,
                        'has_file_upload' => (bool) data_get(
                            $field,
                            'pivot.has_file_upload',
                        ),
                        'has_photo_if_abnormal' => (bool) data_get(
                            $field,
                            'pivot.has_photo_if_abnormal',
                        ),
                        'name' => $field['name'],
                        'desc' => $field['desc'] ?? null,
                        'answer_no' => 1,
                        'order_no' => data_get($field, 'pivot.order_no', 0),
                        'value' => null,
                        'file_path' => null,
                        'type' => $field['type'],
                        'options' => $field['options'] ?? [],
                    ])
                    ->values()
                    ->all();
            }
        }

        if (array_key_exists('rows', $validated)) {
            $existing = collect($form['custom_form_results'] ?? []);
            $fields = $existing
                ->unique(fn ($field) =>
                    $field['field_template_id'] ?? 'runtime:'.$field['name'])
                ->values();
            $fields = $fields->concat(
                collect($validated['columns'] ?? [])->map(fn ($column) => [
                    '_runtime_key' => $column['key'],
                    'field_template_id' => null,
                    'is_required' => $column['required'],
                    'is_abnormal' => false,
                    'has_file_upload' => false,
                    'has_photo_if_abnormal' => false,
                    'name' => $column['name'],
                    'desc' => null,
                    'type' => $column['type'],
                    'options' => $column['options'] ?? [],
                ]),
            )->unique(fn ($field) =>
                $field['field_template_id'] ?? $field['_runtime_key'] ?? 'runtime:'.$field['name']
            )->values();

            $form['custom_form_results'] = collect($validated['rows'])
                ->flatMap(function ($values, $rowIndex) use ($fields, $existing) {
                    return $fields->values()->map(function ($field, $fieldIndex) use (
                        $values,
                        $rowIndex,
                        $existing,
                    ) {
                        $fieldId = (string) (
                            $field['field_template_id'] ??
                            $field['_runtime_key'] ??
                            'runtime:'.$field['name']
                        );
                        $savedResult = $existing->first(
                            fn ($result) =>
                                (string) (
                                    $result['field_template_id'] ??
                                    'runtime:'.$result['name']
                                ) === $fieldId &&
                                (int) ($result['answer_no'] ?? 1) === $rowIndex + 1,
                        );
                        $value = $values[$fieldId] ?? $values[(int) $fieldId] ?? null;
                        $photo = $values['photo:'.$fieldId] ?? null;
                        $filePath = is_array($value) && isset($value['content'])
                            ? $value['content']
                            : (is_array($photo) && isset($photo['content'])
                                ? $photo['content']
                                : ($savedResult['file_path'] ?? null));

                        return array_merge($field, [
                            'id' => $savedResult['id'] ?? null,
                            'answer_no' => $rowIndex + 1,
                            'order_no' => $fieldIndex,
                            'value' => is_array($value) && isset($value['name'])
                                ? $value['name']
                                : $value,
                            'file_path' => $filePath,
                            'exising_file' => $savedResult['file_path'] ?? null,
                        ]);
                    });
                })
                ->values()
                ->all();
        } else {
            $answers = collect($validated['answers'] ?? [])->mapWithKeys(
                fn ($value, $key) => [(string) $key => $value],
            );
            $form['custom_form_results'] = collect($form['custom_form_results'] ?? [])
                ->map(function ($result) use ($answers) {
                    $key = (string) ($result['id'] ?? '');

                    if ($answers->has($key)) {
                        $result['value'] = $answers->get($key);
                    }
                    $photoKey = 'photo:'.$key;
                    if (
                        $answers->has($photoKey) &&
                        is_array($answers->get($photoKey))
                    ) {
                        $existingFile = $result['file_path'] ?? null;
                        $result['file_path'] = $answers->get($photoKey)['content'] ?? null;
                        $result['exising_file'] = $existingFile;
                    }

                    return $result;
                })
                ->values()
                ->all();
        }

        $saved = Http::timeout(15)
            ->withHeaders(['Authorization' => $secret])
            ->put(rtrim($baseUri, '/').'/api/custom_forms/'.$formId, $form)
            ->throw()
            ->json('data');

        return $this->successResponse($saved);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProjectRequest $request, string $id)
    {
        return $this->successResponse($request->update($id), Response::HTTP_CREATED);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        if ($request->is_permanent_delete) {
            DB::beginTransaction();

            $data = Project::withTrashed()->findOrFail($id);

            $data->forceDelete();

            DB::commit();
        } else {
            $data = Project::findOrFail($id);

            $data->delete();
        }

        return $this->successResponse('deleted data is successfull');
    }

    public function getByCode(Request $request)
    {
        $request->validate([
            'tenant_id' => 'required',
            'code' => 'required'
        ]);

        $data = Project::where('tenant_id', $request->tenant_id)
            ->where('code', $request->code)
            ->first();

        return $this->successResponse($data);
    }

    public function getCode(Request $request)
    {
        $request->validate(['tenant_id' => 'required|integer']);

        return $this->successResponse(
            Controller::getMasterCode('projects', 'PRJ', $request->tenant_id, $request)
        );
    }

    public function getData(object $request)
    {
        $query = Project::query()
            ->with([
                'projectSteps' => fn($q) => $q->orderBy('order_no'),
                'projectTemplate',
            ])
            ->where('tenant_id', $request->tenant_id);

        if ($request->data_type == 'softdelete') $query->onlyTrashed();

        $keyword = $request->input('search.value');

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%")
                    ->orWhere('desc', 'like', "%{$keyword}%");
            });
        }

        if ($request->filled('order_by') && $request->filled('sort_by')) {
            $query->orderBy($request->order_by, $request->sort_by);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }

    public function datatables(Request $request)
    {
        $query = $this->getData($request);

        // filter status: a = berjalan, l = selesai
        if ($request->status_filter === 'a') {
            $query->whereIn('status', ['draft', 'in progress', 'on hold']);
        } elseif ($request->status_filter === 'l') {
            $query->where('status', 'completed');
        }

        if ($request->has('order')) {
            $query->reorder();
        }

        $base = Project::query()->where('tenant_id', $request->tenant_id);

        return datatables()->eloquent($query)
            ->addColumn('steps_total', fn($row) => $row->projectSteps->count())
            ->addColumn('steps_done', fn($row) => $row->projectSteps->whereNotNull('ended_at')->count())
            ->addColumn('progress', function ($row) {
                $total = $row->projectSteps->count();

                return $total
                    ? (int) round($row->projectSteps->whereNotNull('ended_at')->count() / $total * 100)
                    : 0;
            })
            ->addColumn('last_step', function ($row) {
                $step = $row->projectSteps->first(fn($s) => is_null($s->ended_at))
                    ?? $row->projectSteps->last();

                return $step?->name;
            })
            ->with('summary', fn() => [
                'total' => (clone $base)->count(),
                'running' => (clone $base)->whereIn('status', ['draft', 'in progress', 'on hold'])->count(),
                'done' => (clone $base)->where('status', 'completed')->count(),
            ])
            ->toJson();
    }

    private function loadCustomForms($steps)
    {
        $formIds = $steps
            ->flatMap(fn ($step) => $step->forms->pluck('custom_form_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($formIds->isEmpty()) {
            return collect();
        }

        $baseUri = config('services.files.base_uri');
        $secret = config('services.files.secret');
        if (! $baseUri || ! $secret) {
            throw new RuntimeException('File service URL and secret must be configured.');
        }

        $responses = Http::pool(function (Pool $pool) use ($baseUri, $secret, $formIds) {
            foreach ($formIds as $formId) {
                $pool->as((string) $formId)
                    ->withHeaders(['Authorization' => $secret])
                    ->timeout(15)
                    ->get(rtrim($baseUri, '/').'/api/custom_forms/'.$formId);
            }
        });

        return $formIds->mapWithKeys(function ($formId) use (
            $responses,
            $baseUri,
            $secret,
        ) {
            $response = $responses[(string) $formId];
            $form = $response->throw()->json('data');

            if (! is_array($form)) {
                throw new RuntimeException("File service returned an invalid custom form {$formId}.");
            }

            if (! empty($form['form_template_id'])) {
                $template = Http::timeout(15)
                    ->withHeaders(['Authorization' => $secret])
                    ->get(
                        rtrim($baseUri, '/').'/api/form_templates/'.$form['form_template_id'],
                    )
                    ->throw()
                    ->json('data');

                if (! is_array($template)) {
                    throw new RuntimeException(
                        "File service returned an invalid form template {$form['form_template_id']}.",
                    );
                }

                $form['type'] = $template['type'] ?? $form['type'];
                $form['custom_form_results'] = empty($form['custom_form_results'])
                    ? collect($template['fields'] ?? [])
                    ->map(fn ($field) => [
                        'id' => $field['id'],
                        'field_template_id' => $field['id'],
                        'custom_form_id' => $formId,
                        'is_required' => (bool) data_get($field, 'pivot.is_required'),
                        'is_abnormal' => false,
                        'has_file_upload' => (bool) data_get(
                            $field,
                            'pivot.has_file_upload',
                        ),
                        'has_photo_if_abnormal' => (bool) data_get(
                            $field,
                            'pivot.has_photo_if_abnormal',
                        ),
                        'name' => $field['name'],
                        'desc' => $field['desc'] ?? null,
                        'answer_no' => 1,
                        'order_no' => data_get($field, 'pivot.order_no', 0),
                        'value' => null,
                        'file_path' => null,
                        'type' => $field['type'],
                        'options' => $field['options'] ?? [],
                    ])
                    ->values()
                    ->all()
                    : $form['custom_form_results'];
            }

            return [$formId => $form];
        });
    }

    private function refreshProjectProgress(Project $project): void
    {
        $steps = $project->projectSteps()->orderBy('order_no')->get();
        $done = $steps->whereNotNull('ended_at')->count();
        $total = $steps->count();
        $nextStep = $steps->first(fn ($step) => $step->ended_at === null);

        $project->progress = $total ? round($done / $total * 100) : 0;
        $project->current_step = $nextStep?->name ?? 'Completed';
        $project->status = $done === $total && $total > 0 ? 'completed' : 'in progress';
        $project->started_at ??= $steps->whereNotNull('started_at')->min('started_at');
        $project->ended_at = $project->status === 'completed' ? now() : null;
        $project->save();
    }
}
