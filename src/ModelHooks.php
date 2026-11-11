<?php

namespace PeopleInside\RestrictedVisibility;

use Flarum\Database\AbstractModel;

/**
 * Scrive le restrizioni IN FASE DI SALVATAGGIO del modello, non dopo.
 *
 * Perché: appena il post è salvato, Flarum invia gli eventi di dominio (es. "nuova risposta") e
 * le estensioni delle iscrizioni accodano le notifiche; con la coda "sync" i job partono subito e
 * rileggono il post dal database. Se le righe di restrizione non ci sono ancora, la notifica
 * (e l'email con il testo) arriverebbe anche a chi non può vedere il post.
 *
 * Gli eventi Eloquent `created` e `saved` scattano dentro save(), prima di tutto questo.
 * Si registrano sulla classe esatta del modello (CommentPost, Discussion, ...) alla prima
 * scrittura, quando il dispatcher degli eventi è sicuramente pronto.
 */
final class ModelHooks
{
    /** @var array<class-string, true> */
    private static array $registered = [];

    public static function ensure(AbstractModel $model): void
    {
        $class = $model::class;
        if (isset(self::$registered[$class])) {
            return;
        }
        self::$registered[$class] = true;

        $flush = function ($saved) {
            if ($saved instanceof AbstractModel) {
                Repository::flush($saved);
            }
        };

        // Prima del salvataggio: se il passaggio pubblico/riservato non è permesso, si blocca tutto.
        $class::saving(function ($saving) {
            if ($saving instanceof AbstractModel) {
                Repository::assertTransitionAllowed($saving);
            }
        });

        $class::created($flush); // nuovi contenuti: prima di ogni evento di dominio
        $class::saved($flush);   // modifiche di contenuti esistenti
    }
}
