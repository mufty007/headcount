<?php
namespace Headcount\Elementor\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Headcount\Core\APIClient;
use Headcount\Core\EventsLoopRenderer;
use Headcount\Core\EventLoopContext;
use Headcount\Core\Renderer;

/**
 * Repeats an Elementor template for each upcoming Headcount event.
 */
class EventsLoop extends Widget_Base {

    public function get_name() {
        return 'headcount_events_loop';
    }

    public function get_title() {
        return __('Headcount Events Loop', 'headcount');
    }

    public function get_icon() {
        return 'eicon-post-list';
    }

    public function get_categories() {
        return array('headcount');
    }

    public function get_keywords() {
        return array('headcount', 'events', 'loop', 'calendar');
    }

    protected function register_controls() {
        $this->start_controls_section('section_query', array(
            'label' => __('Query', 'headcount'),
        ));

        $this->add_control('limit', array(
            'label' => __('Limit', 'headcount'),
            'type' => Controls_Manager::NUMBER,
            'default' => 12,
            'min' => 1,
            'max' => 100,
        ));

        $this->add_control('category', array(
            'label' => __('Category', 'headcount'),
            'type' => Controls_Manager::TEXT,
            'default' => '',
        ));

        $this->add_control('sort_by', array(
            'label' => __('Sort by', 'headcount'),
            'type' => Controls_Manager::SELECT,
            'default' => 'date',
            'options' => array(
                'date' => __('Date', 'headcount'),
                'title' => __('Title', 'headcount'),
                'category' => __('Category', 'headcount'),
            ),
        ));

        $this->end_controls_section();

        $this->start_controls_section('section_template', array(
            'label' => __('Template', 'headcount'),
        ));

        $this->add_control('template_id', array(
            'label' => __('Elementor template', 'headcount'),
            'type' => Controls_Manager::SELECT,
            'default' => '',
            'options' => $this->get_elementor_templates(),
            'description' => __('Create a Section in Templates → Saved Templates. Use Headcount Event dynamic tags inside. Image widgets are hidden automatically when an event has no banner. Remove the Image widget from your template if you never want images.', 'headcount'),
        ));

        $this->add_control('empty_message', array(
            'label' => __('Empty message', 'headcount'),
            'type' => Controls_Manager::TEXTAREA,
            'default' => __('No upcoming events.', 'headcount'),
        ));

        $this->end_controls_section();

        $this->start_controls_section('section_layout', array(
            'label' => __('Layout', 'headcount'),
        ));

        $this->add_responsive_control('columns', array(
            'label' => __('Columns', 'headcount'),
            'type' => Controls_Manager::NUMBER,
            'default' => 3,
            'min' => 1,
            'max' => 6,
            'tablet_default' => 2,
            'mobile_default' => 1,
            'selectors' => array(
                '{{WRAPPER}} .hc-events-loop--grid' => '--hc-loop-columns: {{VALUE}};',
            ),
        ));

        $this->add_control('gap', array(
            'label' => __('Gap (px)', 'headcount'),
            'type' => Controls_Manager::NUMBER,
            'default' => 24,
            'min' => 0,
            'max' => 80,
            'selectors' => array(
                '{{WRAPPER}} .hc-events-loop--grid' => '--hc-loop-gap: {{VALUE}}px;',
            ),
        ));

        $this->end_controls_section();
    }

    /**
     * @return array<string,string>
     */
    private function get_elementor_templates() {
        $options = array('' => __('— Select template —', 'headcount'));

        $posts = get_posts(array(
            'post_type' => 'elementor_library',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ));

        foreach ($posts as $post) {
            $options[(string) $post->ID] = $post->post_title . ' (#' . $post->ID . ')';
        }

        return $options;
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        wp_enqueue_style('headcount-styles');

        EventLoopContext::set_api_client(new APIClient());

        $renderer = new EventsLoopRenderer(new APIClient());
        $result = $renderer->fetch_events(array(
            'limit' => (int) ($settings['limit'] ?? 12),
            'category' => $settings['category'] ?? '',
            'sort_by' => $settings['sort_by'] ?? 'date',
            'show_past' => false,
        ));

        if (!$result['success']) {
            echo Renderer::render('error', array(
                'message' => $result['message'] ?? __('Unable to load events.', 'headcount'),
            ));
            return;
        }

        if (empty($result['events'])) {
            $msg = !empty($settings['empty_message'])
                ? $settings['empty_message']
                : __('No upcoming events.', 'headcount');
            echo '<div class="hc-events-loop-empty">' . esc_html($msg) . '</div>';
            return;
        }

        $template_id = (int) ($settings['template_id'] ?? 0);
        if ($template_id <= 0) {
            echo '<div class="hc-events-loop-empty">' . esc_html__(
                'Select an Elementor template in the widget settings.',
                'headcount'
            ) . '</div>';
            return;
        }

        $columns = (int) ($settings['columns'] ?? 3);
        if (!empty($settings['columns_tablet'])) {
            // Responsive control stores device-specific keys when rendered
        }

        $wrap = array(
            'layout' => 'grid',
            'columns' => $columns > 0 ? $columns : 3,
            'gap' => (int) ($settings['gap'] ?? 24),
        );

        echo $renderer->render_elementor_loop($result['events'], $template_id, $wrap);
    }
}
