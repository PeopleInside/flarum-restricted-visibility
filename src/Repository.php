<?php

namespace PeopleInside\RestrictedVisibility;

use Closure;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Unico punto di accesso ai dati di restrizione. Tutte le query usano il query builder
 * (parametri legati, nessuna concatenazione di input) e valori castati a int.
 */
final class Repository
{
    /** @var array<int, array{groups: int[], users: int[], restricted: bool}> */
    private static array $discussionRows = [];

    /** @var array<int, array<int, array{groups: int[], users: int[], restricted: bool}>> discussion_id => post_id => righe */
    private static array $postRows = [];

    /** @var array<int, array<int, int>> actor_id => discussion_id => conteggio */
    private static array $counts = [];

    /** @var array<string, array{id: int, name: string, avatarUrl: ?string}|null> "actorId:userId" => info (null = non visibile) */
    private static array $userCache = [];

    /**
     * Code di caricamento differito. I getter dei campi API non caricano subito: si "prenotano" e
     * restituiscono una Closure, che il serializzatore di Flarum esegue SOLO dopo aver aggiunto tutti
     * i modelli del documento (principali e inclusi). Alla prima Closure eseguita si carica in blocco
     * tutto ciò che è in coda: una query per tipo di dato, qualunque endpoint serializzi le discussioni
     * (ricerca, relazioni incluse, notifiche, ...), non solo Index/Show.
     *
     * @var array<int, true> discussion_id => true
     */
    private static array $queuedDiscussions = [];

    /** @var array<int, true> discussion_id (dei post) => true */
    private static array $queuedPostDiscussions = [];

    /** @var array<int, array<int, true>> actor_id => discussion_id => true */
    private static array $queuedCounts = [];

    /** @var array<int, array<int, true>> actor_id => user_id => true */
    private static array $queuedUsers = [];

    private const EMPTY = ['groups' => [], 'users' => [], 'restricted' => false];

    // ------------------------------------------------------ caricamento differito

    /**
     * Da usare nei getter dei campi API. Se i dati del modello sono già in cache restituisce subito
     * $resolve(); altrimenti mette il modello in coda e restituisce una Closure che, quando il
     * serializzatore la esegue, carica in blocco TUTTI i modelli in coda e poi chiama $resolve().
     * Anche fuori dal serializzatore il risultato resta corretto (la coda ha al massimo un elemento).
     */
    public static function defer(AbstractModel $model, Closure $resolve): mixed
    {
        if (! self::enqueueRows($model)) {
            return $resolve();
        }

        return function () use ($resolve) {
            self::flushRowQueues();

            return $resolve();
        };
    }

    /**
     * Come defer(), per l'elenco dei destinatari con nome e avatar ($actor vede solo gli utenti che può vedere).
     * Due livelli: prima si caricano le righe di restrizione, poi (al passaggio successivo del
     * serializzatore, quando tutti i modelli hanno già indicato gli utenti che servono) gli utenti.
     */
    public static function deferUserInfo(AbstractModel $model, User $actor): mixed
    {
        return self::defer($model, function () use ($model, $actor) {
            $ids = self::rows($model)['users'];
            if ($ids === []) {
                return [];
            }
            if (! self::enqueueUsers($ids, $actor)) {
                return self::userInfo($ids, $actor);
            }

            return fn () => self::userInfo($ids, $actor);
        });
    }

    /** Come defer(), per il conteggio dei post riservati visibili a $actor (badge). */
    public static function deferPostCount(Discussion $discussion, User $actor): mixed
    {
        $id = (int) $discussion->id;
        if ($id <= 0) {
            return 0;
        }
        $key = (int) $actor->id;
        if (isset(self::$counts[$key][$id])) {
            return self::$counts[$key][$id];
        }
        self::$queuedCounts[$key][$id] = true;

        return fn () => self::postCount($discussion, $actor);
    }

    /** @return bool true se il modello è stato messo in coda (dati non ancora in cache) */
    private static function enqueueRows(AbstractModel $model): bool
    {
        $id = (int) $model->getKey();
        if ($id <= 0) {
            return false;
        }

        if ($model instanceof Post) {
            $did = (int) $model->discussion_id;
            if ($did <= 0 || isset(self::$postRows[$did])) {
                return false;
            }
            self::$queuedPostDiscussions[$did] = true;

            return true;
        }

        if (isset(self::$discussionRows[$id])) {
            return false;
        }
        self::$queuedDiscussions[$id] = true;

        return true;
    }

