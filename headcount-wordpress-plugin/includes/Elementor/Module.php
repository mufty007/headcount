<?php
namespace Headcount\Elementor;

/**
 * Registers Headcount Elementor widgets and dynamic tags.
 */
class Module {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('elementor/widgets/register', array($this, 'register_widgets'));
        add_action('elementor/dynamic_tags/register', array($this, 'register_dynamic_tags'));
        add_action('elementor/elements/categories_registered', array($this, 'register_category'));
        add_filter('elementor/widget/render_content', array($this, 'filter_widget_render_content'), 10, 2);
        add_filter('elementor/frontend/widget/should_render', array($this, 'filter_widget_should_render'), 10, 2);
    }

    /**
     * Do not output image widget wrappers at all when the event has no banner.
     *
     * @param bool                 $should_render
     * @param \Elementor\Widget_Base $widget
     * @return bool
     */
    public function filter_widget_should_render($should_render, $widget) {
        if (!$should_render || !\Headcount\Core\EventLoopContext::current()) {
            return $should_render;
        }

        $name = method_exists($widget, 'get_name') ? $widget->get_name() : '';
        if (!in_array($name, array('image', 'image-box'), true)) {
            return $should_render;
        }

        $event = \Headcount\Core\EventLoopContext::current();
        if (!$event || !\Headcount\Core\EventsLoopRenderer::event_has_banner_image($event)) {
            return false;
        }

        return $should_render;
    }

    /**
     * Inside a Headcount event loop, hide Elementor Image widgets when the event has no banner.
     *
     * @param string              $content
     * @param \Elementor\Widget_Base $widget
     * @return string
     */
    public function filter_widget_render_content($content, $widget) {
        if (!\Headcount\Core\EventLoopContext::current()) {
            return $content;
        }

        $name = method_exists($widget, 'get_name') ? $widget->get_name() : '';
        if (!in_array($name, array('image', 'image-box'), true)) {
            return $content;
        }

        $event = \Headcount\Core\EventLoopContext::current();
        if (!$event || !\Headcount\Core\EventsLoopRenderer::event_has_banner_image($event)) {
            return '';
        }

        if (is_string($content) && preg_match('/<img\b[^>]*\bsrc\s*=\s*["\']\s*["\']/i', $content)) {
            return '';
        }

        return $content;
    }

    public function register_category($elements_manager) {
        $elements_manager->add_category('headcount', array(
            'title' => __('Headcount', 'headcount'),
            'icon' => 'fa fa-calendar',
        ));
    }

    /**
     * @param \Elementor\Widgets_Manager $widgets_manager
     */
    public function register_widgets($widgets_manager) {
        require_once HEADCOUNT_PLUGIN_DIR . 'includes/Elementor/Widgets/EventsLoop.php';
        $widget = new Widgets\EventsLoop();
        if (method_exists($widgets_manager, 'register')) {
            $widgets_manager->register($widget);
        } else {
            $widgets_manager->register_widget_type($widget);
        }
    }

    /**
     * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags
     */
    public function register_dynamic_tags($dynamic_tags) {
        $dynamic_tags->register_group('headcount-event', array(
            'title' => __('Headcount Event', 'headcount'),
        ));

        require_once HEADCOUNT_PLUGIN_DIR . 'includes/Elementor/Tags/EventTags.php';

        $tag_classes = array(
            Tags\EventTitleTag::class,
            Tags\EventDateTag::class,
            Tags\EventTimeTag::class,
            Tags\EventDayTag::class,
            Tags\EventMonthTag::class,
            Tags\EventYearTag::class,
            Tags\EventWeekdayTag::class,
            Tags\EventLocationTag::class,
            Tags\EventCategoryTag::class,
            Tags\EventExcerptTag::class,
            Tags\EventImageUrlTag::class,
            Tags\EventLinkUrlTag::class,
            Tags\EventPriceTag::class,
            Tags\EventIdTag::class,
        );

        foreach ($tag_classes as $class) {
            if (class_exists($class)) {
                $dynamic_tags->register(new $class());
            }
        }
    }
}
