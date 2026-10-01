<?php

namespace App\Models;

use CodeIgniter\Model;

class ShowcaseModel extends Model
{
    public const LOCATIONS = [
        'Virtual Only', 'Anywhere',
        'Raffles Place, Marina, Cecil', 'Anson, Tanjong Pagar', 'Tiong Bahru, Queenstown',
        'Telok Blangah, Harbourfront', 'High Street, Beach Road', 'Little India',
        'Orchard, Cairnhill, River Valley', 'Bukit Timah, Holland Road, Tanglin',
        'Novena, Thomson', 'Toa Payoh, Serangoon, Balestier',
    ];

    /** "Links" upload categories: key => label. */
    public const DOCUMENT_CATEGORIES = [
        'completed_projects' => 'Completed Projects',
        'testimonials'       => 'Testimonials',
        'certifications'     => 'Certifications',
        'portfolio'          => 'Portfolio',
        'others'             => 'Others',
    ];

    protected $table         = 'showcases';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'category_id', 'speciality_id', 'title', 'years_experience', 'seniority',
        'credentials', 'relevant_achievements', 'headline', 'about', 'how_i_work', 'locations',
        'available_now', 'available_from', 'capacity', 'availability_notes', 'rate_per_hour',
        'rate_notes', 'search_tags', 'photo_path', 'status',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected array $casts = [
        'locations'     => 'json-array',
        'search_tags'   => '?json-array',
        'available_now' => 'boolean',
    ];

    protected $validationRules = [
        'user_id'          => 'required|is_natural_no_zero',
        'category_id'      => 'required|is_natural_no_zero',
        'title'            => 'required|max_length[255]',
        'years_experience' => 'required|is_natural|less_than_equal_to[70]',
        'seniority'        => 'required|in_list[mid,senior,expert]',
        'credentials'      => 'required',
        'headline'         => 'required|max_length[255]',
        'about'            => 'required',
        'how_i_work'       => 'required',
        'available_from'   => 'permit_empty|valid_date[Y-m-d]',
        'capacity'         => 'required|is_natural_no_zero|less_than_equal_to[5]',
        'rate_per_hour'    => 'required|is_natural_no_zero',
    ];

    /**
     * Inserts a showcase with its services and documents in one transaction.
     *
     * @param list<array{title: string, description: ?string}>                $services
     * @param list<array{category: string, path: string, original_name: string}> $documents
     *
     * @return int|false New showcase id, or false if any insert failed.
     */
    public function createWithChildren(array $showcase, array $services, array $documents): int|false
    {
        $this->db->transStart();

        $showcaseId = $this->insert($showcase);

        if ($showcaseId !== false) {
            $rows = [];
            foreach (array_values($services) as $i => $service) {
                $rows[] = ['showcase_id' => $showcaseId, 'sort_order' => $i] + $service;
            }
            $this->db->table('showcase_services')->insertBatch($rows);

            if ($documents !== []) {
                $now = date('Y-m-d H:i:s');
                $this->db->table('showcase_documents')->insertBatch(array_map(
                    static fn (array $doc): array => ['showcase_id' => $showcaseId, 'created_at' => $now] + $doc,
                    $documents
                ));
            }
        }

        $this->db->transComplete();

        return $this->db->transStatus() && $showcaseId !== false ? (int) $showcaseId : false;
    }
}
