<?php

namespace App\Models;

use App\Libraries\Taxonomy;
use CodeIgniter\Model;

/**
 * Gigs (hirers' job posts). Tags live in gig_specialisations / gig_skills,
 * written through App\Libraries\Taxonomy.
 */
class GigModel extends Model
{
    protected $table         = 'gigs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'title', 'summary', 'duration_weeks_min', 'duration_weeks_max', 'additional_notes', 'status',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'title'   => 'required|max_length[500]',
        'summary' => 'required',
    ];

    /**
     * Inserts a gig with its taxonomy (specialisations and skills) in one
     * transaction.
     *
     * @param array<string, mixed>                                            $gig      gigs columns (user_id, title, summary, ...)
     * @param array{specialisations: list<int>, skills: array<int, list<int>>} $taxonomy Taxonomy::check()'s selection
     *
     * @return int|false New gig id, or false if any insert failed (nothing is kept then)
     */
    public function createWithTaxonomy(array $gig, array $taxonomy): int|false
    {
        $this->db->transStart();

        $gigId = $this->insert($gig); // false when the model's validation rules fail

        if ($gigId !== false) {
            (new Taxonomy($this->db))->save('gig', (int) $gigId, $taxonomy);
        }

        $this->db->transComplete();

        return $this->db->transStatus() && $gigId !== false ? (int) $gigId : false;
    }

    /**
     * The gig's tags in the records' shape (hirers.json `tags.categories`), for
     * the matcher request's `taxonomy`.
     *
     * @param int $gigId An existing gig
     *
     * @return list<array{name: string, group: string|null, specialisations: list<array{name: string, search_tag: string, skills: list<string>}>}>
     */
    public function rankerTaxonomy(int $gigId): array
    {
        return (new Taxonomy($this->db))->tags('gig', [$gigId])[$gigId] ?? [];
    }
}
