<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Matcher;

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
     * @return array<string, mixed> The decoded response; results that lack the fields the page shows are dropped
     *
     * @throws MatcherException Why matching failed: unreachable, timed out, refused, or an unusable answer
     *                          (the exception code is the HTTP status, 0 when matcher/ never answered)
     */
    public function match(array $request): array
    {
        // matcher/ answers 422 for this anyway; no need to spend a round trip on it.
        if (trim($request['hire_title'] ?? '') === '' && trim($request['hire_description'] ?? '') === '') {
            throw new MatcherException(MatcherException::REJECTED, 'The gig has no title or description to match');
        }

        try {
            $response = RequestTimer::span('matcher', fn () => $this->client->post('match', [
                'json'        => $request,
                'http_errors' => false,
            ]));
        } catch (HTTPException $e) { // connection refused, DNS, timeout
            throw new MatcherException(MatcherException::UNAVAILABLE, 'Matcher unreachable: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = json_decode($body, true);

        if ($status !== 200) {
            $reason = in_array($status, [400, 422], true) ? MatcherException::REJECTED : MatcherException::UNAVAILABLE;

            throw new MatcherException($reason, "Matcher returned HTTP {$status}: {$body}", $status);
        }
        if (! is_array($data) || ! is_array($data['results'] ?? null)) {
            throw new MatcherException(MatcherException::BAD_RESPONSE, 'Matcher returned no results: ' . $body, $status);
        }

        $data['results'] = array_values(array_filter($data['results'], self::usable(...)));

        return $data;
    }

    /**
     * A result the matches page can show: the ranker's contract guarantees these,
     * so anything else is skipped rather than allowed to break the page.
     */
    private static function usable(mixed $result): bool
    {
        return is_array($result)
            && is_int($result['rank'] ?? null)
            && is_int($result['provider_id'] ?? null)
            && is_numeric($result['score'] ?? null)
            && is_array($result['provider'] ?? null);
    }
}
