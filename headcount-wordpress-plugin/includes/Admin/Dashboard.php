<?php
namespace Headcount\Admin;

use Headcount\Core\Renderer;

/**
 * Admin Dashboard Class
 */
class Dashboard {
    
    private $api_client;
    
    public function __construct($api_client) {
        $this->api_client = $api_client;
    }

    public function add_menu_pages() {
        // Main Menu
        add_menu_page(
            'Headcount',
            'Headcount',
            'manage_options',
            'headcount-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-groups',
            30
        );

        // Submenus
        add_submenu_page(
            'headcount-dashboard',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'headcount-dashboard',
            array($this, 'render_dashboard')
        );
    }

    public function render_dashboard() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $response = $this->api_client->get_events(array('limit' => 5));

        echo Renderer::render('dashboard', array(
            'events' => $response['events'] ?? [],
            'connection' => $response['success'] ?? false,
            'event_count' => $response['count'] ?? 0,
            'event_details_url' => trim((string) get_option('headcount_event_details_url', '')),
        ), 'admin');
    }
}
