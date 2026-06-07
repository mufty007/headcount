<?php
namespace Headcount\Core;

/**
 * Fetches upcoming events and renders loop items (shortcode content or Elementor template).
 */
class EventsLoopRenderer {

    /** @var APIClient */
    private $api_client;

    /** @var EventPresenter */
    private $presenter;

    public function __construct(APIClient $api_client) {
        $this->api_client = $api_client;
        $this->presenter = new EventPresenter();
    }

    /**
     * @param array $args limit, category, sort_by, show_past
     * @return array{success:bool,events:array[],message?:string}
     */
    public function fetch_events(array $args = array()) {
        $defaults = array(
            'limit' => 10,
            'category' => '',
            'sort_by' => 'date',
            'show_past' => false,
        );
        $args = wp_parse_args($args, $defaults);

        $response = $this->api_client->get_events(array(
            'limit' => (int) $args['limit'],
            'category' => $args['category'],
            'show_past' => !empty($args['show_past']),
        ));

        if (empty($response['success'])) {
            return array(
                'success' => false,
                'events' => array(),
                'message' => $response['message'] ?? __('Unable to load events.', 'headcount'),
            );
        }

        $events = $this->presenter->format_events($response['events'] ?? array());
        $events = $this->sort_events($events, $args['sort_by']);

        return array(
            'success' => true,
            'events' => $events,
        );
    }

    /**
     * @param array[]  $events
     * @param callable $render_item function(array $event): string
     * @param array    $wrap        layout, item_class, columns, gap
     * @return string
     */
    public function render_loop(array $events, callable $render_item, array $wrap = array()) {
        $layout = isset($wrap['layout']) ? (string) $wrap['layout'] : 'default';
        $item_class = isset($wrap['item_class']) ? sanitize_html_class($wrap['item_class']) : '';
        $columns = max(1, min(6, (int) ($wrap['columns'] ?? 3)));
        $gap = isset($wrap['gap']) ? (int) $wrap['gap'] : 24;

        $wrapper_class = 'hc-events-loop';
        if ($layout === 'grid') {
            $wrapper_class .= ' hc-events-loop--grid';
        }

        $style = '';
        if ($layout === 'grid') {
            $style = sprintf(
                ' style="--hc-loop-columns:%d;--hc-loop-gap:%dpx;"',
                $columns,
                $gap
            );
        }

        $html = '<div class="' . esc_attr($wrapper_class) . '"' . $style . '>';

        foreach ($events as $event) {
            EventLoopContext::push($event);
            $item_html = (string) call_user_func($render_item, $event);
            EventLoopContext::pop();

            if (stripos($item_html, 'elementor') !== false) {
                $item_html = ElementorLoopSanitizer::sanitize_item_html($item_html, $event);
            }

            $has_image = stripos($item_html, 'elementor') !== false
                ? ElementorLoopSanitizer::html_contains_valid_image($item_html)
                : self::event_has_banner_image($event);
            $classes = 'hc-event-loop-item';
            if (!$has_image) {
                $classes .= ' hc-event-loop-item--no-image';
            }
            if ($item_class !== '') {
                $classes .= ' ' . $item_class;
            }
            $html .= '<div class="' . esc_attr($classes) . '" data-event-id="' . esc_attr((string) ($event['id'] ?? '')) . '" data-hc-has-image="' . ($has_image ? '1' : '0') . '">';
            $html .= $item_html;
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render Elementor template per event.
     *
     * @param array[] $events
     * @param int     $template_id
     * @param array   $wrap
     * @return string
     */
    public function render_elementor_loop(array $events, $template_id, array $wrap = array()) {
        if (!class_exists('\Elementor\Plugin') || $template_id <= 0) {
            return '';
        }

        return $this->render_loop($events, function () use ($template_id) {
            return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id, true);
        }, $wrap);
    }

    /**
     * @param array $event Formatted event row
     */
    public static function event_has_banner_image(array $event) {
        $url = trim((string) ($event['banner_image'] ?? ''));
        if ($url === '' || $url === '#' || strtolower($url) === 'null') {
            return false;
        }
        if (preg_match('#/image\.php\?path=$#i', $url) || preg_match('#/image\.php$#i', $url)) {
            return false;
        }
        return true;
    }

    /**
     * @param array[] $events
     * @param string  $sort_by
     * @return array[]
     */
    private function sort_events($events, $sort_by) {
        switch ($sort_by) {
            case 'title':
                usort($events, function ($a, $b) {
                    return strcmp($a['title'] ?? '', $b['title'] ?? '');
                });
                break;
            case 'category':
                usort($events, function ($a, $b) {
                    return strcmp($a['category'] ?? '', $b['category'] ?? '');
                });
                break;
            case 'date':
            default:
                break;
        }
        return $events;
    }
}
