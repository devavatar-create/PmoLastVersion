@php
    $p = $project ?? null;
@endphp

{{-- shared by projects/create and projects/edit. A new project has no status field (it starts as Draft); editing shows it.
     Sales Order / Project Owner are no longer asked for (their old values stay on the record). --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-5"
     x-data="{ value: @js((string) old('contract_value', $p?->contract_value !== null ? number_format((float) $p->contract_value, 2) : '')),
               margin: @js((string) old('margin', $p?->margin !== null ? number_format((float) $p->margin, 2) : '')),
               num(v) { const n = parseFloat(String(v || '').replace(/[\s,฿]|บาท/g, '')); return isNaN(n) ? null : n; },
               pct() { const v = this.num(this.value), m = this.num(this.margin); return v && m !== null ? (m / v * 100).toFixed(1) + ' %' : ''; },
               withVat() { const v = this.num(this.value); return v === null ? '' : (Math.round(v * {{ 1 + \App\Models\Project::VAT_RATE }} * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } }">
    <div>
        <x-input-label for="project_no" value="เลขที่โครงการ" />
        <x-text-input id="project_no" name="project_no" class="mt-1 block w-full" :value="old('project_no', $p?->project_no)" required />
        <x-input-error :messages="$errors->get('project_no')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="project_name" value="ชื่อโครงการ" />
        <x-text-input id="project_name" name="project_name" class="mt-1 block w-full" :value="old('project_name', $p?->project_name)" required />
        <x-input-error :messages="$errors->get('project_name')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="customer_name" value="ลูกค้า" />
        <x-text-input id="customer_name" name="customer_name" class="mt-1 block w-full" :value="old('customer_name', $p?->customer_name)" required />
        <x-input-error :messages="$errors->get('customer_name')" class="mt-1" />
    </div>
    @if (auth()->user()->allows('data.contact'))   {{-- Settings > สิทธิ์การใช้งาน --}}
    <div>
        <x-input-label for="customer_phone" value="เบอร์ติดต่อ" />
        <x-text-input id="customer_phone" name="customer_phone" type="tel" class="mt-1 block w-full" :value="old('customer_phone', $p?->customer_phone)" maxlength="50" placeholder="เช่น 081-234-5678" />
        <x-input-error :messages="$errors->get('customer_phone')" class="mt-1" />
    </div>
    @endif
    <div>
        <x-input-label for="po_no" value="เลขที่ใบสั่งซื้อ (PO)" />
        <x-text-input id="po_no" name="po_no" class="mt-1 block w-full" :value="old('po_no', $p?->po_no)" />
    </div>
    <div>
        <x-input-label for="sales_person" value="พนักงานขาย" />
        <x-text-input id="sales_person" name="sales_person" class="mt-1 block w-full" :value="old('sales_person', $p?->sales_person)" />
        <x-input-error :messages="$errors->get('sales_person')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="project_manager_id" value="ผู้จัดการโครงการ (PM)" />
        <select id="project_manager_id" name="project_manager_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            <option value="">-</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}" @selected(old('project_manager_id', $p?->project_manager_id) == $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('project_manager_id')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="job_type_id" value="ประเภทงาน" />
        <select id="job_type_id" name="job_type_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            <option value="">-</option>
            @foreach ($jobTypes as $jobType)
                <option value="{{ $jobType->id }}" @selected(old('job_type_id', $p?->job_type_id) == $jobType->id)>{{ $jobType->name }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('job_type_id')" class="mt-1" />
    </div>
    @if (($boards ?? collect())->isNotEmpty())
        <div>
            <x-input-label for="board_id" value="บอร์ด (Planning)" />
            <select id="board_id" name="board_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                <option value="">PMO (บอร์ดหลัก)</option>
                @foreach ($boards as $board)
                    <option value="{{ $board->id }}" @selected(old('board_id', $p?->board_id ?? request()->integer('board')) == $board->id)>{{ $board->name }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('board_id')" class="mt-1" />
        </div>
    @endif
    <div>
        <x-input-label for="payment_terms" value="เงื่อนไขการชำระ" />
        <x-text-input id="payment_terms" name="payment_terms" class="mt-1 block w-full" :value="old('payment_terms', $p?->payment_terms)" />
        <x-input-error :messages="$errors->get('payment_terms')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="start_date" value="วันเริ่ม" />
        <x-text-input type="date" id="start_date" name="start_date" class="mt-1 block w-full" :value="old('start_date', $p?->start_date?->format('Y-m-d'))" />
    </div>
    <div>
        <x-input-label for="due_date" value="วันครบกำหนด" />
        <x-text-input type="date" id="due_date" name="due_date" class="mt-1 block w-full" :value="old('due_date', $p?->due_date?->format('Y-m-d'))" />
    </div>
    @if ($p)
        <div>
            <x-input-label for="status" value="สถานะ" />
            <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(old('status', $p->status) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div>
        <x-input-label for="priority" value="ความสำคัญ" />
        <select id="priority" name="priority" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
            @foreach ($priorities as $key => $label)
                <option value="{{ $key }}" @selected(old('priority', $p?->priority ?? 'normal') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @if (auth()->user()->allows('data.finance'))
    <div>
        <x-input-label for="contract_value" value="มูลค่างาน ก่อน VAT (บาท)" />
        <x-text-input id="contract_value" name="contract_value" inputmode="decimal" class="mt-1 block w-full" x-model="value" placeholder="เช่น 1,250,000" />
        <x-input-error :messages="$errors->get('contract_value')" class="mt-1" />
    </div>
    <div>
        <x-input-label for="contract_value_vat" value="มูลค่างานรวม VAT 7% (บาท)" />
        {{-- worked out from มูลค่างาน as it is typed; not sent / not stored --}}
        <input id="contract_value_vat" type="text" readonly tabindex="-1" :value="withVat()" placeholder="คำนวณจากมูลค่างานอัตโนมัติ"
               class="mt-1 block w-full rounded-md border-slate-200 bg-slate-50 text-slate-700 shadow-sm">
    </div>
    <div>
        <x-input-label for="margin" value="Margin (บาท)" />
        <x-text-input id="margin" name="margin" inputmode="decimal" class="mt-1 block w-full" x-model="margin" placeholder="เช่น 225,000" />
        <p class="mt-1 text-xs text-slate-500" x-show="pct()" x-text="'คิดเป็น ' + pct() + ' ของมูลค่างาน'"></p>
        <x-input-error :messages="$errors->get('margin')" class="mt-1" />
    </div>
    @endif
    <div class="md:col-span-2">
        <x-input-label for="description" value="คำอธิบาย" />
        <textarea id="description" name="description" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ old('description', $p?->description) }}</textarea>
    </div>
</div>
