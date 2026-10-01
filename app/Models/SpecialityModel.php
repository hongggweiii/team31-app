<?php

namespace App\Models;

use CodeIgniter\Model;

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
     * Active rows as [id => name], for forms that submit ids.
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
