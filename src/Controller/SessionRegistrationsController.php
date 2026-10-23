<?php

namespace App\Controller;

use App\Entity\Session;
use App\Roster\SessionRoster;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class SessionRegistrationsController
{
    public function __construct(
        private readonly SessionRoster $roster,
    ) {
    }

    /**
     * The id goes to Postgres as is: one it cannot compare (NUL byte, longer than any stored id) is a 404 here
     * rather than a wrong match or a database error (ADR 0014).
     */
    #[Route('/api/sessions/{id}/inscriptions', requirements: ['id' => '[^/\x00]{1,'.Session::EXTERNAL_ID_LENGTH.'}'], methods: ['GET'], utf8: true)]
    public function __invoke(string $id): JsonResponse
    {
        $roster = $this->roster->forSession($id) ?? throw new NotFoundHttpException(\sprintf('Unknown session "%s".', $id));

        return new JsonResponse($roster);
    }
}
