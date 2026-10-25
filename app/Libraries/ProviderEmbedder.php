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
 * A showcase maps onto those fields in fieldsFromShowcase(). Vectors are
 * stored by EmbeddingStore, filled offline by `php spark embed:batch`.
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

        return ['showcase_id' => $showcaseId] + $this->embed(self::showcaseFields([$showcase])[$showcaseId]);
    }

    /**
     * Maps saved showcases onto the ranker's provider records, loading their
     * services and achievements in two queries for the whole list. Each record
     * is fieldsFromShowcase() plus years_experience, which is not embedded but
     * which matcher/ reads from the stored record for its exp_years feature.
     *
     * @param list<array<string, mixed>> $showcases showcases rows
     *
     * @return array<int, array<string, int|string|null>> Keyed by showcase id
     */
    public static function showcaseFields(array $showcases): array
    {
        if ($showcases === []) {
            return [];
        }

        $ids      = array_map(static fn (array $s): int => (int) $s['id'], $showcases);
        $db       = db_connect();
        $children = static function (string $table, string $columns) use ($db, $ids): array {
            $byShowcase = [];
            $rows       = $db->table($table)->select("showcase_id, {$columns}")->whereIn('showcase_id', $ids)
                ->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
            foreach ($rows as $row) {
                $byShowcase[(int) $row['showcase_id']][] = $row;
            }

            return $byShowcase;
        };
        $services     = $children('showcase_services', 'title, description');
        $achievements = $children('showcase_achievements', 'description');

        $fields = [];
        foreach ($showcases as $showcase) {
            $id          = (int) $showcase['id'];
            $fields[$id] = self::fieldsFromShowcase($showcase, $services[$id] ?? [], array_column($achievements[$id] ?? [], 'description'))
                + ['years_experience' => isset($showcase['years_experience']) ? (int) $showcase['years_experience'] : null];
        }

        return $fields;
    }

    /**
     * The exact text embedding-service/ embeds for these fields (provider_text
     * in embedding.py: FIELDS in order, empty ones skipped, space-joined).
     *
     * @param array<string, mixed> $provider
     */
    public static function providerText(array $provider): string
    {
        $parts = array_map(static fn (string $field): string => (string) ($provider[$field] ?? ''), self::FIELDS);

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * sha256 of providerText(), stored next to each vector so batches can skip
     * showcases whose embedded text hasn't changed.
     *
     * @param array<string, mixed> $provider
     */
    public static function textHash(array $provider): string
    {
        return hash('sha256', self::providerText($provider));
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
     * title, years_experience, credentials (which the ranker also leaves out),
     * availability, rate, how_i_work, skills, taxonomy and documents are not embedded.
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
        $data      = $this->post('embed/provider', self::payload($provider));
        $embedding = $this->checkVector(is_array($data) ? ($data['embedding'] ?? null) : null);

        return [
            'model'      => (string) ($data['model'] ?? ''),
            'dimensions' => count($embedding),
            'embedding'  => $embedding,
        ];
    }

    /**
     * Embeds many providers in one request (the service caps a batch at 128).
     *
     * @param array<array-key, array<string, mixed>> $providers Keys are kept
     *
     * @return array{model: string, embeddings: array<array-key, list<float>>} Same keys as $providers
     *
     * @throws RuntimeException As embed(), or when the number of vectors returned doesn't match
     */
    public function embedMany(array $providers): array
    {
        if ($providers === []) {
            return ['model' => '', 'embeddings' => []];
        }

        $data       = $this->post('embed/providers', ['providers' => array_map(self::payload(...), array_values($providers))]);
        $embeddings = is_array($data) ? ($data['embeddings'] ?? null) : null;

        if (! is_array($embeddings) || count($embeddings) !== count($providers)) {
            throw new RuntimeException(
                'Embedding service returned ' . (is_array($embeddings) ? count($embeddings) : 'no') . ' embeddings for ' . count($providers) . ' providers',
            );
        }

        return [
            'model'      => (string) ($data['model'] ?? ''),
            'embeddings' => array_combine(array_keys($providers), array_map($this->checkVector(...), $embeddings)),
        ];
    }

    /**
     * @return array<string, mixed> Exactly FIELDS, missing ones null
     */
    private static function payload(array $provider): array
    {
        $payload = [];
        foreach (self::FIELDS as $field) {
            $payload[$field] = $provider[$field] ?? null;
        }

        return $payload;
    }

    /**
     * POSTs JSON to the service and returns the decoded body.
     *
     * @throws RuntimeException On a non-200 response
     */
    private function post(string $path, array $json): mixed
    {
        $response = $this->client->post($path, [
            'json'        => $json,
            'http_errors' => false,
        ]);

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status !== 200) {
            throw new RuntimeException("Embedding service returned HTTP {$status}: {$body}");
        }

        return json_decode($body, true);
    }

    /**
     * @return list<float>
     *
     * @throws RuntimeException When $embedding isn't a vector of the configured length
     */
    private function checkVector(mixed $embedding): array
    {
        if (! is_array($embedding) || count($embedding) !== $this->config->dimensions) {
            throw new RuntimeException(
                'Embedding service returned ' . (is_array($embedding) ? count($embedding) . ' dimensions' : 'no embedding')
                . ", expected {$this->config->dimensions}",
            );
        }

        return $embedding;
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
