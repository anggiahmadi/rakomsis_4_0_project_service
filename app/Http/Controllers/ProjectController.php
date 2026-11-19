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
        $project = Project::with(['projectSteps', 'projectTemplate'])->findOrFail($id);

        $formsById = $this->loadCustomForms($project->projectSteps);
        $project->projectSteps->each(function ($step) use ($formsById) {
            $step->setAttribute(
                'forms',
                collect($step->forms)->map(function ($form) use ($formsById) {
                    return array_merge(
                        (array) $form,
                        $formsById->get((int) $form->custom_form_id, []),
                    );
                })->values(),
            );
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
        $validated = $request->validate(['answers' => 'required|array']);
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

        $answers = collect($validated['answers'])->mapWithKeys(
            fn ($value, $key) => [(string) $key => $value],
        );
        $form['custom_form_results'] = collect($form['custom_form_results'] ?? [])
            ->map(function ($result) use ($answers) {
                $key = (string) ($result['id'] ?? '');

                if ($answers->has($key)) {
                    $result['value'] = $answers->get($key);
                }

                return $result;
            })
            ->values()
            ->all();

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
            ->flatMap(fn ($step) => collect($step->forms)->pluck('custom_form_id'))
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

        return $formIds->mapWithKeys(function ($formId) use ($responses) {
            $response = $responses[(string) $formId];
            $form = $response->throw()->json('data');

            if (! is_array($form)) {
                throw new RuntimeException("File service returned an invalid custom form {$formId}.");
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
