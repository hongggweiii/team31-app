<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Reads and writes one embeddings table (showcase_embeddings or
 * gig_embeddings) in MariaDB Vector, and runs nearest-neighbour search over
 * it through the table's cosine VECTOR INDEX.
 *
 * MariaDB only: vectors go in and out through VEC_FromText() and
 * VEC_DISTANCE_COSINE(), which other drivers (e.g. the SQLite test database)
 * don't have.
 */
class EmbeddingStore
{
    private BaseConnection $db;

    /**
     * Use showcases() or gigs() rather than this.
     *
     * @param string              $table The embeddings table
     * @param string              $key   Its id column (showcase_id / gig_id)
     * @param BaseConnection|null $db    Defaults to the app's database
     */
    public function __construct(private readonly string $table, private readonly string $key, ?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /** The provider side: showcase_embeddings, written by embed:batch. */
    public static function showcases(?BaseConnection $db = null): self
    {
        return new self('showcase_embeddings', 'showcase_id', $db);
    }

    /** The query side: gig_embeddings, written by matcher/ after each match. */
    public static function gigs(?BaseConnection $db = null): self
    {
        return new self('gig_embeddings', 'gig_id', $db);
    }

    /**
     * What is stored for these ids, to decide what needs (re-)embedding.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{model: string, text_hash: string, has_fields: bool, fields_json: ?string, updated_at: ?string}> Only ids that have a vector
     */
    public function stored(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stored = [];
        $rows   = $this->db->table($this->table)->select("{$this->key}, model, text_hash, fields_json, fields_json IS NOT NULL AS has_fields, updated_at", false)
            ->whereIn($this->key, $ids)->get()->getResultArray();
        foreach ($rows as $row) {
            $stored[(int) $row[$this->key]] = [
                'model'       => $row['model'],
                'text_hash'   => $row['text_hash'],
                'has_fields'  => (bool) $row['has_fields'],
                'fields_json' => $row['fields_json'],
                'updated_at'  => $row['updated_at'],
            ];
        }

        return $stored;
    }

    /**
     * Marks vectors as checked against their (unchanged) showcase / gig now,
     * without re-embedding: embed:auto counts a showcase as waiting while it
     * was updated after its vector.
     *
     * @param list<int> $ids Ids whose text and record are unchanged
     */
    public function touch(array $ids): void
    {
        if ($ids !== []) {
            $this->db->table($this->table)->whereIn($this->key, $ids)->update(['updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * Inserts or replaces vectors in one statement (one round trip for a whole batch).
     *
     * @param string                             $model      The model that produced them (embedding-service's "model")
     * @param array<int, list<float>>            $embeddings Keyed by showcase / gig id
     * @param array<int, string>                 $textHashes Keyed like $embeddings
     * @param array<int, array<string, mixed>>   $fields     Keyed like $embeddings: the ranker-format record each
     *                                                      vector was embedded from, stored as fields_json
     */
    public function saveMany(string $model, array $embeddings, array $textHashes, array $fields = []): void
    {
        if ($embeddings === []) {
            return;
        }
        $this->requireMariaDb();

        $now      = date('Y-m-d H:i:s');
        // One "(?, ?, ...)" group per vector; the vector goes in as JSON text for VEC_FromText().
        $rows     = [];
        $bindings = [];
        foreach ($embeddings as $id => $vector) {
            $rows[] = '(?, ?, ?, ?, VEC_FromText(?), ?, ?)';
            array_push(
                $bindings,
                (int) $id,
                $model,
                $textHashes[$id],
                isset($fields[$id]) ? self::encodeFields($fields[$id]) : null,
                json_encode(array_map('floatval', $vector)),
                $now,
                $now,
            );
        }

        $this->db->query(
            "INSERT INTO {$this->db->prefixTable($this->table)} ({$this->key}, model, text_hash, fields_json, embedding, created_at, updated_at)
             VALUES " . implode(', ', $rows) . '
             ON DUPLICATE KEY UPDATE model = VALUES(model), text_hash = VALUES(text_hash), fields_json = VALUES(fields_json),
                 embedding = VALUES(embedding), updated_at = VALUES(updated_at)',
            $bindings,
        );
    }

    /**
     * The $limit stored vectors closest to $vector by cosine distance
     * (0 = same direction, 2 = opposite). Approximate (HNSW), via the
     * table's VECTOR INDEX: the ORDER BY must stay the bare distance call
     * with a LIMIT, or MariaDB falls back to a full scan. Filter (status,
     * category, ...) on an over-fetched result rather than in this query.
     *
     * @param list<float> $vector Same model and dimensions as the stored vectors
     *
     * @param int         $limit  How many to return (at least 1)
     *
     * @return list<array{id: int, distance: float}> Nearest first
     */
    public function nearest(array $vector, int $limit = 20): array
    {
        $this->requireMariaDb();

        $json = json_encode(array_map('floatval', $vector));
        $rows = $this->db->query(
            "SELECT {$this->key} AS id, VEC_DISTANCE_COSINE(embedding, VEC_FromText(?)) AS distance
             FROM {$this->db->prefixTable($this->table)}
             ORDER BY VEC_DISTANCE_COSINE(embedding, VEC_FromText(?))
             LIMIT ?",
            [$json, $json, max(1, $limit)],
        )->getResultArray();

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'distance' => (float) $row['distance']], $rows);
    }

    /**
     * A stored vector, e.g. to find showcases similar to a given one.
     *
     * @param int $id A showcase / gig id
     *
     * @return list<float>|null The vector, or null if that id has none
     */
    public function vector(int $id): ?array
    {
        $this->requireMariaDb();

        $row = $this->db->table($this->table)->select('VEC_ToText(embedding) AS embedding', false)->where($this->key, $id)->get()->getRowArray();

        return $row === null ? null : array_map('floatval', json_decode($row['embedding'], true));
    }

    /**
     * A ranker-format record as stored in fields_json. Batches compare against
     * this to spot records that changed outside the embedded text.
     *
     * @param array<string, mixed> $fields The record
     *
     * @return string Its JSON, Unicode unescaped (always encoded the same way, so strings compare)
     */
    public static function encodeFields(array $fields): string
    {
        return json_encode($fields, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws RuntimeException On any driver but MySQLi (MariaDB), e.g. the SQLite test database
     */
    private function requireMariaDb(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException("EmbeddingStore needs MariaDB Vector; the '{$this->db->DBDriver}' driver has no VECTOR support");
        }
    }
}
