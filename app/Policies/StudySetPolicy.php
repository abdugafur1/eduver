<?php

namespace App\Policies;

use App\Models\StudySet;
use App\Models\User;

/**
 * Write authorisation for sets. Read authorisation deliberately stays on the
 * model as StudySet::isReadableBy(), because it has to answer for guests —
 * policies take a User and the whole point of `unlisted` is that a logged-out
 * visitor holding the link can read it.
 *
 * Auto-discovered: App\Models\StudySet resolves to App\Policies\StudySetPolicy
 * by convention, so there is nothing to register in a service provider.
 *
 * Cards have no policy of their own. A card's owner is its set's owner, so
 * CardController authorises against $card->studySet through this class.
 */
class StudySetPolicy
{
    /**
     * Any authenticated user may create a set. Present as a method anyway so
     * the controller reads consistently, and so a future limit (per-account
     * cap, verified-email requirement) has an obvious home.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StudySet $studySet): bool
    {
        return $user->getKey() === $studySet->user_id;
    }

    public function delete(User $user, StudySet $studySet): bool
    {
        return $this->update($user, $studySet);
    }

    /**
     * Adding, editing and removing cards is the same permission as editing the
     * set. Separate method because that may not stay true — a shared set with
     * contributors would diverge here first.
     */
    public function manageCards(User $user, StudySet $studySet): bool
    {
        return $this->update($user, $studySet);
    }
}
