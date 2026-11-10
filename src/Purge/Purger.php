<?php

namespace PeopleInside\RestrictedVisibility\Purge;

use PeopleInside\RestrictedVisibility\Constants;
use PeopleInside\RestrictedVisibility\Db;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Elimina i dati dell'estensione. Tutto avviene in UNA transazione: se qualcosa fallisce
 * non cambia nulla. Le tabelle restano (vuote) fino a "Elimina dati" nativo di Flarum.
 */
class Purger
{
    /** @return array<string, int> */
    public function preview(): array
    {
        $db = Db::conn();

        return [
            'discussions' => count($this->discussionIds()),
            'posts' => count($this->postIds()),
            'groupLinks' => $db->table('restricted_discussion_groups')->count() + $db->table('restricted_post_groups')->count(),
            'userLinks' => $db->table('restricted_discussion_users')->count() + $db->table('restricted_post_users')->count(),
        ];
    }

    /**
     * @param 'hide'|'delete'|'public' $mode
     * @return array<string, int|string>
     */
    public function purge(string $mode, ?User $actor): array
    {
        if (! in_array($mode, Constants::MODES, true)) {
            throw new \InvalidArgumentException('Modalità non valida.');
        }

        $report = ['mode' => $mode] + $this->preview() + ['affectedDiscussions' => 0, 'affectedPosts' => 0];

        Db::conn()->transaction(function () use ($mode, $actor, &$report) {
            $discussionIds = $this->discussionIds();
            $postIds = $this->postIds();

            if ($mode === 'hide') {
                $this->hide($discussionIds, $postIds, $actor, $report);
            } elseif ($mode === 'delete') {
                $this->delete($discussionIds, $postIds, $report);
            }

            $db = Db::conn();
            foreach (Db::TABLES as $t) {
                $db->table($t['groups'])->delete();
                $db->table($t['users'])->delete();
            }

            $db->table('group_permission')
                ->whereIn('permission', [Constants::PERM_RESTRICT, Constants::PERM_VIEW_ALL])
                ->delete();
            $db->table('settings')->where('key', 'like', Constants::ID.'.%')->delete();
        });

        return $report;
    }

    /** @return int[] */
    private function discussionIds(): array
    {
        $db = Db::conn();

        return array_values(array_unique(array_map('intval', array_merge(
            $db->table('restricted_discussion_groups')->pluck('discussion_id')->all(),
            $db->table('restricted_discussion_users')->pluck('discussion_id')->all()
        ))));
    }

    /** @return int[] */
    private function postIds(): array
    {
        $db = Db::conn();

        return array_values(array_unique(array_map('intval', array_merge(
            $db->table('restricted_post_groups')->pluck('post_id')->all(),
            $db->table('restricted_post_users')->pluck('post_id')->all()
        ))));
    }

    private function hide(array $discussionIds, array $postIds, ?User $actor, array &$report): void
    {
        if ($discussionIds !== []) {
            Discussion::query()->whereIn('id', $discussionIds)->whereNull('hidden_at')
                ->chunkById(100, function ($chunk) use ($actor, &$report) {
                    foreach ($chunk as $discussion) {
                        $discussion->hide($actor);
                        $discussion->save();
                        $report['affectedDiscussions']++;
                    }
                });
        }

        if ($postIds !== []) {
            Post::query()->whereIn('id', $postIds)->whereNull('hidden_at')
                ->chunkById(100, function ($chunk) use ($actor, &$report) {
                    foreach ($chunk as $post) {
                        $discussion = $post->discussion;
                        // Il primo post non si nasconde da solo: si nasconde la discussione.
                        if ($discussion && (int) $discussion->first_post_id === (int) $post->id) {
                            if (! $discussion->hidden_at) {
                                $discussion->hide($actor);
                                $discussion->save();
                                $report['affectedDiscussions']++;
                            }
                            continue;
                        }
                        $post->hide($actor);
                        $post->save();
                        $report['affectedPosts']++;
                    }
                });
        }
    }

    private function delete(array $discussionIds, array $postIds, array &$report): void
    {
        // Via i modelli (non SQL grezzo) così Flarum aggiorna contatori e ultimo post.
        if ($discussionIds !== []) {
            Discussion::query()->whereIn('id', $discussionIds)
                ->chunkById(100, function ($chunk) use (&$report) {
                    foreach ($chunk as $discussion) {
                        $discussion->delete();
                        $report['affectedDiscussions']++;
                    }
                });
        }

        if ($postIds !== []) {
            Post::query()->whereIn('id', $postIds)
                ->chunkById(100, function ($chunk) use (&$report) {
                    foreach ($chunk as $post) {
                        $discussion = $post->discussion;
                        if ($discussion && (int) $discussion->first_post_id === (int) $post->id) {
                            $discussion->delete();
                            $report['affectedDiscussions']++;
                            continue;
                        }
                        $post->delete();
                        $report['affectedPosts']++;
                    }
                });
        }
    }
}
