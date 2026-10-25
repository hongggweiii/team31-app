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

    /**
     * The gig's categories and specialisations in the ranker's taxonomy shape
     * (hirers.json `tags.categories`), for the matcher request. Search tags are
     * "<specialisation> (<category>)", as the app labels them; the app has no
     * category groups or skills, so those are null / empty.
     *
     * @return list<array{name: string, group: null, specialisations: list<array{name: string, search_tag: string, skills: list<string>}>}>
     */
    public function rankerTaxonomy(int $gigId): array
    {
        $tree = [];
        $categories = $this->db->table('gig_categories gc')->select('c.name')
            ->join('categories c', 'c.id = gc.category_id')->where('gc.gig_id', $gigId)->get()->getResultArray();
        foreach ($categories as $row) {
            $tree[$row['name']] ??= [];
        }
        $specialities = $this->db->table('gig_specialities gs')->select('s.name AS speciality, c.name AS category')
            ->join('specialities s', 's.id = gs.speciality_id')->join('categories c', 'c.id = s.category_id')
            ->where('gs.gig_id', $gigId)->get()->getResultArray();
        foreach ($specialities as $row) {
            $tree[$row['category']][] = $row['speciality'];
        }
        ksort($tree);

        $taxonomy = [];
        foreach ($tree as $category => $names) {
            $names = array_values(array_unique($names));
            sort($names);
            $taxonomy[] = [
                'name'            => $category,
                'group'           => null,
                'specialisations' => array_map(static fn (string $name): array => [
                    'name' => $name, 'search_tag' => "{$name} ({$category})", 'skills' => [],
                ], $names),
            ];
        }

        return $taxonomy;
    }
}
