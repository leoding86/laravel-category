<?php namespace LDing\LaravelCategory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use LDing\LaravelCategory\Contracts\CategoryContract;
use LDing\LaravelCategory\Exceptions\DifferentRelatedModelException;
use LDing\LaravelCategory\Exceptions\EmptyRelatedModelException;
use LDing\LaravelCategory\Exceptions\RemoveCategoryHasChildException;

class Category extends Model implements CategoryContract
{
    const CACHE_KEY = 'LDING_CATEGORY_TREE_CACHE';
    protected static $tree;
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
        if ($this->related_model !== $category->related_model) {
            throw new DifferentRelatedModelException;
        }

        $category->removeParents();
        $category->parent_id = $this->id;
        $category->save();
        $this->syncChildren($category);
        $this->load(['children', 'allChildren']);
        $category->load(['parent', 'parents']);

        static::clearTreeCache();
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
            static::clearTreeCache();
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

        static::clearTreeCache();
    }

    /**
     * 缓存分类树
     *
     * @return void
     */
    public function cacheTree()
    {
        $categories = (new static)->all()->keyBy('id');
        $tree_categories = [];

        // 压入一个根分类对象
        $tree_categories[0] = $this->simplify(true);

        foreach ($categories as $category_id => $category) {
            if (!isset($tree_categories[$category->id])) {
                $tree_categories[$category->id] = $category->simplify();
            }

            // 判断是否存在父分类
            if ($category->parent_id > 0) {
                if (!isset($tree_categories[$category->parent_id])) {
                    $tree_categories[$category->parent_id] = $categories[$category->parent_id]->simplify();
                }

                $tree_categories[$category->id]->parent = $tree_categories[$category->parent_id];
                $tree_categories[$category->parent_id]->children->push($tree_categories[$category->id]);
            } else {
                $tree_categories[0]->children->push($tree_categories[$category->id]);
            }
        }

        $serialized_tree = serialize($tree_categories);
        Cache::forever(static::CACHE_KEY, $serialized_tree);

        return $serialized_tree;
    }

    /**
     * 清空分类树缓存
     *
     * @return void
     */
    public static function clearTreeCache()
    {
        Cache::forget(static::CACHE_KEY);
        static::$tree = null;
    }

    /**
     * 获得分类树
     *
     * @param CategoryContract|null $category
     * @return \StdClass
     */
    public function getTree(CategoryContract $category = null)
    {
        return $this->getTreeById($category === null ? $this->id : $category->id);
    }

    /**
     * 获得完整的分类树结构
     *
     * @return \StdClass
     */
    public function getFullTree()
    {
        return $this->getTreeById(0);
    }

    /**
     * 获得指定分类ID的树结构
     *
     * @param integer $category_id
     * @return \StdClass
     */
    protected function getTreeById($category_id = 0)
    {
        if (static::$tree == null) {
            if (!($serialized_tree = Cache::get(static::CACHE_KEY))) {
                $serialized_tree = $this->cacheTree();
            }
            static::$tree = unserialize($serialized_tree);
        }

        return static::$tree[$category_id];
    }

    /**
     * 简化分类对象
     * 用于缓存分类的树状结构，缩小序列化的长度
     *
     * @param boolean $root
     * @return \StdClass
     */
    protected function simplify($root = false)
    {
        $category = new \StdClass;
        $category->id = $root ? 0 : $this->id;
        $category->name = $root ? '' : $this->name;
        $category->parent = null;
        $category->children = collect();
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
