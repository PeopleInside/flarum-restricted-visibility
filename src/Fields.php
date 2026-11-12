<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Database\AbstractModel;

/**
 * I getter non leggono il database direttamente: passano da Repository::defer*(), che accoda il
 * modello e fa caricare in blocco tutti quelli della stessa risposta (qualunque endpoint la produca).
 */
final class Fields
{
    /** Campi comuni a Post e Discussion. */
    public static function build(string $kind): array
    {
        $mayWrite = fn (AbstractModel $m, Context $c): bool => Authz::mayWrite($c->getActor(), $m);
        $mayInspect = fn (AbstractModel $m, Context $c): bool => Authz::mayInspect($c->getActor(), $m);

        return [
            // Lo vede chiunque possa vedere il contenuto (se lo vede, sa che è riservato).
            Schema\Boolean::make('isRestricted')
                ->get(fn (AbstractModel $m) => Repository::defer($m, fn () => Repository::isRestricted($m))),

            Schema\Boolean::make('canRestrictVisibility')
                ->get($mayWrite),

            // Scrittura: permesso + proprietà/moderazione, validazione, poi sync in transazione.
            Schema\Arr::make('restrictedGroupIds')
                ->nullable()
                ->writable($mayWrite)
                ->visible($mayInspect)
                ->get(fn (AbstractModel $m) => Repository::defer($m, fn () => Repository::rows($m)['groups']))
                ->set(function (AbstractModel $m, $value, Context $c) {
                    // Valida ora; la scrittura avviene dentro save() (ModelHooks), prima delle notifiche.
                    ModelHooks::ensure($m);
                    Pending::setActor($m, $c->getActor());
                    Pending::put($m, 'groups', Repository::sanitizeGroups($c->getActor(), $value));
                }),

            Schema\Arr::make('restrictedUserIds')
                ->nullable()
                ->writable($mayWrite)
                ->visible($mayInspect)
                ->get(fn (AbstractModel $m) => Repository::defer($m, fn () => Repository::rows($m)['users']))
                ->set(function (AbstractModel $m, $value, Context $c) {
                    ModelHooks::ensure($m);
                    Pending::setActor($m, $c->getActor());
                    Pending::put($m, 'users', Repository::sanitizeUsers($c->getActor(), $value));
                }),

            // Solo lettura: nomi per l'interfaccia, limitati agli utenti che l'attore può vedere.
            Schema\Arr::make('restrictedUsers')
                ->visible($mayInspect)
                ->get(fn (AbstractModel $m, Context $c) => Repository::deferUserInfo($m, $c->getActor())),
        ];
    }
}
