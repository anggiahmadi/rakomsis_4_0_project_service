<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\Project;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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

        $project->projectSteps->each(function ($detail) {
            $detail->forms;
        });

        return $this->successResponse($project);
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
}
