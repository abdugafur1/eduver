<?php

namespace Tests\Feature;

use App\Models\DailyActivity;
use App\Models\PointTransaction;
use App\Models\QuizSession;
use App\Models\StudySet;
use App\Models\User;
use App\Exceptions\ProtectedAccountException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The disposition rule these tests encode:
 *
 *   Delete a set unless it is public, or somebody other than the owner holds
 *   it — where "holds" means a set_user row or a quiz_sessions row.
 *
 * That answers all three visibilities without branching on visibility twice.
 * Private goes. Public stays. Unlisted stays only if it actually reached
 * someone; an unlisted set nobody ever saved or studied is functionally
 * private, and there is no third party to protect by keeping it.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user furnished with one of everything the delete path has to reason
     * about, plus a second user who holds some of their sets.
     *
     * ProfileTest's existing deletion tests build a bare factory user, which
     * is the single case where none of the hard questions arise: no ledger, no
     * sessions, no sets, no pivot rows. Every real account hits a different
     * path, so nothing below can be proven with that fixture.
     *
     * Balances are seeded so balanceAudit() agrees on both currencies before
     * deletion — column, newest balance_after, and SUM(amount) all 500 points
     * and 40 credits. A later test asserts it still agrees afterwards.
     *
     * @return array{owner:User, other:User, sets:array<string,StudySet>, foreign:StudySet, session:QuizSession}
     */
    private function furnishedUser(): array
    {
        $owner = User::factory()->create([
            'total_points' => 500,
            'credits' => 40,
        ]);

        $other = User::factory()->create();

        $sets = [
            // Nobody else has touched these two: both should go.
            'private' => StudySet::factory()->create([
                'user_id' => $owner->id,
                'visibility' => StudySet::VISIBILITY_PRIVATE,
            ]),
            'unlisted_untouched' => StudySet::factory()->create([
                'user_id' => $owner->id,
                'visibility' => StudySet::VISIBILITY_UNLISTED,
            ]),

            // Public: kept regardless of whether anyone holds it.
            'public' => StudySet::factory()->create([
                'user_id' => $owner->id,
                'visibility' => StudySet::VISIBILITY_PUBLIC,
            ]),

            // The two halves of "held" — one via the pivot, one via a quiz
            // session only. The second clause is the one an implementation
            // is most likely to forget, so it gets its own fixture.
            'unlisted_saved' => StudySet::factory()->create([
                'user_id' => $owner->id,
                'visibility' => StudySet::VISIBILITY_UNLISTED,
            ]),
            'unlisted_studied' => StudySet::factory()->create([
                'user_id' => $owner->id,
                'visibility' => StudySet::VISIBILITY_UNLISTED,
            ]),

            // Public but already trashed by the owner: disposable, because
            // leaving should not make their own deletion permanent. Found in
            // the live data — account 588 has two of these.
            'public_trashed' => tap(StudySet::factory()->create([
                'user_id' => $owner->id,
                'visibility' => StudySet::VISIBILITY_PUBLIC,
            ]), fn (StudySet $s) => $s->delete()),
        ];

        $other->savedSets()->attach([
            $sets['public']->id,
            $sets['unlisted_saved']->id,
        ]);

        QuizSession::factory()->create([
            'user_id' => $other->id,
            'study_set_id' => $sets['unlisted_studied']->id,
            'completed_at' => now(),
        ]);

        // The owner's own library: somebody else's set, saved. This is a
        // record of what the owner studied, so it is their data and should go
        // — but nothing in the FK graph removes it once the user row survives.
        $foreign = StudySet::factory()->create([
            'user_id' => $other->id,
            'visibility' => StudySet::VISIBILITY_PUBLIC,
        ]);

        $owner->savedSets()->attach($foreign->id);

        // A session of the owner's own, on a set that is about to be
        // force-deleted. The ledger row below points at it through the
        // nullableMorphs, which has no FK — so the row survives with a
        // source_id that no longer resolves. Deliberate, and asserted later.
        $session = QuizSession::factory()->create([
            'user_id' => $owner->id,
            'study_set_id' => $sets['private']->id,
            'completed_at' => now(),
        ]);

        DailyActivity::factory()->create([
            'user_id' => $owner->id,
        ]);

        PointTransaction::create([
            'user_id' => $owner->id,
            'currency' => PointTransaction::CURRENCY_POINTS,
            'amount' => 200,
            'reason' => 'legacy_import',
            'balance_after' => 200,
        ]);

        PointTransaction::create([
            'user_id' => $owner->id,
            'currency' => PointTransaction::CURRENCY_POINTS,
            'amount' => 300,
            'reason' => 'quiz',
            'source_type' => QuizSession::class,
            'source_id' => $session->id,
            'balance_after' => 500,
        ]);

        PointTransaction::create([
            'user_id' => $owner->id,
            'currency' => PointTransaction::CURRENCY_CREDITS,
            'amount' => 40,
            'reason' => 'quest',
            'balance_after' => 40,
        ]);

        // sessions has no FK on user_id, so nothing cascades here. One row
        // each: the owner's must go, $other's must not.
        foreach ([$owner, $other] as $user) {
            DB::table('sessions')->insert([
                'id' => Str::random(40),
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => base64_encode('test'),
                'last_activity' => now()->getTimestamp(),
            ]);
        }

        return compact('owner', 'other', 'sets', 'foreign', 'session');
    }

    private function deleteAccount(User $user): TestResponse
    {
        return $this
            ->actingAs($user)
            ->delete('/profile', ['password' => 'password']);
    }

    public function test_the_fixture_builds(): void
    {
        ['owner' => $owner, 'sets' => $sets] = $this->furnishedUser();

        $this->assertCount(6, $sets);
        $this->assertTrue($owner->balanceAudit()['agrees']);
        $this->assertTrue($owner->balanceAudit(PointTransaction::CURRENCY_CREDITS)['agrees']);
    }

    public function test_sets_nobody_else_holds_are_removed_entirely(): void
    {
        ['owner' => $owner, 'sets' => $sets] = $this->furnishedUser();

        $this->deleteAccount($owner);

        foreach (['private', 'unlisted_untouched', 'public_trashed'] as $key) {
            // withTrashed(), because a soft delete here would not be deletion:
            // the cards stay in full, forever — the same shape as the 1,125
            // dedupe shells. If someone asked for their content to be gone,
            // deleted_at is not gone.
            $this->assertNull(
                StudySet::withTrashed()->find($sets[$key]->id),
                "the {$key} set should be force-deleted, not soft-deleted"
            );
        }
    }

    public function test_public_and_held_sets_stay_under_the_tombstone(): void
    {
        ['owner' => $owner, 'sets' => $sets] = $this->furnishedUser();

        $this->deleteAccount($owner);

        foreach (['public', 'unlisted_saved', 'unlisted_studied'] as $key) {
            // Unscoped find(): these must be neither hard- nor soft-deleted.
            $kept = StudySet::find($sets[$key]->id);

            $this->assertNotNull($kept, "the {$key} set should survive");
            $this->assertSame($owner->id, $kept->user_id, 'ownership should not move');
        }

        // Kept, but not promoted. A set reachable by link does not become
        // browsable because its author left.
        $this->assertSame(
            StudySet::VISIBILITY_UNLISTED,
            StudySet::find($sets['unlisted_saved']->id)->visibility
        );
    }

    public function test_other_users_keep_what_they_held(): void
    {
        ['owner' => $owner, 'other' => $other, 'sets' => $sets] = $this->furnishedUser();

        $this->deleteAccount($owner);

        // The assertion that catches a future refactor turning the tombstone
        // back into a cascade: set_user is cascadeOnDelete on study_set_id, so
        // hard-deleting a kept set would empty it out of someone else's
        // library with no notification and no trace.
        $this->assertDatabaseHas('set_user', [
            'user_id' => $other->id,
            'study_set_id' => $sets['public']->id,
        ]);

        $this->assertDatabaseHas('set_user', [
            'user_id' => $other->id,
            'study_set_id' => $sets['unlisted_saved']->id,
        ]);

        $this->assertSame(2, $other->fresh()->savedSets()->count());
    }

    public function test_the_row_survives_with_the_pii_overwritten(): void
    {
        ['owner' => $owner] = $this->furnishedUser();

        // actingAs() puts this very instance on the guard, so $request->user()
        // inside the controller IS $owner and closeAccount() overwrites it in
        // place. Read the originals off before they are gone, or every
        // assertion below compares a value to itself.
        $id = $owner->id;
        $original = $owner->only(['username', 'name', 'email']);

        $this->deleteAccount($owner);

        $tombstone = User::withTrashed()->find($id);

        $this->assertNotNull($tombstone, 'the row is kept — every FK to it cascades');
        $this->assertNotNull($tombstone->deleted_at);
        $this->assertNull(User::find($id), 'and is scoped out of ordinary queries');

        $this->assertSame(User::TOMBSTONE_PREFIX.$id, $tombstone->username);
        $this->assertSame('Deleted user', $tombstone->name);
        $this->assertStringEndsWith('@'.User::TOMBSTONE_EMAIL_DOMAIN, $tombstone->email);
        $this->assertNull($tombstone->email_verified_at);
        $this->assertNull($tombstone->remember_token);
        $this->assertNull($tombstone->legacy_id);
        $this->assertTrue($tombstone->isTombstone());

        // The point of the exercise, stated directly.
        $this->assertNotSame($original['email'], $tombstone->email);
        $this->assertNotSame($original['username'], $tombstone->username);
        $this->assertNotSame($original['name'], $tombstone->name);
    }

    public function test_the_ledger_survives_and_still_audits_clean(): void
    {
        ['owner' => $owner] = $this->furnishedUser();

        $this->deleteAccount($owner);

        $tombstone = User::withTrashed()->find($owner->id);

        $this->assertSame(3, $tombstone->pointTransactions()->count());
        $this->assertSame(1, $tombstone->dailyActivities()->count());

        // NOT zeroed. Clearing the columns while the ledger rows survive would
        // break all three legs of the audit against each other — the tombstone
        // has to keep auditing clean or the audit means nothing.
        $this->assertSame(500, $tombstone->total_points);
        $this->assertSame(40, $tombstone->credits);

        $this->assertTrue($tombstone->balanceAudit()['agrees']);
        $this->assertTrue($tombstone->balanceAudit(PointTransaction::CURRENCY_CREDITS)['agrees']);
    }

    public function test_a_ledger_row_pointing_at_a_destroyed_session_keeps_its_amount(): void
    {
        ['owner' => $owner, 'session' => $session] = $this->furnishedUser();

        $this->deleteAccount($owner);

        // The session went with its set, via the cascade on study_set_id.
        $this->assertDatabaseMissing('quiz_sessions', ['id' => $session->id]);

        // point_transactions.source is a nullableMorphs with no FK, so the
        // ledger row survives with a source_id that no longer resolves. That
        // is the accepted cost of keeping the ledger intact — pinned here so
        // it is documented rather than discovered. Anything calling source()
        // needs a null guard.
        $row = PointTransaction::where('source_id', $session->id)
            ->where('reason', 'quiz')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->source);
        $this->assertSame(300, $row->amount);
    }

    public function test_their_own_library_and_sessions_are_removed(): void
    {
        ['owner' => $owner, 'other' => $other] = $this->furnishedUser();

        $this->deleteAccount($owner);

        $this->assertDatabaseMissing('set_user', ['user_id' => $owner->id]);

        // No FK on sessions.user_id, so this is hand-rolled and therefore the
        // easiest thing in the whole path to forget. Left behind, a cookie on
        // a device they are still signed in on stays authenticated as the
        // tombstone — scrambling the password does not invalidate it.
        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
    }

    public function test_a_closed_account_cannot_log_in(): void
    {
        ['owner' => $owner] = $this->furnishedUser();

        $email = $owner->email;
        $username = $owner->username;

        $this->deleteAccount($owner);

        // LoginRequest accepts either identifier, so both need proving.
        //
        // SoftDeletingScope on EloquentUserProvider's lookup is the only thing
        // stopping this, and it is worth being explicit about why: the
        // credentials array also carries `legacy_id => null` to exclude
        // imported authorship rows, but closeAccount() nulls legacy_id, so a
        // closed legacy account passes that clause. The scope is load-bearing.
        foreach ([$email, $username] as $identifier) {
            $this->post('/login', [
                'login' => $identifier,
                'password' => 'password',
            ])->assertSessionHasErrors('login');

            $this->assertGuest();
        }
    }

    public function test_a_squatted_tombstone_username_does_not_block_deletion(): void
    {
        ['owner' => $owner] = $this->furnishedUser();

        // username is unique. Without the retry in tombstoneUsername(), this
        // would make the deletion fail on a constraint violation — a stranger
        // able to permanently block someone else's account closure.
        User::factory()->create(['username' => User::TOMBSTONE_PREFIX.$owner->id]);

        $this->deleteAccount($owner)->assertRedirect('/');

        $tombstone = User::withTrashed()->find($owner->id);

        $this->assertNotNull($tombstone->deleted_at);
        $this->assertTrue($tombstone->isTombstone());
        $this->assertNotSame(User::TOMBSTONE_PREFIX.$owner->id, $tombstone->username);
        $this->assertLessThanOrEqual(30, strlen($tombstone->username));
    }

    public function test_a_failure_partway_through_leaves_the_account_untouched(): void
    {
        ['owner' => $owner, 'sets' => $sets] = $this->furnishedUser();

        // Captured, not read off $owner afterwards: the guard holds this same
        // instance, so if the failure point ever moves past the forceFill()
        // these assertions would compare mutated state to itself and pass
        // while proving nothing.
        $original = $owner->only(['username', 'email']);

        // Blow up on the sessions delete — after the sets have been destroyed
        // and the pivot detached, before the row is overwritten.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'delete from "sessions"')) {
                throw new RuntimeException('simulated failure');
            }
        });

        try {
            $this->deleteAccount($owner);
        } catch (RuntimeException) {
            // The exception is not the assertion; the state afterwards is.
        }

        $this->assertNotNull(
            StudySet::withTrashed()->find($sets['private']->id),
            'the transaction should have rolled the force-delete back'
        );

        $fresh = $owner->fresh();

        $this->assertNull($fresh->deleted_at);
        $this->assertSame($original['username'], $fresh->username);
        $this->assertSame($original['email'], $fresh->email);

        // The old destroy() logged out first, so a failure here left the user
        // signed out of a half-deleted account with the session never
        // invalidated. They should still be signed in and whole.
        $this->assertAuthenticatedAs($fresh);
    }

        public function test_a_protected_account_cannot_be_closed(): void
    {
        ['owner' => $owner, 'sets' => $sets] = $this->furnishedUser();

        $owner->forceFill(['is_protected' => true])->save();
        $original = $owner->only(['username', 'email']);

        $this->deleteAccount($owner)
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $fresh = $owner->fresh();

        $this->assertNull($fresh->deleted_at);
        $this->assertSame($original['username'], $fresh->username);
        $this->assertSame($original['email'], $fresh->email);
        $this->assertNotNull(
            StudySet::withTrashed()->find($sets['private']->id),
            'nothing should have been destroyed'
        );
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_close_account_refuses_a_protected_account_directly(): void
    {
        ['owner' => $owner] = $this->furnishedUser();

        $owner->forceFill(['is_protected' => true])->save();

        $this->expectException(ProtectedAccountException::class);

        $owner->closeAccount();
    }
}
