<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Showcases are service listings created by a logged-in user acting as a
 * Provider; hirers' gigs are matched against them. A user may have several.
 * Fields mirror the client's "create showcase" form. Repeatable sections and
 * multi-selects live in child tables. As with gigs, category / speciality ids
 * have no FK to the legacy taxonomy tables.
 */
class CreateShowcasesTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'      => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
            'name'         => ['type' => 'varchar', 'constraint' => 150],
            'photo_path'   => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
            'title'        => ['type' => 'varchar', 'constraint' => 255],
            'credentials'  => ['type' => 'text', 'null' => true],
            'headline'     => ['type' => 'varchar', 'constraint' => 255],
            'about'        => ['type' => 'text'],
            'availability' => ['type' => 'text', 'null' => true],
            'rate'         => ['type' => 'text', 'null' => true],
            'how_i_work'   => ['type' => 'text', 'null' => true],
            'status'       => ['type' => 'varchar', 'constraint' => 20, 'default' => 'published'],
            'created_at'   => ['type' => 'datetime', 'null' => true],
            'updated_at'   => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('showcases');

        // Category / specialisation multi-selects. Search tags are derived from
        // showcase_specialities as "Specialisation (Category)", not stored.
        foreach (['showcase_categories' => 'category_id', 'showcase_specialities' => 'speciality_id'] as $table => $column) {
            $this->forge->addField([
                'showcase_id' => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
                $column       => ['type' => 'int', 'constraint' => 11],
            ]);
            $this->forge->addPrimaryKey(['showcase_id', $column]);
            $this->forge->addKey($column);
            $this->forge->addForeignKey('showcase_id', 'showcases', 'id', '', 'CASCADE');
            $this->forge->createTable($table);
        }

        // Repeatable boxes, kept in the order they were entered.
        $children = [
            // "Services I offer": one service per box.
            'showcase_services' => [
                'title'       => ['type' => 'varchar', 'constraint' => 255],
                'description' => ['type' => 'text', 'null' => true],
            ],
            // "Relevant achievements": one achievement per box.
            'showcase_achievements' => [
                'description' => ['type' => 'text'],
            ],
            // "Technical proficiency": a named group of skills, skills as a JSON list.
            'showcase_skill_groups' => [
                'name'   => ['type' => 'varchar', 'constraint' => 100],
                'skills' => ['type' => 'text'],
            ],
        ];
        foreach ($children as $table => $fields) {
            $this->forge->addField(
                ['id' => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                    'showcase_id' => ['type' => 'int', 'constraint' => 11, 'unsigned' => true]]
                + $fields
                + ['sort_order' => ['type' => 'smallint', 'constraint' => 5, 'unsigned' => true, 'default' => 0]]
            );
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('showcase_id');
            $this->forge->addForeignKey('showcase_id', 'showcases', 'id', '', 'CASCADE');
            $this->forge->createTable($table);
        }

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
        foreach (['showcase_documents', 'showcase_skill_groups', 'showcase_achievements', 'showcase_services',
            'showcase_specialities', 'showcase_categories', 'showcases'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
