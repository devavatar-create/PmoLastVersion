<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CabinetIndexOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabinets_are_sorted_by_nearest_due_date_with_days_remaining(): void
    {
        $this->travelTo('2026-09-24 09:00:00');

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'P', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $make = fn (string $mo, ?string $due, string $status = 'in_progress', ?string $expected = null) => Cabinet::forceCreate([
            'project_id' => $project->id, 'mo_no' => $mo, 'cabinet_name' => $mo, 'quantity' => 1,
            'status' => $status, 'progress' => 0, 'due_date' => $due, 'expected_completion_date' => $expected,
        ]);

        $make('MO-FAR', '2026-12-01');
        $make('MO-NODATE', null);
        $make('MO-DONE', '2026-09-01', 'completed');
        $make('MO-LATE', '2026-09-20', 'in_progress', '2026-09-30');
        $make('MO-SOON', '2026-09-27');
        $make('MO-TODAY', '2026-09-24');

        $user = User::forceCreate(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('cabinets.index'))->assertOk();

        $response->assertSeeInOrder(['MO-LATE', 'MO-TODAY', 'MO-SOON', 'MO-FAR', 'MO-NODATE', 'MO-DONE']);
        $response->assertSee('เลยกำหนด 4 วัน');
        $response->assertSee('ครบกำหนดวันนี้');
        $response->assertSee('เหลือ 3 วัน');
        $response->assertSee('เหลือ 68 วัน');
        $response->assertSee('คาดเสร็จ 30/09/2569');
        $this->assertSame(4, substr_count($response->getContent(), 'inline-block text-xs font-medium px-1.5'));
    }
}
