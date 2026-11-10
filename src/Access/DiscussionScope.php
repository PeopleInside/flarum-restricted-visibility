<?php

namespace PeopleInside\RestrictedVisibility\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class DiscussionScope
{
    public function __invoke(User $actor, Builder $query): void
    {
        if (Scope::bypasses($actor)) {
            return;
        }

        $query->where(function ($q) use ($actor) {
            Scope::conditions($q, $actor, 'discussion', 'discussions');
        });
    }
}
