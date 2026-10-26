<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Department;
use App\Models\User;

/**
 * Who may do what, by DEPARTMENT (Settings > สิทธิ์การใช้งาน): a column per department, plus one for people without a
 * department. Admin and Project Manager may everything, always (by their role) - the page cannot take it away.
 * Every check in the app goes through User::allows(), so a page, its JSON and its CSV decide the same way:
 *  - page.*  the pages in the menu (a project / cabinet page itself opens by "which projects", not by these)
 *  - scope.* which projects a person sees (off = the ones their department has work in, or they are in charge of);
 *            for a department this IS its "เห็นงานทุกแผนก" switch (departments.sees_all), the same box on both pages
 *  - data.*  parts of a project; left out of the page, Planning's JSON and the CSV - not just hidden on screen
 *  - edit.*  the PM-level powers (edit.manage is what canDispatchWork() and the 'pmo' middleware mean)
 */
final class Access
{
    /** the column of the people without a department */
    public const NO_DEPARTMENT = 'none';

    /** roles that may everything, whatever their department */
    public const FULL_ROLES = [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER];

    public const GROUPS = [
        'pages' => 'หน้าที่เข้าได้',
        'scope' => 'เห็นโครงการไหน',
        'data' => 'ข้อมูลในโครงการ',
        'edit' => 'การแก้ไข',
    ];

    /** key => [group, label, hint, default for a department] */
    public const PERMISSIONS = [
        'page.dashboard' => ['pages', 'แดชบอร์ด', '', true],
        'page.today' => ['pages', 'สรุปงาน', 'งานเข้า / เสร็จของแต่ละแผนกวันนี้ (แสดงสด) — ถ้าไม่มีสิทธิ์ "จัดการโครงการและจ่ายงาน" เห็นเฉพาะแผนกตัวเอง', true],
        'page.projects' => ['pages', 'รายการโครงการ', 'เมนู "โครงการ" — หน้าโครงการแต่ละอันยังเปิดได้ตาม "เห็นโครงการไหน" (เช่น จาก Planning)', true],
        'page.cabinets' => ['pages', 'รายการใบสั่งผลิต / ตู้', 'เมนู "ตู้ไฟฟ้า"', true],
        'page.planning' => ['pages', 'Planning', 'หน้าทำงานหลักของแผนก', true],
        'page.assignments' => ['pages', 'จ่ายงาน', 'ใช้ได้เมื่อมีสิทธิ์ "จัดการโครงการและจ่ายงาน" ด้วย', false],
        'page.templates' => ['pages', 'เทมเพลตตู้ / เช็คลิสต์ (ดู)', '', false],
        'page.export' => ['pages', 'Export CSV', 'ทุกปุ่ม Export และประวัติแบบ CSV', false],
        'scope.all_projects' => ['scope', 'เห็นทุกโครงการ', 'ไม่ติ๊ก = เฉพาะโครงการที่แผนกมีงาน หรือเป็นผู้รับผิดชอบ · ติ๊กแล้วเห็นอย่างเดียว (ช่องเดียวกับ "เห็นงานทุกแผนก" ในตั้งค่า → แผนก)', false],
        'data.finance' => ['data', '💰 การเงิน', 'มูลค่างาน, VAT, Margin', false],
        'data.contact' => ['data', '📞 ข้อมูลติดต่อลูกค้า', 'เบอร์ติดต่อ', false],
        'data.comments' => ['data', '💬 คอมเมนต์และประวัติ', 'อ่านและเขียนคอมเมนต์ ดูประวัติการแก้ไข', true],
        'data.other_fields' => ['data', 'ฟิลด์เพิ่มเติมของแผนกอื่น', 'ไม่ติ๊ก = เห็นเฉพาะฟิลด์ของแผนกตัวเอง', false],
        'edit.manage' => ['edit', 'จัดการโครงการและจ่ายงาน', 'สร้าง / แก้โครงการ ตู้ งาน วันที่ จ่ายงาน (สิทธิ์ระดับ PM — เห็นทุกโครงการด้วย)', false],
        'edit.templates' => ['edit', 'แก้เทมเพลตตู้ / เช็คลิสต์', '', false],
        'edit.trash' => ['edit', 'ลบ / กู้คืน (ถังขยะ)', '', false],
    ];

    /** a permission that comes with another one (who may edit the templates may open their page) */
    public const IMPLIED = ['page.templates' => 'edit.templates'];

    /** @return array<string, array<string, bool>> column ('none' or a department id) => key => allowed, for the active departments */
    public static function table(): array
    {
        $out = [];
        foreach (Department::where('is_active', true)->orderBy('name')->get(['id', 'sees_all']) as $d) {
            $out[(string) $d->id] = self::column((string) $d->id, (bool) $d->sees_all);
        }
        $out[self::NO_DEPARTMENT] = self::column(self::NO_DEPARTMENT, null);

        return $out;
    }

    /** One column: the saved choices over the defaults; a department's "see every project" is its sees_all. */
    private static function column(string $col, ?bool $seesAll): array
    {
        $saved = (array) (((array) AppSetting::get('department_permissions'))[$col] ?? []);   // AppSetting reads the table once per request
        $out = [];
        foreach (self::PERMISSIONS as $key => [, , , $default]) {
            $out[$key] = $key === 'scope.all_projects' && $seesAll !== null ? $seesAll : (array_key_exists($key, $saved) ? (bool) $saved[$key] : $default);
        }

        return $out;
    }

    public static function allows(User $user, string $key): bool
    {
        if (! array_key_exists($key, self::PERMISSIONS)) {
            throw new \InvalidArgumentException("unknown permission {$key}");
        }
        if (in_array($user->role, self::FULL_ROLES, true) || (isset(self::IMPLIED[$key]) && self::allows($user, self::IMPLIED[$key]))) {
            return true;
        }
        if ($key === 'scope.all_projects' && $user->department_id) {
            return (bool) $user->department?->sees_all;
        }
        $col = $user->department_id ? (string) $user->department_id : self::NO_DEPARTMENT;
        $saved = (array) (((array) AppSetting::get('department_permissions'))[$col] ?? []);

        return array_key_exists($key, $saved) ? (bool) $saved[$key] : self::PERMISSIONS[$key][3];
    }

    /** @param array<string, array<string, bool>> $table column => key => allowed (an unticked box = false; unknown columns / keys dropped) */
    public static function save(array $table): void
    {
        $clean = [];
        $departments = Department::where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach (array_merge($departments, [self::NO_DEPARTMENT]) as $col) {
            foreach (array_keys(self::PERMISSIONS) as $key) {
                $clean[$col][$key] = (bool) ($table[$col][$key] ?? false);
            }
            if ($col !== self::NO_DEPARTMENT) {   // one switch, two pages: Settings > แผนก "เห็นงานทุกแผนก"
                Department::whereKey((int) $col)->update(['sees_all' => $clean[$col]['scope.all_projects']]);
                unset($clean[$col]['scope.all_projects']);
            }
        }
        AppSetting::put('department_permissions', $clean + array_diff_key((array) AppSetting::get('department_permissions'), $clean));   // inactive departments keep theirs
    }
}
