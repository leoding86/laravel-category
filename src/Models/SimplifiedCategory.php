<?php namespace LDing\LaravelCategory\Models;

use LDing\LaravelCategory\Models\Category;

class SimplifiedCategory
{
    public $id;

    public $name;

    /**
     * Parent model
     *
     * @var SimplifiedCategory
     */
    public $parent;

    /**
     * Children models
     *
     * @var array
     */
    public $children = [];

    public static function createFromCategory(Category $category)
    {
        $simplifiedCategory = new static;
        $simplifiedCategory->id = $category->id;
        $simplifiedCategory->name = $category->name;
        return $simplifiedCategory;
    }

    public function toJson()
    {
        $cat = $this->toArray($this);

        return json_encode($cat);
    }

    public function toArray(SimplifiedCategory $category = null)
    {
        if ($category === null) {
            $category = $this;
        }

        $cat = [
            'id' => $category->id,
            'parent_id' => $category->parent ? $category->parent->id : null,
            'name' => $category->name,
            'children' => [],
        ];

        foreach ($category->children as $childCategory) {
            $cat['children'][] = $this->toArray($childCategory);
        }

        return $cat;
    }

    /**
     * Add child category
     *
     * @param SimplifiedCategory $child
     * @return void
     */
    public function addChild(SimplifiedCategory $child)
    {
        $this->children[] = $child;
    }

    /**
     * Set parent category
     *
     * @param SimplifiedCategory $parent
     * @return void
     */
    public function setParent(SimplifiedCategory $parent)
    {
        $this->parent = $parent;
    }
}