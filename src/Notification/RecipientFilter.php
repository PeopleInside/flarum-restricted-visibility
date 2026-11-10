<?php

namespace PeopleInside\RestrictedVisibility\Notification;

use PeopleInside\RestrictedVisibility\Repository;
use Flarum\Discussion\Discussion;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Rimuove dai destinatari chi non può vedere il contenuto. Poiché avviene prima dei driver,
 * vale sia per la campanella sia per le email (anche per gli iscritti alla discussione).
 */
class RecipientFilter
{
    /**
     * @param User[] $recipients
     * @return User[]
     */
    public function __invoke(BlueprintInterface $blueprint, array $recipients): array
    {
        $rules = [];
        foreach ($this->models($blueprint) as $model) {
            foreach (Repository::rulesFor($model) as $rule) {
                if ($rule->isRestricted()) {
                    $rules[] = $rule;
                }
            }
        }

        if ($rules === []) {
            return $recipients;
        }

        return array_values(array_filter($recipients, function (User $user) use ($rules) {
            foreach ($rules as $rule) {
                if (! $rule->allows($user)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Soggetto della notifica più OGNI Post/Discussion contenuto nel blueprint, qualunque sia la
     * visibilità della proprietà (public, protected, private) e anche se dichiarata in una classe
     * padre. Serve perché, ad esempio, il blueprint di una nuova risposta ha come soggetto la
     * discussione (pubblica) ma contiene il post (riservato) di cui viene inviato il testo.
     *
     * @return array<Post|Discussion>
     */
    private function models(BlueprintInterface $blueprint): array
    {
        $found = [];
        $add = function ($value) use (&$found) {
            if ($value instanceof Post || $value instanceof Discussion) {
                $found[spl_object_id($value)] = $value;
            }
        };

        $add($blueprint->getSubject());

        for ($class = new \ReflectionClass($blueprint); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($blueprint)) {
                    continue;
                }
                $property->setAccessible(true);
                $add($property->getValue($blueprint));
            }
        }

        return array_values($found);
    }
}
