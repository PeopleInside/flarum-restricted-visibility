<?php

namespace PeopleInside\RestrictedVisibility\Api;

use PeopleInside\RestrictedVisibility\Constants;
use PeopleInside\RestrictedVisibility\Jobs\PurgeJob;
use Illuminate\Contracts\Queue\Queue;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PurgeController implements RequestHandlerInterface
{
    public function __construct(protected Queue $queue)
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

        // La pulizia può richiedere molto tempo: va in coda e la richiesta termina subito.
        // Con il driver di coda "sync" il job gira comunque in linea; per veri vantaggi
        // serve un worker (php flarum queue:work). In alternativa c'è il comando CLI.
        $this->queue->push(new PurgeJob($mode, $actor->id));

        return new JsonResponse(['data' => ['mode' => $mode, 'status' => 'queued']], 202);
    }
}
