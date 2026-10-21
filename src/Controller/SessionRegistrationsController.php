<?php

namespace App\Controller;

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

    #[Route('/api/sessions/{id}/inscriptions', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $roster = $this->roster->forSession($id) ?? throw new NotFoundHttpException(\sprintf('Unknown session "%s".', $id));

        return new JsonResponse($roster);
    }
}
