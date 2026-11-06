<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The client's legacy `specialities` (under `categories`). Unused since the
 * switch to the SkillsFuture taxonomy; kept until the legacy tables are dropped.
 */
class SpecialityModel extends Model
{
    protected $table         = 'specialities';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'url', 'meta_title', 'meta_desc', 'meta_keywords', 'image', 'description', 'category_id', 'status'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Active rows grouped by parent category: [category_id => [id => name]].
     *
     * @return array<int, array<int, string>>
     */
    public function activeByCategory(): array
    {
        $grouped = [];
        foreach ($this->select('id, name, category_id')->where('status', '1')->orderBy('name')->findAll() as $row) {
            $grouped[$row['category_id']][$row['id']] = $row['name'];
        }

        return $grouped;
    }

    /**
     * Active rows as [speciality id => its category id].
     *
     * @return array<int, int>
     */
    public function activeCategoryMap(): array
    {
        return array_column(
            $this->select('id, category_id')->where('status', '1')->findAll(),
            'category_id',
            'id'
        );
    }

    /**
     * Search tags for the given specialities: "Specialisation (Category)",
     * e.g. "Investor Reporting (Asset/Wealth Management)".
     *
     * @param list<int|string> $ids Speciality ids
     *
     * @return list<string> Sorted by category, then speciality
     */
    public function searchTags(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->select('specialities.name, categories.name AS category')
            ->join('categories', 'categories.id = specialities.category_id')
            ->whereIn('specialities.id', array_map('intval', $ids))
            ->orderBy('categories.name')
            ->orderBy('specialities.name')
            ->findAll();

        return array_map(static fn (array $row): string => "{$row['name']} ({$row['category']})", $rows);
    }

    /**
     * Active rows as [id => name], for forms that submit ids.
     *
     * @return array<int, string> Sorted by name
     */
    public function activeOptions(): array
    {
        return array_column(
            $this->select('id, name')->where('status', '1')->orderBy('name')->findAll(),
            'name',
            'id'
        );
    }
}
