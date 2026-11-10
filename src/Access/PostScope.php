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

        // Difesa in profondità: il core già filtra i post per visibilità della discussione,
        // ma qui la ripetiamo esplicitamente così la sicurezza non dipende da quel dettaglio.
        $query->whereExists(function ($s) use ($actor) {
            $s->selectRaw('1')->from('discussions')
                ->whereColumn('discussions.id', 'posts.discussion_id')
                ->where(function ($q) use ($actor) {
                    Scope::conditions($q, $actor, 'discussion', 'discussions');
                });
        });
    }
}
