<?php
/**
 * Plugin Name: Headcount
 * Plugin URI: https://github.com/yourusername/headcount-wordpress-plugin
 * Description: Seamlessly integrate and display events from your Headcount event management system with modern, responsive designs and RSVP functionality.
 * Version: 2.1.1
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Muhammad Tomasiewicz
 * Author URI: https://gorideng.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: headcount
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HEADCOUNT_VERSION', '2.1.1');
define('HEADCOUNT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HEADCOUNT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HEADCOUNT_BASENAME', plugin_basename(__FILE__));
define('HEADCOUNT_MIN_PHP_VERSION', '7.4');
define('HEADCOUNT_MIN_WP_VERSION', '5.0');

// Check PHP version
if (version_compare(PHP_VERSION, HEADCOUNT_MIN_PHP_VERSION, '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        printf(
            __('Headcount Events requires PHP version %s or higher. You are running version %s.', 'headcount'),
            HEADCOUNT_MIN_PHP_VERSION,
            PHP_VERSION
        );
        echo '</p></div>';
    });
    return;
}

// Check WordPress version
if (version_compare(get_bloginfo('version'), HEADCOUNT_MIN_WP_VERSION, '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        printf(
            __('Headcount Events requires WordPress version %s or higher. You are running version %s.', 'headcount'),
            HEADCOUNT_MIN_WP_VERSION,
            get_bloginfo('version')
        );
        echo '</p></div>';
    });
    return;
}

// Autoload classes (Manual PSR-4 autoloader)
spl_autoload_register(function ($class) {
    // Only autoload Headcount classes
    if (strpos($class, 'Headcount\\') !== 0) {
        return;
    }

    // Convert namespace to file path
    $class_path = str_replace('Headcount\\', '', $class);
    $file = str_replace('\\', DIRECTORY_SEPARATOR, $class_path);
    $file_path = HEADCOUNT_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $file . '.php';

    // Load the file if it exists
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        // Log missing class file in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Headcount: Failed to autoload class ' . $class . ' from ' . $file_path);
        }
    }
});

/**
 * Plugin Activation Hook
 */
function activate_headcount() {
    require_once HEADCOUNT_PLUGIN_DIR . 'includes/Core/Activator.php';
    \Headcount\Core\Activator::activate();
}
register_activation_hook(__FILE__, 'activate_headcount');

/**
 * Plugin Deactivation Hook
 */
function deactivate_headcount() {
    require_once HEADCOUNT_PLUGIN_DIR . 'includes/Core/Deactivator.php';
    \Headcount\Core\Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'deactivate_headcount');

/**
 * Initialize the plugin
 */
function run_headcount() {
    try {
        $plugin = new \Headcount\Core\Plugin();
        $plugin->run();
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Headcount Plugin Error: ' . $e->getMessage());
        }
        
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p>';
            echo __('Headcount Events encountered an error: ', 'headcount') . esc_html($e->getMessage());
            echo '</p></div>';
        });
    }
}

add_action('plugins_loaded', 'run_headcount');

/**
 * Load plugin text domain for translations
 */
function headcount_load_textdomain() {
    load_plugin_textdomain('headcount', false, dirname(HEADCOUNT_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'headcount_load_textdomain');
