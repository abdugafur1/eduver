<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Imported accounts are authorship records, not accounts.
 *
 * Their password column holds the sentinel '!legacy-import-no-login!', which
 * Hash::check() can never match because it isn't a parseable crypt string. That
 * is currently the only thing standing between 187 dormant rows and a working
 * login, so it's worth asserting rather than assuming — a later change to the
 * import, the factory, or the credential lookup could undo it silently.
 */
class LegacyAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_factory_state_writes_the_sentinel_unhashed(): void
    {
        $user = User::factory()->legacy()->create();

        // getRawOriginal, not ->password: the 'hashed' cast on the model would
        // otherwise quietly bcrypt the sentinel, and a hash of a known string is
        // a password. This assertion is the whole reason for the afterMaking().
        $this->assertSame('!legacy-import-no-login!', $user->getRawOriginal('password'));
        $this->assertNotNull($user->legacy_id);
        $this->assertTrue($user->must_reset_password);
    }

    public function test_legacy_accounts_cannot_authenticate_with_the_sentinel(): void
    {
        $user = User::factory()->legacy()->create();

        $response = $this->post('/login', [
            'login' => $user->username,
            'password' => '!legacy-import-no-login!',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();
    }

    public function test_legacy_accounts_cannot_authenticate_by_email_either(): void
    {
        $user = User::factory()->legacy()->create();

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();
    }

    public function test_normal_accounts_still_authenticate(): void
    {
        // The control. Without it, a bug that broke login for everyone would
        // leave the three tests above passing and looking like good news.
        $user = User::factory()->create();

        $this->post('/login', [
            'login' => $user->username,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }
}
