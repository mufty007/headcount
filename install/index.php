<?php
/**
 * Installation Wizard
 * Guides users through initial setup
 */

// Simple autoloader for installation
spl_autoload_register(function ($class) {
    $prefix = 'Headcount\\';
    $baseDir = __DIR__ . '/../src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Calculate base URL
$baseUrl = dirname(dirname($_SERVER['SCRIPT_NAME']));
if ($baseUrl === '/' || $baseUrl === '\\') {
    $baseUrl = '';
}

// Start session for installation
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if already installed
$configPath = __DIR__ . '/../config/config.php';
if (file_exists($configPath)) {
    $config = require $configPath;
    // Check if database connection works
    try {
        $db = new PDO(
            "mysql:host={$config['database']['host']};dbname={$config['database']['name']};charset={$config['database']['charset']}",
            $config['database']['username'],
            $config['database']['password']
        );
        // If connection works, redirect to login
        header('Location: ' . $baseUrl . '/admin/?page=login');
        exit;
    } catch (PDOException $e) {
        // Connection failed, allow re-installation
    }
}

// Check requirements first
require_once __DIR__ . '/check.php';
$requirements = checkRequirements();

$step = $_GET['step'] ?? 0;
$errors = [];
$success = false;

// Step 0: Requirements check
if ($step == 0) {
    // If requirements not met, show check page
    if (!$requirements['all_passed']) {
        // Continue to show requirements
    } else {
        // All requirements met, proceed to step 1
        $step = 1;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // Step 1: Database Configuration
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPass = $_POST['db_pass'] ?? '';

        if (empty($dbName) || empty($dbUser)) {
            $errors[] = 'Database name and username are required.';
        } else {
            // Test database connection
            try {
                $testDb = new PDO(
                    "mysql:host={$dbHost};charset=utf8mb4",
                    $dbUser,
                    $dbPass
                );
                $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Create database if it doesn't exist
                $testDb->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                // Save to session for next step
                $_SESSION['install_db'] = [
                    'host' => $dbHost,
                    'name' => $dbName,
                    'username' => $dbUser,
                    'password' => $dbPass,
                    'charset' => 'utf8mb4'
                ];
                header('Location: ' . $baseUrl . '/install/?step=2');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Database connection failed: ' . $e->getMessage();
            }
        }
    } elseif ($step == 2) {
        // Step 2: Create Admin Account
        $orgName = $_POST['org_name'] ?? '';
        $adminEmail = $_POST['admin_email'] ?? '';
        $adminPassword = $_POST['admin_password'] ?? '';
        $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';

        if (empty($orgName) || empty($adminEmail) || empty($adminPassword)) {
            $errors[] = 'All fields are required.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif ($adminPassword !== $adminPasswordConfirm) {
            $errors[] = 'Passwords do not match.';
        } elseif (strlen($adminPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        } else {
            // Create config file
            $dbConfig = $_SESSION['install_db'];
            $configContent = "<?php\n\nreturn [\n";
            $configContent .= "    'database' => [\n";
            $configContent .= "        'host' => '{$dbConfig['host']}',\n";
            $configContent .= "        'name' => '{$dbConfig['name']}',\n";
            $configContent .= "        'username' => '{$dbConfig['username']}',\n";
            $configContent .= "        'password' => '{$dbConfig['password']}',\n";
            $configContent .= "        'charset' => '{$dbConfig['charset']}',\n";
            $configContent .= "    ],\n";
            $configContent .= "    'app' => [\n";
            $configContent .= "        'name' => 'Headcount Events',\n";
            $configContent .= "        'url' => '" . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME'])) . "',\n";
            $configContent .= "        'timezone' => 'America/Indiana/Indianapolis',\n";
            $configContent .= "        'debug' => false,\n";
            $configContent .= "        'environment' => 'production',\n";
            $configContent .= "    ],\n";
            $configContent .= "    'session' => [\n";
            $configContent .= "        'lifetime' => 86400,\n";
            $configContent .= "        'cookie_name' => 'headcount_session',\n";
            $configContent .= "        'cookie_secure' => false,\n";
            $configContent .= "        'cookie_httponly' => true,\n";
            $configContent .= "        'cookie_samesite' => 'Strict',\n";
            $configContent .= "    ],\n";
            $configContent .= "    'security' => [\n";
            $configContent .= "        'password_min_length' => 8,\n";
            $configContent .= "        'password_require_uppercase' => true,\n";
            $configContent .= "        'password_require_number' => true,\n";
            $configContent .= "        'max_login_attempts' => 5,\n";
            $configContent .= "        'lockout_duration' => 1800,\n";
            $configContent .= "        'bcrypt_cost' => 12,\n";
            $configContent .= "    ],\n";
            $configContent .= "    'stripe' => [\n";
            $configContent .= "        'publishable_key' => '',\n";
            $configContent .= "        'secret_key' => '',\n";
            $configContent .= "        'webhook_secret' => '',\n";
            $configContent .= "        'test_mode' => true,\n";
            $configContent .= "    ],\n";
            $configContent .= "    'smtp2go' => [\n";
            $configContent .= "        'api_key' => '',\n";
            $configContent .= "        'from_email' => '',\n";
            $configContent .= "        'from_name' => '',\n";
            $configContent .= "        'reply_to' => '',\n";
            $configContent .= "    ],\n";
            $configContent .= "    'uploads' => [\n";
            $configContent .= "        'max_file_size' => 10485760,\n";
            $configContent .= "        'allowed_extensions' => ['csv', 'jpg', 'jpeg', 'png', 'gif'],\n";
            $configContent .= "        'upload_path' => __DIR__ . '/../uploads',\n";
            $configContent .= "    ],\n";
            $configContent .= "    'logging' => [\n";
            $configContent .= "        'enabled' => true,\n";
            $configContent .= "        'level' => 'INFO',\n";
            $configContent .= "        'log_path' => __DIR__ . '/../logs',\n";
            $configContent .= "        'max_log_size' => 10485760,\n";
            $configContent .= "    ],\n";
            $configContent .= "];\n";

            if (!is_dir(__DIR__ . '/../config')) {
                mkdir(__DIR__ . '/../config', 0755, true);
            }

            file_put_contents($configPath, $configContent);
            chmod($configPath, 0600);

            // Create database tables
            try {
                $db = new PDO(
                    "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}",
                    $dbConfig['username'],
                    $dbConfig['password']
                );
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
                $db->exec($schema);

                // Create organization
                $stmt = $db->prepare("INSERT INTO organizations (name, created_at) VALUES (?, NOW())");
                $stmt->execute([$orgName]);
                $orgId = $db->lastInsertId();

                // Create admin user
                // Load Security class directly (can't use bootstrap since config doesn't exist yet)
                require __DIR__ . '/../src/Helpers/Security.php';
                $hashedPassword = \Headcount\Helpers\Security::hashPassword($adminPassword);
                
                $stmt = $db->prepare("INSERT INTO users (organization_id, email, password, first_name, last_name, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'admin', 'active', NOW())");
                $stmt->execute([$orgId, $adminEmail, $hashedPassword, 'Admin', 'User']);

                $success = true;
                unset($_SESSION['install_db']);
            } catch (PDOException $e) {
                $errors[] = 'Failed to create database tables: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Headcount Events</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8 bg-white p-8 rounded-lg shadow">
            <div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Headcount Events Installation
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    <?php if ($step == 0): ?>
                        Requirements Check
                    <?php elseif ($step == 1): ?>
                        Step 1 of 2: Database Configuration
                    <?php elseif ($step == 2): ?>
                        Step 2 of 2: Organization Setup
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded">
                    <p>Installation completed successfully!</p>
                    <p class="mt-2"><a href="<?php echo htmlspecialchars($baseUrl); ?>/admin/?page=login" class="underline">Click here to login</a></p>
                </div>
            <?php elseif ($step == 0): ?>
                <div class="space-y-4">
                    <h3 class="text-lg font-semibold">System Requirements Check</h3>
                    <div class="space-y-2">
                        <?php foreach ($requirements['checks'] as $check): ?>
                            <div class="flex items-center justify-between p-3 border rounded <?php echo $check['status'] ? 'bg-green-50 border-green-200' : ($check['required'] === 'Required' ? 'bg-red-50 border-red-200' : 'bg-yellow-50 border-yellow-200'); ?>">
                                <div>
                                    <span class="font-medium"><?php echo htmlspecialchars($check['name']); ?></span>
                                    <span class="text-sm text-gray-600">(<?php echo htmlspecialchars($check['required']); ?>)</span>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($check['message']); ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm text-gray-600"><?php echo htmlspecialchars($check['current']); ?></span>
                                    <?php if ($check['status']): ?>
                                        <span class="ml-2 text-green-600">✓</span>
                                    <?php else: ?>
                                        <span class="ml-2 text-red-600">✗</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($requirements['all_passed']): ?>
                        <div class="mt-4">
                            <a href="<?php echo htmlspecialchars($baseUrl); ?>/install/?step=1" class="inline-block w-full text-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                                Continue to Installation
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="mt-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
                            <p>Please fix the required issues above before continuing.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($step == 1): ?>
                <form method="POST" class="mt-8 space-y-6">
                    <div class="space-y-4">
                        <div>
                            <label for="db_host" class="block text-sm font-medium text-gray-700">Database Host</label>
                            <input type="text" name="db_host" id="db_host" value="localhost" required
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="db_name" class="block text-sm font-medium text-gray-700">Database Name</label>
                            <input type="text" name="db_name" id="db_name" required
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="db_user" class="block text-sm font-medium text-gray-700">Database Username</label>
                            <input type="text" name="db_user" id="db_user" required
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="db_pass" class="block text-sm font-medium text-gray-700">Database Password</label>
                            <input type="password" name="db_pass" id="db_pass"
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            Continue
                        </button>
                    </div>
                </form>
            <?php elseif ($step == 2): ?>
                <form method="POST" class="mt-8 space-y-6">
                    <div class="space-y-4">
                        <div>
                            <label for="org_name" class="block text-sm font-medium text-gray-700">Organization Name</label>
                            <input type="text" name="org_name" id="org_name" required
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="admin_email" class="block text-sm font-medium text-gray-700">Admin Email</label>
                            <input type="email" name="admin_email" id="admin_email" required
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                        <div>
                            <label for="admin_password" class="block text-sm font-medium text-gray-700">Admin Password</label>
                            <input type="password" name="admin_password" id="admin_password" required minlength="8"
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                            <p class="mt-1 text-xs text-gray-500">Minimum 8 characters</p>
                        </div>
                        <div>
                            <label for="admin_password_confirm" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                            <input type="password" name="admin_password_confirm" id="admin_password_confirm" required minlength="8"
                                   class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                            Complete Installation
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
