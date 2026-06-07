<?php

namespace Headcount\Tests;

/**
 * Test Helper Functions
 * Utility functions for testing
 */
class TestHelper
{
    /**
     * Create a mock HTTP request
     */
    public static function mockRequest($method = 'GET', $uri = '/', $data = [], $headers = [])
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        
        if ($method === 'POST' || $method === 'PUT') {
            $_POST = $data;
        }
        
        foreach ($headers as $key => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }
    }

    /**
     * Reset request globals
     */
    public static function resetRequest()
    {
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
    }

    /**
     * Create test session
     */
    public static function createTestSession($userId = 1, $organizationId = 1, $role = 'admin')
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['organization_id'] = $organizationId;
        $_SESSION['role'] = $role;
        $_SESSION['email'] = 'test@example.com';
    }

    /**
     * Clear test session
     */
    public static function clearSession()
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Generate test data
     */
    public static function generateTestData($type, $count = 1)
    {
        $data = [];
        
        for ($i = 0; $i < $count; $i++) {
            switch ($type) {
                case 'user':
                    $data[] = [
                        'email' => "test{$i}@example.com",
                        'first_name' => "Test{$i}",
                        'last_name' => "User{$i}",
                        'phone' => "123456789{$i}",
                        'password' => password_hash('Test123!@#', PASSWORD_BCRYPT),
                    ];
                    break;
                    
                case 'event':
                    $data[] = [
                        'title' => "Test Event {$i}",
                        'description' => "Test Description {$i}",
                        'event_date' => date('Y-m-d', strtotime("+{$i} weeks")),
                        'location' => "Test Location {$i}",
                        'category' => 'Test',
                    ];
                    break;
            }
        }
        
        return $count === 1 ? $data[0] : $data;
    }

    /**
     * Assert response structure
     */
    public static function assertApiResponse($response, $expectedSuccess = true)
    {
        \PHPUnit\Framework\Assert::assertIsArray($response);
        \PHPUnit\Framework\Assert::assertArrayHasKey('success', $response);
        \PHPUnit\Framework\Assert::assertEquals($expectedSuccess, $response['success']);
        
        if ($expectedSuccess) {
            \PHPUnit\Framework\Assert::assertArrayHasKey('data', $response);
        } else {
            \PHPUnit\Framework\Assert::assertArrayHasKey('message', $response);
        }
    }

    /**
     * Measure execution time
     */
    public static function measureTime(callable $callback)
    {
        $start = microtime(true);
        $result = $callback();
        $end = microtime(true);
        
        return [
            'result' => $result,
            'time' => ($end - $start) * 1000, // milliseconds
        ];
    }
}
