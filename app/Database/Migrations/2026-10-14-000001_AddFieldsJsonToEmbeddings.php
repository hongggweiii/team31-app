<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stores, next to each vector, the exact ranker-format record it was embedded
 * from (the five provider fields for a showcase, the three gig fields for a
 * gig), as JSON. matcher/ reads these instead of rebuilding them, so its BM25
 * index sees exactly the text the vectors were built from -- the same records
 * the ranker reads from providers.json / hirers.json.
 *
 * Nullable: rows embedded before this column existed have none, and
 * `php spark embed:batch` re-embeds them to fill it.
 */
class AddFieldsJsonToEmbeddings extends Migration
{
    private const TABLES = ['showcase_embeddings', 'gig_embeddings'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->forge->addColumn($table, [
                'fields_json' => ['type' => 'mediumtext', 'null' => true, 'after' => 'text_hash'],
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->forge->dropColumn($table, 'fields_json');
        }
    }
}
