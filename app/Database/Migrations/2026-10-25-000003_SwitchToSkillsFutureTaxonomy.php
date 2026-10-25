<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Switches gigs and showcases from the client's legacy categories /
 * specialities to the SkillsFuture taxonomy (sf_v1) that the Data team's
 * records and the ranker use: category (sector) -> specialisation (track)
 * -> skills. The taxonomy itself is loaded by `php spark taxonomy:import`.
 *
 * A gig or showcase stores its specialisations and, per specialisation, its
 * skills; its categories are the parents of its specialisations (schema v2
 * requires every category to carry at least one specialisation). Composite
 * FKs keep each skill under one of the owner's specialisations, and each
 * skill linked to that specialisation in the taxonomy.
 *
 * The old link tables are dropped with their rows: their ids point at the
 * legacy taxonomy and can't be carried over. The legacy tables themselves
 * (categories, specialities, ...) are left alone.
 */
class SwitchToSkillsFutureTaxonomy extends Migration
{
    private const OWNERS = ['gig' => 'gigs', 'showcase' => 'showcases'];

    public function up(): void
    {
        $id = ['type' => 'int', 'constraint' => 11, 'unsigned' => true];

        $this->forge->addField([
            'id'         => $id + ['auto_increment' => true],
            'name'       => ['type' => 'varchar', 'constraint' => 150],
            'group_name' => ['type' => 'varchar', 'constraint' => 20, 'null' => true], // the record's group: Industry / Function / Subject / Others
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('sf_categories');

        $this->forge->addField([
            'id'          => $id + ['auto_increment' => true],
            'category_id' => $id,
            'name'        => ['type' => 'varchar', 'constraint' => 150],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['category_id', 'name']); // a few track names recur under several sectors
        $this->forge->addForeignKey('category_id', 'sf_categories', 'id', '', 'CASCADE');
        $this->forge->createTable('sf_specialisations');

        $this->forge->addField([
            'id'   => $id + ['auto_increment' => true],
            'name' => ['type' => 'varchar', 'constraint' => 255],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('sf_skills');

        $this->forge->addField(['specialisation_id' => $id, 'skill_id' => $id]);
        $this->forge->addPrimaryKey(['specialisation_id', 'skill_id']);
        $this->forge->addKey('skill_id');
        $this->forge->addForeignKey('specialisation_id', 'sf_specialisations', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('skill_id', 'sf_skills', 'id', '', 'CASCADE');
        $this->forge->createTable('sf_specialisation_skills');

        foreach (self::OWNERS as $owner => $ownerTable) {
            $this->forge->dropTable("{$owner}_specialities", true);
            $this->forge->dropTable("{$owner}_categories", true);

            $this->forge->addField(["{$owner}_id" => $id, 'specialisation_id' => $id]);
            $this->forge->addPrimaryKey(["{$owner}_id", 'specialisation_id']);
            $this->forge->addKey('specialisation_id');
            $this->forge->addForeignKey("{$owner}_id", $ownerTable, 'id', '', 'CASCADE');
            $this->forge->addForeignKey('specialisation_id', 'sf_specialisations', 'id', '', 'CASCADE');
            $this->forge->createTable("{$owner}_specialisations");

            $this->forge->addField(["{$owner}_id" => $id, 'specialisation_id' => $id, 'skill_id' => $id]);
            $this->forge->addPrimaryKey(["{$owner}_id", 'specialisation_id', 'skill_id']);
            $this->forge->addKey(['specialisation_id', 'skill_id']);
            $this->forge->addForeignKey(["{$owner}_id", 'specialisation_id'], "{$owner}_specialisations", ["{$owner}_id", 'specialisation_id'], '', 'CASCADE');
            $this->forge->addForeignKey(['specialisation_id', 'skill_id'], 'sf_specialisation_skills', ['specialisation_id', 'skill_id'], '', 'CASCADE');
            $this->forge->createTable("{$owner}_skills");
        }
    }

    public function down(): void
    {
        foreach (self::OWNERS as $owner => $ownerTable) {
            $this->forge->dropTable("{$owner}_skills", true);
            $this->forge->dropTable("{$owner}_specialisations", true);

            // As CreateGigsTables / CreateShowcasesTables made them (empty).
            foreach (["{$owner}_categories" => 'category_id', "{$owner}_specialities" => 'speciality_id'] as $table => $column) {
                $this->forge->addField([
                    "{$owner}_id" => ['type' => 'int', 'constraint' => 11, 'unsigned' => true],
                    $column       => ['type' => 'int', 'constraint' => 11],
                ]);
                $this->forge->addPrimaryKey(["{$owner}_id", $column]);
                $this->forge->addKey($column);
                $this->forge->addForeignKey("{$owner}_id", $ownerTable, 'id', '', 'CASCADE');
                $this->forge->createTable($table);
            }
        }

        foreach (['sf_specialisation_skills', 'sf_skills', 'sf_specialisations', 'sf_categories'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
