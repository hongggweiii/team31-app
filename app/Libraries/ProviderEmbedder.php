<?php

namespace App\Libraries;

use App\Models\ShowcaseModel;
use CodeIgniter\HTTP\CURLRequest;
use Config\Embedding;
use RuntimeException;

/**
 * Turns a provider's showcase into its embedding vector by calling
 * embedding-service/, which joins and encodes the ranker's five provider text
 * fields exactly as the ranker does (ranker/pipeline/corpus.py +
 * retrieval_dense.py).
 *
 * A showcase maps onto those fields in fieldsFromShowcase(). Pushing the
 * result into the vector DB is left to the caller.
 */
class ProviderEmbedder
{
    /**
     * The ranker's provider text fields, in the order they are joined. Nothing
     * else is embedded.
     */
    public const FIELDS = [
        'about_title',
        'about_description',
        'services_offered_title',
        'services_offered_description',
        'relevant_experience',
    ];

    private Embedding $config;
    private CURLRequest $client;

    public function __construct(?Embedding $config = null, ?CURLRequest $client = null)
    {
        $this->config = $config ?? config(Embedding::class);
        $this->client = $client ?? single_service('curlrequest', [
            'baseURI' => $this->config->baseURL,
            'timeout' => $this->config->timeout,
        ]);
    }

    /**
     * Embeds a saved showcase, reading it and its services and achievements
     * from the database.
     *
     * @return array{showcase_id: int, model: string, dimensions: int, embedding: list<float>}
     *
     * @throws RuntimeException When the showcase doesn't exist, or embed() fails
     */
    public function embedShowcase(int $showcaseId): array
    {
        $showcase = (new ShowcaseModel())->find($showcaseId);
        if ($showcase === null) {
            throw new RuntimeException("Showcase {$showcaseId} not found");
        }

        $db    = db_connect();
        $child = static fn (string $table, string $columns): array => $db->table($table)
            ->select($columns)
            ->where('showcase_id', $showcaseId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->getResultArray();

        $fields = self::fieldsFromShowcase(
            $showcase,
            $child('showcase_services', 'title, description'),
            array_column($child('showcase_achievements', 'description'), 'description'),
        );

        return ['showcase_id' => $showcaseId] + $this->embed($fields);
    }

    /**
     * Maps a showcase onto the ranker's provider fields:
     *
     *   about_title                  <- headline
     *   about_description            <- about (Bio)
     *   services_offered_title       <- every service title, "; "-separated
     *   services_offered_description <- every service description
     *   relevant_experience          <- achievements, one "- " line each (the ranker data's format)
     *
     * title, credentials (which the ranker also leaves out), availability,
     * rate, how_i_work, skills, taxonomy and documents are not embedded.
     * Rich text is reduced to plain text first.
     *
     * @param array<string, mixed>                                  $showcase     showcases row
     * @param list<array{title: string, description: string|null}> $services     showcase_services rows, in order
     * @param list<string|null>                                     $achievements showcase_achievements descriptions, in order
     *
     * @return array<string, string> Keyed by FIELDS; '' where there is nothing to embed
     */
    public static function fieldsFromShowcase(array $showcase, array $services, array $achievements): array
    {
        $bullets = [];
        foreach ($achievements as $achievement) {
            foreach (explode("\n", self::plainText($achievement)) as $line) {
                if ($line !== '') {
                    $bullets[] = str_starts_with($line, '- ') ? $line : '- ' . $line;
                }
            }
        }

        $nonEmpty = static fn (array $values): array => array_values(array_filter($values, static fn (string $v): bool => $v !== ''));

        return [
            'about_title'                  => trim((string) ($showcase['headline'] ?? '')),
            'about_description'            => self::plainText($showcase['about'] ?? null),
            'services_offered_title'       => implode('; ', $nonEmpty(array_map(static fn (array $s): string => trim((string) ($s['title'] ?? '')), $services))),
            'services_offered_description' => implode("\n", $nonEmpty(array_map(static fn (array $s): string => self::plainText($s['description'] ?? null), $services))),
            'relevant_experience'          => implode("\n", $bullets),
        ];
    }

    /**
     * Embeds the ranker's provider fields directly.
     *
     * @param array<string, mixed> $provider Keys outside FIELDS are ignored
     *
     * @return array{model: string, dimensions: int, embedding: list<float>} Unit-length vector, cosine-ready
     *
     * @throws RuntimeException When the service is unreachable, rejects the profile, or returns a malformed vector
     */
    public function embed(array $provider): array
    {
        $payload = [];

        foreach (self::FIELDS as $field) {
            $payload[$field] = $provider[$field] ?? null;
        }

        $response = $this->client->post('embed/provider', [
            'json'        => $payload,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status !== 200) {
            throw new RuntimeException("Embedding service returned HTTP {$status}: {$body}");
        }

        $data      = json_decode($body, true);
        $embedding = is_array($data) ? ($data['embedding'] ?? null) : null;

        if (! is_array($embedding) || count($embedding) !== $this->config->dimensions) {
            throw new RuntimeException(
                'Embedding service returned ' . (is_array($embedding) ? count($embedding) . ' dimensions' : 'no embedding')
                . ", expected {$this->config->dimensions}",
            );
        }

        return [
            'model'      => (string) ($data['model'] ?? ''),
            'dimensions' => count($embedding),
            'embedding'  => $embedding,
        ];
    }

    /**
     * Quill HTML (already limited to p/br/strong/em/u/ol/li by RichText) to
     * plain text: one line per paragraph, "- " per list item.
     */
    private static function plainText(?string $html): string
    {
        $html  = preg_replace(['#<li\b[^>]*>#i', '#<br\s*/?>|</(?:p|li)>#i'], ['- ', "\n"], (string) $html);
        $text  = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(static fn (string $line): string => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line)), explode("\n", $text));

        return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '' && $line !== '-'));
    }
}
