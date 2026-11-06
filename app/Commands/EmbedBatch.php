<?php

namespace App\Commands;

use App\Libraries\EmbeddingStore;
use App\Libraries\ProviderEmbedder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

/**
 * Offline batch embedding: embeds published showcases through
 * embedding-service/ in pages and stores the vectors in showcase_embeddings,
 * with the ranker-format record each was embedded from (fields_json, which
 * matcher/ reads). Showcases whose embedded text and stored record are both
 * unchanged since the last run are skipped, so it is safe to run on a schedule.
 * (The record also carries fields that aren't embedded, such as
 * years_experience, so a change there re-saves it.)
 *
 * embed:auto calls embedShowcases() with just the showcases it found waiting,
 * so a scheduled run doesn't rescan the whole catalogue.
 */
class EmbedBatch extends BaseCommand
{
    private const MAX_SIZE = 128; // embedding-service/app.py MAX_BATCH

    protected $group       = 'App';
    protected $name        = 'embed:batch';
    protected $description = 'Embeds new or changed showcases via embedding-service/ and stores the vectors in MariaDB.';
    protected $usage       = 'embed:batch showcases [--size 32] [--all] [--ids 5,6,7]';
    protected $arguments   = ['target' => 'What to embed: showcases (gigs need a gig embedder first)'];
    protected $options     = [
        '--size' => 'Showcases per request to the embedding service (1-' . self::MAX_SIZE . ', default 32)',
        '--all'  => 'Re-embed everything, even when the text is unchanged',
        '--ids'  => 'Only these showcase ids (comma-separated) instead of every published showcase',
    ];

    /**
     * `php spark embed:batch showcases [--size N] [--all] [--ids 1,2,3]`
     *
     * @param list<string> $params Positional arguments: the target ("showcases")
     *
     * @return int EXIT_SUCCESS, EXIT_ERROR (a showcase failed) or EXIT_USER_INPUT (bad arguments)
     */
    public function run(array $params)
    {
        $target = $params[0] ?? '';
        if ($target !== 'showcases') {
            CLI::error($target === 'gigs'
                ? 'Gig embeddings need a gig embedder (the ranker\'s gig text and query prefix), which does not exist yet.'
                : 'Usage: php spark ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $ids = CLI::getOption('ids');
        if ($ids !== null && ! is_string($ids)) { // --ids given without a value
            CLI::error('--ids needs a comma-separated list, e.g. --ids 5,6,7');

            return EXIT_USER_INPUT;
        }

        return $this->embedShowcases(
            $ids === null ? null : array_map('intval', array_filter(explode(',', $ids), 'is_numeric')),
            (int) (CLI::getOption('size') ?? 32),
            CLI::getOption('all') !== null,
        );
    }

    /**
     * Embeds published showcases, page by page.
     *
     * @param list<int>|null $ids Only these showcases; null for every published one
     * @param int            $size Showcases per request to the embedding service
     * @param bool           $all Re-embed even when unchanged
     *
     * @return int EXIT_SUCCESS, or EXIT_ERROR if any showcase failed
     */
    public function embedShowcases(?array $ids = null, int $size = 32, bool $all = false): int
    {
        $size = max(1, min(self::MAX_SIZE, $size));
        if ($ids === []) {
            CLI::write('Done. Nothing to embed.');

            return EXIT_SUCCESS;
        }

        $db       = db_connect();
        $embedder = new ProviderEmbedder();
        $store    = EmbeddingStore::showcases($db);
        $counts   = ['embedded' => 0, 'unchanged' => 0, 'empty' => 0, 'failed' => 0];
        $lastId   = 0;

        // Keyset pagination by id (id > the last page's last id): stable even while
        // showcases are added, and no OFFSET scans.
        while (true) {
            $query = $db->table('showcases')->where('status', 'published')->where('id >', $lastId);
            if ($ids !== null) {
                $query->whereIn('id', $ids);
            }
            $page = $query->orderBy('id')->limit($size)->get()->getResultArray();
            if ($page === []) {
                break;
            }
            $lastId = (int) end($page)['id'];

            $fields = ProviderEmbedder::showcaseFields($page);
            $stored = $all ? [] : $store->stored(array_keys($fields));

            // Sort the page: empty text is skipped; same hash and record means unchanged
            // (only re-stamped if the showcase was saved since); the rest is embedded.
            $todo    = [];
            $checked = []; // unchanged, but the showcase was updated after its vector
            $updated = array_column($page, 'updated_at', 'id');
            foreach ($fields as $id => $provider) {
                if (ProviderEmbedder::providerText($provider) === '') {
                    $counts['empty']++;
                } elseif (($stored[$id]['text_hash'] ?? null) === ProviderEmbedder::textHash($provider)
                    && $stored[$id]['fields_json'] === EmbeddingStore::encodeFields($provider)) {
                    $counts['unchanged']++;
                    if (($updated[$id] ?? '') > ($stored[$id]['updated_at'] ?? '')) {
                        $checked[] = $id;
                    }
                } else {
                    $todo[$id] = $provider;
                }
            }

            // One embedding request and one upsert per page; a failure only loses this page.
            try {
                $store->touch($checked);
                $result = $embedder->embedMany($todo);
                $store->saveMany($result['model'], $result['embeddings'], array_map(ProviderEmbedder::textHash(...), $todo), $todo);
                $counts['embedded'] += count($todo);
            } catch (RuntimeException $e) { // service failure, or DatabaseException
                $counts['failed'] += count($todo);
                CLI::error('Showcases ' . implode(', ', array_keys($todo)) . ': ' . $e->getMessage());
                log_message('error', 'embed:batch failed for showcases {ids}: {message}', ['ids' => implode(',', array_keys($todo)), 'message' => $e->getMessage()]);
            }

            CLI::write("Up to showcase {$lastId}: " . $this->summary($counts));
        }

        CLI::write('Done. ' . $this->summary($counts), $counts['failed'] > 0 ? 'red' : 'green');

        return $counts['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * @param array<string, int> $counts e.g. ['embedded' => 3, 'unchanged' => 10, ...]
     *
     * @return string e.g. "3 embedded, 10 unchanged, 0 empty, 0 failed"
     */
    private function summary(array $counts): string
    {
        return implode(', ', array_map(static fn (string $k, int $v): string => "{$v} {$k}", array_keys($counts), $counts));
    }
}
