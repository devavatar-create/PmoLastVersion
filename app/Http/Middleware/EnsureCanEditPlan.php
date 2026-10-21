<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project data and dates (projects, cabinets, tasks, sub task records, templates) are changed by Admin /
 * Project Manager only. Departments still do their work everywhere: accept / start / finish, tick and manage checklists,
 * fill in their extra fields, comment.
 */
class EnsureCanEditPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canDispatchWork(), 403, 'คุณไม่มีสิทธิ์แก้ข้อมูลและวันที่ของโครงการ (ตั้งค่า → สิทธิ์การใช้งาน: จัดการโครงการและจ่ายงาน)');

        return $next($request);
    }
}
