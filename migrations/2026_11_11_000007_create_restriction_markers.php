<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Marcatore di restrizione, indipendente dai destinatari.
 *
 * Prima un contenuto era "riservato" solo se aveva almeno una riga in restricted_*_groups/users.
 * Quelle righe spariscono con ON DELETE CASCADE quando si cancella il gruppo o l'utente: se era
 * l'ultimo destinatario, il contenuto tornava pubblico. Ora il marcatore resta: il contenuto
 * rimane riservato (autore, chi può vedere tutto e amministratori) finché qualcuno con il
 * permesso giusto non lo rende pubblico.
 */
$discussions = Migration::createTable('restricted_discussions', function (Blueprint $table) {
    $table->unsignedInteger('discussion_id')->primary();
    $table->foreign('discussion_id')->references('id')->on('discussions')->onDelete('cascade');
});

$posts = Migration::createTable('restricted_posts', function (Blueprint $table) {
    $table->unsignedInteger('post_id')->primary();
    $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
});

return [
    'up' => function (Builder $schema) use ($discussions, $posts) {
        $discussions['up']($schema);
        $posts['up']($schema);

        // Contenuti già riservati: un marcatore per ognuno.
        $db = $schema->getConnection();
        foreach ([
            ['restricted_discussions', 'discussion_id', 'restricted_discussion_groups', 'restricted_discussion_users'],
            ['restricted_posts', 'post_id', 'restricted_post_groups', 'restricted_post_users'],
        ] as [$marker, $fk, $groups, $users]) {
            $ids = $db->table($groups)->select($fk)->union($db->table($users)->select($fk));
            $db->table($marker)->insertUsing([$fk], $ids);
        }
    },
    'down' => function (Builder $schema) use ($discussions, $posts) {
        $posts['down']($schema);
        $discussions['down']($schema);
    },
];
