<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Flarum\User\User;

final class Authz
{
    /** Può creare nuovi contenuti privati. */
    public static function mayCreatePrivate(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission(Constants::PERM_CREATE_PRIVATE);
    }

    /** Può rendere privato (o cambiare i destinatari di) un contenuto già esistente. */
    public static function mayMakePrivate(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission(Constants::PERM_MAKE_PRIVATE);
    }

    /** Può rendere pubblici (rimuovere la restrizione) i contenuti. */
    public static function mayMakePublic(User $actor): bool
    {
        return ! $actor->isGuest() && $actor->hasPermission(Constants::PERM_MAKE_PUBLIC);
    }

    /** Mostra i controlli nell'interfaccia: serve almeno uno dei tre permessi. */
    public static function mayUseUi(User $actor): bool
    {
        return self::mayCreatePrivate($actor) || self::mayMakePrivate($actor) || self::mayMakePublic($actor);
    }

    /** Può impostare o modificare la restrizione su questo modello (anche non ancora salvato). */
    public static function mayWrite(User $actor, AbstractModel $model): bool
    {
        // Contenuto nuovo: può solo nascere riservato, serve il permesso di crearne.
        if (! $model->exists) {
            return self::mayCreatePrivate($actor);
        }
        if (! (self::mayMakePrivate($actor) || self::mayMakePublic($actor))) {
            return false;
        }
        // Il primo post segue la discussione: va restretta la discussione, non il suo primo post.
        if ($model instanceof Post && $model->exists && (int) $model->number === 1) {
            return false;
        }

        return $actor->isAdmin()
            || $actor->hasPermission(Constants::PERM_VIEW_ALL)
            || (int) $model->user_id === (int) $actor->id;
    }

    /**
     * Verifica il passaggio di stato richiesto, confrontando restrizione salvata e restrizione finale:
     *  - nuovo contenuto riservato       : serve "creare nuovi contenuti privati"
     *  - esistente pubblico -> riservato : serve "rendere privato un contenuto esistente"
     *  - riservato -> riservato diverso  : serve "rendere privato un contenuto esistente" (cambio destinatari)
     *  - riservato -> pubblico           : serve "rendere pubblico"
     *  - nessun cambiamento              : sempre consentito
     *
     * "Riservato" è il marcatore, non l'elenco: un contenuto senza più destinatari è comunque riservato.
     *
     * @param array{groups: int[], users: int[], restricted: bool} $stored
     * @param array{groups: int[], users: int[], restricted: bool} $final
     */
    public static function mayTransition(User $actor, array $stored, array $final, bool $isNew = false): bool
    {
        if ($stored['restricted'] === $final['restricted']
            && self::sameSet($stored['groups'], $final['groups'])
            && self::sameSet($stored['users'], $final['users'])) {
            return true;
        }
        if ($stored['restricted'] && ! $final['restricted']) {
            return self::mayMakePublic($actor);
        }

        return $isNew ? self::mayCreatePrivate($actor) : self::mayMakePrivate($actor);
    }

    /** Può vedere l'elenco dei destinatari (gli altri vedono solo che il contenuto è riservato). */
    public static function mayInspect(User $actor, AbstractModel $model): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        return $actor->isAdmin()
            || $actor->hasPermission(Constants::PERM_VIEW_ALL)
            || (self::mayUseUi($actor) && (int) $model->user_id === (int) $actor->id);
    }

    /** @param int[] $a @param int[] $b */
    private static function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
