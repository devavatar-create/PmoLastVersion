{{--
    "← ย้อนกลับ" for a page's header. Goes back to where the person came from (keeping that page's tab / filters / scroll) -
    except when that was a create / edit form (after saving, going "back" to the form is never wanted) or another site;
    then it goes to the page's natural parent ($href, named $label in the tooltip). Ctrl / ⌘-click opens the parent in a new tab.
--}}
@props(['href', 'label'])

<a href="{{ $href }}"
   x-data="{ prev: (() => { try { const r = document.referrer; if (!r || history.length < 2) return false; const u = new URL(r);
                return u.origin === location.origin && u.pathname !== location.pathname && !/\/(create|edit)\/?$/.test(u.pathname) && !/\/login\/?$/.test(u.pathname); } catch (e) { return false; } })() }"
   @click="if (prev && !$event.metaKey && !$event.ctrlKey && !$event.shiftKey) { $event.preventDefault(); history.back(); }"
   :title="prev ? 'ย้อนกลับไปหน้าก่อน' : @js('ไปที่ '.$label)"
   {{ $attributes->merge(['class' => 'inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-slate-300 bg-white pl-1.5 pr-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900']) }}>
    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
    <span>ย้อนกลับ</span>
</a>
