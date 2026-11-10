<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Unico punto di accesso ai dati di restrizione. Tutte le query usano il query builder
 * (parametri legati, nessuna concatenazione di input) e valori castati a int.
 */
final class Repository
{
    /** @var array<int, array{groups: int[], users: int[]}> */
    private static array $discussionRows = [];

    /** @var array<int, array<int, array{groups: int[], users: int[]}>> discussion_id => post_id => righe */
    private static array $postRows = [];

    /** @var array<int, array<int, int>> actor_id => discussion_id => conteggio */
    private static array $counts = [];

    private const EMPTY = ['groups' => [], 'users' => []];

    // ---------------------------------------------------------------- lettura

    /** @return array{groups: int[], users: int[]} righe effettive (DB + eventuali valori in sospeso). */
    public static function rows(AbstractModel $model): array
    {
        $rows = self::EMPTY;
        $id = (int) $model->getKey();

        if ($id > 0) {
            if ($model instanceof Post) {
                $did = (int) $model->discussion_id;
                if ($did > 0) {
                    self::loadPostsOfDiscussion($did);
                    $rows = self::$postRows[$did][$id] ?? self::EMPTY;
                }
            } else {
                self::loadDiscussions([$id]);
                $rows = self::$discussionRows[$id] ?? self::EMPTY;
            }
        }

        $pending = Pending::get($model);
        if ($pending !== null) {
            $rows['groups'] = $pending['groups'] ?? $rows['groups'];
            $rows['users'] = $pending['users'] ?? $rows['users'];
        }

        return $rows;
    }

    /**
     * Regole da rispettare per vedere il modello: la sua e, per un post, quella della discussione.
     *
     * @return Rule[]
     */
    public static function rulesFor(AbstractModel $model): array
    {
        $rows = self::rows($model);
        $rules = [new Rule($rows['groups'], $rows['users'], ((int) $model->user_id) ?: null)];

        if ($model instanceof Post && $model->discussion) {
            $d = self::rows($model->discussion);
            $rules[] = new Rule($d['groups'], $d['users'], ((int) $model->discussion->user_id) ?: null);
        }

        return $rules;
    }

    public static function isRestricted(AbstractModel $model): bool
    {
        $rows = self::rows($model);

        return $rows['groups'] !== [] || $rows['users'] !== [];
    }

