<?php

namespace App\Commands;

use App\Libraries\EmbeddingStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lists the showcases nearest to a given showcase's stored vector, to check
 * the vector index and the embeddings by eye. Read-only.
 */
class EmbedSimilar extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'embed:similar';
    protected $description = 'Lists the showcases whose stored embeddings are nearest to a showcase (read-only).';
    protected $usage       = 'embed:similar <showcase_id> [--limit 10]';
    protected $arguments   = ['showcase_id' => 'A showcases.id that has been embedded (php spark embed:batch showcases)'];
    protected $options     = ['--limit' => 'How many neighbours to list (default 10)'];

    public function run(array $params)
    {
        $id     = (int) ($params[0] ?? 0);
        $store  = EmbeddingStore::showcases();
        $vector = $id > 0 ? $store->vector($id) : null;
        if ($vector === null) {
            CLI::error($id > 0 ? "Showcase {$id} has no stored embedding" : 'Usage: php spark ' . $this->usage);

            return EXIT_USER_INPUT;
        }

        $nearest = $store->nearest($vector, max(1, (int) (CLI::getOption('limit') ?? 10)));
        $titles  = array_column(
            db_connect()->table('showcases')->select('id, title')->whereIn('id', array_column($nearest, 'id'))->get()->getResultArray(),
            'title',
            'id',
        );

        CLI::table(
            array_map(static fn (array $n): array => [$n['id'], number_format($n['distance'], 4), $titles[$n['id']] ?? '?'], $nearest),
            ['Showcase', 'Cosine distance', 'Title'],
        );

        return EXIT_SUCCESS;
    }
}
