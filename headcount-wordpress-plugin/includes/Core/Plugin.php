<?php
namespace Headcount\Core;

/**
 * Main Plugin Class
 */
class Plugin {
    
    protected $api_client;
    protected $shortcodes;
    protected $field_shortcodes;
    protected $facility_field_shortcodes;
    protected $admin_dashboard;
    protected $admin_settings;

    public function __construct() {
        $this->api_client = new APIClient();
        $this->shortcodes = new Shortcodes($this->api_client);
        $this->field_shortcodes = new EventFieldShortcodes($this->api_client);
        $this->facility_field_shortcodes = new FacilityFieldShortcodes($this->api_client);
        $this->admin_dashboard = new \Headcount\Admin\Dashboard($this->api_client);
        $this->admin_settings = new \Headcount\Admin\Settings($this->api_client);
    }

    public function run() {
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function define_admin_hooks() {
        add_action('admin_menu', array($this->admin_dashboard, 'add_menu_pages'));
        add_action('admin_menu', array($this->admin_settings, 'add_settings_page'));
        add_action('admin_init', array($this->admin_settings, 'register_settings'));
        add_filter('plugin_action_links_' . HEADCOUNT_BASENAME, array($this, 'add_settings_link'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    private function define_public_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        $this->shortcodes->register();
        $this->field_shortcodes->register();
        $this->facility_field_shortcodes->register();
        add_action('plugins_loaded', array($this, 'maybe_init_elementor'), 20);
    }

    public function maybe_init_elementor() {
        if (did_action('elementor/loaded')) {
            $this->init_elementor();
            return;
        }
        add_action('elementor/loaded', array($this, 'init_elementor'));
    }

    public function init_elementor() {
        if (!class_exists('\Elementor\Plugin')) {
            return;
        }
        require_once HEADCOUNT_PLUGIN_DIR . 'includes/Elementor/Module.php';
        \Headcount\Elementor\Module::instance();
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=headcount-settings">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function enqueue_admin_assets($hook) {
        // Enqueue on all headcount pages
        if (strpos($hook, 'headcount') === false) {
            return;
        }

        wp_enqueue_style(
            'headcount-admin-styles',
            HEADCOUNT_PLUGIN_URL . 'assets/css/headcount-admin.css',
            array(),
            HEADCOUNT_VERSION
        );
    }

    public function enqueue_public_assets() {
        wp_enqueue_style(
            'headcount-styles',
            HEADCOUNT_PLUGIN_URL . 'assets/css/headcount.css',
            array(),
            HEADCOUNT_VERSION
        );

        wp_enqueue_script(
            'headcount-scripts',
            HEADCOUNT_PLUGIN_URL . 'assets/js/headcount.js',
            array('jquery'),
            HEADCOUNT_VERSION,
            true
        );

        // Load Alpine.js for frontend filtering
        wp_enqueue_script(
            'alpinejs',
            'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js',
            array(),
            '3.x.x',
            true
        );
    }
}
