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
     * Soggetto della notifica più ogni Post/Discussion pubblico del blueprint
     * (es. NewPostBlueprint ha come soggetto la discussione ma contiene il post).
     *
     * @return array<Post|Discussion>
     */
    private function models(BlueprintInterface $blueprint): array
    {
        $found = [];
        $subject = $blueprint->getSubject();
        if ($subject instanceof Post || $subject instanceof Discussion) {
            $found[spl_object_id($subject)] = $subject;
        }
        foreach (get_object_vars($blueprint) as $value) {
            if ($value instanceof Post || $value instanceof Discussion) {
                $found[spl_object_id($value)] = $value;
            }
        }

        return array_values($found);
    }
}
