<?php

namespace PeopleInside\RestrictedVisibility\Api;

use PeopleInside\RestrictedVisibility\Constants;
use PeopleInside\RestrictedVisibility\Purge\Purger;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PurgeController implements RequestHandlerInterface
{
    public function __construct(protected Purger $purger)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $confirmation = is_string($body['confirmation'] ?? null) ? $body['confirmation'] : '';
        if (! hash_equals(Constants::CONFIRM_WORD, $confirmation)) {
            throw new ValidationException(['confirmation' => 'Conferma mancante o errata.']);
        }

        $mode = $body['mode'] ?? null;
        if (! is_string($mode) || ! in_array($mode, Constants::MODES, true)) {
            throw new ValidationException(['mode' => 'Modalità non valida.']);
        }

        // Su forum grandi l'operazione può superare i tempi PHP predefiniti e l'admin può chiudere la
        // pagina: si continua comunque. Resta sicura da rilanciare (vedi Purger) e c'è il comando CLI.
        ignore_user_abort(true);
        @set_time_limit(0);

        return new JsonResponse(['data' => $this->purger->purge($mode, $actor)]);
    }
}
