<?php

namespace App\Models;

use CodeIgniter\Model;

class GigModel extends Model
{
    protected $table         = 'gigs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['user_id', 'title', 'summary', 'status'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'title'   => 'required|max_length[500]',
        'summary' => 'required',
    ];

    /**
     * Inserts a gig with its category and speciality links in one transaction.
     *
     * @return int|false New gig id, or false if any insert failed.
     */
    public function createWithTaxonomy(array $gig, array $categoryIds, array $specialityIds): int|false
    {
        $this->db->transStart();

        $gigId = $this->insert($gig);

        if ($gigId !== false) {
            $link = static fn (string $column, array $ids): array => array_map(
                static fn ($id): array => ['gig_id' => $gigId, $column => (int) $id],
                array_unique($ids)
            );
            $this->db->table('gig_categories')->insertBatch($link('category_id', $categoryIds));
            $this->db->table('gig_specialities')->insertBatch($link('speciality_id', $specialityIds));
        }

        $this->db->transComplete();

        return $this->db->transStatus() && $gigId !== false ? (int) $gigId : false;
    }
}
