<?php

namespace App\Tests\Import;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for the form API's transport (framework.http_client.mock_response_factory): behaves like
 * docs/api-formulaire.md, 25 submissions per page, unless a response is scripted for a page.
 */
final class FakeFormApi
{
    private const PER_PAGE = 25;

    /** @var array{id: string, title: string} */
    private array $form = ['id' => 'frm_7Hq2xL', 'title' => 'Découvrir les métiers en réalité virtuelle — Ain, novembre 2026'];

    /** @var list<array<string, mixed>> */
    private array $submissions = [];

    /** @var array<int, list<MockResponse>> */
    private array $scripted = [];

    /** @var list<int> */
    private array $requestedPages = [];

    /**
     * @param list<array<string, mixed>> $submissions
     */
    public function serve(array $submissions): void
    {
        $this->submissions = $submissions;
    }

    /**
     * Serves one of the kit's datasets (mock-api/data/).
     */
    public function serveDataset(string $file): void
    {
        /** @var array{form: array{id: string, title: string}, submissions: list<array<string, mixed>>} $dataset */
        $dataset = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/mock-api/data/'.$file), true, flags: \JSON_THROW_ON_ERROR);
        $this->form = $dataset['form'];
        $this->submissions = $dataset['submissions'];
    }

    /**
     * The next requests for this page get these responses, in order, before the page is served normally.
     */
    public function script(int $page, MockResponse ...$responses): void
    {
        $this->scripted[$page] = [...($this->scripted[$page] ?? []), ...$responses];
    }

    /**
     * @return list<int>
     */
    public function requestedPages(): array
    {
        return $this->requestedPages;
    }

    public static function serverError(): MockResponse
    {
        return new MockResponse('{"error":"internal_error","message":"An unexpected error occurred."}', ['http_code' => 500]);
    }

    /**
     * Nothing received before max_duration, like the kit's slow page 8. A body cut after a 200 status
     * would not be retried by Symfony: the retry strategy decides on the status code.
     */
    public static function maxDurationReached(): MockResponse
    {
        return new MockResponse('', ['error' => 'Max duration was reached.']);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $this->requestedPages[] = $page;

        if ([] !== ($this->scripted[$page] ?? [])) {
            return array_shift($this->scripted[$page]);
        }

        return new MockResponse(json_encode([
            'form' => $this->form,
            'data' => \array_slice($this->submissions, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'meta' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => \count($this->submissions),
                'total_pages' => max(1, (int) ceil(\count($this->submissions) / self::PER_PAGE)),
            ],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
