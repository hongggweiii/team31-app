<?php

namespace App\Libraries;

use Config\Database;
use Throwable;

/**
 * Saves each matcher run for analytics: one analytics_outputs row (stage times,
 * the machine matcher/ ran on) and one run_outputs row per suggested showcase.
 * Written once the page has been sent, so the hirer doesn't wait on it, and
 * after RequestTimer has worked out this request's numbers. A failure is
 * logged, never raised.
 */
final class MatchRecorder
{
    /** Signal columns of run_outputs, under the API's signal groups. */
    private const SIGNALS = [
        'retrieval'    => ['bm25_score', 'bm25_rank', 'dense_cosine', 'dense_rank', 'rrf_score', 'rrf_rank'],
        'business_fit' => ['budget_fit', 'seniority_fit', 'avail_immediacy'],
        'taxonomy'     => ['tag_level', 'has_tags_gig', 'has_tags_prov', 'cat_any', 'spec_cov', 'skill_cov', 'skill_idf_cov', 'skill_soft'],
    ];

    /**
     * @param array<string, mixed>      $gig     gigs row
     * @param array<string, mixed>|null $matches MatcherClient::match()'s response, null when it failed
     */
    public static function record(array $gig, ?array $matches, ?string $error): void
    {
        // The CLI (tests, spark) has no response to wait for.
        if (is_cli()) {
            self::save($gig, $matches, $error);

            return;
        }

        register_shutdown_function(static fn () => self::save($gig, $matches, $error));
    }

    private static function save(array $gig, ?array $matches, ?string $error): void
    {
        $now    = date('Y-m-d H:i:s');
        $meta   = $matches['meta'] ?? [];
        $stages = $meta['timing_ms'] ?? [];
        $timer  = RequestTimer::enabled() ? RequestTimer::stats() : null;
        // The request just before this page in the redirect chain: the POST that saved the gig.
        $saved  = $timer['action'][2] ?? [];
        $saved  = end($saved) ?: null;
        $ms     = static fn ($v) => $v === null ? null : round((float) $v, 1);

        try {
            $db = Database::connect();
            $db->table('analytics_outputs')->insert([
                'gig_id'                => (int) $gig['id'],
                'user_id'               => $gig['user_id'] ?? null,
                'status'                => $matches === null ? 'error' : 'ok',
                'error'                 => $error,
                'matcher_machine'       => $meta['machine'] ?? null,
                'candidate_pool'        => $meta['candidate_pool'] ?? null,
                'returned'              => $meta['returned'] ?? null,
                'matcher_embed_ms'      => $ms($stages['embed'] ?? null),
                'matcher_candidates_ms' => $ms($stages['candidates'] ?? null),
                'matcher_score_ms'      => $ms($stages['score'] ?? null),
                'matcher_response_ms'   => $ms($stages['response'] ?? null),
                'matcher_total_ms'      => $ms($stages['total'] ?? null),
                'matcher_call_ms'       => $ms($timer['spans']['matcher'] ?? null),
                'save_connect_ms'       => $ms($saved['connect'] ?? null),
                'save_query_ms'         => $ms($saved['queryMs'] ?? null),
                'save_query_count'      => $saved['queryCount'] ?? null,
                'save_php_ms'           => $ms($saved['php'] ?? null),
                'save_total_ms'         => $ms($saved['total'] ?? null),
                'db_connect_ms'         => $ms($timer['connect'] ?? null),
                'db_query_ms'           => $ms($timer['queryMs'] ?? null),
                'db_query_count'        => $timer === null ? null : count($timer['queries']),
                'php_views_ms'          => $ms($timer['php'] ?? null),
                'page_total_ms'         => $ms($timer['total'] ?? null),
                'action_total_ms'       => $ms($timer['action'][0] ?? null),
                'action_chain'          => $timer['action'][1] ?? null,
                'created_at'            => $now,
            ]);
            $runId = $db->insertID();

            $rows = [];

            foreach ($matches['results'] ?? [] as $result) {
                $row = [
                    'analytics_output_id' => $runId,
                    'gig_id'              => (int) $gig['id'],
                    'rank'                => $result['rank'],
                    'provider_id'         => $result['provider_id'],
                    'score'               => $result['score'],
                    'raw_margin'          => $result['raw_margin'],
                    'display_title'       => $result['provider']['display_title'] ?? null,
                    'industry'            => $result['provider']['industry'] ?? null,
                    'bio_excerpt'         => $result['provider']['bio_excerpt'] ?? null,
                ];

                foreach (self::SIGNALS as $group => $signals) {
                    foreach ($signals as $signal) {
                        $row[$signal] = $result['signals'][$group][$signal] ?? null;
                    }
                }
                $rows[] = [...$row, 'created_at' => $now];
            }

            if ($rows !== []) {
                $db->table('run_outputs')->insertBatch($rows);
            }
        } catch (Throwable $e) {
            log_message('error', 'Saving the matcher run of gig {id} failed: {message}', ['id' => $gig['id'], 'message' => $e->getMessage()]);
        }
    }
}
