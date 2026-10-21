<?php

namespace App\Services;

/**
 * The CEO report's "บทสรุปผู้บริหาร": a few plain sentences written from ExecutiveReport ($r) and ProductionReport ($p),
 * counted by project or by cabinet, and what to do next (rules, not guesses). Every number is one the report shows below.
 */
class CeoSummary
{
    /** @return array{lines: list<array{tone: string, text: string}>, actions: list<string>} */
    public function write(array $r, array $p, string $by, bool $canFinance): array
    {
        $byProject = $by === 'project';
        $pc = $p['projectCount'];
        $late = count($p['late']);
        $rush = count($p['rush']);
        $risk = count($p['risk']);
        $ready = count($p['ready']);
        $depts = collect($p['departments'])->where('late', '>', 0)->values();
        $lines = [];
        $actions = [];

        // 1. how much is in production
        $value = $canFinance && $p['value'] > 0 ? ' มูลค่า '.number_format($p['value'] / 1e6, 1).' ล้านบาท' : '';
        $lines[] = ['tone' => 'navy', 'text' => 'ขณะนี้มีงานสั่งผลิตที่ยังไม่ปิด '.$p['so'].' โครงการ รวม '.$p['cabinets'].' ตู้'.$value.' ตู้ที่ยังไม่เสร็จผลิตแล้วเฉลี่ย '.$p['avgProgress'].'%'];

        // 2. on time or not
        if ($byProject) {
            $lines[] = $pc['late'] || $pc['watch']
                ? ['tone' => $pc['late'] ? 'red' : 'orange', 'text' => ($pc['late'] ? 'มี '.$pc['late'].' โครงการที่มีตู้เลยวันส่งแล้ว' : 'ยังไม่มีโครงการที่มีตู้เลยวันส่ง')
                    .($pc['watch'] ? ' และอีก '.$pc['watch'].' โครงการต้องจับตา (ต้องเร่ง '.$pc['rush'].' · เสี่ยง '.$pc['risk'].')' : '')]
                : ['tone' => 'green', 'text' => 'ทุกโครงการยังทันกำหนดส่ง'];
        } else {
            $lines[] = $late || $rush || $risk
                ? ['tone' => $late ? 'red' : 'orange', 'text' => ($late ? 'ตู้เลยวันส่งแล้ว '.$late.' ตู้' : 'ยังไม่มีตู้เลยวันส่ง')
                    .($rush + $risk ? ' และอีก '.($rush + $risk).' ตู้ต้องจับตา (ต้องเร่ง '.$rush.' · เสี่ยง '.$risk.')' : '')]
                : ['tone' => 'green', 'text' => 'ทุกตู้ยังทันกำหนดส่ง'];
        }

        // 3. where it is stuck
        if ($depts->isNotEmpty()) {
            $top = $depts->take(2)->map(fn ($d) => $d['name'].' ('.$d['late'].' งาน)')->implode(' และ ');
            $lines[] = ['tone' => 'orange', 'text' => 'คอขวดหลักอยู่ที่แผนก'.$top.' ซึ่งถืองานย่อยเลยกำหนดมากที่สุด จากทั้งหมด '.$depts->sum('late').' งาน'];
            $actions[] = 'เร่งติดตามแผนก'.$depts->first()['name'].' ให้ปิดงานที่เลยกำหนด '.$depts->first()['late'].' งาน และอัปเดตวันที่คาดว่าจะเสร็จ';
        }

        // 4. what is due soon
        $due7 = $byProject ? $pc['due'][7] : $p['dueCount'][7];
        $due30 = $byProject ? $pc['due'][30] : $p['dueCount'][30];
        $unit = $byProject ? 'โครงการ' : 'ตู้';
        $lines[] = ['tone' => $due7 ? 'orange' : 'navy', 'text' => $due30
            ? 'ใน 7 วันข้างหน้าต้องส่งมอบ '.$due7.' '.$unit.' และภายใน 30 วัน '.$due30.' '.$unit.($rush ? ' โดยมี '.$rush.' ตู้ที่ผลิตยังไม่ถึง 80%' : '')
            : 'ไม่มีกำหนดส่งมอบใน 30 วันข้างหน้า'];
        if ($rush) {
            $actions[] = 'จัดลำดับให้ '.$rush.' ตู้ที่ต้องส่งใน 7 วันแต่ผลิตยังไม่ถึง 80% เป็นงานแรกของสายการผลิต';
        }

        // 5. produced, the customer has not taken it
        if ($ready) {
            $lines[] = ['tone' => $p['readyMaxWait'] >= ProductionReport::WAIT_RED_DAYS ? 'orange' : 'navy',
                'text' => 'ผลิตเสร็จแล้วรอลูกค้ารับ '.$ready.' ตู้ ('.$pc['ready'].' โครงการ) รอนานสุด '.$p['readyMaxWait'].' วัน'];
            if ($p['readyMaxWait'] >= ProductionReport::WAIT_RED_DAYS) {
                $actions[] = 'ประสานลูกค้านัดรับตู้ที่รอเกิน '.ProductionReport::WAIT_RED_DAYS.' วัน เพื่อลดพื้นที่จัดเก็บและเร่งวางบิล';
            }
        }

        if ($late) {
            $actions[] = 'ทบทวนวันส่งใหม่กับลูกค้าสำหรับ '.$late.' ตู้ที่เลยวันส่งแล้ว';
        }
        if ($canFinance && $p['valueMissing']) {
            $actions[] = 'ให้ฝ่ายขาย / PM กรอกมูลค่างานใน '.$p['valueMissing'].' SO ที่ยังไม่มีมูลค่า เพื่อให้รายงานมูลค่าครบ';
        }

        return ['lines' => $lines, 'actions' => array_slice($actions, 0, 5)];
    }
}
