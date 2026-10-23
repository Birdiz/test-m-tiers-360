<?php

namespace App\Import;

use App\Entity\Operation;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads the form API page by page (docs/api-formulaire.md).
 *
 * Retries are done here, not with the client's retry_failed: Symfony's RetryableHttpClient shares max_duration
 * across attempts, so a page cut for being too slow would never be retried (ADR 0011).
 */
final class FormApi
{
    private const MAX_DELAY_MS = 30_000;

    public function __construct(
        #[Autowire(service: 'form_api.client')]
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly ValidatorInterface $validator,
        private readonly int $maxRetries,
        private readonly int $retryDelayMs,
    ) {
    }

    /**
     * @return \Generator<int, SourcePage>
     *
     * @throws ExceptionInterface when a page still fails after the retries
     * @throws SourceUnreadable   when a page does not have the documented shape
     */
    public function pages(): \Generator
    {
        for ($page = 1; ; ++$page) {
            $body = $this->fetch($page)->toArray();

            $data = $body['data'] ?? null;
            $meta = $body['meta'] ?? null;
            $form = $body['form'] ?? null;
            if (!\is_array($data) || !array_is_list($data) || !\is_array($meta) || !\is_int($meta['total'] ?? null)
                || !\is_int($meta['total_pages'] ?? null) || !\is_array($form) || !\is_string($form['id'] ?? null)) {
                throw new SourceUnreadable(\sprintf('Page %d does not have the documented shape.', $page));
            }
            // Without a form id the database can hold, no registration can be tied to its operation (ADR 0014).
            if (str_contains($form['id'], "\0") || 0 !== \count($this->validator->validatePropertyValue(Operation::class, 'externalFormId', $form['id']))) {
                throw new SourceUnreadable(\sprintf('Page %d has a form id we cannot store.', $page));
            }
            $title = \is_string($form['title'] ?? null) ? str_replace("\0", '', $form['title']) : $form['id'];

            yield new SourcePage($form['id'], mb_substr($title, 0, Operation::TITLE_LENGTH), $data, $meta['total']);

            if ([] === $data || $page >= $meta['total_pages']) {
                return;
            }
        }
    }

    /**
     * Retries transport errors (including max_duration), 429 and 5xx, with exponential backoff or Retry-After.
     * Returns the last response: one still failing throws when read.
     */
    private function fetch(int $page): ResponseInterface
    {
        for ($attempt = 0; ; ++$attempt) {
            $response = $this->client->request('GET', '/submissions', ['query' => ['page' => $page]]);
            try {
                $status = $response->getStatusCode();
                if ((429 !== $status && $status < 500) || $attempt >= $this->maxRetries) {
                    return $response;
                }
                $reason = 'status '.$status;
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            } catch (TransportExceptionInterface $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                $reason = $e->getMessage();
                $retryAfter = null;
            }

            $delayMs = is_numeric($retryAfter) ? (int) $retryAfter * 1000 : $this->retryDelayMs * 2 ** $attempt;
            $delayMs = min($delayMs, self::MAX_DELAY_MS);
            $this->logger->notice('Retrying page {page} in {delay} ms ({reason})', ['page' => $page, 'delay' => $delayMs, 'reason' => $reason]);
            usleep($delayMs * 1000);
        }
    }
}
