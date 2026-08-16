<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Replaces Breeze's LoginRequest.
 *
 * The legacy site authenticated on username OR email (functions.inc.php's
 * uidExists() was called with the same value for both parameters). Breeze only
 * knows about email, so imported users who signed up under a username they
 * remember and an email they don't would be locked out. This restores the
 * legacy behaviour on top of Laravel's guard.
 *
 * Legacy passwords need no migration: signup used password_hash($p,
 * PASSWORD_DEFAULT), which is bcrypt in the same crypt() format Laravel's
 * default hash driver produces, so Hash::check() validates them untouched.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function validationData(): array
    {
        // Trim here rather than in the view so a pasted identifier with a
        // trailing space doesn't read as a wrong password.
        return array_merge($this->all(), [
            'login' => trim((string) $this->input('login')),
        ]);
    }

    public function attributes(): array
    {
        return ['login' => 'username or email'];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            $this->identifierField() => $this->input('login'),
            'password' => $this->input('password'),

            // Imported rows are authorship records, not accounts. Their password
            // column holds the string '!legacy', which Hash::check() can never
            // match — but that's an accident of the import script rather than a
            // guarantee, so the lookup excludes them structurally as well.
            'legacy_id' => null,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            // One message for both branches on purpose. Saying "no such user"
            // versus "wrong password" turns the login form into a tool for
            // discovering which accounts exist.
            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Which column the submitted identifier should be matched against.
     *
     * Usernames are varchar(30) and validated as alpha_dash at registration, so
     * they can never contain '@' — an input that parses as an email is
     * unambiguously an email, and anything else is a username lookup.
     */
    protected function identifierField(): string
    {
        return filter_var($this->input('login'), FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'username';
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('login')).'|'.$this->ip());
    }
}
