<?php
// app/Models/CategoryModel.php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table = 'product_categories';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $protectFields = true;
    protected $allowedFields = ['name', 'parent_id', 'description'];
    
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
    
    // Récupérer l'arbre des catégories
    public function getCategoriesTree()
    {
        $categories = $this->where('deleted_at', null)
            ->orderBy('name', 'ASC')
            ->findAll();
        
        $tree = $this->buildTree($categories);
        
        return [
            'data' => $categories,
            'tree' => $tree
        ];
    }
    
    private function buildTree($categories, $parentId = null)
    {
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = $this->buildTree($categories, $category['id']);
                if ($children) {
                    $category['children'] = $children;
                }
                $tree[] = $category;
            }
        }
        return $tree;
    }
    
    // Obtenir le chemin complet d'une catégorie
    public function getCategoryPath($id)
    {
        $path = [];
        $category = $this->find($id);
        
        while ($category) {
            array_unshift($path, $category['name']);
            $category = $this->find($category['parent_id']);
        }
        
        return implode(' > ', $path);
    }
}