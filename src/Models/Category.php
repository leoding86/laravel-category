<?php namespace LDing\LaravelCategory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use LDing\LaravelCategory\Contracts\CategoryContract;
use LDing\LaravelCategory\Exceptions\DifferentRelatedModelException;
use LDing\LaravelCategory\Exceptions\EmptyRelatedModelException;
use LDing\LaravelCategory\Exceptions\RemoveCategoryHasChildException;
use LDing\LaravelCategory\Exceptions\AppendSelfException;
use LDing\LaravelCategory\Exceptions\AppendRootException;

class Category extends Model implements CategoryContract
{
    const CACHE_KEY = 'LDING_CATEGORIES_BRANCHES_CACHE';
    protected static $branches;
    protected $dateFormat = 'U';
    protected $fillable = ['name', 'parent_id'];

    /**
     * 获得当前分类的父分类关系
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(__CLASS__, 'parent_id');
    }

    /**
     * 获得当前分类的所有父分类关系
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function parents()
    {
        return $this->belongsToMany(__CLASS__, 'category_relationships', 'category_id', 'parents_category_id');
    }

    /**
     * 获得当前分类的所有子分类关系
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelgonsToMany
     */
    public function allChildren()
    {
        return $this->belongsToMany(__CLASS__, 'category_relationships', 'parents_category_id', 'category_id');
    }

    /**
     * 获得当前分类的直接子分类
     *
     * @return \Illuminate\Database\Eloquent\Relatons\HasMany
     */
    public function children()
    {
        return $this->hasMany(__CLASS__, 'parent_id');
    }

    /**
     * 移除父分类关系
     *
     * @param CategoryContract $parentCategory
     * @return void
     */
    protected function removeParents()
    {
        $parent_ids = $this->parents()->get()->pluck('id');
        $allChildren = $this->allChildren()->get()->prepend($this);
        foreach ($allChildren as $child) {
            $child->parents()->detach($parent_ids);
        }
    }

    /**
     * 添加子分类关系
     *
     * @param CategoryContract $childCategory
     * @return void
     */
    protected function syncChildren(CategoryContract $childCategory)
    {
        $allChildren = $childCategory->allChildren()->get()->prepend($childCategory);
        $children_ids = $allChildren->pluck('id');
        foreach ($this->parents()->get()->push($this) as $parent) {
            $parent->allChildren()->attach($children_ids);
        }
    }

    /**
     * 给当前分类添加一个子分类
     *
     * @param CategoryContract $category
     * @return void
     */
    public function appendCategory(CategoryContract $category)
    {
        if ($category->id == 1) {
            throw new appendRootException;
        }

        if ($this->related_model !== $category->related_model) {
            throw new DifferentRelatedModelException;
        }

        if ($this->id == $category->id) {
            throw new AppendSelfException;
        }

        $category->removeParents();
        $category->parent_id = $this->id;
        $category->save();
        $this->syncChildren($category);
        $this->load(['children', 'allChildren']);
        $category->load(['parent', 'parents']);

        static::clearBranchesCache();
    }

    /**
     * Create a root category, a helper method
     *
     * @param string $name
     * @param string $related_model
     * @return Category
     */
    public static function createRootCategory($name, $related_model = null)
    {
        return static::createCategory($name, $related_model);
    }

    /**
     * Create a sub-category, a helper method
     *
     * @param string $name
     * @param CategoryContract $parentCategory
     * @return Category
     */
    public static function createSubCategory($name, CategoryContract $parentCategory)
    {
        return static::createCategory($name, null, $parentCategory);
    }

