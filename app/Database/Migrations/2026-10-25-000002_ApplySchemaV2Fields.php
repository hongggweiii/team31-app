<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Brings showcases and gigs in line with the Data team's record schema v2
 * (ML_provider_schema_v2.json / ML_hirer_schema_v2.json), field for field
 * except the taxonomy:
 *
 *   showcases.years_experience    provider years_experience (0-60, nullable)
 *   showcase_credentials          provider credentials: a list of up to 6
 *                                 strings, replacing the rich-text column
 *   gigs.duration_weeks_min/max   hirer duration_weeks_min / _max
 *   gigs.additional_notes         hirer additional_notes
 *
 * The old credentials text is not carried over: existing showcases predate
 * schema v2 and are cleared separately.
 */
class ApplySchemaV2Fields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('showcases', [
            'years_experience' => ['type' => 'tinyint', 'constraint' => 3, 'unsigned' => true, 'null' => true, 'after' => 'title'],
        ]);

        $this->forge->addField([
            'id'          => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'showcase_id' => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'description' => ['type' => 'varchar', 'constraint' => 255],
            'sort_order'  => ['type' => 'smallint', 'constraint' => 5, 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('showcase_id');
        $this->forge->addForeignKey('showcase_id', 'showcases', 'id', '', 'CASCADE');
        $this->forge->createTable('showcase_credentials');

        $this->withLegacyAlter(fn () => $this->forge->dropColumn('showcases', 'credentials'));

        $this->forge->addColumn('gigs', [
            'duration_weeks_min' => ['type' => 'tinyint', 'constraint' => 3, 'unsigned' => true, 'null' => true, 'after' => 'summary'],
            'duration_weeks_max' => ['type' => 'tinyint', 'constraint' => 3, 'unsigned' => true, 'null' => true, 'after' => 'duration_weeks_min'],
            'additional_notes'   => ['type' => 'text', 'null' => true, 'after' => 'duration_weeks_max'],
        ]);
    }

    public function down(): void
    {
        $this->withLegacyAlter(function (): void {
            $this->forge->dropColumn('gigs', ['duration_weeks_min', 'duration_weeks_max', 'additional_notes']);
            $this->forge->dropColumn('showcases', 'years_experience');
        });
        $this->forge->addColumn('showcases', [
            'credentials' => ['type' => 'text', 'null' => true, 'after' => 'title'],
        ]);
        $this->forge->dropTable('showcase_credentials', true);
    }

    /**
     * SQLite (the test DB) drops columns by rebuilding the table; legacy ALTER
     * mode keeps the child tables' FKs pointing at the real table (see
     * DropGigExtraFields).
     */
    private function withLegacyAlter(callable $alter): void
    {
        $sqlite = $this->db->DBDriver === 'SQLite3';
        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }

        $alter();

        if ($sqlite) {
            $this->db->query('PRAGMA legacy_alter_table = OFF');
        }
    }
}
