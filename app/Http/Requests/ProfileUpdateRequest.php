<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'alpha_dash:ascii',
                // The tombstone namespace is reserved. Without this, registering
                // deleted_user_57 makes user 57's account closure fall back to a random
                // suffix — the retry in User::tombstoneUsername() is a backstop, not
                // the guard. Case-insensitive because MySQL's collation is and SQLite's
                // isn't; without /i the rule is weaker in production than in tests.
                'not_regex:/^'.preg_quote(User::TOMBSTONE_PREFIX, '/').'/i',
                'not_in:admin,administrator,root,support,help,api,eduver,official,staff,moderator',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
