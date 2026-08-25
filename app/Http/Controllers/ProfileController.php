<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * Close the user's account.
     *
     * Order matters and used to be wrong. The destructive work runs first, in
     * one transaction; the auth teardown runs only after it commits. Logging
     * out first — as this did — means a failure halfway through leaves the
     * person signed out of an account that is half deleted, with the session
     * never invalidated and nothing rolled back.
     *
     * User::closeAccount() explains what survives and why. The short version:
     * the row stays, the PII goes, and the ledger is untouched.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Before the password check: there is no reason to process a
        // credential for an action that cannot happen either way.
        if ($request->user()->is_protected) {
            return Redirect::route('profile.edit')->withErrors(
                ['password' => 'This account cannot be closed.'],
                'userDeletion'
            );
        }

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        DB::transaction(fn () => $user->closeAccount());

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
