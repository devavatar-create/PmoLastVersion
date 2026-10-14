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

        $this->assertStringContainsString('href="'.route('cabinets.show', $this->cabinet).'"', $html);
        $this->assertStringNotContainsString('openCabinetPanel', $html, 'no quick panel any more');
        $this->assertStringContainsString("tab: 'cabinets'", $html, 'the tab comes from the URL');
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

    public function test_the_cabinet_page_crumb_lands_on_the_projects_mo_tab_and_is_html_even_when_json_is_asked(): void
    {
        $this->actingAs($this->admin)->get(route('cabinets.show', $this->cabinet))
            ->assertSee(route('projects.show', [$this->project, 'tab' => 'cabinets']), false)
            ->assertSee("keepTabInUrl(t, 'tasks')", false);
        $this->actingAs($this->admin)->getJson(route('cabinets.show', $this->cabinet))->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
