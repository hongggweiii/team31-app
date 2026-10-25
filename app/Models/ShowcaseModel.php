<?php

namespace App\Models;

use App\Libraries\Taxonomy;
use CodeIgniter\Model;

class ShowcaseModel extends Model
{
    /** "Links" upload categories: key => label. */
    public const DOCUMENT_CATEGORIES = [
        'completed_projects' => 'Completed Projects',
        'testimonials'       => 'Testimonials',
        'certifications'     => 'Certifications',
        'portfolio'          => 'Portfolio',
        'others'             => 'Others',
    ];

    /**
     * Most entries per repeatable section, from the Data team's schema v2
     * (ML_provider_schema_v2.json). skills is per technical proficiency group.
     */
    public const LIMITS = [
        'credentials'  => 6,
        'services'     => 3,
        'achievements' => 5,
        'skill_groups' => 4,
        'skills'       => 8,
    ];

    protected $table         = 'showcases';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'user_id', 'name', 'photo_path', 'title', 'years_experience', 'headline', 'about',
        'availability', 'rate', 'how_i_work', 'status',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'user_id'  => 'required|is_natural_no_zero',
        'name'     => 'required|max_length[150]',
        'title'    => 'required|max_length[255]',
        'headline' => 'required|max_length[255]',
        'about'    => 'required',
    ];

    /**
     * Inserts a showcase and all of its child rows in one transaction.
     *
     * @param array{
     *     credentials?: list<string>,
     *     taxonomy?: array{specialisations: list<int>, skills: array<int, list<int>>},
     *     services: list<array{title: string, description: ?string}>,
     *     achievements: list<string>,
     *     skill_groups: list<array{name: string, skills: list<string>}>,
     *     documents: list<array{category: string, path: string, original_name: string}>,
     * } $children
     *
     * @return int|false New showcase id, or false if any insert failed.
     */
    public function createWithChildren(array $showcase, array $children): int|false
    {
        $this->db->transStart();

        $id = $this->insert($showcase);

        if ($id !== false) {
            $rows = [
                'showcase_credentials'  => self::ordered(array_map(static fn (string $c): array => ['description' => $c], $children['credentials'] ?? [])),
                'showcase_services'     => self::ordered($children['services']),
                'showcase_achievements' => self::ordered(array_map(static fn (string $a): array => ['description' => $a], $children['achievements'])),
                'showcase_skill_groups' => self::ordered(array_map(static fn (array $g): array => [
                    'name'   => $g['name'],
                    'skills' => json_encode(array_values($g['skills'])),
                ], $children['skill_groups'])),
                'showcase_documents' => array_map(static fn (array $d): array => $d + ['created_at' => date('Y-m-d H:i:s')], $children['documents']),
            ];
            foreach ($rows as $table => $tableRows) {
                if ($tableRows !== []) {
                    $this->db->table($table)->insertBatch(array_map(static fn (array $r): array => ['showcase_id' => $id] + $r, $tableRows));
                }
            }
            (new Taxonomy($this->db))->save('showcase', (int) $id, $children['taxonomy'] ?? ['specialisations' => [], 'skills' => []]);
        }

        $this->db->transComplete();

        return $this->db->transStatus() && $id !== false ? (int) $id : false;
    }

    /**
     * Adds sort_order to repeatable-box rows, in the order given.
     */
    private static function ordered(array $rows): array
    {
        return array_map(static fn (array $row, int $i): array => $row + ['sort_order' => $i], array_values($rows), array_keys(array_values($rows)));
    }
}
