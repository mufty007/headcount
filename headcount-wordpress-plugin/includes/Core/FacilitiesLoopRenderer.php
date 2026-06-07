<?php
namespace Headcount\Core;

/**
 * Fetches facilities and renders loop items.
 */
class FacilitiesLoopRenderer {

    /** @var APIClient */
    private $api_client;

    /** @var FacilityPresenter */
    private $presenter;

    public function __construct(APIClient $api_client) {
        $this->api_client = $api_client;
        $this->presenter = new FacilityPresenter();
    }

    /**
     * @param array $args limit, sort_by
     * @return array{success:bool,facilities:array[],message?:string}
     */
    public function fetch_facilities(array $args = array()) {
        $defaults = array(
            'limit' => 50,
            'sort_by' => 'name',
        );
        $args = wp_parse_args($args, $defaults);

        $response = $this->api_client->get_facilities(array(
            'limit' => (int) $args['limit'],
        ));

        if (empty($response['success'])) {
            return array(
                'success' => false,
                'facilities' => array(),
                'message' => $response['message'] ?? __('Unable to load facilities.', 'headcount'),
            );
        }

        $facilities = $this->presenter->format_facilities($response['facilities'] ?? array());
        $facilities = $this->sort_facilities($facilities, $args['sort_by']);

        return array(
            'success' => true,
            'facilities' => $facilities,
        );
    }

    /**
     * @param array[]  $facilities
     * @param callable $render_item
     * @param array    $wrap
     * @return string
     */
    public function render_loop(array $facilities, callable $render_item, array $wrap = array()) {
        $layout = isset($wrap['layout']) ? (string) $wrap['layout'] : 'grid';
        $item_class = isset($wrap['item_class']) ? sanitize_html_class($wrap['item_class']) : '';
        $columns = max(1, min(6, (int) ($wrap['columns'] ?? 3)));
        $gap = isset($wrap['gap']) ? (int) $wrap['gap'] : 24;

        $wrapper_class = 'hc-facilities-loop';
        if ($layout === 'grid') {
            $wrapper_class .= ' hc-facilities-loop--grid hc-events-loop--grid';
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

        foreach ($facilities as $facility) {
            FacilityLoopContext::push($facility);
            $item_html = (string) call_user_func($render_item, $facility);
            FacilityLoopContext::pop();

            $has_image = self::facility_has_image($facility);
            $classes = 'hc-facility-loop-item hc-event-loop-item';
            if (!$has_image) {
                $classes .= ' hc-event-loop-item--no-image';
            }
            if ($item_class !== '') {
                $classes .= ' ' . $item_class;
            }
            $html .= '<div class="' . esc_attr($classes) . '" data-facility-id="' . esc_attr((string) ($facility['id'] ?? '')) . '">';
            $html .= $item_html;
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param array $facility
     */
    public static function facility_has_image(array $facility) {
        $url = trim((string) ($facility['thumbnail_url'] ?? $facility['image'] ?? ''));
        return $url !== '' && $url !== '#';
    }

    /**
     * @param array[] $facilities
     * @param string  $sort_by
     * @return array[]
     */
    private function sort_facilities(array $facilities, $sort_by) {
        if ($sort_by === 'name' || $sort_by === 'title') {
            usort($facilities, function ($a, $b) {
                return strcmp($a['title'] ?? '', $b['title'] ?? '');
            });
        }
        return $facilities;
    }
}
