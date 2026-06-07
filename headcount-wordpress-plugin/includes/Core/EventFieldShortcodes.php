<?php
namespace Headcount\Core;

/**
 * Granular event field shortcodes and events loop wrapper.
 */
class EventFieldShortcodes {

    /** @var APIClient */
    private $api_client;

    /** @var EventPresenter */
    private $presenter;

    /** @var EventsLoopRenderer */
    private $loop_renderer;

    /** @var array<string,string> */
    private $field_aliases = array(
        'headcount_event_title' => 'title',
        'headcount_event_date' => 'date',
        'headcount_event_time' => 'time',
        'headcount_event_day' => 'day',
        'headcount_event_month' => 'month',
        'headcount_event_year' => 'year',
        'headcount_event_weekday' => 'weekday',
        'headcount_event_location' => 'location',
        'headcount_event_category' => 'category',
        'headcount_event_description' => 'description',
        'headcount_event_excerpt' => 'excerpt',
        'headcount_event_image' => 'image',
        'headcount_event_link' => 'link',
        'headcount_event_price' => 'price',
        'headcount_event_id' => 'id',
        'headcount_event_spots' => 'spots',
    );

    public function __construct(APIClient $api_client) {
        $this->api_client = $api_client;
        $this->presenter = new EventPresenter();
        $this->loop_renderer = new EventsLoopRenderer($api_client);
        EventLoopContext::set_api_client($api_client);
    }

    public function register() {
        add_shortcode('headcount_event_field', array($this, 'field_shortcode'));
        add_shortcode('headcount_events_loop', array($this, 'events_loop_shortcode'));

        foreach ($this->field_aliases as $shortcode_tag => $field) {
            add_shortcode($shortcode_tag, function ($atts, $content = null) use ($field) {
                $atts = is_array($atts) ? $atts : array();
                $atts['field'] = $field;
                return $this->field_shortcode($atts, $content, 'headcount_event_field');
            });
        }
    }

    /**
     * @param array|string $atts
     * @param string|null  $content
     * @param string       $tag
     * @return string
     */
    public function field_shortcode($atts, $content = null, $tag = 'headcount_event_field') {
        $atts = shortcode_atts(array(
            'field' => '',
            'id' => '',
            'class' => '',
            'tag' => '',
            'fallback' => '',
            'format' => '',
            'text' => '',
            'alt' => '',
        ), $atts, $tag);

        $field = strtolower(trim((string) $atts['field']));
        if ($field === '') {
            return '';
        }

        $event = $this->resolve_event($atts['id']);
        if ($event === null) {
            return $atts['fallback'] !== '' ? esc_html($atts['fallback']) : '';
        }

        wp_enqueue_style('headcount-styles');

        return $this->presenter->render_field($event, $field, $atts);
    }

    /**
     * @param array|string $atts
     * @param string|null  $content
     * @return string
     */
    public function events_loop_shortcode($atts, $content = null) {
        $atts = shortcode_atts(array(
            'limit' => '12',
            'category' => '',
            'sort_by' => 'date',
            'layout' => 'default',
            'item_class' => '',
            'template_id' => '',
            'columns' => '3',
            'gap' => '24',
        ), $atts, 'headcount_events_loop');

        wp_enqueue_style('headcount-styles');

        $result = $this->loop_renderer->fetch_events(array(
            'limit' => (int) $atts['limit'],
            'category' => $atts['category'],
            'sort_by' => $atts['sort_by'],
            'show_past' => false,
        ));

        if (!$result['success']) {
            return Renderer::render('error', array(
                'message' => $result['message'] ?? __('Unable to load events.', 'headcount'),
            ));
        }

        if (empty($result['events'])) {
            return Renderer::render('empty', array(
                'title' => __('No upcoming events', 'headcount'),
                'message' => __('There are no events to show yet. Check back soon for new dates.', 'headcount'),
            ));
        }

        $wrap = array(
            'layout' => $atts['layout'],
            'item_class' => $atts['item_class'],
            'columns' => (int) $atts['columns'],
            'gap' => (int) $atts['gap'],
        );

        $template_id = (int) $atts['template_id'];
        if ($template_id > 0 && class_exists('\Elementor\Plugin')) {
            return $this->loop_renderer->render_elementor_loop($result['events'], $template_id, $wrap);
        }

        $inner = $content !== null ? trim($content) : '';
        if ($inner === '') {
            return Renderer::render('empty', array(
                'title' => __('Loop template empty', 'headcount'),
                'message' => __('Add shortcodes inside [headcount_events_loop] or set template_id for an Elementor template.', 'headcount'),
            ));
        }

        return $this->loop_renderer->render_loop($result['events'], function () use ($inner) {
            return do_shortcode($inner);
        }, $wrap);
    }

    /**
     * @param string|int $id
     * @return array|null
     */
    private function resolve_event($id) {
        $current = EventLoopContext::current();
        if ($id === '' || $id === null) {
            return $current;
        }
        $id = (int) $id;
        if ($id <= 0) {
            return $current;
        }
        if ($current !== null && (int) ($current['id'] ?? 0) === $id) {
            return $current;
        }
        return EventLoopContext::get_event_by_id($id);
    }
}
