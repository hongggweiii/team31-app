<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Taxonomy governance, written by taxonomy-service/ (Technical Brief §7.5, §11):
 *
 *   taxonomy_proposals  terms the taxonomy doesn't have yet, from AI tag
 *                       suggestion or typed by a user, held as pending_review
 *                       for the gig / showcase they were suggested for until
 *                       they are approved (added to the sf_* tables and
 *                       tagged) or rejected (soft-deleted: deleted_at).
 *   user_notifications  messages for a user, e.g. that their pending term was
 *                       rejected.
 *
 * Column names follow the brief's taxonomy_proposals where they carry over;
 * kind / category_id / specialisation_id stand in for its tier_service_id /
 * parent_category_id on the SkillsFuture taxonomy; status, confidence, model,
 * owner_type / owner_id and deleted_at are what the deliverable adds.
 */
class CreateTaxonomyProposals extends Migration
{
    public function up(): void
    {
        $id = ['type' => 'int', 'constraint' => 11, 'unsigned' => true];

        $this->forge->addField([
            'id'                  => $id + ['auto_increment' => true],
            'proposed_name'       => ['type' => 'varchar', 'constraint' => 255],
            'kind'                => ['type' => 'varchar', 'constraint' => 20], // specialisation | skill
            'category_id'         => $id + ['null' => true], // a specialisation's category, or a skill's specialisation's
            'specialisation_id'   => $id + ['null' => true], // a skill's specialisation
            'status'              => ['type' => 'varchar', 'constraint' => 20, 'default' => 'pending_review'], // pending_review | approved | rejected
            'submitted_by_source' => ['type' => 'varchar', 'constraint' => 20], // ai_suggestion | hirer | provider
            'submitted_by_user'   => $id + ['null' => true],
            'confidence'          => ['type' => 'decimal', 'constraint' => '4,3', 'null' => true], // the model's, 0-1
            'model'               => ['type' => 'varchar', 'constraint' => 100, 'null' => true],
            'owner_type'          => ['type' => 'varchar', 'constraint' => 20, 'null' => true], // gig | showcase it was suggested for
            'owner_id'            => $id + ['null' => true],
            'auto_flag'           => ['type' => 'varchar', 'constraint' => 20, 'default' => 'none'], // none | exact | fuzzy | synonym
            'closest_match_table' => ['type' => 'varchar', 'constraint' => 50, 'null' => true], // sf_skills | sf_specialisations | taxonomy_proposals
            'closest_match_id'    => $id + ['null' => true],
            'closest_match_name'  => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
            'similarity_score'    => ['type' => 'tinyint', 'constraint' => 3, 'unsigned' => true, 'null' => true], // Levenshtein %, for exact / fuzzy
            'approved_item_id'    => $id + ['null' => true], // the sf_skills / sf_specialisations row it became
            'admin_user_id'       => $id + ['null' => true],
            'admin_reason'        => ['type' => 'text', 'null' => true],
            'created_at'          => ['type' => 'datetime', 'null' => true],
            'actioned_at'         => ['type' => 'datetime', 'null' => true],
            'deleted_at'          => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status');
        $this->forge->addKey(['owner_type', 'owner_id']);
        // Reviewed rows outlive a removed specialisation; the dashboard won't remove one with pending rows.
        $this->forge->addForeignKey('category_id', 'sf_categories', 'id', '', 'SET NULL');
        $this->forge->addForeignKey('specialisation_id', 'sf_specialisations', 'id', '', 'SET NULL');
        $this->forge->createTable('taxonomy_proposals');

        // No FK on user_id: a caller-supplied user id must never make a rejection fail.
        $this->forge->addField([
            'id'         => $id + ['auto_increment' => true],
            'user_id'    => $id,
            'type'       => ['type' => 'varchar', 'constraint' => 50],
            'message'    => ['type' => 'text'],
            'data'       => ['type' => 'text', 'null' => true], // JSON
            'read_at'    => ['type' => 'datetime', 'null' => true],
            'created_at' => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->createTable('user_notifications');
    }

    public function down(): void
    {
        $this->forge->dropTable('user_notifications', true);
        $this->forge->dropTable('taxonomy_proposals', true);
    }
}
