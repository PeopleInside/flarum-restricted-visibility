<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Il "down" (drop della tabella) è generato da Migration::createTable.
return Migration::createTable('restricted_discussion_users', function (Blueprint $table) {
    $table->unsignedInteger('discussion_id');
    $table->unsignedInteger('user_id');
    $table->primary(['discussion_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('discussion_id')->references('id')->on('discussions')->onDelete('cascade');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
});
