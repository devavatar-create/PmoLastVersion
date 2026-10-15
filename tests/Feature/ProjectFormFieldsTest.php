<?php

namespace Tests\Feature;

use App\Models\JobType;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** projects/create & edit (and Planning's project windows): phone, contract value, margin; no SO / owner; PM only; no status on create. */
class ProjectFormFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'Form'.++$n, 'email' => 'form'.$n, 'password' => 'secret-pass', 'role' => $role, 'is_active' => true]);
    }

    private function form(array $over = []): array
    {
        return $over + ['project_no' => 'PJ-1', 'project_name' => 'MDB', 'customer_name' => 'ลูกค้า', 'priority' => 'normal'];
    }

    public function test_the_create_form_has_the_new_fields_and_not_the_removed_ones(): void
    {
        $admin = $this->user('admin');
        $pm = $this->user('project_manager');
        $member = $this->user();

        $page = $this->actingAs($admin)->get(route('projects.create'))->assertOk();
        $page->assertSee('เบอร์ติดต่อ')->assertSee('มูลค่างาน ก่อน VAT (บาท)')->assertSee('มูลค่างานรวม VAT 7% (บาท)')->assertSee('Margin (บาท)')->assertSee('ผู้จัดการโครงการ (PM)');
        $page->assertDontSee('name="contract_value_vat"', false);   // worked out on the page, never sent
        $page->assertDontSee('name="sales_order_no"', false)->assertDontSee('name="project_owner"', false)->assertDontSee('name="status"', false);
        $this->assertEqualsCanonicalizing([$admin->id, $pm->id], $page->viewData('users')->pluck('id')->all(), 'only Admin / PM can be picked');
        $this->assertNotContains($member->id, $page->viewData('users')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['Service', 'ตู้', 'Trading'], JobType::pluck('name')->all(), 'the job types the form offers');
    }

    public function test_a_new_project_starts_as_draft_and_keeps_phone_value_and_margin(): void
    {
        $admin = $this->user('admin');
        $pm = $this->user('project_manager');

        $this->actingAs($admin)->post(route('projects.store'), $this->form([
            'customer_phone' => '081-234-5678', 'contract_value' => '1,250,000', 'margin' => '฿ 225,000.50', 'project_manager_id' => $pm->id,
            'job_type_id' => JobType::where('name', 'ตู้')->value('id'),
        ]))->assertSessionHasNoErrors();

        $p = Project::where('project_no', 'PJ-1')->firstOrFail();
        $this->assertSame(['draft', '081-234-5678', '1250000.00', '225000.50', $pm->id], [$p->status, $p->customer_phone, $p->contract_value, $p->margin, $p->project_manager_id]);
        $this->assertSame(18.0, $p->marginPercent());
        $this->assertSame([87500.0, 1337500.0], [$p->vatAmount(), $p->contractValueWithVat()], 'VAT 7% worked out, not stored');

        $this->actingAs($admin)->get(route('projects.show', $p))->assertOk()
            ->assertSeeInOrder(['มูลค่างาน (ก่อน VAT)', '1,250,000.00', 'VAT 7%', '87,500.00', 'มูลค่างานรวม VAT 7%', '1,337,500.00', 'Margin', '225,000.50', '18 %'])
            ->assertSee('081-234-5678')
            ->assertDontSee('ใบสั่งขาย / อ้างอิง')->assertDontSee('เจ้าของโครงการ');
    }

    public function test_bad_amounts_and_a_non_pm_manager_are_refused(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();

        $this->actingAs($admin)->post(route('projects.store'), $this->form(['contract_value' => 'หนึ่งล้าน']))->assertSessionHasErrors(['contract_value' => 'มูลค่างานต้องเป็นตัวเลข']);
        $this->actingAs($admin)->post(route('projects.store'), $this->form(['contract_value' => '-5']))->assertSessionHasErrors('contract_value');
        $this->actingAs($admin)->post(route('projects.store'), $this->form(['project_manager_id' => $member->id]))->assertSessionHasErrors('project_manager_id');
        $this->assertSame(0, Project::count());
    }

    public function test_editing_keeps_the_status_field_old_sales_order_and_an_existing_non_pm_manager(): void
    {
        $admin = $this->user('admin');
        $old = $this->user();   // made manager before the PM-only rule
        $p = Project::forceCreate(['project_no' => 'PJ-9', 'project_name' => 'X', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'sales_order_no' => 'SO-OLD', 'project_owner' => 'คุณเก่า', 'project_manager_id' => $old->id]);

        $page = $this->actingAs($admin)->get(route('projects.edit', $p))->assertOk()->assertSee('name="status"', false);
        $this->assertContains($old->id, $page->viewData('users')->pluck('id')->all(), 'the current manager is still offered');

        $this->actingAs($admin)->put(route('projects.update', $p), $this->form(['project_no' => 'PJ-9', 'status' => 'on_hold', 'project_manager_id' => $old->id, 'margin' => '1,000']))
            ->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['on_hold', 'SO-OLD', 'คุณเก่า', $old->id, '1000.00'], [$p->status, $p->sales_order_no, $p->project_owner, $p->project_manager_id, $p->margin], 'old values stay; nothing removed');
        $this->actingAs($admin)->get(route('projects.show', $p))->assertSee('SO-OLD')->assertSee('คุณเก่า');
    }

    public function test_planning_reads_and_writes_the_same_fields(): void
    {
        $pm = $this->user('project_manager');
        $member = $this->user();
        $sync = app(PlanningSync::class);
        $job = JobType::where('name', 'Service')->value('id');

        $r = $sync->apply($pm, [['type' => 'project_create', 'so' => 'PL-1', 'title' => 'จาก Planning', 'customer' => 'C', 'customerPhone' => '02-111-2222',
            'jobTypeId' => (string) $job, 'contractValue' => '500,000', 'margin' => '50,000', 'managerId' => (string) $member->id]])[0];
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $p = Project::where('project_no', 'PL-1')->firstOrFail();
        $this->assertSame(['02-111-2222', $job, '500000.00', '50000.00', null], [$p->customer_phone, $p->job_type_id, $p->contract_value, $p->margin, $p->project_manager_id], 'a member is not taken as PM');
        $this->assertFalse($sync->apply($pm, [['type' => 'project_create', 'so' => 'PL-2', 'title' => 'x', 'customer' => 'C', 'contractValue' => 'abc']])[0]['ok']);

        $info = collect(app(PlanningBoard::class)->tasks($pm))->firstWhere('id', 'p'.$p->id)['info'];
        $this->assertSame(['02-111-2222', 500000.0, 50000.0], [$info['customerPhone'], $info['contractValue'], $info['margin']]);

        $op = fn (array $f) => ['type' => 'project', 'taskId' => 'p'.$p->id, 'fields' => $f];
        $this->assertTrue($sync->apply($pm, [$op(['contractValue' => '750000', 'margin' => '', 'customerPhone' => '089'])])[0]['ok']);
        $this->assertSame(['750000.00', null, '089'], [$p->fresh()->contract_value, $p->fresh()->margin, $p->fresh()->customer_phone]);
        $this->assertFalse($sync->apply($pm, [$op(['margin' => 'มาก'])])[0]['ok']);
        $this->assertFalse($sync->apply($pm, [$op(['managerId' => (string) $member->id])])[0]['ok'], 'PM only here too');
        $this->assertFalse($sync->apply($member, [$op(['margin' => '1'])])[0]['ok'], 'Admin / PM only');
    }
}
