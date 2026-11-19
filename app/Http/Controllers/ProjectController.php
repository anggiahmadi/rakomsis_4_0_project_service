<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\Project;
use App\Models\ProjectTemplate;
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

    public function dashboard(Request $request)
    {
        $request->validate(['tenant_id' => 'required|integer']);

        $projects = Project::query()
            ->with([
                'projectSteps' => fn($query) => $query->orderBy('order_no'),
                'projectTemplate',
            ])
            ->where('tenant_id', $request->tenant_id)
            ->orderByDesc('updated_at')
            ->get();

        $active = $projects->whereIn('status', ['draft', 'in progress', 'on hold']);
        $completed = $projects->where('status', 'completed');
        $noSource = $active->whereNull('source_id');

        $rows = $projects->map(function (Project $project) {
            $total = $project->projectSteps->count();
            $done = $project->projectSteps->whereNotNull('ended_at')->count();
            $progress = $total > 0 ? (int) round($done / $total * 100) : 0;
            $activeStep = $project->projectSteps->first(
                fn($step) => $step->ended_at === null,
            ) ?? $project->projectSteps->last();
            $issues = [];

            if (! $project->source_id && $project->status !== 'completed') {
                $issues[] = [
                    'tone' => 'warn',
                    'tag' => 'Sales Order',
                    'text' => 'Belum memilih Sales Order',
                ];
            }

            if (
                $project->status !== 'completed' &&
                $project->updated_at?->lt(now()->subDays(7))
            ) {
                $issues[] = [
                    'tone' => 'warn',
                    'tag' => 'Draft diam',
                    'text' => 'Tidak ada perubahan lebih dari 7 hari',
                ];
            }

            if ($total > 0 && $done < $total && $total - $done <= 2 && $done > 0) {
                $issues[] = [
                    'tone' => 'acc',
                    'tag' => 'Hampir selesai',
                    'text' => 'Tinggal ' . ($total - $done) . ' step lagi',
                ];
            }

            usort($issues, fn($a, $b) => strlen($b['text']) <=> strlen($a['text']));

            return [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'source_id' => $project->source_id,
                'template_name' => $project->projectTemplate?->name,
                'template_id' => $project->project_template_id,
                'status' => $project->status,
                'steps_done' => $done,
                'steps_total' => $total,
                'progress' => $progress,
                'last_step' => $activeStep?->name,
                'updated_at' => $project->updated_at,
                'issues' => $issues,
                'todos_total' => 0,
                'todos_done' => 0,
                'next_todo' => null,
            ];
        })->sortByDesc(
            fn($project) => count($project['issues']) * 1000 + $project['progress'],
        )->values();

        $templates = ProjectTemplate::query()
            ->where('tenant_id', $request->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'total' => $projects->where(
                    'project_template_id',
                    $template->id,
                )->count(),
            ]);

        return $this->successResponse([
            'summary' => [
                'total' => $projects->count(),
                'active' => $active->count(),
                'completed' => $completed->count(),
                'no_customer' => $noSource->count(),
                'avg_progress' => (int) round($active->avg(
                    fn($project) => $project->projectSteps->count()
                        ? $project->projectSteps->whereNotNull('ended_at')->count()
                        / $project->projectSteps->count() * 100
                        : 0,
                ) ?? 0),
            ],
            'projects' => $rows,
            'templates' => $templates,
        ]);
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
        $allResults = $formsById
            ->flatMap(fn($form) => $form['custom_form_results'] ?? [])
            ->values();
        $project->projectSteps->each(function ($step) use ($formsById, $allResults) {
            $step->forms->each(function ($form) use ($formsById, $allResults) {
                $customForm = $formsById->get((int) $form->custom_form_id, []);
                unset($customForm['id']);
                $customForm['custom_form_results'] = collect(
                    $customForm['custom_form_results'] ?? [],
                )->map(function ($result) use ($allResults) {
                    $result['horizontal_items'] = collect(
                        $allResults->where(
                            'field_template_id',
                            $result['horizontal_field_template_id'] ?? null,
                        ),
                    )->map(fn($item) => [
                        'id' => $item['answer_no'] . '-' . $item['field_template_id'],
                        'label' => $item['value'] ?? '',
                    ])->filter(fn($item) => filled($item['label']))->values();
                    $result['vertical_items'] = collect(
                        $allResults->where(
                            'field_template_id',
                            $result['vertical_field_template_id'] ?? null,
                        ),
                    )->map(fn($item) => [
                        'id' => $item['answer_no'] . '-' . $item['field_template_id'],
                        'label' => $item['value'] ?? '',
                    ])->filter(fn($item) => filled($item['label']))->values();

                    return $result;
                })->values();

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
            'answers' => 'nullable|array|required_without:rows',
            'rows' => 'nullable|array|required_without:answers',
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
            ->get(rtrim($baseUri, '/') . '/api/custom_forms/' . $formId)
            ->throw()
            ->json('data');

        if (! is_array($form)) {
            throw new RuntimeException('File service returned an invalid custom form.');
        }

        if (! empty($form['form_template_id'])) {
            $template = Http::timeout(15)
                ->withHeaders(['Authorization' => $secret])
                ->get(rtrim($baseUri, '/') . '/api/form_templates/' . $form['form_template_id'])
                ->throw()
                ->json('data');

            // FIX: validasi template, sama seperti di loadCustomForms
            if (! is_array($template)) {
                throw new RuntimeException('File service returned an invalid form template.');
            }

            $form['type'] = $template['type'] ?? $form['type'];
            if (empty($form['custom_form_results'])) {
                $form['custom_form_results'] = collect($template['fields'] ?? [])
                    ->map(fn($field) => [
                        'id' => null, // FIX: sebelumnya $field['id'] (ID template, bukan ID baris DB)
                        'custom_form_id' => (int) $formId, // FIX
                        'field_template_id' => $field['id'],
                        'horizontal_field_template_id' =>
                        data_get($field, 'pivot.horizontal_field_template_id'),
                        'vertical_field_template_id' =>
                        data_get($field, 'pivot.vertical_field_template_id'),
                        'is_required' => (bool) data_get($field, 'pivot.is_required'),
                        'is_abnormal' => false,
                        'has_file_upload' => (bool) data_get($field, 'pivot.has_file_upload'),
                        'has_photo_if_abnormal' => (bool) data_get($field, 'pivot.has_photo_if_abnormal'),
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
            } else {
                $templateFields = collect($template['fields'] ?? [])->keyBy('id');
                $form['custom_form_results'] = collect($form['custom_form_results'])
                    ->map(function ($result) use ($templateFields) {
                        $field = $templateFields->get($result['field_template_id']);
                        if (! $field) {
                            return $result;
                        }

                        return array_merge($result, [
                            'horizontal_field_template_id' =>
                            data_get($field, 'pivot.horizontal_field_template_id'),
                            'vertical_field_template_id' =>
                            data_get($field, 'pivot.vertical_field_template_id'),
                            'has_file_upload' => (bool) data_get($field, 'pivot.has_file_upload'),
                            'is_required' => (bool) data_get($field, 'pivot.is_required'),
                            'has_photo_if_abnormal' => (bool) data_get($field, 'pivot.has_photo_if_abnormal'),
                        ]);
                    })->values()->all();
            }
        }

        // FIX: file service (CustomFormController@show) membuat baris virtual dari template
        // dengan id = ID field template. Baris asli dari DB selalu punya created_at,
        // baris virtual tidak. Kosongkan id baris virtual agar dibuat baru, bukan meng-update id palsu.
        $form['custom_form_results'] = collect($form['custom_form_results'] ?? [])
            ->map(function ($result) use ($formId) {
                if (empty($result['created_at'])) {
                    $result['id'] = null;
                }
                $result['custom_form_id'] = (int) $formId;

                return $result;
            })
            ->values()
            ->all();

        if (array_key_exists('rows', $validated)) {
            $existing = collect($form['custom_form_results']);
            $fields = $existing
                ->unique(fn($field) =>
                $field['field_template_id'] ?? 'runtime:' . $field['name'])
                ->values();
            $fields = $fields->concat(
                collect($validated['columns'] ?? [])->map(fn($column) => [
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
            )->unique(
                fn($field) =>
                $field['field_template_id'] ?? $field['_runtime_key'] ?? 'runtime:' . $field['name']
            )->values();

            $form['custom_form_results'] = collect($validated['rows'])
                ->flatMap(function ($values, $rowIndex) use ($fields, $existing, $formId) {
                    return $fields->values()->map(function ($field, $fieldIndex) use (
                        $values,
                        $rowIndex,
                        $existing,
                        $formId,
                    ) {
                        $fieldId = (string) (
                            $field['field_template_id'] ??
                            $field['_runtime_key'] ??
                            'runtime:' . $field['name']
                        );
                        $savedResult = $existing->first(
                            fn($result) =>
                            (string) (
                                $result['field_template_id'] ??
                                'runtime:' . $result['name']
                            ) === $fieldId &&
                                (int) ($result['answer_no'] ?? 1) === $rowIndex + 1,
                        );
                        $value = $values[$fieldId] ?? $values[(int) $fieldId] ?? null;
                        $photo = $values['photo:' . $fieldId] ?? null;
                        $filePath = is_array($value) && isset($value['content'])
                            ? $value['content']
                            : (is_array($photo) && isset($photo['content'])
                                ? $photo['content']
                                : ($savedResult['file_path'] ?? null));

                        return array_merge($field, [
                            'id' => $savedResult['id'] ?? null,
                            'custom_form_id' => (int) $formId, // FIX
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
                fn($value, $key) => [(string) $key => $value],
            );
            $form['custom_form_results'] = collect($form['custom_form_results'])
                ->map(function ($result) use ($answers) {
                    // FIX: kunci jawaban pakai field_template_id dulu (id baris bisa null)
                    $resultId = (string) ($result['id'] ?? '');
                    $fieldId = (string) ($result['field_template_id'] ?? $resultId);
                    $key = $answers->has($fieldId) ? $fieldId : $resultId;

                    if ($key !== '' && $answers->has($key)) {
                        $result['value'] = $answers->get($key);
                    }
                    $photoKey = 'photo:' . $key;
                    if ($answers->has($photoKey) && is_array($answers->get($photoKey))) {
                        $existingFile = $result['file_path'] ?? null;
                        $result['file_path'] = $answers->get($photoKey)['content'] ?? null;
                        $result['exising_file'] = $existingFile;
                    }

                    return $result;
                })
                ->values()
                ->all();
        }

        // FIX: log respons supaya kegagalan simpan di file service terlihat
        $response = Http::timeout(15)
            ->withHeaders([
                'Authorization' => $secret,
                'Accept' => 'application/json',
            ])
            ->put(rtrim($baseUri, '/') . '/api/custom_forms/' . $formId, $form);

        return response()->json([
            'debug' => true,
            'put_status' => $response->status(),
            'put_body' => $response->json() ?? $response->body(),
        ]);

        \Log::info('saveForm PUT', [
            'form_id' => $formId,
            'sent_count' => count($form['custom_form_results']),
            'sent_ids' => collect($form['custom_form_results'])->pluck('id'),
            'response' => $response->json(),
        ]);

        return $this->show($id);
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
            ->flatMap(fn($step) => $step->forms->pluck('custom_form_id'))
            ->filter()
            ->map(fn($id) => (int) $id)
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
                    ->get(rtrim($baseUri, '/') . '/api/custom_forms/' . $formId);
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
                        rtrim($baseUri, '/') . '/api/form_templates/' . $form['form_template_id'],
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
                    ->map(fn($field) => [
                        'id' => $field['id'],
                        'field_template_id' => $field['id'],
                        'horizontal_field_template_id' =>
                        data_get($field, 'pivot.horizontal_field_template_id'),
                        'vertical_field_template_id' =>
                        data_get($field, 'pivot.vertical_field_template_id'),
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
                    : collect($form['custom_form_results'])->map(function ($result) use (
                        $template,
                    ) {
                        $field = collect($template['fields'] ?? [])
                            ->firstWhere('id', $result['field_template_id']);
                        if (! $field) {
                            return $result;
                        }

                        return array_merge($result, [
                            'horizontal_field_template_id' => data_get(
                                $field,
                                'pivot.horizontal_field_template_id',
                            ),
                            'vertical_field_template_id' => data_get(
                                $field,
                                'pivot.vertical_field_template_id',
                            ),
                            'has_file_upload' => (bool) data_get(
                                $field,
                                'pivot.has_file_upload',
                            ),
                            'is_required' => (bool) data_get(
                                $field,
                                'pivot.is_required',
                            ),
                            'has_photo_if_abnormal' => (bool) data_get(
                                $field,
                                'pivot.has_photo_if_abnormal',
                            ),
                        ]);
                    })->values()->all();
            }

            return [$formId => $form];
        });
    }

    private function refreshProjectProgress(Project $project): void
    {
        $steps = $project->projectSteps()->orderBy('order_no')->get();
        $done = $steps->whereNotNull('ended_at')->count();
        $total = $steps->count();
        $nextStep = $steps->first(fn($step) => $step->ended_at === null);

        $project->progress = $total ? round($done / $total * 100) : 0;
        $project->current_step = $nextStep?->name ?? 'Completed';
        $project->status = $done === $total && $total > 0 ? 'completed' : 'in progress';
        $project->started_at ??= $steps->whereNotNull('started_at')->min('started_at');
        $project->ended_at = $project->status === 'completed' ? now() : null;
        $project->save();
    }
}
