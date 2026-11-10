<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Il "down" (drop della tabella) è generato da Migration::createTable.
return Migration::createTable('restricted_post_groups', function (Blueprint $table) {
    $table->unsignedInteger('post_id');
    $table->unsignedInteger('group_id');
    $table->primary(['post_id', 'group_id']);
    $table->index('group_id');
    $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
    $table->foreign('group_id')->references('id')->on('groups')->onDelete('cascade');
});
