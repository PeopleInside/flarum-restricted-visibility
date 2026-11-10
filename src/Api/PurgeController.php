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

        return new JsonResponse(['data' => $this->purger->purge($mode, $actor)]);
    }
}
