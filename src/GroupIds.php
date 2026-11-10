<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Group\Group;
use Flarum\User\User;

final class GroupIds
{
    /** @return int[] ID dei gruppi dell'utente, compresi quelli impliciti (Guest / Member). */
    public static function for(User $user): array
    {
        $ids = $user->exists ? $user->groups->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $ids[] = $user->isGuest() ? Group::GUEST_ID : Group::MEMBER_ID;

        return array_values(array_unique($ids));
    }
}
