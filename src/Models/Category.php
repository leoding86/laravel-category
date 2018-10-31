<?php namespace LDing\LaravelCategory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use LDing\LaravelCategory\Models\SimplifiedCategory;
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
        }

        static::clearBranchesCache();

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
     * Add branch to static property $branches
     *
     * @param SimplifiedCategory $category
     * @return void
     */
    protected function addBranch(SimplifiedCategory $category)
    {
        static::$branches[$category->id] = $category;
    }

    /**
     * Check is branch with specified offset exists in static property $branches.
     *
     * @param mixed $category
     * @return boolean
     */
    protected function hasBranch($category)
    {
        return $this->retrieveBranch($category) !== null;
    }

    /**
     * Get a branch from static property $branches
     *
     * @param mixed $category
     * @return SimplifiedCategory
     */
    protected function retrieveBranch($category)
    {
        $class = __CLASS__;

        if ($category instanceof $class) {
            $offset = $category->id;
        } else {
            $offset = $category;
        }

        return isset(static::$branches[$offset]) ? static::$branches[$offset] : null;
    }

    /**
     * Cache categories tree
     *
     * @return string
     */
    public function cacheBranches()
    {
        $categories = (new static)->all()->keyBy('id');

        foreach ($categories as $category) {
            if (!$this->hasBranch($category)) {
                $this->addBranch($category->simplify());
            }

            $simplifiedCategory = $this->retrieveBranch($category);

            if ($category->parent_id > 0) {
                if (!$this->hasBranch($category->parent_id)) {
                    $this->addBranch($categories[$category->parent_id]->simplify());
                }

                $parentSimplifiedCategory = $this->retrieveBranch($category->parent_id);

                $simplifiedCategory->setParent($parentSimplifiedCategory);

                $parentSimplifiedCategory->addChild($simplifiedCategory);
            }
        }

        Cache::forever(static::CACHE_KEY, static::$branches);

        return static::$branches;
    }

    /**
     * Clear branches cache
     *
     * @return void
     */
    public static function clearBranchesCache()
    {
        Cache::forget(static::CACHE_KEY);
        static::$branches = null;
    }

    public function getBranches()
    {
        if (static::$branches) {
            return static::$branches;
        } else if (Cache::get(static::CACHE_KEY)) {
            return Cache::get(static::CACHE_KEY);
        } else {
            return $this->cacheBranches();
        }
    }

    public function getBranch($category = null)
    {
        $class = __CLASS__;

        if ($category === null) {
            $offset = $this->id;
        } else if ($category instanceof $class) {
            $offset = $category->id;
        } else {
            $offset = $category;
        }

        return $this->getBranches()[$offset];
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
            if (!$branch->parent) {
                $trees[] = $branch;
            }
        }

        return $trees;
    }

    /**
     * Get specified category branch from cache
     *
     * @param int $category_id
     * @return \StdClass
     */
    public function getBranchById($category_id)
    {
        return $this->getBranches()[$category_id];
    }

    /**
     * Simplify category model for caching categories branches, not include children or parent category,
     * Children and parent category must be setted manually.
     *
     * @param boolean $root
     * @return SimplifiedCategory
     */
    protected function simplify()
    {
        return SimplifiedCategory::createFromCategory($this);
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
