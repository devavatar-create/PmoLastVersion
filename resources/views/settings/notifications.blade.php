<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto">
        <x-settings-nav />

        @if (auth()->user()->isAdmin())
            @php
                $pushKeys = (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
                $https = request()->isSecure();
                $devices = \App\Models\PushSubscription::count();
                $emptyDepts = \App\Models\Department::where('is_active', true)->whereDoesntHave('users', fn ($q) => $q->where('is_active', true))->orderBy('name')->pluck('name');
            @endphp
            <x-card title="ความพร้อมของระบบแจ้งเตือน (Admin)" class="mb-4">
                <ul class="space-y-1.5 text-sm">
                    <li>{{ $https ? '✅' : '⚠️' }} เว็บเปิดผ่าน https {{ $https ? '' : '— ต้องมี https ถึงจะใช้แจ้งเตือนตอนปิดแอปได้' }}</li>
                    <li>{{ $pushKeys ? '✅' : '⚠️' }} คีย์ Web Push ใน .env {{ $pushKeys ? '' : '— รัน php artisan webpush:keys --write แล้ว php artisan config:clear' }}</li>
                    <li>📱 เครื่องที่เปิดรับแจ้งเตือนตอนปิดแอป: {{ $devices }} เครื่อง</li>
                    <li>✅ เตือนงานใกล้ครบ / เกินกำหนด: ส่งเองวันละครั้งเมื่อมีคนเปิดเว็บหลัง 07:00 (ไม่ต้องตั้ง cron)</li>
                    <li>{{ $emptyDepts->isEmpty() ? '✅ ทุกแผนกมีผู้ใช้' : '⚠️ แผนกที่ยังไม่มีผู้ใช้ (ไม่มีใครได้รับงาน/แจ้งเตือน): '.$emptyDepts->implode(', ') }}</li>
                </ul>
            </x-card>
        @endif

        {{-- this device: notices when the app is closed (web push). Needs https and the server's VAPID keys. --}}
        <x-card title="แจ้งเตือนบนเครื่องนี้ (ตอนปิดแอป)" subtitle="เปิดแล้ว การแจ้งเตือนที่เข้ากระดิ่งจะเด้งบนเครื่องนี้ด้วย แม้ไม่ได้เปิดหน้าเว็บ — ตั้งแยกทีละเครื่อง / เบราว์เซอร์" class="mb-4">
            <div x-data="pushDevice(@js(['key' => config('services.webpush.public_key'), 'sw' => asset('sw.js'), 'subscribe' => route('push.subscribe'), 'test' => route('push.test')]))" x-init="init()" class="space-y-2 text-sm">
                <p x-text="message" :class="ok ? 'text-emerald-700' : 'text-slate-600'"></p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" x-show="supported && !on" @click="enable()" :disabled="busy" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">เปิดการแจ้งเตือนบนเครื่องนี้</button>
                    <button type="button" x-show="on" @click="disable()" :disabled="busy" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50">ปิดบนเครื่องนี้</button>
                    <button type="button" x-show="on" @click="test()" :disabled="busy" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50">ส่งทดสอบ</button>
                </div>
            </div>
        </x-card>

        <x-card title="การแจ้งเตือนของฉัน" subtitle="ตั้งได้เฉพาะของตัวเอง — แจ้งเตือนเร่งด่วน (มอบหมายงาน, แท็ก @, แจ้งเตือนเข้าแผนก) จะส่งถึงคุณเสมอตราบใดที่เปิดการแจ้งเตือนอยู่">
            <form method="POST" action="{{ route('settings.notifications.update') }}" class="space-y-4">
                @csrf @method('PUT')

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="enabled" value="1" @checked($pref->enabled) class="mt-0.5 rounded border-slate-300 text-blue-600">
                    <span><span class="font-semibold text-slate-800">เปิดการแจ้งเตือน</span><br><span class="text-xs text-slate-500">ปิด = ไม่มีการแจ้งเตือนใดเข้ากระดิ่งของคุณเลย</span></span>
                </label>

                <fieldset class="space-y-3 border-t border-slate-100 pt-4">
                    <legend class="text-xs font-semibold uppercase tracking-wide text-slate-400 pb-1">แจ้งเตือนเพิ่มเมื่อ…</legend>
                    @foreach ([
                        ['progress', 'แผนกรับงาน / เริ่มงาน', 'เมื่อแผนกกดรับงานหรือเริ่มงานในโครงการที่คุณดูแล'],
                        ['completed', 'แผนกทำงานเสร็จ', 'เมื่อแผนกกดปิดงาน'],
                        ['reminders', 'งานใกล้ครบกำหนด / เกินกำหนด', 'การเตือนรายวัน'],
                        ['comments', 'คอมเมนต์ / ตอบกลับใหม่', 'ในโครงการที่ฉันเคยคอมเมนต์ เป็น PM หรือเป็นผู้รับผิดชอบ'],
                        ['due_changes', 'วันส่งงานของแผนกฉันถูกเปลี่ยน', 'เมื่อคนนอกแผนกเลื่อนวันส่งงานของแผนกคุณ'],
                    ] as [$name, $title, $hint])
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" name="{{ $name }}" value="1" @checked($pref->{$name}) class="mt-0.5 rounded border-slate-300 text-blue-600">
                            <span><span class="font-semibold text-slate-800">{{ $title }}</span><br><span class="text-xs text-slate-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="only_my_department" value="1" @checked($pref->only_my_department) class="mt-0.5 rounded border-slate-300 text-blue-600">
                        <span><span class="font-semibold text-slate-800">เฉพาะงานของแผนกฉัน</span><br><span class="text-xs text-slate-500">ข้ามข่าวความคืบหน้าและการเตือนของแผนกอื่น (ใช้กับผู้ที่มีแผนกเท่านั้น)</span></span>
                    </label>
                </fieldset>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">บันทึก</button>
                </div>
            </form>
        </x-card>
    </div>
    @push('scripts')
    <script>
        function pushDevice(cfg) {
            const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
            const b64 = (s) => { const p = '='.repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...r].map((c) => c.charCodeAt(0))); };
            const call = (url, method, body) => fetch(url, { method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: body ? JSON.stringify(body) : undefined })
                .then(async (r) => { const d = await r.json().catch(() => ({})); if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status)); return d; });
            return {
                supported: false, on: false, busy: false, ok: false, message: 'กำลังตรวจสอบ...', reg: null,
                async init() {
                    if (!cfg.key) { this.message = 'ผู้ดูแลระบบยังไม่ได้ตั้งค่า Web Push บนเซิร์ฟเวอร์ — ใช้กระดิ่งในแอปได้ตามปกติ'; return; }
                    if (!('serviceWorker' in navigator) || !('PushManager' in window)) { this.message = 'เบราว์เซอร์นี้ไม่รองรับการแจ้งเตือนตอนปิดแอป (iPhone/iPad: เพิ่มเว็บไปที่หน้าจอโฮมก่อน)'; return; }
                    if (!window.isSecureContext) { this.message = 'ต้องเปิดผ่าน https การแจ้งเตือนตอนปิดแอปถึงจะใช้ได้'; return; }
                    this.supported = true;
                    try { this.reg = await navigator.serviceWorker.register(cfg.sw); const sub = await this.reg.pushManager.getSubscription(); this.on = !!sub; } catch (e) { this.message = 'ลงทะเบียนไม่สำเร็จ: ' + e.message; return; }
                    this.paint();
                },
                paint() {
                    this.ok = this.on;
                    this.message = Notification.permission === 'denied' ? 'เบราว์เซอร์บล็อกการแจ้งเตือนไว้ — อนุญาตที่ไอคอน 🔒 หน้า URL แล้วรีโหลด' : (this.on ? '✓ เปิดอยู่บนเครื่องนี้' : 'ปิดอยู่บนเครื่องนี้');
                },
                async enable() {
                    this.busy = true;
                    try {
                        if (await Notification.requestPermission() !== 'granted') throw new Error('ไม่ได้รับอนุญาตการแจ้งเตือน');
                        const sub = await this.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(cfg.key) });
                        await call(cfg.subscribe, 'POST', sub.toJSON());
                        this.on = true;
                    } catch (e) { window.showToast && window.showToast('เปิดไม่สำเร็จ: ' + e.message, 'error'); }
                    this.busy = false; this.paint();
                },
                async disable() {
                    this.busy = true;
                    try { const sub = await this.reg.pushManager.getSubscription(); if (sub) { await call(cfg.subscribe, 'DELETE', { endpoint: sub.endpoint }); await sub.unsubscribe(); } this.on = false; }
                    catch (e) { window.showToast && window.showToast('ปิดไม่สำเร็จ: ' + e.message, 'error'); }
                    this.busy = false; this.paint();
                },
                async test() {
                    this.busy = true;
                    try { const d = await call(cfg.test, 'POST'); window.showToast && window.showToast('ส่งทดสอบแล้ว (' + d.devices + ' เครื่อง)', 'success'); }
                    catch (e) { window.showToast && window.showToast(e.message, 'error'); }
                    this.busy = false;
                },
            };
        }
    </script>
    @endpush
</x-app-layout>
