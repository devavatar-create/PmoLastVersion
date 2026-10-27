<?php

namespace App\Http\Controllers;

use App\Services\DailyWork;
use App\Services\DepartmentDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** "สรุปงาน": the page, and the JSON it polls to stay live (App\Services\DailyWork). */
class DailyWorkController extends Controller
{
    /** how often the page asks for new numbers while what it shows ends today */
    public const REFRESH_SECONDS = 15;

    /** the longest range one look covers */
    public const MAX_DAYS = 366;

    public function index(Request $request, DailyWork $dailyWork)
    {
        return view('reports.today', [
            'data' => $this->summary($request, $dailyWork),
            'noDepartment' => DepartmentDashboard::scopeFor($request->user()) === [],
            'refreshSeconds' => self::REFRESH_SECONDS,
        ]);
    }

    public function data(Request $request, DailyWork $dailyWork)
    {
        return response()->json($this->summary($request, $dailyWork));
    }

    /** opening work that came in marks it seen (DailyWork::acknowledge); 404 when it is not the viewer's or cannot be */
    public function acknowledge(Request $request, DailyWork $dailyWork)
    {
        $subtaskId = $request->validate(['subtask_id' => ['required', 'integer']])['subtask_id'];
        $acknowledged = $dailyWork->acknowledge(DepartmentDashboard::scopeFor($request->user()), (int) $subtaskId);

        return response()->json(['acknowledged' => $acknowledged], $acknowledged ? 200 : 404);
    }

    /** People outside the PMO see their own department only, whatever they ask for (DepartmentDashboard::scopeFor). */
    private function summary(Request $request, DailyWork $dailyWork): array
    {
        [$firstDay, $lastDay] = $this->requestedRange($request);

        return $dailyWork->build(DepartmentDashboard::scopeFor($request->user()), $firstDay, $lastDay);
    }

    /**
     * ?from=yyyy-mm-dd&to=yyyy-mm-dd (or ?date= for one day); today without them. Days still to come are today, a
     * range given backwards is turned round, and one longer than MAX_DAYS keeps its last MAX_DAYS days.
     *
     * @return array{Carbon, Carbon}
     */
    private function requestedRange(Request $request): array
    {
        $firstDay = $this->parseDay($request->query('from', $request->query('date')));
        $lastDay = $this->parseDay($request->query('to', $request->query('date'))) ?? $firstDay;
        $firstDay ??= $lastDay;
        if (! $firstDay) {
            return [today(), today()];
        }
        if ($firstDay->gt($lastDay)) {
            [$firstDay, $lastDay] = [$lastDay, $firstDay];
        }
    
        if ($firstDay->diffInDays($lastDay) >= self::MAX_DAYS) {
            $firstDay = $lastDay->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [$firstDay, $lastDay];
    }

    /** a real yyyy-mm-dd, not after today (a later one is today); null when it is not a date at all */
    private function parseDay(mixed $value): ?Carbon
    {
        $text = is_string($value) ? $value : '';
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? Carbon::createFromFormat('!Y-m-d', $text) : null;
        if (! $day || $day->format('Y-m-d') !== $text) {
            return null;
        }

        return $day->min(today());
    }
}
