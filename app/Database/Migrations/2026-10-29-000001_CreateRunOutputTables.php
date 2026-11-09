<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What each matcher run returned and how long it took, written by
 * App\Libraries\MatchRecorder after the matches page has been sent:
 *
 *   analytics_outputs  one row per run (one gig, one request): where the time
 *                      went, in matcher/ (meta.timing_ms) and in the app
 *                      (RequestTimer), and the machine matcher/ ran on
 *   run_outputs        one row per suggested showcase: the API's result,
 *                      flattened, signals included
 *
 * The app-side times are only collected while RequestTimer is enabled
 * (development); otherwise those columns are NULL.
 */
class CreateRunOutputTables extends Migration
{
    /** Per-result signals, in the API's order (matcher/app.py response()). */
    private const SIGNALS = [
        'bm25_score', 'bm25_rank', 'dense_cosine', 'dense_rank', 'rrf_score', 'rrf_rank',
        'budget_fit', 'seniority_fit', 'avail_immediacy',
        'tag_level', 'has_tags_gig', 'has_tags_prov', 'cat_any', 'spec_cov', 'skill_cov', 'skill_idf_cov', 'skill_soft',
    ];

    public function up(): void
    {
        $ms = ['type' => 'float', 'null' => true];

        $this->forge->addField([
            'id'                    => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'gig_id'                => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'user_id'               => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'status'                => ['type' => 'varchar', 'constraint' => 10],
            'error'                 => ['type' => 'text', 'null' => true],
            'matcher_machine'       => ['type' => 'varchar', 'constraint' => 200, 'null' => true],
            'candidate_pool'        => ['type' => 'int', 'constraint' => 11, 'null' => true],
            'returned'              => ['type' => 'int', 'constraint' => 11, 'null' => true],
            'matcher_embed_ms'      => $ms,
            'matcher_candidates_ms' => $ms,
            'matcher_score_ms'      => $ms,
            'matcher_response_ms'   => $ms,
            'matcher_total_ms'      => $ms,
            'matcher_call_ms'       => $ms,
            'db_connect_ms'         => $ms,
            'db_query_ms'           => $ms,
            'db_query_count'        => ['type' => 'int', 'constraint' => 11, 'null' => true],
            'php_views_ms'          => $ms,
            'page_total_ms'         => $ms,
            'action_total_ms'       => $ms,
            'action_chain'          => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
            'created_at'            => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('gig_id');
        $this->forge->addForeignKey('gig_id', 'gigs', 'id', '', 'CASCADE');
        $this->forge->createTable('analytics_outputs');

        $this->forge->addField([
            'id'                  => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'analytics_output_id' => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'gig_id'              => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'rank'                => ['type' => 'int', 'constraint' => 11],
            'provider_id'         => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'score'               => ['type' => 'float'],
            'raw_margin'          => ['type' => 'float'],
            'display_title'       => ['type' => 'varchar', 'constraint' => 500, 'null' => true],
            'industry'            => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
            'bio_excerpt'         => ['type' => 'text', 'null' => true],
            ...array_fill_keys(self::SIGNALS, ['type' => 'float', 'null' => true]),
            'created_at'          => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('gig_id');
        $this->forge->addForeignKey('analytics_output_id', 'analytics_outputs', 'id', '', 'CASCADE');
        $this->forge->createTable('run_outputs');
    }

    public function down(): void
    {
        $this->forge->dropTable('run_outputs', true);
        $this->forge->dropTable('analytics_outputs', true);
    }
}
