<?php

use App\Http\Middleware\AuthenticateAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function projectPayload(array $steps, ?string $startedAt = null): array
{
    $templateId = DB::table('project_templates')->insertGetId([
        'tenant_id' => 1,
        'code' => 'TEMPLATE',
        'name' => 'Template',
    ]);

    foreach ($steps as &$step) {
        $step['project_template_step_id'] = DB::table('project_template_steps')->insertGetId([
            'tenant_id' => 1,
            'project_template_id' => $templateId,
            'name' => $step['name'],
            'order_no' => $step['order_no'],
        ]);
        $step += ['need_approval' => false, 'has_print' => false];
    }

    return [
        'tenant_id' => 1,
        'project_template_id' => $templateId,
        'source' => 'internal',
        'code' => 'PROJECT',
        'name' => 'Project',
        'status' => 'draft',
        'started_at' => $startedAt,
        'project_steps' => $steps,
    ];
}

test('creation calculates sequential estimates in step order for every term', function () {
    $payload = projectPayload([
        ['name' => 'No duration', 'order_no' => 7, 'term' => 'day', 'estimation_time_in_term' => null],
        ['name' => 'No term', 'order_no' => 6, 'term' => 'no term', 'estimation_time_in_term' => 4],
        ['name' => 'Years', 'order_no' => 5, 'term' => 'year', 'estimation_time_in_term' => 1],
        ['name' => 'Months', 'order_no' => 4, 'term' => 'month', 'estimation_time_in_term' => 1],
        ['name' => 'Weeks', 'order_no' => 3, 'term' => 'week', 'estimation_time_in_term' => 1],
        ['name' => 'Days', 'order_no' => 2, 'term' => 'day', 'estimation_time_in_term' => 1],
        ['name' => 'Hours', 'order_no' => 1, 'term' => 'hour', 'estimation_time_in_term' => 2, 'started_at' => '2026-03-01 10:00:00'],
    ], '2026-01-15 10:00:00');

    $this->withoutMiddleware(AuthenticateAccess::class)
        ->postJson('/api/projects', $payload)
        ->assertCreated()
        ->assertJsonPath('data.estimation_ended_at', '2027-02-23 12:00:00')
        ->assertJsonPath('data.project_steps.6.estimation_ended_at', '2026-01-15 12:00:00');

    $estimates = DB::table('project_steps')->orderBy('order_no')->pluck('estimation_ended_at')->all();
    expect($estimates)->toBe([
        '2026-01-15 12:00:00',
        '2026-01-16 12:00:00',
        '2026-01-23 12:00:00',
        '2026-02-23 12:00:00',
        '2027-02-23 12:00:00',
        '2027-02-23 12:00:00',
        '2027-02-23 12:00:00',
    ]);
    expect(DB::table('projects')->value('estimation_ended_at'))->toBe('2027-02-23 12:00:00');
    expect(DB::table('project_steps')->where('order_no', 7)->value('estimation_time_in_term'))->toBe(0);
});

test('creation uses the creation time when the project has no start date', function () {
    Carbon::setTestNow('2026-05-04 09:00:00');

    try {
        $payload = projectPayload([
            ['name' => 'Step', 'order_no' => 1, 'term' => 'hour', 'estimation_time_in_term' => 3],
        ]);

        $this->withoutMiddleware(AuthenticateAccess::class)
            ->postJson('/api/projects', $payload)
            ->assertCreated();

        expect(DB::table('project_steps')->value('estimation_ended_at'))->toBe('2026-05-04 12:00:00');
        expect(DB::table('projects')->value('estimation_ended_at'))->toBe('2026-05-04 12:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('the estimation migration adds columns to existing tables', function () {
    Schema::table('projects', fn ($table) => $table->dropColumn('estimation_ended_at'));
    Schema::table('project_steps', fn ($table) => $table->dropColumn('estimation_ended_at'));

    $migration = require database_path('migrations/2026_11_26_000001_add_estimation_end_dates_to_projects_and_steps.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('projects', 'estimation_ended_at'))->toBeTrue();
    expect(Schema::hasColumn('project_steps', 'estimation_ended_at'))->toBeTrue();
});
