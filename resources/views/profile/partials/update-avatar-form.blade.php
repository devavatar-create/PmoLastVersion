<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">รูปโปรไฟล์</h2>
        <p class="mt-1 text-sm text-gray-600">แสดงข้างคอมเมนต์ในหน้า Planning (JPG / PNG ไม่เกิน 2MB)</p>
    </header>
    <div class="mt-4 flex items-center gap-4">
        @if ($user->avatar_url)
            <img src="{{ $user->avatar_url }}" alt="" class="h-16 w-16 rounded-full object-cover">
        @else
            <span class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-400 text-2xl font-bold text-white">{{ mb_substr($user->name, 0, 1) }}</span>
        @endif
        <form method="POST" action="{{ route('profile.avatar') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
            @csrf
            <input type="file" name="avatar" accept=".jpg,.jpeg,.png" required class="text-sm">
            <x-primary-button>อัปโหลด</x-primary-button>
        </form>
        @if ($user->avatar_url)
            <form method="POST" action="{{ route('profile.avatar') }}">@csrf <input type="hidden" name="remove" value="1">
                <button class="text-sm text-red-600 hover:underline">เอารูปออก</button></form>
        @endif
    </div>
    <x-input-error :messages="$errors->get('avatar')" class="mt-2" />
    @if (session('status') === 'avatar-updated')<p class="mt-2 text-sm text-gray-600">บันทึกแล้ว</p>@endif
</section>
