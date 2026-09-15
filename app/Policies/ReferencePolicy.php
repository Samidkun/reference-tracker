<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reference;
use App\Models\User;

/**
 * Every ownership rule for references lives here — not in the controller.
 *
 * Why: controllers get rewritten, refactored, and copy-pasted. A policy is
 * a single choke point, so "can this user touch this row?" has exactly one
 * answer, and one place to test.
 */
class ReferencePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Reference $reference): bool
    {
        return $reference->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Reference $reference): bool
    {
        return $reference->user_id === $user->id;
    }

    public function delete(User $user, Reference $reference): bool
    {
        return $reference->user_id === $user->id;
    }
}
