{{--
    "เทียบกับ…": choose the other Sub Tasks whose checklist shows next to this one's (several at once), saved by PUT {sources}.
    On the cabinet template (every cabinet made from it) or on one cabinet (Admin / PM; the page reloads to show the new pairs).
--}}
@props(['url', 'chosen', 'options', 'canEdit' => false, 'chips' => true, 'reload' => false, 'hint' => 'เลือก Sub Task ที่จะวาง Checklist ข้างกัน (เลือกได้หลายงาน)'])
<div {{ $attributes->merge(['class' => 'relative flex flex-wrap items-center gap-1.5 text-xs']) }}
     x-data="{ open: false, busy: false, chosen: @js($chosen), sel: [], options: @js($options), pos: '',
               // fixed to the screen, so the card around it (overflow hidden) cannot cut it; above the button when there is no room below
               place() {
                   const r = this.$refs.btn.getBoundingClientRect(), w = Math.min(320, window.innerWidth - 24), h = 380;
                   const left = Math.max(12, Math.min(r.left, window.innerWidth - w - 12));
                   const below = window.innerHeight - r.bottom - 12;
                   this.pos = 'left:' + left + 'px;width:' + w + 'px;' + (below >= Math.min(h, r.top - 12) ? 'top:' + (r.bottom + 4) + 'px;max-height:' + Math.min(h, below) + 'px' : 'bottom:' + (window.innerHeight - r.top + 4) + 'px;max-height:' + Math.min(h, r.top - 12) + 'px');
               },
               async save() {
                   this.busy = true;
                   try {
                       const res = await fetch(@js($url), { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ sources: this.sel }) });
                       const data = await res.json().catch(() => ({}));
                       if (!res.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ');
                       this.chosen = data.sources; this.open = false;
                       window.showToast(data.message + (data.cabinets ? ' · ตู้เดิม ' + data.cabinets + ' ตู้: กด “ใช้คู่เทียบกับตู้เดิม” ด้านบน' : ''));
                       if (@js((bool) $reload)) setTimeout(() => location.reload(), 500);
                   } catch (e) { window.showToast(e.message, 'error'); } finally { this.busy = false; }
               } }"
     @keydown.escape.stop="open = false" @click.outside="open = false" @scroll.window.passive="open && place()" @resize.window="open && place()">
    @if ($chips)
        <template x-for="c in chosen" :key="c.id">
            <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 font-medium text-blue-800 ring-1 ring-blue-200">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11l-3-3M17 17H6l3 3"/></svg>
                <span x-text="'เทียบกับ ' + c.name + (c.dept ? ' · ' + c.dept : '')"></span>
            </span>
        </template>
    @endif
    @if ($canEdit)
        <button type="button" x-ref="btn" @click="open = !open; sel = chosen.map((c) => c.id); open && place()" :aria-expanded="open"
                class="rounded-full border border-dashed border-slate-300 px-2 py-0.5 font-medium text-slate-600 hover:border-blue-400 hover:text-blue-700"
                x-text="chosen.length ? '⇄ แก้คู่เทียบ' : '＋ เทียบกับ…'"></button>
        <div x-show="open" x-cloak :style="pos" class="fixed z-50 flex flex-col rounded-lg border border-slate-200 bg-white p-2 shadow-xl">
            <p class="px-1 pb-1.5 text-[11px] font-semibold text-slate-500">{{ $hint }}</p>
            <div class="min-h-0 flex-1 overflow-y-auto">
                <template x-for="o in options" :key="o.id">
                    <label class="flex cursor-pointer items-start gap-2 rounded-md px-1.5 py-1.5 hover:bg-slate-50">
                        <input type="checkbox" :value="o.id" x-model.number="sel" class="mt-0.5 rounded border-slate-300 text-blue-600">
                        <span class="min-w-0 leading-snug"><span class="block text-[13px] text-slate-800" x-text="o.name"></span>
                            <span class="text-[11px] text-slate-500" x-text="o.task + (o.dept ? ' · ' + o.dept : '')"></span></span>
                    </label>
                </template>
                <p x-show="!options.length" class="px-1.5 py-2 text-slate-400">ยังไม่มี Sub Task อื่น</p>
            </div>
            <div class="mt-1.5 flex shrink-0 justify-end gap-2 border-t border-slate-100 pt-2">
                <button type="button" @click="open = false" class="rounded-md px-2.5 py-1 text-slate-600 hover:bg-slate-100">ยกเลิก</button>
                <button type="button" @click="save()" :disabled="busy" class="rounded-md bg-blue-600 px-3 py-1 font-semibold text-white hover:bg-blue-700 disabled:opacity-50">บันทึก</button>
            </div>
        </div>
    @endif
    {{ $slot }}
</div>
