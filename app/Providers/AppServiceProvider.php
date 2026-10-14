<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // one per request: it reads Settings > การส่งต่องาน once (the cabinet page asks it for every Sub Task)
        $this->app->scoped(\App\Services\HandoffService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // any saved / deleted record: the Planning board's version moves, so its 15-second poll rebuilds (PlanningBoard::stamp)
        \Illuminate\Support\Facades\Event::listen(['eloquent.saved: *', 'eloquent.deleted: *', 'eloquent.restored: *'], fn () => \App\Services\PlanningBoard::touch());

        // @editplan … @endeditplan: buttons that change project data / dates - Admin / Project Manager only (routes: 'pmo')
        \Illuminate\Support\Facades\Blade::if('editplan', fn () => (bool) auth()->user()?->canDispatchWork());
        // @edittemplates … @endedittemplates: changing the cabinet templates (Settings > สิทธิ์การใช้งาน: edit.templates; routes: 'perm:edit.templates')
        \Illuminate\Support\Facades\Blade::if('edittemplates', fn () => (bool) auth()->user()?->allows('edit.templates'));

        // Every date a person reads is dd/mm/yyyy in พ.ศ. (15/09/2569); stored / sent values stay Y-m-d (ค.ศ.).
        \Illuminate\Support\Carbon::macro('thaiDate', fn () => $this->format('d/m/').($this->year + 543));
        \Illuminate\Support\Carbon::macro('thaiDateTime', fn () => $this->format('d/m/').($this->year + 543).$this->format(' H:i'));
        //
    }
}
