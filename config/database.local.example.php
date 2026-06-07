<?php
/**
 * Copy this file to **database.local.php** (same directory) for local dev, OR copy
 * **database.php.example** to **database.php** on Hostinger (SFTP often skips gitignored files —
 * database.php is the same idea, loaded before database.local.php).
 *
 * database.local.php / database.php are gitignored — safe for credentials on each machine.
 *
 * Hostinger: user and database names often match. From your PC (XAMPP), use Remote MySQL hostname
 * in hPanel as host, not localhost.
 *
 * @return array{host?:string,name?:string,username?:string,password?:string,charset?:string}
 */
return [
    'host' => 'localhost',
    'name' => 'your_database_name',
    'username' => 'your_db_user',
    'password' => 'your_db_password',
    'charset' => 'utf8mb4',
];
