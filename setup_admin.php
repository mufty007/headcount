<?php
/**
 * Setup Test Admin User
 * 
 * This script creates a default organization and test admin user
 * Run this once after setting up the database
 * 
 * Usage: php setup_admin.php
 * Or visit: http://localhost/Headcount/setup_admin.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load configuration
$config = require __DIR__ . '/config/config.php';

// Initialize database connection
try {
    $db = Database::getInstance([
        'host' => $config['database']['host'],
        'database' => $config['database']['name'],
        'username' => $config['database']['username'],
        'password' => $config['database']['password']
    ]);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Admin credentials
$adminEmail = 'admin@headcount.local';
$adminPassword = 'admin123';
$adminFirstName = 'Admin';
$adminLastName = 'User';

echo "Setting up test admin user...\n\n";

// Step 1: Create default organization if it doesn't exist
echo "1. Checking for default organization...\n";
$org = $db->queryOne("SELECT id FROM organizations WHERE slug = 'headcount' LIMIT 1");

if (!$org) {
    echo "   Creating default organization...\n";
    $orgId = $db->insert('organizations', [
        'name' => 'Headcount Events',
        'slug' => 'headcount',
        'primary_color' => '#3B82F6',
        'timezone' => 'America/Indiana/Indianapolis',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        'stripe_test_mode' => 1
    ]);
    echo "   ✓ Organization created with ID: $orgId\n\n";
} else {
    $orgId = $org['id'];
    echo "   ✓ Organization already exists (ID: $orgId)\n\n";
}

// Step 2: Check if admin user already exists
echo "2. Checking for existing admin user...\n";
$existingAdmin = $db->queryOne(
    "SELECT id FROM users WHERE email = :email AND organization_id = :org_id LIMIT 1",
    ['email' => $adminEmail, 'org_id' => $orgId]
);

if ($existingAdmin) {
    echo "   Admin user already exists. Updating password...\n";
    
    // Hash password
    $passwordHash = Security::hashPassword($adminPassword, 12);
    
    // Update existing admin
    $db->update('users', $existingAdmin['id'], [
        'password_hash' => $passwordHash,
        'first_name' => $adminFirstName,
        'last_name' => $adminLastName,
        'role' => 'admin',
        'status' => 'active',
        'failed_login_attempts' => 0,
        'locked_until' => null
    ]);
    
    echo "   ✓ Admin user updated successfully!\n\n";
} else {
    echo "   Creating new admin user...\n";
    
    // Hash password
    $passwordHash = Security::hashPassword($adminPassword, 12);
    
    // Create admin user
    $userId = $db->insert('users', [
        'organization_id' => $orgId,
        'email' => $adminEmail,
        'password_hash' => $passwordHash,
        'first_name' => $adminFirstName,
        'last_name' => $adminLastName,
        'role' => 'admin',
        'status' => 'active',
        'failed_login_attempts' => 0
    ]);
    
    echo "   ✓ Admin user created with ID: $userId\n\n";
}

// Step 3: Display summary
echo "========================================\n";
echo "SETUP COMPLETE!\n";
echo "========================================\n\n";
echo "Admin Credentials:\n";
echo "  Email: $adminEmail\n";
echo "  Password: $adminPassword\n\n";
echo "Organization ID: $orgId\n";
echo "\nYou can now log in with these credentials.\n";
echo "========================================\n";
