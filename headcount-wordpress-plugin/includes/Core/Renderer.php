<?php
namespace Headcount\Core;

/**
 * Template Renderer Class
 */
class Renderer {
    
    /**
     * Render a template
     * 
     * @param string $template_name Template name (without .php)
     * @param array $data Data to pass to template
     * @param string $context Context (admin or frontend)
     * @return string Rendered HTML
     */
    public static function render($template_name, $data = array(), $context = 'frontend') {
        $file = HEADCOUNT_PLUGIN_DIR . 'templates/' . $context . '/' . $template_name . '.php';
        
        if (!file_exists($file)) {
            // Fallback for older structure if needed
            $file = HEADCOUNT_PLUGIN_DIR . 'templates/' . $template_name . '.php';
        }
        
        if (!file_exists($file)) {
            return '<div class="headcount-error">Template ' . esc_html($template_name) . ' not found.</div>';
        }
        
        // Extract data to current scope
        extract($data);
        
        ob_start();
        include $file;
        return ob_get_clean();
    }
}
