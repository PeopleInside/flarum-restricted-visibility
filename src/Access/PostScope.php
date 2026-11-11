<?php

namespace PeopleInside\RestrictedVisibility\Access;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class PostScope
{
    public function __invoke(User $actor, Builder $query): void
    {
        if (Scope::bypasses($actor)) {
            return;
        }

        $query->where(function ($q) use ($actor) {
            Scope::conditions($q, $actor, 'post', 'posts');
        });

        // La visibilità della discussione è già applicata dal core (PostPolicy::find usa whereVisibleTo
        // sulla discussione, che include il nostro DiscussionScope): non serve ripeterla qui.
    }
}
