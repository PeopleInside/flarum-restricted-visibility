<?php

namespace PeopleInside\RestrictedVisibility\Api;

use PeopleInside\RestrictedVisibility\Purge\Purger;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PurgePreviewController implements RequestHandlerInterface
{
    public function __construct(protected Purger $purger)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['data' => $this->purger->preview()]);
    }
}
