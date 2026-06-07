<?php
namespace Headcount\Core;

/**
 * Granular facility field shortcodes and facilities loop wrapper.
 */
class FacilityFieldShortcodes {

    /** @var APIClient */
    private $api_client;

    /** @var FacilityPresenter */
    private $presenter;

    /** @var FacilitiesLoopRenderer */
    private $loop_renderer;

    /** @var array<string,string> */
    private $field_aliases = array(
        'headcount_facility_title' => 'title',
        'headcount_facility_name' => 'name',
        'headcount_facility_location' => 'location',
        'headcount_facility_description' => 'description',
        'headcount_facility_excerpt' => 'excerpt',
        'headcount_facility_image' => 'image',
        'headcount_facility_link' => 'link',
        'headcount_facility_price' => 'price',
        'headcount_facility_slug' => 'slug',
        'headcount_facility_id' => 'id',
        'headcount_facility_guest_book_link' => 'guest_book_link',
        'headcount_facility_member_book_link' => 'member_book_link',
    );

    public function __construct(APIClient $api_client) {
        $this->api_client = $api_client;
        $this->presenter = new FacilityPresenter();
        $this->loop_renderer = new FacilitiesLoopRenderer($api_client);
        FacilityLoopContext::set_api_client($api_client);
    }

    public function register() {
        add_shortcode('headcount_facility_field', array($this, 'field_shortcode'));
        add_shortcode('headcount_facilities_loop', array($this, 'facilities_loop_shortcode'));

        foreach ($this->field_aliases as $shortcode_tag => $field) {
            add_shortcode($shortcode_tag, function ($atts, $content = null) use ($field) {
                $atts = is_array($atts) ? $atts : array();
                $atts['field'] = $field;
                return $this->field_shortcode($atts, $content, 'headcount_facility_field');
            });
        }
    }

    /**
     * @param array|string $atts
     * @param string|null  $content
     * @param string       $tag
     * @return string
     */
    public function field_shortcode($atts, $content = null, $tag = 'headcount_facility_field') {
        $atts = shortcode_atts(array(
            'field' => '',
            'id' => '',
            'slug' => '',
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

        $facility = $this->resolve_facility($atts['id'], $atts['slug']);
        if ($facility === null) {
            return $atts['fallback'] !== '' ? esc_html($atts['fallback']) : '';
        }

        wp_enqueue_style('headcount-styles');

        return $this->presenter->render_field($facility, $field, $atts);
    }

    /**
     * @param array|string $atts
     * @param string|null  $content
     * @return string
     */
    public function facilities_loop_shortcode($atts, $content = null) {
        $atts = shortcode_atts(array(
            'limit' => '12',
            'sort_by' => 'name',
            'layout' => 'grid',
            'item_class' => '',
            'columns' => '3',
            'gap' => '24',
        ), $atts, 'headcount_facilities_loop');

        wp_enqueue_style('headcount-styles');

        $result = $this->loop_renderer->fetch_facilities(array(
            'limit' => (int) $atts['limit'],
            'sort_by' => $atts['sort_by'],
        ));

        if (!$result['success']) {
            return Renderer::render('error', array(
                'message' => $result['message'] ?? __('Unable to load facilities.', 'headcount'),
            ));
        }

        if (empty($result['facilities'])) {
            return Renderer::render('empty', array(
                'title' => __('No facilities', 'headcount'),
                'message' => __('There are no bookable facilities to show yet.', 'headcount'),
            ));
        }

        $wrap = array(
            'layout' => $atts['layout'],
            'item_class' => $atts['item_class'],
            'columns' => (int) $atts['columns'],
            'gap' => (int) $atts['gap'],
        );

        $inner = $content !== null ? trim($content) : '';
        if ($inner === '') {
            return Renderer::render('empty', array(
                'title' => __('Loop template empty', 'headcount'),
                'message' => __('Add shortcodes inside [headcount_facilities_loop] or use [headcount_facilities] for the default grid.', 'headcount'),
            ));
        }

        return $this->loop_renderer->render_loop($result['facilities'], function () use ($inner) {
            return do_shortcode($inner);
        }, $wrap);
    }

    /**
     * @param string|int $id
     * @param string     $slug
     * @return array|null
     */
    private function resolve_facility($id, $slug) {
        $current = FacilityLoopContext::current();
        $id = (int) $id;
        $slug = trim((string) $slug);

        if ($id <= 0 && $slug === '') {
            return $current;
        }

        if ($current !== null) {
            if ($id > 0 && (int) ($current['id'] ?? 0) === $id) {
                return $current;
            }
            if ($slug !== '' && ($current['slug'] ?? '') === $slug) {
                return $current;
            }
        }

        return FacilityLoopContext::get_facility_by_id_or_slug($id, $slug);
    }
}
