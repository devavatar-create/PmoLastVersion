{{--
    "รายงานสถานะงาน" + urgent alerts popup (Avatar Planning's status report).

    On page load it fetches status-report.show once and decides what to show:
      1. unread urgent notices (new work for the department / overdue) -> alert step first, "รับทราบ" marks them read;
      2. otherwise the daily report, if the user's preference allows (daily = once per day, always, off) and there is
         something pending. Urgent alerts ignore the "off" preference on purpose - they are the point of the popup.
    The 📋 button in the header (event: open-status-report) opens the report any time. Preference + last-shown day
    live in this browser's localStorage only; everything shown is server-scoped to the viewer's department.
--}}
<div x-data="statusReport(@js(['url' => route('status-report.show'), 'ackUrl' => route('status-report.acknowledge'), 'listUrl' => route('planning.board')]))"
     x-init="init()" @open-status-report.window="openReport()" @pmo-urgent.window="showAlerts()" @keydown.escape.window="close()">
    <template x-if="open">
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog" aria-modal="true" :aria-label="step === 'alerts' ? 'แจ้งเตือนเร่งด่วน' : 'รายงานสถานะงาน'">
            <div class="absolute inset-0 bg-slate-900/40" @click="close()"></div>

            <div class="relative w-full max-w-lg max-h-[90vh] flex flex-col bg-white rounded-2xl shadow-2xl border border-slate-200">
                {{-- Step 1: urgent alerts --}}
                <template x-if="step === 'alerts'">
                    <div class="flex flex-col min-h-0">
                        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-red-700">🔔 แจ้งเตือนเร่งด่วน</h3>
                            <span class="text-xs text-slate-500 tabular-nums" x-text="data.alerts.length + ' รายการ'"></span>
                        </div>
                        <ul class="overflow-y-auto divide-y divide-slate-100">
                            <template x-for="a in data.alerts" :key="a.id">
                                <li class="px-5 py-3">
                                    <p class="text-sm font-semibold" :class="a.kind === 'overdue' ? 'text-red-700' : 'text-slate-900'" x-text="a.title"></p>
                                    <p class="text-xs text-slate-500" x-text="a.body"></p>
                                </li>
                            </template>
                        </ul>
                        <div class="px-5 py-3 border-t border-slate-100 flex justify-end">
                            <button type="button" @click="acknowledge()" :disabled="busy" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50">รับทราบ</button>
                        </div>
                    </div>
                </template>

                {{-- Step 2: status report --}}
                <template x-if="step === 'report'">
                    <div class="flex flex-col min-h-0">
                        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-slate-900">📋 รายงานสถานะงาน</h3>
                            <button type="button" @click="close()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none" aria-label="ปิด">&times;</button>
                        </div>
                        <div class="overflow-y-auto px-5 py-4 space-y-4">
                            <div class="grid grid-cols-4 gap-3 rounded-xl bg-slate-50 p-3 text-center">
                                <div><p class="text-xl font-bold text-slate-900 tabular-nums" x-text="data.totals.pending"></p><p class="text-[11px] text-slate-500">งานค้าง</p></div>
                                <div><p class="text-xl font-bold text-emerald-600 tabular-nums" x-text="data.totals.completed"></p><p class="text-[11px] text-slate-500">เสร็จแล้ว</p></div>
                                <div><p class="text-xl font-bold text-red-600 tabular-nums" x-text="data.totals.overdue"></p><p class="text-[11px] text-slate-500">เลยกำหนด</p></div>
                                <div><p class="text-xl font-bold text-slate-900 tabular-nums" x-text="data.totals.checklist_pct + '%'"></p><p class="text-[11px] text-slate-500" x-text="'เช็คลิสต์ ' + data.totals.checklist_done + '/' + data.totals.checklist_total"></p></div>
                            </div>

                            <div>
                                <p class="text-xs font-semibold text-slate-500 mb-1.5">เลยกำหนด / ครบกำหนดใน 3 วัน</p>
                                <p x-show="data.items.length === 0" class="text-sm text-slate-400 py-3 text-center">ไม่มีงานที่ต้องเร่งตอนนี้ ✓</p>
                                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-100">
                                    <template x-for="i in data.items" :key="i.id">
                                        <li class="px-3 py-2 flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-slate-800 truncate" x-text="i.name"></p>
                                                <p class="text-xs text-slate-500 truncate" x-text="i.project_no + ' · ' + i.cabinet_mo + (i.department_name ? ' · ' + i.department_name : '')"></p>
                                            </div>
                                            <span class="shrink-0 text-xs font-semibold" :class="i.overdue ? 'text-red-600' : 'text-amber-600'"
                                                  x-text="i.overdue ? 'เลย ' + Math.abs(i.days_remaining) + ' วัน' : (i.days_remaining === 0 ? 'วันนี้' : 'อีก ' + i.days_remaining + ' วัน')"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                        <div class="px-5 py-3 border-t border-slate-100 flex items-center justify-between gap-3">
                            <label class="text-xs text-slate-500 flex items-center gap-2">แสดงรายงานนี้
                                <select x-model="pref" @change="savePref()" class="rounded-lg border-slate-300 text-xs py-1">
                                    <option value="daily">วันละครั้ง</option>
                                    <option value="always">ทุกครั้งที่เปิด</option>
                                    <option value="off">ไม่ต้องแสดง</option>
                                </select>
                            </label>
                            <div class="flex items-center gap-2">
                                <a :href="cfg.listUrl" class="text-sm font-medium text-blue-600 hover:underline">ดูงานทั้งหมด</a>
                                <button type="button" @click="close()" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700">เริ่มทำงาน</button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>

<script>
    function statusReport(cfg) {
        const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
        const get = (k, d) => { try { return localStorage.getItem(k) ?? d; } catch (e) { return d; } };
        const set = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* storage blocked - preference just won't stick */ } };

        return {
            cfg, open: false, step: 'report', busy: false, data: null,
            pref: get('statusReport.pref', 'daily'),

            async load() {
                const res = await fetch(cfg.url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('status report ' + res.status);
                this.data = await res.json();
            },

            // Page load: urgent alerts always win; otherwise the daily report if the preference allows and there is work.
            async init() {
                try {
                    await this.load();
                    if (this.data.alerts.length > 0) { this.step = 'alerts'; this.open = true; return; }
                    const due = this.pref === 'always' || (this.pref === 'daily' && get('statusReport.lastShown', '') !== this.data.date);
                    if (due && this.data.totals.pending > 0) { this.markShown(); this.step = 'report'; this.open = true; }
                } catch (e) { /* the popup is a convenience - never break the page over it */ }
            },

            // the bell saw a new urgent notice while the page was open
            async showAlerts() {
                try { await this.load(); if (this.data.alerts.length > 0) { this.step = 'alerts'; this.open = true; } } catch (e) { /* ignore */ }
            },

            async openReport() {
                try { await this.load(); this.step = 'report'; this.open = true; } catch (e) { window.showToast && window.showToast('โหลดรายงานไม่สำเร็จ', 'error'); }
            },

            markShown() { set('statusReport.lastShown', this.data.date); },
            savePref() { set('statusReport.pref', this.pref); },
            close() { this.open = false; },

            async acknowledge() {
                this.busy = true;
                try {
                    await fetch(cfg.ackUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                        body: JSON.stringify({ ids: this.data.alerts.map((a) => a.id) }),
                    });
                    this.data.alerts = [];
                    // after the alerts, fall through to the report when it would have shown anyway
                    const due = this.pref === 'always' || (this.pref === 'daily' && get('statusReport.lastShown', '') !== this.data.date);
                    if (due && this.data.totals.pending > 0) { this.markShown(); this.step = 'report'; } else { this.open = false; }
                } finally { this.busy = false; }
            },
        };
    }
</script>
