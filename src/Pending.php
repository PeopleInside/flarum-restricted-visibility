<?php

namespace PeopleInside\RestrictedVisibility;

/**
 * Restrizioni validate ma non ancora scritte nel database, associate all'istanza del modello.
 *
 * Servono in due modi: (1) i valori vengono scritti durante il save() del modello dagli eventi
 * Eloquent (vedi ModelHooks), prima che Flarum o altre estensioni inviino notifiche; (2) se la
 * notifica viene creata con la stessa istanza, il filtro vede già la restrizione.
 */
final class Pending
{
    private static ?\WeakMap $map = null;

    /** @param int[] $ids */
    public static function put(object $model, string $part, array $ids): void
    {
        self::$map ??= new \WeakMap();
        $current = self::$map[$model] ?? ['groups' => null, 'users' => null, 'done' => ['groups' => false, 'users' => false]];
        $current[$part] = $ids;
        $current['done'][$part] = false; // un nuovo valore va riscritto
        self::$map[$model] = $current;
    }

    /** @return int[]|null */
    public static function part(object $model, string $part): ?array
    {
        return self::$map[$model][$part] ?? null;
    }

    /** @return array{groups: ?array, users: ?array, done: array{groups: bool, users: bool}}|null */
    public static function get(object $model): ?array
    {
        return self::$map[$model] ?? null;
    }

    public static function markDone(object $model, string $part): void
    {
        if (isset(self::$map[$model])) {
            $current = self::$map[$model];
            $current['done'][$part] = true;
            self::$map[$model] = $current;
        }
    }
}
