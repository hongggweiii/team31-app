<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Trims gigs to the client's "create gig" form (title, short description,
 * category, specialisation). These columns came from the ranker's synthetic
 * hirer data and are no longer collected.
 */
class DropGigExtraFields extends Migration
{
    private const COLUMNS = [
        'notes'         => ['type' => 'text', 'null' => true],
        'document_path' => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
        'budget_min'    => ['type' => 'decimal', 'constraint' => '10,2', 'null' => true],
        'budget_max'    => ['type' => 'decimal', 'constraint' => '10,2', 'null' => true],
        'seniority'     => ['type' => 'varchar', 'constraint' => 50, 'null' => true],
        'start_date'    => ['type' => 'date', 'null' => true],
    ];

    public function up(): void
    {
        // SQLite (the test DB) drops columns by renaming and rebuilding the
        // table; without legacy ALTER mode that rename repoints the FKs in
        // gig_categories / gig_specialities at the temporary table.
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }

        $this->forge->dropColumn('gigs', array_keys(self::COLUMNS));

        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = OFF');
        }
    }

    public function down(): void
    {
        $this->forge->addColumn('gigs', self::COLUMNS);
    }
}
