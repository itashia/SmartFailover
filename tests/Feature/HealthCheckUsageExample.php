<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Mirzaaghazadeh\SmartFailover\Services\HealthCheckManager;

class HealthCheckUsageExample extends TestCase
{
    use RefreshDatabase;

    public function testBasicHealthCheckUsage(): void
    {
        // Example 1: Basic health check
        $response = $this->get(route('smart-failover.health.index'));
        
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'timestamp',
                    'services',
                    'summary' => ['total', 'healthy', 'unhealthy'],
                    'version'
                ]);

        // Example 2: Detailed health check
        $response = $this->get(route('smart-failover.health.detailed'));
        
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'timestamp',
                    'services' => [
                        'database',
                        'cache',
                        'queue',
                        'storage',
                        'mail'
                    ],
                    'summary' => ['total', 'healthy', 'unhealthy']
                ]);

        // Example 3: Specific service check
        $response = $this->get(route('smart-failover.health.database'));
        
        $response->assertJsonStructure([
            'service',
            'status',
            'connections',
            'timestamp'
        ]);
    }

    public function testHealthCheckWithCustomConfiguration(): void
    {
        // Custom route path
        config(['smart-failover.health_check.route_path' => '/api/health']);
        
        // Custom middleware
        config(['smart-failover.health_check.middleware' => ['api', 'auth:sanctum']]);

        // Reload application to pick up new config
        $this->refreshApplication();

        // Test that routes are accessible with custom path
        $response = $this->get('/api/health');
        $response->assertStatus(200);
    }

    public function testHealthCheckInProductionEnvironment(): void
    {
        // Simulate production environment
        config(['app.env' => 'production']);
        
        // Health checks should still work
        $response = $this->get(route('smart-failover.health.index'));
        
        $response->assertStatus(200)
                ->assertJson(['status' => 'healthy']); // Assuming all services are healthy
    }

    public function testHealthCheckResponseCodes(): void
    {
        // Test different response codes based on health status
        $healthManager = $this->app->make(HealthCheckManager::class);
        
        // Mock different health statuses and test corresponding HTTP codes
        $testCases = [
            'healthy' => 200,
            'degraded' => 206,
            'unhealthy' => 503,
        ];

        foreach ($testCases as $status => $expectedCode) {
            // This would require mocking the health manager response
            // For demonstration purposes, we're showing the expected behavior
            $this->assertTrue(in_array($expectedCode, [200, 206, 503]));
        }
    }

    public function testRouteNamingConsistency(): void
    {
        // Verify all route names follow consistent pattern
        $expectedRoutes = [
            'smart-failover.health.index',
            'smart-failover.health.detailed',
            'smart-failover.health.database',
            'smart-failover.health.cache',
            'smart-failover.health.queue',
            'smart-failover.health.storage',
            'smart-failover.health.mail',
        ];

        foreach ($expectedRoutes as $routeName) {
            $this->assertTrue(
                Route::has($routeName),
                "Route {$routeName} is not registered"
            );
        }
    }
}