    /** @return array<int, array{id: int, name: string}> solo utenti visibili a $actor. */
    public static function userInfo(array $ids, User $actor): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereVisibleTo($actor)->whereIn('id', $ids)->get()
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) $u->display_name])
            ->values()->all();
    }

    private static function loadPostsOfDiscussion(int $did): void
    {
        if (isset(self::$postRows[$did])) {
            return;
        }
        $db = Db::conn();
        $map = [];

        $groups = $db->table('restricted_post_groups')
            ->join('posts', 'posts.id', '=', 'restricted_post_groups.post_id')
            ->where('posts.discussion_id', $did)
            ->get(['restricted_post_groups.post_id as pid', 'restricted_post_groups.group_id as gid']);
        foreach ($groups as $r) {
            $map[(int) $r->pid]['groups'][] = (int) $r->gid;
        }

        $users = $db->table('restricted_post_users')
            ->join('posts', 'posts.id', '=', 'restricted_post_users.post_id')
            ->where('posts.discussion_id', $did)
            ->get(['restricted_post_users.post_id as pid', 'restricted_post_users.user_id as uid']);
        foreach ($users as $r) {
            $map[(int) $r->pid]['users'][] = (int) $r->uid;
        }

        foreach ($map as $pid => $row) {
            $map[$pid] = ['groups' => $row['groups'] ?? [], 'users' => $row['users'] ?? []];
        }
        self::$postRows[$did] = $map;
    }

    /** @param int[] $ids */
    private static function loadDiscussions(array $ids): void
    {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys(self::$discussionRows)));
        if ($ids === []) {
            return;
        }
        $db = Db::conn();
        foreach ($ids as $id) {
            self::$discussionRows[$id] = self::EMPTY;
        }
        foreach ($db->table('restricted_discussion_groups')->whereIn('discussion_id', $ids)->get(['discussion_id', 'group_id']) as $r) {
            self::$discussionRows[(int) $r->discussion_id]['groups'][] = (int) $r->group_id;
        }
        foreach ($db->table('restricted_discussion_users')->whereIn('discussion_id', $ids)->get(['discussion_id', 'user_id']) as $r) {
            self::$discussionRows[(int) $r->discussion_id]['users'][] = (int) $r->user_id;
        }
    }

    // ------------------------------------------------- conteggio per il badge

    public static function postCount(Discussion $discussion, User $actor): int
    {
        $id = (int) $discussion->id;
        if ($id <= 0) {
            return 0;
        }
        if (! isset(self::$counts[(int) $actor->id][$id])) {
            self::preloadPostCounts([$id], $actor);
        }

        return self::$counts[(int) $actor->id][$id] ?? 0;
    }

    /**
     * Conta i post riservati che $actor può vedere (stesso scope dei post: per chi non è
     * autorizzato il risultato è sempre 0, quindi il badge non rivela nulla).
     *
     * @param int[] $ids
     */
    public static function preloadPostCounts(array $ids, User $actor): void
    {
        $key = (int) $actor->id;
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys(self::$counts[$key] ?? [])));
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            self::$counts[$key][$id] = 0;
        }

        $rows = Post::query()
            ->whereVisibleTo($actor)
            ->whereIn('posts.discussion_id', $ids)
            ->where(function ($w) {
                $w->whereExists(function ($s) {
                    $s->selectRaw('1')->from('restricted_post_groups')->whereColumn('restricted_post_groups.post_id', 'posts.id');
                })->orWhereExists(function ($s) {
                    $s->selectRaw('1')->from('restricted_post_users')->whereColumn('restricted_post_users.post_id', 'posts.id');
                });
            })
            ->groupBy('posts.discussion_id')
            // select() (non selectRaw): così il prefisso delle tabelle viene applicato alla colonna.
            ->select('posts.discussion_id as did')
            ->selectRaw('COUNT(*) as c')
            ->get();

        foreach ($rows as $r) {
            self::$counts[$key][(int) $r->did] = (int) $r->c;
        }
    }

    // ------------------------------------------------------------ validazione

    /**
     * @return int[]
     * @throws ValidationException
     */
    public static function sanitizeGroups(User $actor, mixed $value): array
    {
        $ids = self::ints($value, Constants::MAX_GROUPS, 'restrictedGroupIds');
        if ($ids === []) {
            return [];
        }
        $found = Group::query()->whereVisibleTo($actor)
            ->where('id', '!=', Group::GUEST_ID)
            ->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();

        if (count($found) !== count($ids)) {
            throw new ValidationException(['restrictedGroupIds' => 'Uno o più gruppi non sono validi.']);
        }

        return $ids;
    }

    /**
     * @return int[]
     * @throws ValidationException
     */
    public static function sanitizeUsers(User $actor, mixed $value): array
    {
        $ids = self::ints($value, Constants::MAX_USERS, 'restrictedUserIds');
        if ($ids === []) {
            return [];
        }
        $found = User::query()->whereVisibleTo($actor)->whereIn('id', $ids)->pluck('id')->all();

        if (count($found) !== count($ids)) {
            throw new ValidationException(['restrictedUserIds' => 'Uno o più utenti non sono validi.']);
        }

        return $ids;
    }

    /** @return int[] */
    private static function ints(mixed $value, int $max, string $field): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > $max) {
            throw new ValidationException([$field => 'Valore non valido.']);
        }
        $out = [];
        foreach ($value as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $i = (int) $v;
                if ($i > 0) {
                    $out[$i] = $i;
                    continue;
                }
            }
            throw new ValidationException([$field => 'Valore non valido.']);
        }

        return array_values($out);
    }

    // ------------------------------------------------------------- scrittura

    /** Sostituisce l'elenco di gruppi o utenti del modello (già salvato) in una transazione. */
    public static function replace(AbstractModel $model, string $part, array $ids): void
    {
        $id = (int) $model->getKey();
        if ($id <= 0 || ! in_array($part, ['groups', 'users'], true)) {
            throw new \LogicException('Il modello deve essere salvato prima di impostare la visibilità.');
        }
        $t = Db::TABLES[$model instanceof Post ? 'post' : 'discussion'];
        $table = $t[$part];
        $fk = $t['fk'];
        $col = $part === 'groups' ? 'group_id' : 'user_id';

        Db::conn()->transaction(function () use ($table, $fk, $col, $id, $ids) {
            $db = Db::conn();
            $db->table($table)->where($fk, $id)->delete();
            if ($ids !== []) {
                $db->table($table)->insert(array_map(fn ($x) => [$fk => $id, $col => (int) $x], $ids));
            }
        });

        self::forget($model);
    }

    private static function forget(AbstractModel $model): void
    {
        if ($model instanceof Post) {
            unset(self::$postRows[(int) $model->discussion_id]);
        } else {
            unset(self::$discussionRows[(int) $model->getKey()]);
        }
        self::$counts = [];
    }
}
