<?php

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * Thrown when something tries to close an account flagged is_protected.
 *
 * The controller checks the flag before it gets this far, so reaching here
 * means a caller went straight to closeAccount() — a command, a seeder, a
 * future admin screen. That is exactly when the guard needs to hold, which is
 * why it lives on the model and not only in the request path.
 */
class ProtectedAccountException extends RuntimeException
{
    public static function for(User $user): self
    {
        return new self(
            "Account {$user->getKey()} ({$user->username}) is protected and cannot be closed."
        );
    }
}
