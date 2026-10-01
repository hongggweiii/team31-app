<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Gigs are job postings created by a logged-in user acting as a Hirer.
 * Category / speciality links have no FK to the legacy taxonomy tables,
 * whose signed int ids would not match an unsigned FK column.
 */
class CreateGigsTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'       => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'title'         => ['type' => 'varchar', 'constraint' => 500],
            'summary'       => ['type' => 'text'],
            'notes'         => ['type' => 'text', 'null' => true],
            'document_path' => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
            'budget_min'    => ['type' => 'decimal', 'constraint' => '10,2', 'null' => true],
            'budget_max'    => ['type' => 'decimal', 'constraint' => '10,2', 'null' => true],
            'seniority'     => ['type' => 'varchar', 'constraint' => 50],
            'start_date'    => ['type' => 'date', 'null' => true],
            'status'        => ['type' => 'varchar', 'constraint' => 20, 'default' => 'open'],
            'created_at'    => ['type' => 'datetime', 'null' => true],
            'updated_at'    => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('gigs');

        foreach (['gig_categories' => 'category_id', 'gig_specialities' => 'speciality_id'] as $table => $column) {
            $this->forge->addField([
                'gig_id' => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
                $column  => ['type' => 'int', 'constraint' => 11],
            ]);
            $this->forge->addPrimaryKey(['gig_id', $column]);
            $this->forge->addKey($column);
            $this->forge->addForeignKey('gig_id', 'gigs', 'id', '', 'CASCADE');
            $this->forge->createTable($table);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('gig_specialities', true);
        $this->forge->dropTable('gig_categories', true);
        $this->forge->dropTable('gigs', true);
    }
}
