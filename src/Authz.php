<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Flarum\User\User;

final class Authz
{
    /** Mostra i controlli nell'interfaccia. */
    public static function mayUseUi(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission(Constants::PERM_RESTRICT);
    }

    /** Può impostare o modificare la restrizione su questo modello (anche non ancora salvato). */
    public static function mayWrite(User $actor, AbstractModel $model): bool
    {
        if (! self::mayUseUi($actor)) {
            return false;
        }
        // Il primo post segue la discussione: va restretta la discussione, non il suo primo post.
        if ($model instanceof Post && $model->exists && (int) $model->number === 1) {
            return false;
        }
        if (! $model->exists) {
            return true;
        }

        return $actor->isAdmin()
            || $actor->hasPermission(Constants::PERM_VIEW_ALL)
            || (int) $model->user_id === (int) $actor->id;
    }

    /** Può vedere l'elenco dei destinatari (gli altri vedono solo che il contenuto è riservato). */
    public static function mayInspect(User $actor, AbstractModel $model): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        return $actor->isAdmin()
            || $actor->hasPermission(Constants::PERM_VIEW_ALL)
            || ($actor->hasPermission(Constants::PERM_RESTRICT) && (int) $model->user_id === (int) $actor->id);
    }
}
