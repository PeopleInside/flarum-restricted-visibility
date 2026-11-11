<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\User\User;

/** Regola di visibilità di un singolo modello. Nessun gruppo e nessun utente = pubblico. */
final class Rule
{
    /**
     * @param int[] $groups
     * @param int[] $users
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $users,
        public readonly ?int $authorId,
        public readonly bool $restricted = false
    ) {
    }

    public function isRestricted(): bool
    {
        // Il marcatore conta anche senza destinatari (es. gruppo o utente cancellati): resta riservato.
        return $this->restricted || $this->groups !== [] || $this->users !== [];
    }

    public function allows(User $user): bool
    {
        if (! $this->isRestricted()) {
            return true;
        }
        if ($user->isAdmin() || $user->hasPermission(Constants::PERM_VIEW_ALL)) {
            return true;
        }

        $id = (int) $user->id;
        if ($id > 0 && ($this->authorId === $id || in_array($id, $this->users, true))) {
            return true;
        }

        return array_intersect($this->groups, GroupIds::for($user)) !== [];
    }
}
