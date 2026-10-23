<?php

namespace App\Tests\Controller;

use App\Import\RegistrationImporter;
use App\Tests\Import\FakeFormApi;
use App\Tests\Import\Submissions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Seam S4: GET /api/sessions/{id}/inscriptions.
 */
final class SessionRegistrationsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(Connection::class)
            ->executeStatement('TRUNCATE registration, session, operation, import_run RESTART IDENTITY CASCADE');
        self::getContainer()->get(FakeFormApi::class)->serve([
            Submissions::submission(['comment' => "Besoin d'un accès PMR."]),
        ]);
        self::getContainer()->get(RegistrationImporter::class)->run();
    }

    public function testRequestWithoutTheOpsTokenIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/sessions/ses_01/inscriptions');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRequestWithAWrongTokenIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/sessions/ses_01/inscriptions', server: ['HTTP_AUTHORIZATION' => 'Bearer wrong']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownSessionIsNotFound(): void
    {
        $this->client->request('GET', '/api/sessions/ses_99/inscriptions', server: ['HTTP_AUTHORIZATION' => 'Bearer test-ops-token']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The path is free text: no session can have these ids, and Postgres must never see bytes it rejects.
     */
    #[DataProvider('idsNoSessionCanHave')]
    public function testIdNoSessionCanHaveIsNotFound(string $id): void
    {
        $this->client->request('GET', '/api/sessions/'.$id.'/inscriptions', server: ['HTTP_AUTHORIZATION' => 'Bearer test-ops-token']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsNoSessionCanHave(): iterable
    {
        yield 'NUL byte' => ['ses_01%00'];
        yield 'invalid UTF-8' => ['ses_%FF'];
        yield 'longer than any id' => [str_repeat('x', 65)];
    }

    public function testSessionRosterIsServedAsJsonWithoutBirthDates(): void
    {
        $this->client->request('GET', '/api/sessions/ses_01/inscriptions', server: ['HTTP_AUTHORIZATION' => 'Bearer test-ops-token']);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['id' => 'ses_01', 'label' => 'Bourg-en-Bresse — 17 novembre', 'date' => '2026-11-17', 'location' => 'Espace des métiers, Bourg-en-Bresse', 'capacity' => 80], $body['session']);
        self::assertSame(['active' => 1, 'cancelled' => 0, 'removed' => 0, 'estimatedPeople' => 1], array_intersect_key($body['summary'], array_flip(['active', 'cancelled', 'removed', 'estimatedPeople'])));
        self::assertSame(['sub_k3m9qz7tpe' => ['comment']], $body['summary']['toReview']);
        self::assertSame('completed', $body['summary']['data']['lastRunStatus']);
        self::assertSame([
            'id' => 'sub_k3m9qz7tpe',
            'rank' => 1,
            'beyondCapacity' => false,
            'firstName' => 'Léa',
            'lastName' => 'Martin',
            'email' => 'lea.martin@example.com',
            'phone' => '+33639981234',
            'age' => 17,
            'structure' => ['type' => 'lycee', 'name' => 'Lycée des Quatre-Vallées'],
            'comment' => "Besoin d'un accès PMR.",
            'anomalies' => [],
            'reviewReasons' => ['comment'],
        ], $body['registrations'][0]);
    }
}
