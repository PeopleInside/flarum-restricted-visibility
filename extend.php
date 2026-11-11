<?php

use PeopleInside\RestrictedVisibility\Access;
use PeopleInside\RestrictedVisibility\Api;
use PeopleInside\RestrictedVisibility\Authz;
use PeopleInside\RestrictedVisibility\Console;
use PeopleInside\RestrictedVisibility\Constants;
use PeopleInside\RestrictedVisibility\Fields;
use PeopleInside\RestrictedVisibility\Notification\RecipientFilter;
use PeopleInside\RestrictedVisibility\Repository;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\Post;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    // Posizione del pulsante nel composer (scelta dall'amministratore).
    (new Extend\Settings())
        ->default(Constants::SETTING_BUTTON_POSITION, Constants::BUTTON_POSITION_DEFAULT)
        ->serializeToForum(
            'peopleinsideComposerButtonPosition',
            Constants::SETTING_BUTTON_POSITION,
            fn ($value) => in_array($value, Constants::BUTTON_POSITIONS, true) ? $value : Constants::BUTTON_POSITION_DEFAULT
        ),

    // Scope di visibilità: valgono ovunque (API, ricerca, conteggi, ...).
    (new Extend\ModelVisibility(Discussion::class))
        ->scope(Access\DiscussionScope::class),
    (new Extend\ModelVisibility(Post::class))
        ->scope(Access\PostScope::class),

    // Campi API. Prima della serializzazione si precarica in blocco ciò che serve a tutta la pagina
    // (righe di restrizione, conteggi, nomi dei destinatari): niente query per ogni modello.
    (new Extend\ApiResource(Resource\PostResource::class))
        ->fields(fn () => Fields::build('post'))
        ->endpoint([Endpoint\Index::class, Endpoint\Show::class], fn ($endpoint) => $endpoint->after(
            function (Context $context, mixed $data) {
                Repository::preload($data, $context->getActor());

                return $data;
            }
        )),

    (new Extend\ApiResource(Resource\DiscussionResource::class))
        ->fields(fn () => array_merge(Fields::build('discussion'), [
            Schema\Integer::make('restrictedPostCount')
                ->get(fn (Discussion $d, Context $c) => Repository::postCount($d, $c->getActor())),
        ]))
        ->endpoint([Endpoint\Index::class, Endpoint\Show::class], fn ($endpoint) => $endpoint->after(
            function (Context $context, mixed $data) {
                Repository::preload($data, $context->getActor());

                return $data;
            }
        )),

    (new Extend\ApiResource(Resource\ForumResource::class))
        ->fields(fn () => [
            // Compatibilità: vero se l'utente può usare almeno uno dei due permessi.
            Schema\Boolean::make('peopleinsideCanRestrictVisibility')
                ->get(fn ($forum, Context $c) => Authz::mayUseUi($c->getActor())),
            Schema\Boolean::make('peopleinsideCanCreatePrivate')
                ->get(fn ($forum, Context $c) => Authz::mayCreatePrivate($c->getActor())),
            Schema\Boolean::make('peopleinsideCanMakePrivate')
                ->get(fn ($forum, Context $c) => Authz::mayMakePrivate($c->getActor())),
            Schema\Boolean::make('peopleinsideCanMakePublic')
                ->get(fn ($forum, Context $c) => Authz::mayMakePublic($c->getActor())),
        ]),

    // Filtro centralizzato dei destinatari: nessun alert e nessuna email a chi non può vedere.
    (new Extend\Notification())
        ->beforeSending(RecipientFilter::class),

    // Rimozione dati (solo amministratori).
    (new Extend\Routes('api'))
        ->get('/restricted-visibility/purge-preview', 'peopleinside-restricted-visibility.purge.preview', Api\PurgePreviewController::class)
        ->post('/restricted-visibility/purge', 'peopleinside-restricted-visibility.purge', Api\PurgeController::class),

    (new Extend\Console())
        ->command(Console\PurgeCommand::class),
];
