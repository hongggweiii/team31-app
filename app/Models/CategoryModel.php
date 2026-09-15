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

    public function activeNames(): array
    {
        return array_column(
            $this->select('name')->where('status', '1')->orderBy('name')->findAll(),
            'name'
        );
    }
}
