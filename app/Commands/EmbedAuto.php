<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Embedding;

/**
 * Cron entry point for showcase embedding: runs `embed:batch showcases` only
 * when enough showcases are waiting (Embedding::$autoBatchSize) or the oldest
 * has waited long enough (Embedding::$autoMaxWaitMinutes), whichever comes
 * first. Otherwise it exits after one cheap query, so cron can call it every
 * minute:
 *
 *     * * * * * cd /path/to/team31-app && php spark embed:auto
 *
 * A showcase is waiting when it is published and has no vector yet, or was
 * updated after its vector. Only those showcases are handed to embed:batch
 * (EmbedBatch::embedShowcases()), which does the exact check (text and stored
 * record), so a false positive only costs a skip. A lock stops a slow run from
 * overlapping the next minute's.
 */
class EmbedAuto extends BaseCommand
{
    private const LOCK_FILE = WRITEPATH . 'cache/embed-auto.lock'; // git-ignored

    protected $group       = 'App';
    protected $name        = 'embed:auto';
    protected $description = 'Runs embed:batch showcases when the size or time threshold is reached (for cron).';
    protected $usage       = 'embed:auto [--dry-run]';
    protected $options     = ['--dry-run' => 'Report what is waiting and whether a batch would run, without running it'];

    /**
     * `php spark embed:auto [--dry-run]`, run by cron every minute.
     *
     * @param list<string> $params Unused
     *
     * @return int EXIT_SUCCESS (including "nothing to do" and "another run holds the lock"),
     *             or embed:batch's exit code when a batch ran
     */
    public function run(array $params)
    {
        // Non-blocking exclusive lock: if the previous minute's run is still going, skip this one.
        $lock = fopen(self::LOCK_FILE, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::write('Another embed:auto run is still going; skipping.');

            return EXIT_SUCCESS;
        }

        try {
            $config  = config(Embedding::class);
            $pending = self::pending(db_connect());
            $age     = $pending['oldest'] === null ? null : time() - strtotime($pending['oldest']);
            $reason  = self::reason($pending['count'], $age, $config->autoBatchSize, $config->autoMaxWaitMinutes);

            CLI::write(sprintf(
                '%d showcase(s) waiting%s; thresholds: %d showcases or %d min. %s',
                $pending['count'],
                $age === null ? '' : ', oldest for ' . intdiv($age, 60) . ' min',
                $config->autoBatchSize,
                $config->autoMaxWaitMinutes,
                $reason === null ? 'Not running yet.' : "Running: {$reason}.",
            ));

            if ($reason === null || CLI::getOption('dry-run') !== null) {
                return EXIT_SUCCESS;
            }

            log_message('info', 'embed:auto running embed:batch showcases: {reason}', ['reason' => $reason]);

            return (new EmbedBatch($this->logger, $this->commands))->embedShowcases($pending['ids']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Published showcases with no vector, or updated since their vector.
     *
     * @param BaseConnection $db The app's database
     *
     * @return array{count: int, oldest: string|null, ids: list<int>} oldest: updated_at of the longest-waiting one
     */
    public static function pending(BaseConnection $db): array
    {
        $rows = $db->table('showcases s')
            ->select('s.id, s.updated_at')
            ->join('showcase_embeddings e', 'e.showcase_id = s.id', 'left')
            ->where('s.status', 'published')
            ->groupStart()->where('e.showcase_id', null)->orWhere('s.updated_at > e.updated_at', null, false)->groupEnd()
            ->orderBy('s.id')
            ->get()->getResultArray();

        return [
            'count'  => count($rows),
            'oldest' => $rows === [] ? null : min(array_column($rows, 'updated_at')),
            'ids'    => array_map('intval', array_column($rows, 'id')),
        ];
    }

    /**
     * Why a batch should run now, or null if neither threshold is reached.
     *
     * @param int      $count          Showcases waiting
     * @param int|null $oldestAge      Seconds the oldest waiting showcase has waited; null when none are waiting
     * @param int      $batchSize      Config\Embedding::$autoBatchSize
     * @param int      $maxWaitMinutes Config\Embedding::$autoMaxWaitMinutes
     *
     * @return string|null The reason, for the log line; null means don't run yet
     */
    public static function reason(int $count, ?int $oldestAge, int $batchSize, int $maxWaitMinutes): ?string
    {
        if ($count === 0) {
            return null;
        }
        if ($count >= $batchSize) {
            return "{$count} waiting (size threshold {$batchSize})";
        }
        if ($oldestAge !== null && $oldestAge >= $maxWaitMinutes * 60) {
            return "oldest waited " . intdiv($oldestAge, 60) . " min (time threshold {$maxWaitMinutes} min)";
        }

        return null;
    }
}
