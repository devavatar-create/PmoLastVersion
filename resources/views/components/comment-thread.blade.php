{{--
    Shared by the Project and Cabinet "Comment" tabs - both post through the
    same ActivityLog (action='note') / activity-logs.update flow, just against
    a different parent model, so this is the one place that markup lives.

    $comments - paginated ActivityLog rows (action='note'), eager-loaded with
                'user' and 'attachments' by the caller.
    $noteRoute - route name for posting a new comment (e.g. 'projects.notes.store').
    $noteModel - the Project/Cabinet to bind that route to.
--}}
@props(['comments', 'noteRoute', 'noteModel', 'title' => 'Comment', 'subtitle' => null])

<x-card :title="$title" :subtitle="$subtitle">
    <form method="POST" action="{{ route($noteRoute, $noteModel) }}" enctype="multipart/form-data" class="space-y-2 pb-4 mb-1 border-b border-slate-100">
        @csrf
        <div class="flex items-start gap-2">
            <textarea name="note" rows="2" required maxlength="2000" placeholder="เพิ่มคอมเมนต์ เช่น มีการแก้แบบ..." class="flex-1 rounded-lg border-slate-300 text-sm"></textarea>
            <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 shrink-0">บันทึก</button>
        </div>
        <div>
            <input type="file" name="attachments[]" multiple
                   class="text-xs text-slate-500 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:bg-slate-100 file:text-xs file:font-medium file:text-slate-700 hover:file:bg-slate-200">
            <p class="text-[11px] text-slate-400 mt-0.5">แนบไฟล์ได้สูงสุด 5 ไฟล์ — PDF, Word, Excel, รูปภาพ, DWG/DXF (ไม่เกิน 20 MB ต่อไฟล์)</p>
        </div>
    </form>

    <div class="divide-y divide-slate-100 -mx-5 -mb-5">
        @forelse ($comments as $comment)
            <div class="px-5 py-3 flex items-start gap-3 text-sm" x-data="{ editing: false }">
                <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-xs font-semibold text-slate-500 shrink-0">
                    {{ $comment->user ? mb_substr($comment->user->name, 0, 1) : '?' }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-slate-700" x-show="!editing">
                        <span class="font-medium text-slate-900">{{ $comment->user?->name ?? 'ระบบ' }}</span>
                        {{ $comment->description }}
                    </p>

                    @if ($comment->attachments->isNotEmpty())
                        <div class="flex flex-wrap gap-1.5 mt-1.5" x-show="!editing">
                            @foreach ($comment->attachments as $attachment)
                                <a href="{{ $attachment->url }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1 text-xs text-blue-600 bg-blue-50 rounded-lg px-2 py-1 hover:bg-blue-100">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 10-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                                    <span class="truncate max-w-[10rem]">{{ $attachment->original_name }}</span>
                                    <span class="text-blue-400 shrink-0">({{ $attachment->size_for_humans }})</span>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @if ($comment->user_id === auth()->id())
                        <form x-show="editing" x-cloak method="POST" action="{{ route('activity-logs.update', $comment) }}" class="flex items-start gap-2 mt-1.5">
                            @csrf @method('PUT')
                            <textarea name="note" rows="2" required maxlength="2000" class="flex-1 rounded-lg border-slate-300 text-sm">{{ $comment->description }}</textarea>
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700 shrink-0">บันทึก</button>
                            <button type="button" @click="editing = false" class="px-3 py-1.5 text-xs text-slate-500 shrink-0">ยกเลิก</button>
                        </form>
                    @endif

                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ $comment->created_at->thaiDateTime() }}
                        @if ($comment->updated_at->ne($comment->created_at))
                            <span class="italic">(แก้ไขแล้ว)</span>
                        @endif
                        @if ($comment->user_id === auth()->id())
                            <button type="button" @click="editing = !editing" class="ml-2 text-blue-600 hover:underline">แก้ไข</button>
                        @endif
                    </p>
                </div>
            </div>
        @empty
            <p class="px-5 py-6 text-center text-slate-400 text-sm">ยังไม่มีคอมเมนต์</p>
        @endforelse
    </div>
    @if ($comments->hasPages())
        <div class="mt-4 -mx-5 -mb-5 px-5 pb-2">
            {{ $comments->onEachSide(1)->links() }}
        </div>
    @endif
</x-card>
