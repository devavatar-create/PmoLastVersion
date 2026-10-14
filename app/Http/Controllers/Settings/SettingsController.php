<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\NotificationPreference;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * The Settings hub (Avatar Planning's "ตั้งค่า" window, as Laravel pages sharing one tab bar - <x-settings-nav>).
 * Everyone may open "การแจ้งเตือน" (their own); every other tab is admin-only (middleware 'admin' on the routes).
 */
class SettingsController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->route($request->user()->isAdmin() ? 'settings.users.index' : 'settings.notifications.edit');
    }

    public function editNotifications(Request $request)
    {
        return view('settings.notifications', ['pref' => NotificationPreference::for($request->user())]);
    }

    public function updateNotifications(Request $request)
    {
        // an unchecked box is simply absent from the form, so every switch is read as boolean()
        $data = collect(['enabled', 'only_my_department', 'progress', 'completed', 'reminders', 'comments', 'due_changes'])
            ->mapWithKeys(fn ($k) => [$k => $request->boolean($k)])->all();

        NotificationPreference::updateOrCreate(['user_id' => $request->user()->id], $data);

        return back()->with('success', 'บันทึกการแจ้งเตือนของคุณแล้ว');
    }

    public function editRules()
    {
        return view('settings.rules', [
            'values' => collect(array_keys(AppSetting::DEFAULTS))->mapWithKeys(fn ($k) => [$k => AppSetting::get($k)])->all(),
            'statuses' => Project::$statuses,
        ]);
    }

    public function updateRules(Request $request)
    {
        $data = $request->validate([
            'automation_locked_statuses' => ['nullable', 'array'],
            'automation_locked_statuses.*' => ['string', 'in:'.implode(',', array_keys(Project::$statuses))],
            'waiting_reminder_days' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        if (isset($data['waiting_reminder_days'])) {
            AppSetting::put('waiting_reminder_days', (int) $data['waiting_reminder_days']);
        }
        foreach (['cross_mention', 'block_done_needs_checklist', 'block_project_done_needs_work', 'pmo_acts_for_departments'] as $key) {
            AppSetting::put($key, $request->boolean($key));
        }
        AppSetting::put('automation_locked_statuses', array_values($data['automation_locked_statuses'] ?? []));

        return back()->with('success', 'บันทึกกฎการทำงานแล้ว');
    }
}
