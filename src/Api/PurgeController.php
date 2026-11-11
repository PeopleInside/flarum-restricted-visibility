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
use Symfony\Contracts\Translation\TranslatorInterface;

class PurgeController implements RequestHandlerInterface
{
    public function __construct(protected Queue $queue, protected TranslatorInterface $translator)
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
            throw new ValidationException(['confirmation' => $this->translator->trans(Constants::ID.'.api.invalid_confirmation')]);
        }

        $mode = $body['mode'] ?? null;
        if (! is_string($mode) || ! in_array($mode, Constants::MODES, true)) {
            throw new ValidationException(['mode' => $this->translator->trans(Constants::ID.'.api.invalid_mode')]);
        }

        // La pulizia può richiedere molto tempo: va in coda e la richiesta termina subito.
        // Con il driver di coda "sync" il job gira comunque in linea; per veri vantaggi
        // serve un worker (php flarum queue:work). In alternativa c'è il comando CLI.
        $this->queue->push(new PurgeJob($mode, $actor->id));

        return new JsonResponse(['data' => ['mode' => $mode, 'status' => 'queued']], 202);
    }
}
