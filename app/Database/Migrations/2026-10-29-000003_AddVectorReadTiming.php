<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How long matcher/ took to read the gig's stored vector from gig_embeddings,
 * apart from matcher_embed_ms, which is now only a live embedding-service call
 * (NULL when the stored vector was reused). NULL for runs saved before this
 * column existed: their matcher_embed_ms holds both.
 */
class AddVectorReadTiming extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('analytics_outputs', [
            'matcher_vector_read_ms' => ['type' => 'float', 'null' => true, 'after' => 'returned'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('analytics_outputs', 'matcher_vector_read_ms');
    }
}
