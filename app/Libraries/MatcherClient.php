<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use Config\Matcher;
use RuntimeException;

/**
 * Asks matcher/ for the showcases that best match a gig, in the ranker's API
 * contract (ranker API_IO_SPEC.md, example_io.json). matcher/ embeds the gig
 * live and ranks every published showcase with the ranker's pipeline:
 *
 *   request  ['hire_id', 'hire_title', 'hire_description', 'industry', 'taxonomy', 'top_k']
 *   response ['results' => [['rank', 'provider_id' (= showcases.id), 'score', 'raw_margin',
 *                             'provider' => [...], 'signals' => [...]], ...],
 *             'meta' => [...]]
 */
class MatcherClient
{
    private Matcher $config;
    private CURLRequest $client;

    public function __construct(?Matcher $config = null, ?CURLRequest $client = null)
    {
        $this->config = $config ?? config(Matcher::class);
        $this->client = $client ?? single_service('curlrequest', [
            'baseURI' => $this->config->baseURL,
            'timeout' => $this->config->timeout,
        ]);
    }

    /**
     * The ranker's request for a saved gig. Field names are the ranker's
     * (hirers.json); the app has no industry for a gig.
     *
     * @param array<string, mixed> $gig      gigs row
     * @param list<array>          $taxonomy GigModel::rankerTaxonomy()
     *
     * @return array<string, mixed>
     */
    public static function gigRequest(array $gig, array $taxonomy, ?int $topK = null): array
    {
        return [
            'hire_id'          => (int) $gig['id'],
            'hire_title'       => trim((string) $gig['title']),
            'hire_description' => trim((string) $gig['summary']),
            'industry'         => null,
            'taxonomy'         => $taxonomy,
            'top_k'            => $topK ?? config(Matcher::class)->topK,
        ];
    }

    /**
     * @param array<string, mixed> $request gigRequest()
     *
     * @return array<string, mixed> The decoded response
     *
     * @throws RuntimeException When matcher/ is unreachable, times out, or answers with an error
     *                          (the exception code is the HTTP status)
     */
    public function match(array $request): array
    {
        $response = RequestTimer::span('matcher', fn () => $this->client->post('match', [
            'json'        => $request,
            'http_errors' => false,
        ]));

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = json_decode($body, true);

        if ($status !== 200) {
            throw new RuntimeException("Matcher returned HTTP {$status}: {$body}", $status);
        }
        if (! is_array($data) || ! is_array($data['results'] ?? null)) {
            throw new RuntimeException('Matcher returned no results: ' . $body);
        }

        return $data;
    }
}
