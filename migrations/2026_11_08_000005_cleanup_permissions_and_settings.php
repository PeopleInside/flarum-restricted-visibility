<?php

use Illuminate\Database\Schema\Builder;

/*
 * Permessi e impostazioni non vengono rimossi da Flarum quando si elimina l'estensione:
 * lo facciamo noi nel "down" (eseguito da "Elimina dati" / migrate:reset).
 */
return [
    'up' => function (Builder $schema) {
        // niente da fare
    },
    'down' => function (Builder $schema) {
        $db = $schema->getConnection();

        $db->table('group_permission')->whereIn('permission', [
            'peopleinside-restricted-visibility.restrict',
            'peopleinside-restricted-visibility.viewAll',
        ])->delete();

        $db->table('settings')->where('key', 'like', 'peopleinside-restricted-visibility.%')->delete();
    },
];
