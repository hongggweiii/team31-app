<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table         = 'categories';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'url', 'meta_title', 'meta_desc', 'meta_keywords', 'image', 'description', 'user_id', 'service_id', 'status'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Active rows grouped under their service heading (Industry, Function, ...):
     * [service name => [id => name]], headings in services.id order.
     */
    public function activeByService(): array
    {
        $grouped = [];
        $rows    = $this->select('categories.id, categories.name, services.name AS service')
            ->join('services', 'services.id = categories.service_id')
            ->where('categories.status', '1')
            ->orderBy('services.id')
            ->orderBy('categories.name')
            ->findAll();
        foreach ($rows as $row) {
            $grouped[$row['service']][$row['id']] = $row['name'];
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
