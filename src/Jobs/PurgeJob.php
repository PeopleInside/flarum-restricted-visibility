<?php

namespace PeopleInside\RestrictedVisibility\Jobs;

use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use PeopleInside\RestrictedVisibility\Purge\Purger;

/**
 * Esegue la pulizia in coda, fuori dalla richiesta HTTP.
 * Resta sicura da rilanciare: vedi Purger.
 */
class PurgeJob extends AbstractJob
{
    /** Un solo tentativo: la pulizia è idempotente, ma un retry automatico non è desiderabile. */
    public int $tries = 1;

    /** Passiamo l'ID e non il modello, per non serializzare un User in coda. */
    public function __construct(private string $mode, private ?int $actorId)
    {
    }

    public function handle(Purger $purger): void
    {
        $actor = $this->actorId ? User::find($this->actorId) : null;

        $purger->purge($this->mode, $actor);
    }
}
