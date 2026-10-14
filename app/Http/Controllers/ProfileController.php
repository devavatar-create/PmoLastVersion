<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /** The profile picture shown on comments on the Planning page (a JPG / PNG, or remove=1 to go back to the initial). */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $dir = public_path('uploads/avatars');

        if ($user->avatar_path) {
            @unlink($dir.'/'.$user->avatar_path);
        }
        $path = null;
        if (! $request->boolean('remove') && $request->hasFile('avatar')) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
                file_put_contents($dir.'/.htaccess', "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
            }
            $path = \Illuminate\Support\Str::random(32).'.'.$request->file('avatar')->extension();
            $request->file('avatar')->move($dir, $path);
        }
        $user->forceFill(['avatar_path' => $path])->save();

        return Redirect::route('profile.edit')->with('status', 'avatar-updated');
    }
}
