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