    /** @param int[] $ids @return bool true se almeno un utente non è ancora in cache (e quindi è in coda) */
    private static function enqueueUsers(array $ids, User $actor): bool
    {
        $key = (int) $actor->id;
        $queued = false;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! array_key_exists($key.':'.$id, self::$userCache)) {
                self::$queuedUsers[$key][$id] = true;
                $queued = true;
            }
        }

        return $queued;
    }

    private static function flushRowQueues(): void
    {
        if (self::$queuedDiscussions !== []) {
            $ids = array_keys(self::$queuedDiscussions);
            self::$queuedDiscussions = [];
            self::loadDiscussions($ids);
        }
        if (self::$queuedPostDiscussions !== []) {
            $ids = array_keys(self::$queuedPostDiscussions);
            self::$queuedPostDiscussions = [];
            self::loadPostsOfDiscussions($ids);
        }
    }

    // ---------------------------------------------------------------- lettura

    /** @return array{groups: int[], users: int[], restricted: bool} righe effettive (DB + eventuali valori in sospeso). */
    public static function rows(AbstractModel $model): array
    {
        $rows = self::storedRows($model);

        $pending = Pending::get($model);
        if ($pending !== null) {
            $rows['groups'] = $pending['groups'] ?? $rows['groups'];
            $rows['users'] = $pending['users'] ?? $rows['users'];
            // Una modifica in corso decide lo stato finale: senza destinatari il contenuto diventa pubblico.
            $rows['restricted'] = $rows['groups'] !== [] || $rows['users'] !== [];
        }

        return $rows;
    }

    /** @return array{groups: int[], users: int[]} solo ciò che è scritto nel database (senza valori in sospeso). */
    public static function storedRows(AbstractModel $model): array
    {
        $id = (int) $model->getKey();
        if ($id <= 0) {
            return self::EMPTY;
        }

        if ($model instanceof Post) {
            $did = (int) $model->discussion_id;
            if ($did <= 0) {
                return self::EMPTY;
            }
            self::loadPostsOfDiscussions([$did]);

            return self::$postRows[$did][$id] ?? self::EMPTY;
        }

        self::loadDiscussions([$id]);

        return self::$discussionRows[$id] ?? self::EMPTY;
    }

    /**
     * Chiamato prima del salvataggio: confronta lo stato salvato con quello richiesto e verifica
     * che chi agisce abbia il permesso giusto (rendere privato / rendere pubblico).
     *
     * @throws PermissionDeniedException
     */
    public static function assertTransitionAllowed(AbstractModel $model): void
    {
        $pending = Pending::get($model);
        $actor = Pending::actor($model);
        if ($pending === null || $actor === null) {
            return; // nessuna modifica di visibilità richiesta
        }

        if (! Authz::mayTransition($actor, self::storedRows($model), self::rows($model), ! $model->exists)) {
            throw new PermissionDeniedException();
        }
    }

    /**
     * Regole da rispettare per vedere il modello: la sua e, per un post, quella della discussione.
     *
     * @return Rule[]
     */
    public static function rulesFor(AbstractModel $model): array
    {
        $rows = self::rows($model);
        $rules = [new Rule($rows['groups'], $rows['users'], ((int) $model->user_id) ?: null, $rows['restricted'])];

        if ($model instanceof Post && $model->discussion) {
            $d = self::rows($model->discussion);
            $rules[] = new Rule($d['groups'], $d['users'], ((int) $model->discussion->user_id) ?: null, $d['restricted']);
        }

        return $rules;
    }

    public static function isRestricted(AbstractModel $model): bool
    {
        return self::rows($model)['restricted'];
    }

    /** @return array<int, array{id: int, name: string, avatarUrl: ?string}> solo utenti visibili a $actor. */
    public static function userInfo(array $ids, User $actor): array
    {
        if ($ids === []) {
            return [];
        }
        // Di norma già caricati in blocco da preload(); altrimenti una query solo per gli ID mancanti.
        self::preloadUserInfo($ids, $actor);

        $out = [];
        foreach ($ids as $id) {
            $info = self::$userCache[(int) $actor->id.':'.(int) $id] ?? null;
            if ($info !== null) {
                $out[] = $info;
            }
        }

        return $out;
    }

    /** Risolve in UNA query tutti gli utenti non ancora in cache. @param int[] $ids */
    public static function preloadUserInfo(array $ids, User $actor): void
    {
        $key = (int) $actor->id;
        // Include gli utenti già prenotati da deferUserInfo(): una sola query per tutti.
        $ids = array_merge($ids, array_keys(self::$queuedUsers[$key] ?? []));
        unset(self::$queuedUsers[$key]);

        $missing = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0 && ! array_key_exists($key.':'.$id, self::$userCache)) {
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return;
        }

        foreach ($missing as $id) {
            self::$userCache[$key.':'.$id] = null; // non visibile, finché non si trova il contrario
        }
        foreach (User::query()->whereVisibleTo($actor)->whereIn('id', $missing)->get() as $u) {
            self::$userCache[$key.':'.(int) $u->id] = [
                'id' => (int) $u->id,
                'name' => (string) $u->display_name,
                // Letto dall'utente a ogni richiesta (nessuna copia salvata): segue i cambi di avatar.
                'avatarUrl' => $u->avatar_url ?: null,
            ];
        }
    }

    /**
     * Precarica in blocco ciò che serve a serializzare una pagina di discussioni e/o post:
     * righe di restrizione, conteggi del badge e nomi dei destinatari. Evita una query per modello.
     *
     * @param AbstractModel|iterable<mixed>|null $data
     */
    public static function preload(mixed $data, User $actor): void
    {
        $items = $data instanceof AbstractModel ? [$data] : (is_iterable($data) ? $data : []);

        $discussions = [];
        $posts = [];
        foreach ($items as $item) {
            if ($item instanceof Discussion) {
                $discussions[(int) $item->id] = $item;
                if ($item->relationLoaded('posts')) {
                    foreach ($item->posts as $p) {
                        $posts[(int) $p->id] = $p;
                    }
                }
            } elseif ($item instanceof Post) {
                $posts[(int) $item->id] = $item;
            }
        }

        if ($discussions !== []) {
            self::loadDiscussions(array_keys($discussions));
            self::preloadPostCounts(array_keys($discussions), $actor);
        }
        self::loadPostsOfDiscussions(array_map(fn (Post $p) => (int) $p->discussion_id, $posts));

        // Solo chi può ispezionare vede i destinatari: per gli altri non si carica nulla.
        $userIds = [];
        foreach ([...$discussions, ...$posts] as $model) {
            if (Authz::mayInspect($actor, $model)) {
                array_push($userIds, ...self::rows($model)['users']);
            }
        }
        self::preloadUserInfo($userIds, $actor);
    }

    /**
     * Carica in blocco le righe di restrizione dei post di più discussioni (3 query in totale,
     * non 3 per discussione).
     *
     * @param int[] $dids
     */
    private static function loadPostsOfDiscussions(array $dids): void
    {
        $dids = array_filter(
            array_unique(array_map('intval', $dids)),
            fn (int $did) => $did > 0 && ! isset(self::$postRows[$did])
        );
        if ($dids === []) {
            return;
        }
        $dids = array_values($dids);
        foreach ($dids as $did) {
            self::$postRows[$did] = [];
        }

        $db = Db::conn();
        /** @var array<int, array<int, array{groups?: int[], users?: int[]}>> $map discussion_id => post_id => righe */
        $map = [];

        $groups = $db->table('restricted_post_groups')
            ->join('posts', 'posts.id', '=', 'restricted_post_groups.post_id')
            ->whereIn('posts.discussion_id', $dids)
            ->get(['posts.discussion_id as did', 'restricted_post_groups.post_id as pid', 'restricted_post_groups.group_id as gid']);
        foreach ($groups as $r) {
            $map[(int) $r->did][(int) $r->pid]['groups'][] = (int) $r->gid;
        }

        $users = $db->table('restricted_post_users')
            ->join('posts', 'posts.id', '=', 'restricted_post_users.post_id')
            ->whereIn('posts.discussion_id', $dids)
            ->get(['posts.discussion_id as did', 'restricted_post_users.post_id as pid', 'restricted_post_users.user_id as uid']);
        foreach ($users as $r) {
            $map[(int) $r->did][(int) $r->pid]['users'][] = (int) $r->uid;
        }

        // Marcatore: il post è riservato anche se non ha più destinatari.
        $markers = $db->table('restricted_posts')
            ->join('posts', 'posts.id', '=', 'restricted_posts.post_id')
            ->whereIn('posts.discussion_id', $dids)
            ->get(['posts.discussion_id as did', 'restricted_posts.post_id as pid']);
        foreach ($markers as $r) {
            $map[(int) $r->did][(int) $r->pid] ??= [];
        }

        foreach ($map as $did => $posts) {
            foreach ($posts as $pid => $row) {
                self::$postRows[$did][$pid] = ['groups' => $row['groups'] ?? [], 'users' => $row['users'] ?? [], 'restricted' => true];
            }
        }
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
        foreach ($db->table('restricted_discussions')->whereIn('discussion_id', $ids)->pluck('discussion_id') as $did) {
            self::$discussionRows[(int) $did]['restricted'] = true;
        }
        foreach ($db->table('restricted_discussion_groups')->whereIn('discussion_id', $ids)->get(['discussion_id', 'group_id']) as $r) {
            self::$discussionRows[(int) $r->discussion_id]['groups'][] = (int) $r->group_id;
            self::$discussionRows[(int) $r->discussion_id]['restricted'] = true;
        }
        foreach ($db->table('restricted_discussion_users')->whereIn('discussion_id', $ids)->get(['discussion_id', 'user_id']) as $r) {
            self::$discussionRows[(int) $r->discussion_id]['users'][] = (int) $r->user_id;
            self::$discussionRows[(int) $r->discussion_id]['restricted'] = true;
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
            // Insieme alla discussione richiesta si caricano tutte quelle prenotate da deferPostCount().
            self::preloadPostCounts([$id, ...array_keys(self::$queuedCounts[(int) $actor->id] ?? [])], $actor);
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
            unset(self::$queuedCounts[$key][$id]);
        }

        $rows = Post::query()
            ->whereVisibleTo($actor)
            ->whereIn('posts.discussion_id', $ids)
            ->where(function ($w) {
                $w->whereExists(function ($s) {
                    $s->selectRaw('1')->from('restricted_post_groups')->whereColumn('restricted_post_groups.post_id', 'posts.id');
                })->orWhereExists(function ($s) {
                    $s->selectRaw('1')->from('restricted_post_users')->whereColumn('restricted_post_users.post_id', 'posts.id');
                })->orWhereExists(function ($s) {
                    $s->selectRaw('1')->from('restricted_posts')->whereColumn('restricted_posts.post_id', 'posts.id');
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
            throw new ValidationException(['restrictedGroupIds' => self::t('invalid_groups')]);
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
            throw new ValidationException(['restrictedUserIds' => self::t('invalid_users')]);
        }

        return $ids;
    }

    /** Messaggio di errore tradotto nella lingua del forum. */
    private static function t(string $key): string
    {
        return \Illuminate\Container\Container::getInstance()->make('translator')
            ->trans(Constants::ID.'.api.'.$key);
    }

    /** @return int[] */
    private static function ints(mixed $value, int $max, string $field): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || count($value) > $max) {
            throw new ValidationException([$field => self::t('invalid_value')]);
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
            throw new ValidationException([$field => self::t('invalid_value')]);
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

    /** Scrive su database i valori in sospeso del modello (chiamato dagli eventi di salvataggio). */
    public static function flush(AbstractModel $model): void
    {
        $pending = Pending::get($model);
        if ($pending === null || (int) $model->getKey() <= 0) {
            return;
        }
        $changed = false;
        foreach (['groups', 'users'] as $part) {
            if ($pending[$part] !== null && ! $pending['done'][$part]) {
                self::replace($model, $part, $pending[$part]);
                Pending::markDone($model, $part);
                $changed = true;
            }
        }
        if ($changed) {
            self::syncMarker($model);
        }
    }

    /**
     * Allinea il marcatore allo stato finale: presente se il contenuto è riservato, assente se è
     * pubblico. Il marcatore NON dipende dai destinatari, quindi sopravvive alla cancellazione di
     * un gruppo o di un utente (le righe dei destinatari spariscono con ON DELETE CASCADE).
     */
    private static function syncMarker(AbstractModel $model): void
    {
        $id = (int) $model->getKey();
        $t = Db::TABLES[$model instanceof Post ? 'post' : 'discussion'];
        $final = self::rows($model);
        $db = Db::conn();

        if ($final['groups'] !== [] || $final['users'] !== []) {
            if (! $db->table($t['marker'])->where($t['fk'], $id)->exists()) {
                $db->table($t['marker'])->insert([$t['fk'] => $id]);
            }
        } else {
            $db->table($t['marker'])->where($t['fk'], $id)->delete();
        }

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
