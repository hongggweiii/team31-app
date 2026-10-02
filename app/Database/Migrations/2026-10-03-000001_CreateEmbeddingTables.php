<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Embedding;

/**
 * Embedding vectors for showcases (provider / document side) and gigs (query
 * side), stored in MariaDB Vector (MariaDB 11.7+) and filled offline by
 * `php spark embed:batch`. Similarity search runs in SQL via
 * App\Libraries\EmbeddingStore::nearest().
 *
 * One table per side because MariaDB allows one VECTOR INDEX per table, and
 * the indexed column must be NOT NULL, so a row exists only once embedded.
 * text_hash is the sha256 of the embedded text, so batches skip rows whose
 * text hasn't changed.
 *
 * Forge has no VECTOR type, so on MariaDB the tables are created in raw SQL.
 * Other drivers (the SQLite test database) get the same columns with the
 * vector as TEXT and no vector index.
 */
class CreateEmbeddingTables extends Migration
{
    private const TABLES = [
        'showcase_embeddings' => ['showcase_id', 'showcases'],
        'gig_embeddings'      => ['gig_id', 'gigs'],
    ];

    public function up(): void
    {
        $dimensions = config(Embedding::class)->dimensions;

        foreach (self::TABLES as $table => [$key, $parent]) {
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query(
                    "CREATE TABLE {$this->db->prefixTable($table)} (
                        {$key}     INT UNSIGNED NOT NULL PRIMARY KEY,
                        model      VARCHAR(100) NOT NULL,
                        text_hash  CHAR(64) NOT NULL,
                        embedding  VECTOR({$dimensions}) NOT NULL,
                        created_at DATETIME NULL,
                        updated_at DATETIME NULL,
                        VECTOR INDEX (embedding) M=16 DISTANCE=cosine,
                        CONSTRAINT {$table}_{$key}_fk FOREIGN KEY ({$key})
                            REFERENCES {$this->db->prefixTable($parent)} (id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                );

                continue;
            }

            $this->forge->addField([
                $key         => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
                'model'      => ['type' => 'varchar', 'constraint' => 100],
                'text_hash'  => ['type' => 'char', 'constraint' => 64],
                'embedding'  => ['type' => 'text'],
                'created_at' => ['type' => 'datetime', 'null' => true],
                'updated_at' => ['type' => 'datetime', 'null' => true],
            ]);
            $this->forge->addPrimaryKey($key);
            $this->forge->addForeignKey($key, $parent, 'id', '', 'CASCADE');
            $this->forge->createTable($table);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
