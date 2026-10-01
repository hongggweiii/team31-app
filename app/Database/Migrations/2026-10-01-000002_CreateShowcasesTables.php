<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Showcases are service listings created by a logged-in user acting as a
 * Provider; hirers' gigs are matched against them. A user may have several.
 * As with gigs, category / speciality ids have no FK to the legacy taxonomy
 * tables (signed int ids).
 */
class CreateShowcasesTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'               => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'category_id'           => ['type' => 'int', 'constraint' => 11],
            'speciality_id'         => ['type' => 'int', 'constraint' => 11, 'null' => true],
            'title'                 => ['type' => 'varchar', 'constraint' => 255],
            'years_experience'      => ['type' => 'tinyint', 'constraint' => 3, 'unsigned' => true],
            'seniority'             => ['type' => 'varchar', 'constraint' => 10],
            'credentials'           => ['type' => 'text'],
            'relevant_achievements' => ['type' => 'text', 'null' => true],
            'headline'              => ['type' => 'varchar', 'constraint' => 255],
            'about'                 => ['type' => 'text'],
            'how_i_work'            => ['type' => 'text'],
            'locations'             => ['type' => 'text'],
            'available_now'         => ['type' => 'tinyint', 'constraint' => 1, 'default' => 0],
            'available_from'        => ['type' => 'date', 'null' => true],
            'capacity'              => ['type' => 'tinyint', 'constraint' => 1, 'unsigned' => true],
            'availability_notes'    => ['type' => 'text', 'null' => true],
            'rate_per_hour'         => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'rate_notes'            => ['type' => 'text', 'null' => true],
            'search_tags'           => ['type' => 'text', 'null' => true],
            'photo_path'            => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
            'status'                => ['type' => 'varchar', 'constraint' => 20, 'default' => 'published'],
            'created_at'            => ['type' => 'datetime', 'null' => true],
            'updated_at'            => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('category_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('showcases');

        // "Services I offer" boxes, in the order they were entered.
        $this->forge->addField([
            'id'          => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'showcase_id' => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'title'       => ['type' => 'varchar', 'constraint' => 255],
            'description' => ['type' => 'text', 'null' => true],
            'sort_order'  => ['type' => 'smallint', 'constraint' => 5, 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('showcase_id');
        $this->forge->addForeignKey('showcase_id', 'showcases', 'id', '', 'CASCADE');
        $this->forge->createTable('showcase_services');

        // "Links" uploads, one row per file, tagged with its link category.
        $this->forge->addField([
            'id'            => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'showcase_id'   => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'category'      => ['type' => 'varchar', 'constraint' => 30],
            'path'          => ['type' => 'varchar', 'constraint' => 255],
            'original_name' => ['type' => 'varchar', 'constraint' => 255],
            'created_at'    => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('showcase_id');
        $this->forge->addForeignKey('showcase_id', 'showcases', 'id', '', 'CASCADE');
        $this->forge->createTable('showcase_documents');
    }

    public function down(): void
    {
        $this->forge->dropTable('showcase_documents', true);
        $this->forge->dropTable('showcase_services', true);
        $this->forge->dropTable('showcases', true);
    }
}
