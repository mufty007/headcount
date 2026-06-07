<?php

namespace Headcount\Tests\Performance;

use Headcount\Tests\TestCase;
use Headcount\Helpers\Database;

/**
 * Performance Tests
 * Tests system performance and response times
 */
class PerformanceTest extends TestCase
{
    public function testDatabaseQueryPerformance()
    {
        $db = Database::getInstance();
        
        $startTime = microtime(true);
        
        // Execute a simple query
        $result = $db->query("SELECT * FROM users LIMIT 100");
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        // Should complete in less than 500ms
        $this->assertLessThan(500, $executionTime, "Query took {$executionTime}ms, expected < 500ms");
    }

    public function testMemberSearchPerformance()
    {
        $db = Database::getInstance();
        
        // Create test members
        $memberIds = [];
        for ($i = 0; $i < 100; $i++) {
            $memberIds[] = $this->createTestUser([
                'email' => "perftest{$i}@example.com",
                'first_name' => "Test{$i}",
                'last_name' => "User{$i}"
            ]);
        }
        
        $startTime = microtime(true);
        
        // Search for members
        $sql = "SELECT * FROM users WHERE first_name LIKE :query OR last_name LIKE :query OR email LIKE :query LIMIT 20";
        $results = $db->query($sql, ['query' => '%Test%']);
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;
        
        // Should complete in less than 1000ms (1 second)
        $this->assertLessThan(1000, $executionTime, "Search took {$executionTime}ms, expected < 1000ms");
        
        // Clean up
        foreach ($memberIds as $id) {
            $db->execute("DELETE FROM users WHERE id = :id", ['id' => $id]);
        }
    }

    public function testPasswordHashingPerformance()
    {
        $password = 'TestPassword123!';
        
        $startTime = microtime(true);
        
        for ($i = 0; $i < 10; $i++) {
            \Headcount\Helpers\Security::hashPassword($password);
        }
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;
        $avgTime = $executionTime / 10;
        
        // Each hash should take less than 500ms
        $this->assertLessThan(500, $avgTime, "Average hash time: {$avgTime}ms");
    }
}
