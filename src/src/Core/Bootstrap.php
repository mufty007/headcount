<?php

namespace Headcount\Core;

use Headcount\Models\Event;
use Headcount\Models\User;
use Headcount\Models\Attendance;
use Headcount\Models\RSVP;
use Headcount\Models\Payment;
use Headcount\Services\EventService;
use Headcount\Services\MemberService;
use Headcount\Services\AttendanceService;
use Headcount\Services\EmailService;
use Headcount\Services\PaymentService;
use Headcount\Controllers\AuthController;
use Headcount\Controllers\EventController;
use Headcount\Controllers\MemberController;
use Headcount\Controllers\AttendanceController;
use Headcount\Controllers\PaymentController;
use Headcount\Controllers\SettingsController;
use Headcount\Integrations\StripeService;
use Headcount\Integrations\SMTP2GOService;

/**
 * Bootstrap Class
 * Initializes all dependencies and services
 */
class Bootstrap
{
    private static $config;
    private static $db;
    private static $initialized = false;

    /**
     * Initialize application
     */
    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        // Load config
        self::$config = require __DIR__ . '/../../config/config.php';

        // Initialize error handling
        ErrorHandler::init(self::$config);

        // Initialize logger
        Logger::init(self::$config['logging']);

        // Initialize security
        Security::init(self::$config['security']);

        // Initialize database
        self::$db = Database::getInstance(self::$config['database']);

        // Initialize session
        self::initSession();

        self::$initialized = true;
    }

    /**
     * Initialize session
     */
    private static function initSession()
    {
        $sessionConfig = self::$config['session'];
        
        ini_set('session.cookie_name', $sessionConfig['cookie_name']);
        ini_set('session.cookie_httponly', $sessionConfig['cookie_httponly'] ? '1' : '0');
        ini_set('session.cookie_secure', $sessionConfig['cookie_secure'] ? '1' : '0');
        ini_set('session.cookie_samesite', $sessionConfig['cookie_samesite']);
        ini_set('session.gc_maxlifetime', $sessionConfig['lifetime']);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Get config
     */
    public static function getConfig()
    {
        return self::$config;
    }

    /**
     * Get database instance
     */
    public static function getDatabase()
    {
        return self::$db;
    }

    /**
     * Get current organization ID from session
     */
    public static function getOrganizationId()
    {
        return $_SESSION['organization_id'] ?? null;
    }

    /**
     * Get current user ID from session
     */
    public static function getUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated()
    {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * Check if user is admin
     */
    public static function isAdmin()
    {
        return self::isAuthenticated() && ($_SESSION['role'] ?? '') === 'admin';
    }

    /**
     * Get models
     */
    public static function getModels()
    {
        $db = self::getDatabase();
        return [
            'event' => new Event($db),
            'user' => new User($db),
            'attendance' => new Attendance($db),
            'rsvp' => new RSVP($db),
            'payment' => new Payment($db),
        ];
    }

    /**
     * Get services
     */
    public static function getServices()
    {
        $models = self::getModels();
        $config = self::getConfig();

        // Initialize integrations
        $stripeService = null;
        if (!empty($config['stripe']['secret_key'])) {
            $stripeService = new StripeService(
                $config['stripe']['secret_key'],
                $config['stripe']['webhook_secret'] ?? null
            );
        }

        $smtpService = null;
        if (!empty($config['smtp2go']['api_key'])) {
            $smtpService = new SMTP2GOService(
                $config['smtp2go']['api_key'],
                $config['smtp2go']['from_email'] ?? null,
                $config['smtp2go']['from_name'] ?? null,
                $config['smtp2go']['reply_to'] ?? null
            );
        }

        // Initialize services
        $emailService = null;
        if ($smtpService) {
            $emailService = new EmailService($smtpService, self::getDatabase(), $config);
        }

        $paymentService = null;
        if ($stripeService) {
            $paymentService = new PaymentService(
                $stripeService,
                $models['payment'],
                $models['attendance'],
                $models['rsvp'],
                $config
            );
        }

        return [
            'event' => new EventService($models['event']),
            'member' => new MemberService($models['user']),
            'attendance' => new AttendanceService(
                $models['attendance'],
                $models['event'],
                $models['user']
            ),
            'email' => $emailService,
            'payment' => $paymentService,
        ];
    }

    /**
     * Get controllers
     */
    public static function getControllers()
    {
        $services = self::getServices();
        $models = self::getModels();
        $config = self::getConfig();

        return [
            'auth' => new AuthController(),
            'event' => new EventController($services['event']),
            'member' => new MemberController($services['member']),
            'attendance' => new AttendanceController($services['attendance']),
            'payment' => new PaymentController($services['payment']),
            'settings' => new SettingsController(),
        ];
    }
}
