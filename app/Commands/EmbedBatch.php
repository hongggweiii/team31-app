<?php

namespace App\Commands;

use App\Libraries\EmbeddingStore;
use App\Libraries\ProviderEmbedder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;

/**
 * Offline batch embedding: embeds published showcases through
 * embedding-service/ in pages and stores the vectors in showcase_embeddings.
 * Showcases whose embedded text is unchanged since the last run are skipped,
 * so it is safe to run on a schedule.
 */
class EmbedBatch extends BaseCommand
{
    private const MAX_SIZE = 128; // embedding-service/app.py MAX_BATCH

    protected $group       = 'App';
    protected $name        = 'embed:batch';
    protected $description = 'Embeds new or changed showcases via embedding-service/ and stores the vectors in MariaDB.';
    protected $usage       = 'embed:batch showcases [--size 32] [--all]';
    protected $arguments   = ['target' => 'What to embed: showcases (gigs need a gig embedder first)'];
    protected $options     = [
        '--size' => 'Showcases per request to the embedding service (1-' . self::MAX_SIZE . ', default 32)',
        '--all'  => 'Re-embed everything, even when the text is unchanged',
    ];

    public function run(array $params)
    {
        $target = $params[0] ?? '';
        if ($target !== 'showcases') {
            CLI::error($target === 'gigs'
                ? 'Gig embeddings need a gig embedder (the ranker\'s gig text and query prefix), which does not exist yet.'
                : 'Usage: php spark ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $size = max(1, min(self::MAX_SIZE, (int) (CLI::getOption('size') ?? 32)));
        $all  = CLI::getOption('all') !== null;

        $db       = db_connect();
        $embedder = new ProviderEmbedder();
        $store    = EmbeddingStore::showcases($db);
        $counts   = ['embedded' => 0, 'unchanged' => 0, 'empty' => 0, 'failed' => 0];
        $lastId   = 0;

        while (true) {
            $page = $db->table('showcases')->where('status', 'published')->where('id >', $lastId)
                ->orderBy('id')->limit($size)->get()->getResultArray();
            if ($page === []) {
                break;
            }
            $lastId = (int) end($page)['id'];

            $fields = ProviderEmbedder::showcaseFields($page);
            $stored = $all ? [] : $store->stored(array_keys($fields));

            $todo = [];
            foreach ($fields as $id => $provider) {
                if (ProviderEmbedder::providerText($provider) === '') {
                    $counts['empty']++;
                } elseif (($stored[$id]['text_hash'] ?? null) === ProviderEmbedder::textHash($provider)) {
                    $counts['unchanged']++;
                } else {
                    $todo[$id] = $provider;
                }
            }

            try {
                $result = $embedder->embedMany($todo);
                $store->saveMany($result['model'], $result['embeddings'], array_map(ProviderEmbedder::textHash(...), $todo));
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
     * @param array<string, int> $counts
     */
    private function summary(array $counts): string
    {
        return implode(', ', array_map(static fn (string $k, int $v): string => "{$v} {$k}", array_keys($counts), $counts));
    }
}
