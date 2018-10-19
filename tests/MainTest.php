<?php

namespace LDing\Tests\LaravelCategory;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use LDing\LaravelCategory\Exceptions\DifferentRelatedModelException;
use LDing\LaravelCategory\Exceptions\RemoveCategoryHasChildException;
use LDing\LaravelCategory\Models\Category;

class MainTest extends TestCase
{
    /**
     * 清空测试数据库
     *
     * @return void
     */
    public function testTruncateTables()
    {
        DB::table('categories')->truncate();
        DB::table('category_relationships')->truncate();
        Category::clearTreeCache();
    }

    public function testAddCategory()
    {
        $category = Category::createCategory('一级分类', 'App\\Collector');
        $this->assertDatabaseHas('categories', [
            'name' => '一级分类',
            'parent_id' => '0',
        ]);
    }

    public function testAddChildCategory()
    {
        $category = Category::where('name', '一级分类')->first();
        $newCategory = Category::createCategory('二级分类', 'App\\Collector', $category);
        $this->assertDatabaseHas('categories', [
            'name' => '二级分类',
            'parent_id' => $category->id
        ]);
        $this->assertEquals($newCategory->parent->id, $category->id);
        $this->assertContains($newCategory->id, $category->allChildren->pluck('id'));
    }

    public function testAppendCategory()
    {
        $level1Category = Category::where('name', '一级分类')->first();
        $level2Category = Category::where('name', '二级分类')->first();
        $level3Category = Category::createCategory('三级分类', 'App\\Collector', $level2Category);

        $this->assertEquals(1, $level2Category->getTree()->children->count());
        $this->assertContains($level3Category->id, $level1Category->allChildren->pluck('id'));
    }

    public function testMoveCategory()
    {
        $level2Category4 = Category::createCategory('二级分类4', 'App\\Collector', Category::where('name', '一级分类')->first());
        $level3Category5 = Category::createCategory('三级分类5', 'App\\Collector', $level2Category4);
        $level2Category2 = Category::where('name', '二级分类')->first();
        $level2Category2->appendCategory($level2Category4);
        $this->assertContains($level2Category4->id, $level2Category2->allChildren->pluck('id'));
        $this->assertContains($level3Category5->id, $level2Category2->allChildren->pluck('id'));
        $this->assertEquals($level2Category4->parent_id, $level2Category2->id);
    }

    public function testDifferentRelatedModelException()
    {
        $level2Category = Category::createCategory('二级分类6', 'App\\Investigation');
        $level1Category = Category::where('name', '一级分类')->first();
        try {
            $level1Category->appendCategory($level2Category);
        } catch (DifferentRelatedModelException $e) {
            $this->expectException(get_class($e));
        }
    }

    public function testRemoveCategoryHasChildException()
    {
        $level1Category = Category::where('name', '一级分类')->first();
        try {
            $level1Category->removeCategory();
        } catch (RemoveCategoryHasChildException $e) {
            $this->expectException(get_class($e));
        }
    }

    public function testRemoveCategory()
    {
        $category = Category::where('name', '三级分类5')->first();
        $category_id = $category->id;
        $category->removeCategory();
        $this->assertDatabaseMissing('categories', [
            'name' => '三级分类5',
        ]);
        $this->assertEquals(0, DB::table('category_relationships')->where('category_id', $category_id)->count());
    }

    public function testCacheTree()
    {
        $category = new Category;

        $this->assertEquals(2, $category->getFullTree()->children->count());
        $this->assertEquals(2, $category->getTree(Category::where('id', 2)->first())->children->count());
    }
}
