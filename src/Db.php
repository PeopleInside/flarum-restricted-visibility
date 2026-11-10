<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;

final class Db
{
    public const TABLES = [
        'discussion' => [
            'groups' => 'restricted_discussion_groups',
            'users' => 'restricted_discussion_users',
            'fk' => 'discussion_id',
        ],
        'post' => [
            'groups' => 'restricted_post_groups',
            'users' => 'restricted_post_users',
            'fk' => 'post_id',
        ],
    ];

    /** Usa la connessione di Flarum: il prefisso delle tabelle è gestito automaticamente. */
    public static function conn(): ConnectionInterface
    {
        return (new Post())->getConnection();
    }
}
