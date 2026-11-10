<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

// Il "down" (drop della tabella) è generato da Migration::createTable.
return Migration::createTable('restricted_post_users', function (Blueprint $table) {
    $table->unsignedInteger('post_id');
    $table->unsignedInteger('user_id');
    $table->primary(['post_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
});