    /**
     * Create a category, it should be called in a transaction
     *
     * @param string $name
     * @param string $related_model
     * @param CategoryContract $parentCategory
     * @return $this
     */
    public static function createCategory($name, $related_model = null, CategoryContract $parentCategory = null)
    {
        if ($parentCategory !== null) {
            if ($related_model !== $parentCategory->related_model) {
                throw new DifferentRelatedModelException;
            }

            $related_model = $parentCategory->related_model;
            $parent_id = $parentCategory->id;
        } else {
            $parent_id = 0;
        }

        $category = new static;
        $category->name = $name;
        $category->parent_id = $parent_id;

        if ($related_model) {
            $category->related_model = $related_model;
        }

        $category->save();

        if ($parentCategory !== null) {
            $parentCategory->appendCategory($category);
        } else {
            static::clearBranchesCache();
        }

        return $category;
    }

    /**
     * 删除当前分类
     *
     * @return void
     */
    public function removeCategory()
    {
        if ($this->children && $this->children->count() > 0) {
            throw new RemoveCategoryHasChildException;
        }

        $this->parents()->detach(); // 移除当前分类的父关系

        $this->delete(); // 删除当前分类

        static::clearBranchesCache();
    }

    /**
     * Cache categories tree
     *
     * @return string
     */
    public function cacheBranches()
    {
        $categories = (new static)->all();
        $branches = [];

        foreach ($categories as $category) {
            if (!isset($branches[$category->id])) {
                $branches[$category->id] = $category->simplify();
            }

            if ($category->parent_id > 0) {
                if (!isset($branches[$category->parent_id])) {
                    $branches[$category->parent_id] = $categories[$category->parent_id]->simplify();
                }

                $branches[$category->id]->parent = $branches[$category->parent_id];
                $branches[$category->parent_id]->children[] = $branches[$category->id];
            }
        }

        $serialized_branches = serialize($branches);
        Cache::forever(static::CACHE_KEY, $serialized_branches);

        return $serialized_branches;
    }

    public function getBranches()
    {
        if (static::$branches) {
            return static::$branches;
        } else if (Cache::get(static::CACHE_KEY)) {
            return unserialize(Cache::get(static::CACHE_KEY));
        } else {
            return unserialize($this->cacheBranches());
        }
    }

    /**
     * 清空分类树缓存
     *
     * @return void
     */
    public static function clearBranchesCache()
    {
        Cache::forget(static::CACHE_KEY);
        static::$trees = null;
    }

    /**
     * Get specified category tree
     *
     * @param CategoryContract|null $category
     * @return \StdClass
     */
    public function getTree(CategoryContract $category = null)
    {
        return $this->getTreeById($category === null ? $this->id : $category->id);
    }

    /**
     * Get all categories tree
     *
     * @return array
     */
    public function getTrees()
    {
        $branches = $this->getBranches();
        $trees = [];

        foreach ($branches as $branch) {
            if ($branch->parent_id == 0) {
                $trees[] = $branch;
            }
        }

        return $trees;
    }

    /**
     * Get specified category branch
     *
     * @param int $category_id
     * @return \StdClass
     */
    public function getBranchById($category_id)
    {
        return $this->getBranches()[$category_id];
    }

    /**
     * 获得指定分类ID的树结构
     *
     * @param integer $category_id
     * @return \StdClass
     */
    public function getTreeById($category_id)
    {
        $tree = $this->getBranchById($category_id);

        if ($tree->parent_id != 0) {
            return null;
        }

        return $tree;
    }

    /**
     * 简化分类对象
     * 用于缓存分类的树状结构，缩小序列化的长度
     *
     * @param boolean $root
     * @return \StdClass
     */
    protected function simplify()
    {
        $category = new \StdClass;
        $category->id = $this->id;
        $category->name = $this->name;
        $category->parent = null;
        $category->children = [];
        return $category;
    }

    /**
     * 设置当前分类关联的模型类型
     *
     * @param mixed $model
     * @return void
     */
    public function setRelatedModel($model)
    {
        if (is_string($model)) {
            $this->related_model = $model;
        } else if (is_object($model)) {
            $this->related_model = get_class($model);
        }

        return $this;
    }
}
