<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetTemplate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Getting around: a project's MO cards open the full cabinet page; every sub-page has "← ย้อนกลับ" to its parent; tabs live in the URL. */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::forceCreate(['name' => 'Nav', 'email' => 'nav', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-NAV', 'project_name' => 'X', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-NAV', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
    }

    /** the href of the page's back button */
    private function backHref(string $html): ?string
    {
        return preg_match('/<a href="([^"]+)"\s+x-data="\{ prev:/', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    public function test_a_projects_mo_card_goes_straight_to_the_full_cabinet_page(): void
    {
        $html = $this->actingAs($this->admin)->get(route('projects.show', [$this->project, 'tab' => 'cabinets']))->assertOk()->getContent();

        // the MO table is filled from @js() data: the link is there, JSON-escaped
        $this->assertStringContainsString(substr((string) \Illuminate\Support\Js::from(['url' => route('cabinets.show', $this->cabinet)]), strlen("JSON.parse('{"), -strlen("}')")), $html);
        $this->assertStringNotContainsString('openCabinetPanel', $html, 'no quick panel any more');
        $this->assertStringContainsString("tab: 'info'", $html, 'the old MO tab name lands on ข้อมูลโครงการ');
        $this->assertStringContainsString('keepTabInUrl', $html, 'and goes back into it');
    }

    public function test_each_sub_page_has_a_back_button_to_its_parent(): void
    {
        $this->actingAs($this->admin);
        $template = CabinetTemplate::create(['name' => 'Std', 'code' => 'STD', 'is_default' => true, 'is_active' => true]);
        $pages = [
            [route('projects.show', $this->project), route('projects.index')],
            [route('projects.edit', $this->project), route('projects.show', $this->project)],
            [route('projects.create'), route('projects.index')],
            [route('cabinets.show', $this->cabinet), route('projects.show', [$this->project, 'tab' => 'cabinets'])],
            [route('cabinets.edit', $this->cabinet), route('cabinets.show', $this->cabinet)],
            [route('cabinet-templates.show', $template), route('cabinet-templates.index')],
            [route('cabinet-templates.edit', $template), route('cabinet-templates.show', $template)],
            [route('settings.users.create'), route('settings.users.index')],
            [route('settings.users.edit', $this->admin), route('settings.users.index')],
        ];
        foreach ($pages as [$page, $parent]) {
            $this->assertSame($parent, $this->backHref($this->get($page)->assertOk()->getContent()), "back from {$page}");
        }
    }

    public function test_the_cabinet_page_steps_to_the_projects_other_cabinets_in_due_date_order(): void
    {
        $this->cabinet->update(['due_date' => '2026-12-18']);
        $early = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-EARLY', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'due_date' => '2026-11-01']);
        $late = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-LATE', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'due_date' => '2027-01-20']);

        $this->actingAs($this->admin)->get(route('cabinets.show', $this->cabinet))->assertOk()
            ->assertSee('ตู้ 2 / 3')
            ->assertSee('href="'.route('cabinets.show', $early).'"', false)
            ->assertSee('href="'.route('cabinets.show', $late).'"', false);
        $this->get(route('cabinets.show', $early))->assertSee('ตู้ 1 / 3')->assertDontSee('ตู้ก่อนหน้า');
    }

    public function test_the_cabinet_page_crumb_lands_on_the_projects_mo_tab_and_is_html_even_when_json_is_asked(): void
    {
        $this->actingAs($this->admin)->get(route('cabinets.show', $this->cabinet))
            ->assertSee(route('projects.show', [$this->project, 'tab' => 'cabinets']), false)
            ->assertSee("keepTabInUrl(t, 'tasks')", false);
        $this->actingAs($this->admin)->getJson(route('cabinets.show', $this->cabinet))->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
