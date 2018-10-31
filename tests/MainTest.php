<?php

namespace LDing\Tests\LaravelCategory;

use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container as Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use LDing\LaravelCategory\Exceptions\DifferentRelatedModelException;
use LDing\LaravelCategory\Exceptions\RemoveCategoryHasChildException;
use LDing\LaravelCategory\Exceptions\AppendSelfException;
use LDing\LaravelCategory\Models\Category;

class MainTest extends TestCase
{
    protected static $app;

    public function setUp()
    {
        static::$app = new Application;
        Application::setInstance(static::$app);
        Facade::setFacadeApplication(static::$app);

        static::$app->singleton('config', function () {
            return [
                'path.storage' => __DIR__ . '/storage/cache',
                'cache.default' => 'file',
                'cache.stores.file' => [
                    'driver' => 'file',
                    'path' => __DIR__ . '/storage/cache',
                ]
            ];
        });

        static::$app->singleton('db', function ($app) {
            $capsule = new Capsule;
            $capsule->addConnection([
                'driver'    => 'mysql',
                'host'      => '127.0.0.1',
                'database'  => 'test',
                'username'  => 'root',
                'password'  => '19860807',
                'charset'   => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix'    => '',
            ]);
    
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            return $capsule;
        });

        static::$app->singleton('files', function () {
            return new Filesystem;
        });

        static::$app->singleton('cache', function ($app) {
            $cacheManager = new CacheManager($app);
            return $cacheManager->driver();
        });
    }

    /**
     * 清空测试数据库
     *
     * @return void
     */
    public function testTruncateTables()
    {
        DB::table('categories')->truncate();
        DB::table('category_relationships')->truncate();
        Category::clearBranchesCache();
    }

    public function testAddCategory()
    {
        $category = Category::createCategory('一级分类', 'App\\Collector');
        $count = DB::table('categories')->where('name', '一级分类')->where('parent_id', 0)->count();

        $this->assertEquals(1, $count);
    }

    public function testAddChildCategory()
    {
        $category = Category::where('name', '一级分类')->first();
        $newCategory = Category::createCategory('二级分类', 'App\\Collector', $category);
        $this->assertEquals(
            1,
            DB::table('categories')->where('name', '二级分类')->where('parent_id', $category->id)->count()
        );
        $this->assertEquals($newCategory->parent->id, $category->id);
        $this->assertContains($newCategory->id, $category->allChildren->pluck('id'));
    }

    public function testAppendCategory()
    {
        $level1Category = Category::where('name', '一级分类')->first();
        $level2Category = Category::where('name', '二级分类')->first();
        $level3Category = Category::createCategory('三级分类', 'App\\Collector', $level2Category);

        $this->assertEquals(1, count($level2Category->getBranch()->children));
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

    public function testAppendSelfException()
    {
        $level2Category = Category::createCategory('二级分类6', 'App\\Investigation');

        try {
            $level2Category->appendCategory($level2Category);
        } catch (AppendSelfException $e) {
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
        $this->assertEquals(0,
            DB::table('categories')->where('name', '三级分类5')->count()
        );
        $this->assertEquals(0,
            DB::table('category_relationships')->where('category_id', $category_id)->count()
        );
    }

    public function testCacheTree()
    {
        $category = new Category;

        $this->assertEquals(1, count($category->getBranch(1)->children));
        $this->assertEquals(2, count($category->getBranch(Category::where('id', 2)->first())->children));
    }

    public function testTreesCount()
    {
        $category = Category::createRootCategory('Root Category 2');
        $this->assertEquals(4, count($category->getTrees()));
    }

    public function testCreateNullableRelatedModelCategory()
    {
        $category = Category::createCategory('category without related model');

        $this->assertEquals(null, $category->related_model);
    }

    public function testToJson()
    {
        $category = new Category;
        $sc = $category->getBranchById(1);
        $sc->toJson();
    }
}
