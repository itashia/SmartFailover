<?php

namespace Tests\Unit\Routes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SmartFailoverRoutesTest extends TestCase
{
    use RefreshDatabase;

    private string $routePath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->routePath = config('smart-failover.health_check.route_path', '/health/smart-failover');
    }

    public function testMainHealthCheckRouteIsRegistered(): void
    {
        $this->assertTrue(Route::has('smart-failover.health.index'));
        
        $route = Route::getRoutes()->getByName('smart-failover.health.index');
        $this->assertNotNull($route);
        $this->assertEquals('GET', $route->methods()[0]);
        $this->assertEquals($this->routePath, $route->uri());
        $this->assertEquals(HealthController::class, $route->getControllerClass());
        $this->assertEquals('index', $route->getActionMethod());
    }

    public function testDetailedHealthCheckRouteIsRegistered(): void
    {
        $this->assertTrue(Route::has('smart-failover.health.detailed'));
        
        $route = Route::getRoutes()->getByName('smart-failover.health.detailed');
        $this->assertNotNull($route);
        $this->assertEquals('GET', $route->methods()[0]);
        $this->assertEquals("{$this->routePath}/detailed", $route->uri());
        $this->assertEquals(HealthController::class, $route->getControllerClass());
        $this->assertEquals('detailed', $route->getActionMethod());
    }

    public function testDatabaseHealthCheckRouteIsRegistered(): void
    {
        $this->assertTrue(Route::has('smart-failover.health.database'));
        
        $route = Route::getRoutes()->getByName('smart-failover.health.database');
        $this->assertNotNull($route);
        $this->assertEquals('GET', $route->methods()[0]);
        $this->assertEquals("{$this->routePath}/database", $route->uri());
        $this->assertEquals(HealthController::class, $route->getControllerClass());
        $this->assertEquals('database', $route->getActionMethod());
    }

    public function testCacheHealthCheckRouteIsRegistered(): void
    {
        $this->assertTrue(Route::has('smart-failover.health.cache'));
        
        $route = Route::getRoutes()->getByName('smart-failover.health.cache');
        $this->assertNotNull($route);
        $this->assertEquals('GET', $route->methods()[0]);
        $this->assertEquals("{$this->routePath}/cache", $route->uri());
        $this->assertEquals(HealthController::class, $route->getControllerClass());
        $this->assertEquals('cache', $route->getActionMethod());
    }

    public function testQueueHealthCheckRouteIsRegistered(): void
    {
        $this->assertTrue(Route::has('smart-failover.health.queue'));
        
        $route = Route::getRoutes()->getByName('smart-failover.health.queue');
        $this->assertNotNull($route);
        $this->assertEquals('GET', $route->methods()[0]);
        $this->assertEquals("{$this->routePath}/queue", $route->uri());
        $this->assertEquals(HealthController::class, $route->getControllerClass());
        $this->assertEquals('queue', $route->getActionMethod());
    }

    public function testStorageHealthCheckRouteIsRegistered(): void
    {
        $this->assertTrue(Route::has('smart-failover.health.storage'));
        
        $route = Route::getRoutes()->getByName('smart-failover.health.storage');
        $this->assertNotNull($route);
        $this->assertEquals('GET', $route->methods()[0]);
        $this->assertEquals("{$this->routePath}/storage", $route->uri());
        $this->assertEquals(HealthController::class, $route->getControllerClass());
        $this->assertEquals('storage', $route->getActionMethod());
    }

    public function testMailHealthCheckRouteIsRegistered(): void
    {
        $this->assertTrue(Route::has('smart-failover.health.mail'));
        
        $route = Route::getRoutes()->getByName('smart-failover.health.mail');
        $this->assertNotNull($route);
        $this->assertEquals('GET', $route->methods()[0]);
        $this->assertEquals("{$this->routePath}/mail", $route->uri());
        $this->assertEquals(HealthController::class, $route->getControllerClass());
        $this->assertEquals('mail', $route->getActionMethod());
    }

    public function testRoutesUseConfiguredMiddleware(): void
    {
        $middleware = config('smart-failover.health_check.middleware', ['web']);
        
        $route = Route::getRoutes()->getByName('smart-failover.health.index');
        $this->assertNotNull($route);
        
        $routeMiddleware = $route->gatherMiddleware();
        $this->assertContains('web', $routeMiddleware);
    }

    public function testRoutesUseConfiguredPath(): void
    {
        $customPath = '/custom/health/path';
        config(['smart-failover.health_check.route_path' => $customPath]);
        
        // Reload routes to pick up new config
        $this->refreshApplication();
        
        $route = Route::getRoutes()->getByName('smart-failover.health.index');
        $this->assertNotNull($route);
        $this->assertEquals($customPath, $route->uri());
    }

    public function testAllRoutesAreAccessible(): void
    {
        $routes = [
            'smart-failover.health.index' => $this->routePath,
            'smart-failover.health.detailed' => "{$this->routePath}/detailed",
            'smart-failover.health.database' => "{$this->routePath}/database",
            'smart-failover.health.cache' => "{$this->routePath}/cache",
            'smart-failover.health.queue' => "{$this->routePath}/queue",
            'smart-failover.health.storage' => "{$this->routePath}/storage",
            'smart-failover.health.mail' => "{$this->routePath}/mail",
        ];

        foreach ($routes as $name => $uri) {
            $this->assertTrue(Route::has($name));
            
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertEquals($uri, $route->uri());
        }
    }
}
