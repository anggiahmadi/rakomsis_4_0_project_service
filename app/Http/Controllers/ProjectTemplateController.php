<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectTemplateRequest;
use App\Models\ProjectTemplate;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class ProjectTemplateController extends Controller
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
    public function store(ProjectTemplateRequest $request)
    {
        return $this->successResponse($request->store(), Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $projectTemplate = ProjectTemplate::with(['projectTemplateSteps'])->findOrFail($id);

        $projectTemplate->projectTemplateSteps->each(function ($detail) {
            $detail->forms;
        });

        return $this->successResponse($projectTemplate);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
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

            $data = ProjectTemplate::withTrashed()->findOrFail($id);

            $data->forceDelete();

            DB::commit();
        } else {
            $data = ProjectTemplate::findOrFail($id);

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

        $data = ProjectTemplate::where('tenant_id', $request->tenant_id)
            ->where('code', $request->code)
            ->first();

        return $this->successResponse($data);
    }

    public function getCode(Request $request)
    {
        $request->validate(['tenant_id' => 'required|integer']);

        return $this->successResponse(
            Controller::getMasterCode('project_templates', 'PT', $request->tenant_id, $request)
        );
    }

    public function getData(object $request)
    {
        $query = ProjectTemplate::query()->where('tenant_id', $request->tenant_id);

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
        $query = $this->getData($request)->with([
            'projectTemplateSteps' => fn($q) => $q->orderBy('order_no')->withCount('forms'),
        ]);

        // urutan default (created_at desc) dari getData() dibuang kalau DataTables kirim sort
        if ($request->has('order')) {
            $query->reorder();
        }

        return datatables()->eloquent($query)
            ->addColumn('steps', fn($row) => $row->projectTemplateSteps->map(fn($step) => [
                'id' => $step->id,
                'name' => $step->name,
                'forms_count' => $step->forms_count,
            ])->values())
            ->addColumn('forms_count', fn($row) => $row->projectTemplateSteps->sum('forms_count'))
            ->toJson();
    }
}
