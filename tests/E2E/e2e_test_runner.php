<?php
/**
 * End-to-End Test Runner
 * 
 * This script provides a framework for running E2E tests manually or semi-automated.
 * Tests should be executed in a test environment with proper test data.
 * 
 * Usage:
 *   php tests/E2E/e2e_test_runner.php --test TC-AUTH-001
 *   php tests/E2E/e2e_test_runner.php --all
 *   php tests/E2E/e2e_test_runner.php --category authentication
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Headcount\Helpers\Database;

class E2ETestRunner
{
    private $config;
    private $db;
    private $results = [];
    private $baseUrl;
    
    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/config.php';
        $this->db = Database::getInstance($this->config['database']);
        $this->baseUrl = $this->config['base_url'] ?? 'http://localhost/Headcount';
    }
    
    /**
     * Run a specific test
     */
    public function runTest($testId)
    {
        echo "\n=== Running Test: $testId ===\n";
        
        $test = $this->getTest($testId);
        if (!$test) {
            echo "Test not found: $testId\n";
            return false;
        }
        
        echo "Description: {$test['description']}\n";
        echo "Steps:\n";
        foreach ($test['steps'] as $i => $step) {
            echo "  " . ($i + 1) . ". $step\n";
        }
        
        echo "\nPlease execute the steps manually and enter result (pass/fail): ";
        $result = trim(fgets(STDIN));
        
        $this->results[$testId] = [
            'test_id' => $testId,
            'result' => strtolower($result),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return strtolower($result) === 'pass';
    }
    
    /**
     * Run all tests in a category
     */
    public function runCategory($category)
    {
        $tests = $this->getTestsByCategory($category);
        echo "\n=== Running Category: $category ===\n";
        echo "Found " . count($tests) . " tests\n\n";
        
        foreach ($tests as $testId) {
            $this->runTest($testId);
        }
    }
    
    /**
     * Run all tests
     */
    public function runAll()
    {
        $categories = ['authentication', 'members', 'events', 'attendance', 'payments', 'reports', 'email', 'portal', 'admin'];
        
        foreach ($categories as $category) {
            $this->runCategory($category);
        }
    }
    
    /**
     * Generate test report
     */
    public function generateReport()
    {
        $total = count($this->results);
        $passed = count(array_filter($this->results, fn($r) => $r['result'] === 'pass'));
        $failed = $total - $passed;
        
        echo "\n=== Test Execution Report ===\n";
        echo "Total Tests: $total\n";
        echo "Passed: $passed\n";
        echo "Failed: $failed\n";
        echo "Pass Rate: " . ($total > 0 ? round(($passed / $total) * 100, 2) : 0) . "%\n\n";
        
        if ($failed > 0) {
            echo "Failed Tests:\n";
            foreach ($this->results as $result) {
                if ($result['result'] === 'fail') {
                    echo "  - {$result['test_id']}\n";
                }
            }
        }
        
        // Save report to file
        $reportFile = __DIR__ . '/e2e_report_' . date('Y-m-d_His') . '.json';
        file_put_contents($reportFile, json_encode($this->results, JSON_PRETTY_PRINT));
        echo "\nReport saved to: $reportFile\n";
    }
    
    /**
     * Get test definition
     */
    private function getTest($testId)
    {
        $tests = $this->getAllTests();
        return $tests[$testId] ?? null;
    }
    
    /**
     * Get tests by category
     */
    private function getTestsByCategory($category)
    {
        $tests = $this->getAllTests();
        return array_keys(array_filter($tests, fn($t) => $t['category'] === $category));
    }
    
    /**
     * Get all test definitions
     */
    private function getAllTests()
    {
        return [
            'TC-AUTH-001' => [
                'category' => 'authentication',
                'description' => 'Admin Login - Valid Credentials',
                'steps' => [
                    'Navigate to /admin/?page=login',
                    'Enter valid admin email',
                    'Enter valid password',
                    'Click Login button',
                    'Verify redirect to dashboard'
                ]
            ],
            'TC-AUTH-002' => [
                'category' => 'authentication',
                'description' => 'Admin Login - Invalid Credentials',
                'steps' => [
                    'Navigate to /admin/?page=login',
                    'Enter invalid email',
                    'Enter invalid password',
                    'Click Login button',
                    'Verify error message displayed'
                ]
            ],
            'TC-MEM-001' => [
                'category' => 'members',
                'description' => 'Create New Member',
                'steps' => [
                    'Login as admin',
                    'Navigate to Members page',
                    'Click Add Member button',
                    'Fill in required fields',
                    'Click Save',
                    'Verify member appears in list'
                ]
            ],
            'TC-MEM-002' => [
                'category' => 'members',
                'description' => 'Edit Member Details',
                'steps' => [
                    'Login as admin',
                    'Navigate to Members page',
                    'Click on a member',
                    'Click Edit button',
                    'Modify member information',
                    'Click Save',
                    'Verify changes are saved'
                ]
            ],
            'TC-EVT-001' => [
                'category' => 'events',
                'description' => 'Create New Event',
                'steps' => [
                    'Login as admin',
                    'Navigate to Events page',
                    'Click Create Event button',
                    'Fill in event details',
                    'Click Save',
                    'Verify event appears in list'
                ]
            ],
            'TC-ATT-001' => [
                'category' => 'attendance',
                'description' => 'Check-In Member via Search',
                'steps' => [
                    'Login as admin',
                    'Navigate to Check-In page for an event',
                    'Search for member',
                    'Select member from results',
                    'Click Check In button',
                    'Verify check-in success'
                ]
            ],
            // Add more tests as needed
        ];
    }
}

// Command line interface
if (php_sapi_name() === 'cli') {
    $runner = new E2ETestRunner();
    
    $options = getopt('', ['test:', 'category:', 'all', 'help']);
    
    if (isset($options['help'])) {
        echo "E2E Test Runner\n";
        echo "Usage:\n";
        echo "  --test TC-AUTH-001    Run specific test\n";
        echo "  --category members    Run all tests in category\n";
        echo "  --all                Run all tests\n";
        echo "  --help               Show this help\n";
        exit(0);
    }
    
    if (isset($options['test'])) {
        $runner->runTest($options['test']);
        $runner->generateReport();
    } elseif (isset($options['category'])) {
        $runner->runCategory($options['category']);
        $runner->generateReport();
    } elseif (isset($options['all'])) {
        $runner->runAll();
        $runner->generateReport();
    } else {
        echo "Use --help for usage information\n";
    }
}
