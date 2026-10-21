<?php

namespace App\Http\Controllers;

use App\Services\ProductionReport;
use Illuminate\Http\Request;

/** "รายงานการผลิต": the open production orders as six questions, one tab each (App\Services\ProductionReport). */
class ProductionReportController extends Controller
{
    public const TABS = ['overview', 'late', 'due', 'stage', 'ready'];

    public function index(Request $request, ProductionReport $report)
    {
        $ids = collect((array) $request->query('projects', []))->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        return view('reports.production', [
            'r' => $report->build($request->user(), projectIds: $ids),
            'tab' => in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview',
            'by' => $request->query('by') === 'project' ? 'project' : 'cabinet',   // "ดูเป็น: ตู้ | โครงการ"
            'canFinance' => $request->user()->allows('data.finance'),   // Settings > สิทธิ์การใช้งาน: มูลค่างาน
        ]);
    }
}
