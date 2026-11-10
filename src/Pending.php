<?php

namespace PeopleInside\RestrictedVisibility;

/**
 * Restrizioni validate ma non ancora scritte nel database, associate all'istanza del modello.
 * Servono a garantire che le notifiche generate durante lo stesso salvataggio vedano già
 * la restrizione, qualunque sia l'ordine in cui Flarum esegue i callback.
 */
final class Pending
{
    private static ?\WeakMap $map = null;

    /** @param int[] $ids */
    public static function put(object $model, string $part, array $ids): void
    {
        self::$map ??= new \WeakMap();
        $current = self::$map[$model] ?? ['groups' => null, 'users' => null];
        $current[$part] = $ids;
        self::$map[$model] = $current;
    }

    /** @return int[]|null */
    public static function part(object $model, string $part): ?array
    {
        return self::$map[$model][$part] ?? null;
    }

    /** @return array{groups: ?array, users: ?array}|null */
    public static function get(object $model): ?array
    {
        return self::$map[$model] ?? null;
    }
}
