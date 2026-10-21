<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the form API page by page (docs/api-formulaire.md). Retries live in the client's configuration.
 */
final class FormApi
{
    public function __construct(
        #[Autowire(service: 'form_api.client')]
        private readonly HttpClientInterface $client,
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
            $body = $this->client->request('GET', '/submissions', ['query' => ['page' => $page]])->toArray();

            $data = $body['data'] ?? null;
            $meta = $body['meta'] ?? null;
            $form = $body['form'] ?? null;
            if (!\is_array($data) || !array_is_list($data) || !\is_array($meta) || !\is_int($meta['total'] ?? null)
                || !\is_int($meta['total_pages'] ?? null) || !\is_array($form) || !\is_string($form['id'] ?? null)) {
                throw new SourceUnreadable(\sprintf('Page %d does not have the documented shape.', $page));
            }

            yield new SourcePage($form['id'], \is_string($form['title'] ?? null) ? $form['title'] : $form['id'], $data, $meta['total']);

            if ([] === $data || $page >= $meta['total_pages']) {
                return;
            }
        }
    }
}
