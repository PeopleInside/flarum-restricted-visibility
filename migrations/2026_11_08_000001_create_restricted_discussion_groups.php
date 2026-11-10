<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Il "down" (drop della tabella) è generato da Migration::createTable.
return Migration::createTable('restricted_discussion_groups', function (Blueprint $table) {
    $table->unsignedInteger('discussion_id');
    $table->unsignedInteger('group_id');
    $table->primary(['discussion_id', 'group_id']);
    $table->index('group_id');
    $table->foreign('discussion_id')->references('id')->on('discussions')->onDelete('cascade');
    $table->foreign('group_id')->references('id')->on('groups')->onDelete('cascade');
});
