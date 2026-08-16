<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * Replaces Breeze's RegisteredUserController.
 *
 * `users.username` is varchar(30) NOT NULL UNIQUE in the imported schema, so
 * Breeze's stock registration — which only writes name, email, password —
 * fails on an integrity constraint. Username is collected explicitly rather
 * than derived from the name, because it's user-facing on every set and in the
 * leaderboard, and a derived one would collide constantly.
 */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            // alpha_dash keeps '@' out of usernames, which is what lets the
            // login form tell an email from a username without ambiguity.
            // max:30 matches the column, not Laravel's usual 255 — validating
            // looser than the schema turns a form error into a DB exception.
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'alpha_dash:ascii',
                'not_in:admin,administrator,root,support,help,api,eduver,official,staff,moderator',
                'unique:'.User::class.',username',
            ],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:'.User::class.',email',
            ],

            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('sets.index', absolute: false));
    }
}
