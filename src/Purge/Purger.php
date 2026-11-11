<?php

namespace PeopleInside\RestrictedVisibility\Purge;

use PeopleInside\RestrictedVisibility\Constants;
use PeopleInside\RestrictedVisibility\Db;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Elimina i dati dell'estensione.
 *
 * Non c'è una transazione unica su tutto: su forum grandi bloccherebbe righe di discussioni e
 * post per tutta la durata. Si procede invece in questo ordine, ognuno con transazioni brevi:
 *
 *  1. contenuti riservati (hide/delete): UNA transazione per ogni blocco di 100 modelli;
 *  2. collegamenti di restrizione, permessi e impostazioni: una transazione finale, piccola.
 *
 * L'ordine è la garanzia di sicurezza: i collegamenti vengono rimossi solo dopo aver trattato i
 * contenuti. Se l'operazione si interrompe a metà, i contenuti non ancora trattati sono ancora
 * riservati (non diventano pubblici) e basta rilanciare il comando: riprende da dove si era fermato.
 *
 * Le tabelle restano (vuote) fino a "Elimina dati" nativo di Flarum.
 */
class Purger
{
    private const CHUNK = 100;

    /** @return array<string, int> */
    public function preview(): array
    {
        $db = Db::conn();

        return [
            'discussions' => $this->restrictedDiscussions()->count(),
            'posts' => $this->restrictedPosts()->count(),
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

        // 1) Contenuti, a blocchi: ogni blocco è una transazione a sé.
        if ($mode === 'hide') {
            $this->hide($actor, $report);
        } elseif ($mode === 'delete') {
            $this->delete($report);
        }

        // 2) Pulizia finale: solo righe di collegamento, permessi e impostazioni.
        Db::conn()->transaction(function () {
            $db = Db::conn();
            foreach (Db::TABLES as $t) {
                $db->table($t['groups'])->delete();
                $db->table($t['users'])->delete();
                $db->table($t['marker'])->delete();
            }

            $db->table('group_permission')
                ->whereIn('permission', [Constants::PERM_CREATE_PRIVATE, Constants::PERM_MAKE_PRIVATE, Constants::PERM_MAKE_PUBLIC, Constants::PERM_VIEW_ALL])
                ->delete();
            $db->table('settings')->where('key', 'like', Constants::ID.'.%')->delete();
        });

        return $report;
    }

    /**
     * Discussioni con almeno una restrizione. Sottoquery sulle tabelle di collegamento: niente
     * elenchi di ID in memoria né migliaia di parametri in una sola query.
     */
    private function restrictedDiscussions()
    {
        return Discussion::query()->where(function ($w) {
            $w->whereExists(function ($s) {
                $s->selectRaw('1')->from('restricted_discussions')->whereColumn('restricted_discussions.discussion_id', 'discussions.id');
            })->orWhereExists(function ($s) {
                $s->selectRaw('1')->from('restricted_discussion_groups')->whereColumn('restricted_discussion_groups.discussion_id', 'discussions.id');
            })->orWhereExists(function ($s) {
                $s->selectRaw('1')->from('restricted_discussion_users')->whereColumn('restricted_discussion_users.discussion_id', 'discussions.id');
            });
        });
    }

    private function restrictedPosts()
    {
        return Post::query()->where(function ($w) {
            $w->whereExists(function ($s) {
                $s->selectRaw('1')->from('restricted_posts')->whereColumn('restricted_posts.post_id', 'posts.id');
            })->orWhereExists(function ($s) {
                $s->selectRaw('1')->from('restricted_post_groups')->whereColumn('restricted_post_groups.post_id', 'posts.id');
            })->orWhereExists(function ($s) {
                $s->selectRaw('1')->from('restricted_post_users')->whereColumn('restricted_post_users.post_id', 'posts.id');
            });
        });
    }

    private function hide(?User $actor, array &$report): void
    {
        $this->restrictedDiscussions()->whereNull('hidden_at')
            ->chunkById(self::CHUNK, function ($chunk) use ($actor, &$report) {
                Db::conn()->transaction(function () use ($chunk, $actor, &$report) {
                    foreach ($chunk as $discussion) {
                        $discussion->hide($actor);
                        $discussion->save();
                        $report['affectedDiscussions']++;
                    }
                });
            });

        $this->restrictedPosts()->whereNull('hidden_at')
            ->chunkById(self::CHUNK, function ($chunk) use ($actor, &$report) {
                Db::conn()->transaction(function () use ($chunk, $actor, &$report) {
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
            });
    }

    private function delete(array &$report): void
    {
        // Via i modelli (non SQL grezzo) così Flarum aggiorna contatori e ultimo post.
        $this->restrictedDiscussions()
            ->chunkById(self::CHUNK, function ($chunk) use (&$report) {
                Db::conn()->transaction(function () use ($chunk, &$report) {
                    foreach ($chunk as $discussion) {
                        $discussion->delete();
                        $report['affectedDiscussions']++;
                    }
                });
            });

        $this->restrictedPosts()
            ->chunkById(self::CHUNK, function ($chunk) use (&$report) {
                Db::conn()->transaction(function () use ($chunk, &$report) {
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
            });
    }
}
