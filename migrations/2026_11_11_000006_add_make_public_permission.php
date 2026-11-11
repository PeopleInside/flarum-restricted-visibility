<?php

use Illuminate\Database\Schema\Builder;

/*
 * Nuovi permessi:
 *  - "createPrivate": creare NUOVI contenuti privati;
 *  - "makePublic": rendere pubblico un contenuto riservato.
 * Il permesso esistente ".restrict" ora vale per rendere privato un contenuto ESISTENTE.
 *
 * Fino alla 1.0.8 un unico permesso copriva tutto: per non cambiare il comportamento dei forum
 * esistenti, i gruppi che hanno già ".restrict" ricevono anche i due nuovi permessi. L'amministratore
 * può poi toglierli da Amministrazione → Permessi.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $hasCreatedAt = $schema->hasColumn('group_permission', 'created_at');

        $groupIds = $db->table('group_permission')
            ->where('permission', 'peopleinside-restricted-visibility.restrict')
            ->pluck('group_id');

        foreach (['createPrivate', 'makePublic'] as $new) {
            $permission = 'peopleinside-restricted-visibility.'.$new;
            foreach ($groupIds as $groupId) {
                $exists = $db->table('group_permission')
                    ->where('group_id', $groupId)->where('permission', $permission)->exists();
                if ($exists) {
                    continue;
                }
                $row = ['group_id' => $groupId, 'permission' => $permission];
                if ($hasCreatedAt) {
                    $row['created_at'] = date('Y-m-d H:i:s');
                }
                $db->table('group_permission')->insert($row);
            }
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('group_permission')
            ->whereIn('permission', [
                'peopleinside-restricted-visibility.createPrivate',
                'peopleinside-restricted-visibility.makePublic',
            ])->delete();
    },
];
