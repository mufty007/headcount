<?php

namespace Headcount\Helpers;

/**
 * Simple Authentication Helper Class
 * Provides basic authentication functionality
 */
class Auth
{
    private $db;
    
    /**
     * Constructor
     * Initializes database connection and session
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Login user
     * 
     * @param string $email User email
     * @param string $password User password
     * @return bool True on success, false on failure
     */
    public function login($email, $password)
    {
        $sql = "SELECT * FROM users WHERE email = :email AND role = 'admin' AND status = 'active' LIMIT 1";
        $user = $this->db->queryOne($sql, ['email' => $email]);
        
        if ($user && Security::verifyPassword($password, $user['password_hash'] ?? $user['password'] ?? '')) {
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            
            // Set organization_id if it exists
            if (isset($user['organization_id'])) {
                $_SESSION['organization_id'] = $user['organization_id'];
            }
            
            // Update last login (optional - you can add this column later)
            // $this->db->execute("UPDATE users SET last_login = NOW() WHERE id = :id", ['id' => $user['id']]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Logout user
     * 
     * @return void
     */
    public function logout()
    {
        session_unset();
        session_destroy();
    }
    
    /**
     * Check if user is logged in
     * 
     * @return bool True if logged in, false otherwise
     */
    public function isLoggedIn()
    {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    /**
     * Require login (redirect if not logged in)
     * 
     * @param string|null $redirectUrl Optional redirect URL (defaults to login page)
     * @return void
     */
    public function requireLogin($redirectUrl = null)
    {
        if (!$this->isLoggedIn()) {
            if ($redirectUrl === null) {
                // Calculate base path for redirect
                $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
                $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
                $basePath = rtrim($basePath, '/');
                $redirectUrl = $basePath . '/admin/login.php';
            }
            Utilities::redirect($redirectUrl);
        }
    }
    
    /**
     * Get current user data
     * 
     * @return array|null User data array or null if not logged in
     */
    public function getCurrentUser()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'name' => $_SESSION['user_name'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'role' => $_SESSION['user_role'] ?? null,
            'organization_id' => $_SESSION['organization_id'] ?? null
        ];
    }
    
    /**
     * Hash password
     * 
     * @param string $password Plain text password
     * @param int $cost Bcrypt cost (default: 12)
     * @return string Hashed password
     */
    public static function hashPassword($password, $cost = 12)
    {
        return Security::hashPassword($password, $cost);
    }
}
