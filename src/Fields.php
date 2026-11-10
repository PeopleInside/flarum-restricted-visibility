<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Database\AbstractModel;

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
                ->get(fn (AbstractModel $m) => Repository::isRestricted($m)),

            Schema\Boolean::make('canRestrictVisibility')
                ->get($mayWrite),

            // Scrittura: permesso + proprietà/moderazione, validazione, poi sync in transazione.
            Schema\Arr::make('restrictedGroupIds')
                ->nullable()
                ->writable($mayWrite)
                ->visible($mayInspect)
                ->get(fn (AbstractModel $m) => Repository::rows($m)['groups'])
                ->set(function (AbstractModel $m, $value, Context $c) {
                    Pending::put($m, 'groups', Repository::sanitizeGroups($c->getActor(), $value));
                })
                ->save(function (AbstractModel $m, $value, Context $c) {
                    $ids = Pending::part($m, 'groups') ?? Repository::sanitizeGroups($c->getActor(), $value);
                    Repository::replace($m, 'groups', $ids);
                }),

            Schema\Arr::make('restrictedUserIds')
                ->nullable()
                ->writable($mayWrite)
                ->visible($mayInspect)
                ->get(fn (AbstractModel $m) => Repository::rows($m)['users'])
                ->set(function (AbstractModel $m, $value, Context $c) {
                    Pending::put($m, 'users', Repository::sanitizeUsers($c->getActor(), $value));
                })
                ->save(function (AbstractModel $m, $value, Context $c) {
                    $ids = Pending::part($m, 'users') ?? Repository::sanitizeUsers($c->getActor(), $value);
                    Repository::replace($m, 'users', $ids);
                }),

            // Solo lettura: nomi per l'interfaccia, limitati agli utenti che l'attore può vedere.
            Schema\Arr::make('restrictedUsers')
                ->visible($mayInspect)
                ->get(fn (AbstractModel $m, Context $c) => Repository::userInfo(Repository::rows($m)['users'], $c->getActor())),
        ];
    }
}
