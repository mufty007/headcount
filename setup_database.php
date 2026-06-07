<?php
/**
 * Database Setup Script
 * 
 * This script:
 * 1. Creates the database if it doesn't exist
 * 2. Imports the schema
 * 3. Creates the test admin user
 * 
 * Usage: php setup_database.php
 * Or visit: http://localhost/Headcount/setup_database.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use Headcount\Helpers\Security;

// Load configuration
$config = require __DIR__ . '/config/config.php';

$dbConfig = $config['database'];

echo "========================================\n";
echo "Headcount Events - Database Setup\n";
echo "========================================\n\n";

// Step 1: Connect to MySQL (without database)
try {
    $dsn = sprintf(
        "mysql:host=%s;charset=utf8mb4",
        $dbConfig['host']
    );
    
    $pdo = new PDO(
        $dsn,
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    
    echo "✓ Connected to MySQL server\n\n";
} catch (PDOException $e) {
    die("✗ Failed to connect to MySQL: " . $e->getMessage() . "\n");
}

// Step 2: Create database if it doesn't exist
echo "1. Creating database '{$dbConfig['name']}'...\n";
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "   ✓ Database ready\n\n";
} catch (PDOException $e) {
    die("✗ Failed to create database: " . $e->getMessage() . "\n");
}

// Step 3: Select the database
try {
    $pdo->exec("USE `{$dbConfig['name']}`");
} catch (PDOException $e) {
    die("✗ Failed to select database: " . $e->getMessage() . "\n");
}

// Step 4: Import schema
echo "2. Importing database schema...\n";
$schemaFile = __DIR__ . '/database/schema.sql';

if (!file_exists($schemaFile)) {
    die("✗ Schema file not found: $schemaFile\n");
}

try {
    $schema = file_get_contents($schemaFile);
    
    // Remove SET commands and comments
    $schema = preg_replace('/^SET .*?;$/m', '', $schema);
    $schema = preg_replace('/^--.*$/m', '', $schema);
    
    // Split by semicolon, but be careful with multi-line statements
    $statements = [];
    $currentStatement = '';
    $lines = explode("\n", $schema);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || preg_match('/^--/', $line)) {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // If line ends with semicolon, we have a complete statement
        if (substr(rtrim($line), -1) === ';') {
            $stmt = trim($currentStatement);
            if (!empty($stmt) && strlen($stmt) > 10) { // Minimum meaningful statement
                $statements[] = $stmt;
            }
            $currentStatement = '';
        }
    }
    
    // Execute statements
    $executed = 0;
    $errors = 0;
    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            // Ignore "already exists" and "duplicate key" errors
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'already exists') === false && 
                strpos($errorMsg, 'Duplicate key') === false &&
                strpos($errorMsg, 'Duplicate entry') === false) {
                $errors++;
                if ($errors <= 3) { // Only show first 3 errors
                    echo "   Warning: " . substr($errorMsg, 0, 100) . "...\n";
                }
            }
        }
    }
    
    if ($errors > 3) {
        echo "   (and " . ($errors - 3) . " more warnings)\n";
    }
    
    echo "   ✓ Schema imported ($executed statements executed)\n\n";
} catch (Exception $e) {
    die("✗ Failed to import schema: " . $e->getMessage() . "\n");
}

// Step 5: Create default organization
echo "3. Setting up default organization...\n";
try {
    $orgCheck = $pdo->query("SELECT id FROM organizations WHERE slug = 'headcount' LIMIT 1")->fetch();
    
    if (!$orgCheck) {
        $stmt = $pdo->prepare("
            INSERT INTO organizations (
                name, slug, primary_color, timezone, date_format, time_format, stripe_test_mode
            ) VALUES (
                :name, :slug, :primary_color, :timezone, :date_format, :time_format, :stripe_test_mode
            )
        ");
        
        $stmt->execute([
            'name' => 'Headcount Events',
            'slug' => 'headcount',
            'primary_color' => '#3B82F6',
            'timezone' => 'America/Indiana/Indianapolis',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'stripe_test_mode' => 1
        ]);
        
        $orgId = $pdo->lastInsertId();
        echo "   ✓ Organization created (ID: $orgId)\n\n";
    } else {
        $orgId = $orgCheck['id'];
        echo "   ✓ Organization already exists (ID: $orgId)\n\n";
    }
} catch (PDOException $e) {
    die("✗ Failed to create organization: " . $e->getMessage() . "\n");
}

// Step 6: Create admin user
echo "4. Setting up admin user...\n";
$adminEmail = 'admin@headcount.local';
$adminPassword = 'admin123';
$adminFirstName = 'Admin';
$adminLastName = 'User';

try {
    $userCheck = $pdo->prepare("SELECT id FROM users WHERE email = :email AND organization_id = :org_id LIMIT 1");
    $userCheck->execute(['email' => $adminEmail, 'org_id' => $orgId]);
    $existingUser = $userCheck->fetch();
    
    $passwordHash = Security::hashPassword($adminPassword, 12);
    
    if ($existingUser) {
        // Update existing user
        $stmt = $pdo->prepare("
            UPDATE users SET
                password_hash = :password_hash,
                first_name = :first_name,
                last_name = :last_name,
                role = 'admin',
                status = 'active',
                failed_login_attempts = 0,
                locked_until = NULL
            WHERE id = :id
        ");
        
        $stmt->execute([
            'id' => $existingUser['id'],
            'password_hash' => $passwordHash,
            'first_name' => $adminFirstName,
            'last_name' => $adminLastName
        ]);
        
        echo "   ✓ Admin user updated (ID: {$existingUser['id']})\n\n";
    } else {
        // Create new user
        $stmt = $pdo->prepare("
            INSERT INTO users (
                organization_id, email, password_hash, first_name, last_name, role, status
            ) VALUES (
                :organization_id, :email, :password_hash, :first_name, :last_name, 'admin', 'active'
            )
        ");
        
        $stmt->execute([
            'organization_id' => $orgId,
            'email' => $adminEmail,
            'password_hash' => $passwordHash,
            'first_name' => $adminFirstName,
            'last_name' => $adminLastName
        ]);
        
        $userId = $pdo->lastInsertId();
        echo "   ✓ Admin user created (ID: $userId)\n\n";
    }
} catch (PDOException $e) {
    die("✗ Failed to create admin user: " . $e->getMessage() . "\n");
}

// Summary
echo "========================================\n";
echo "SETUP COMPLETE!\n";
echo "========================================\n\n";
echo "Admin Credentials:\n";
echo "  Email: $adminEmail\n";
echo "  Password: $adminPassword\n\n";
echo "Organization ID: $orgId\n";
echo "\nYou can now log in with these credentials.\n";
echo "========================================\n";
