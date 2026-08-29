<?php
/**
 * Mixed events + programs catalog.
 *
 * @var array $items
 * @var array $categories
 * @var array $atts
 * @var array $filters
 * @var array $pagination
 */
if (!isset($items)) {
    $items = array();
}
if (!isset($categories)) {
    $categories = array();
}
if (!isset($filters)) {
    $filters = array();
}
if (!isset($atts)) {
    $atts = array();
}
if (!isset($pagination)) {
    $pagination = array('show' => false);
}

$layout = isset($atts['layout']) ? $atts['layout'] : 'sidebar';
$show_filters = isset($atts['show_filters']) && $atts['show_filters'] === 'true';
$show_search = isset($atts['show_search']) && $atts['show_search'] === 'true';
$locked = isset($filters['locked_types']) ? $filters['locked_types'] : 'all';
$current_type = isset($filters['type']) ? $filters['type'] : 'all';
$current_search = isset($filters['search']) ? $filters['search'] : '';
$current_category = isset($filters['category']) ? $filters['category'] : '';
$current_from = isset($filters['date_from']) ? $filters['date_from'] : '';
$current_to = isset($filters['date_to']) ? $filters['date_to'] : '';

$hc_listings_url = static function (array $overrides = []) use ($filters, $locked) {
    $q = array(
        'hc_type' => $filters['type'] ?? 'all',
        'hc_search' => $filters['search'] ?? '',
        'hc_category' => $filters['category'] ?? '',
        'hc_date_from' => $filters['date_from'] ?? '',
        'hc_date_to' => $filters['date_to'] ?? '',
    );
    foreach ($overrides as $k => $v) {
        $q[$k] = $v;
    }
    if ($locked !== 'all') {
        unset($q['hc_type']);
    } elseif (($q['hc_type'] ?? 'all') === 'all') {
        unset($q['hc_type']);
    }
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    unset($q['hc_page']);
    if (!empty($overrides['hc_page']) && (int) $overrides['hc_page'] > 1) {
        $q['hc_page'] = (int) $overrides['hc_page'];
    }
    return esc_url(add_query_arg($q, remove_query_arg(array('hc_type', 'hc_search', 'hc_category', 'hc_date_from', 'hc_date_to', 'hc_page'))));
};

$layout_class = $layout === 'stacked' ? 'hc-listings hc-listings--stacked' : 'hc-listings hc-listings--sidebar';
?>
<div class="<?php echo esc_attr($layout_class); ?>">
    <?php if ($show_filters) : ?>
    <aside class="hc-listings-sidebar" aria-label="<?php echo esc_attr__('Listing filters', 'headcount'); ?>">
        <form method="get" class="hc-listings-filters" action="<?php echo esc_url(is_string(get_permalink()) ? get_permalink() : ''); ?>">
            <?php if (!get_option('permalink_structure') && is_singular()) : ?>
                <?php if (is_page()) : ?>
                    <input type="hidden" name="page_id" value="<?php echo esc_attr((string) get_queried_object_id()); ?>">
                <?php else : ?>
                    <input type="hidden" name="p" value="<?php echo esc_attr((string) get_queried_object_id()); ?>">
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($locked === 'all') : ?>
            <div class="hc-listings-filter-group">
                <span class="hc-listings-label"><?php echo esc_html__('Type', 'headcount'); ?></span>
                <div class="hc-filter-bar hc-listings-type-chips">
                    <a href="<?php echo $hc_listings_url(array('hc_type' => 'all')); ?>" class="hc-filter-btn<?php echo $current_type === 'all' ? ' active' : ''; ?>"><?php echo esc_html__('All', 'headcount'); ?></a>
                    <a href="<?php echo $hc_listings_url(array('hc_type' => 'event')); ?>" class="hc-filter-btn<?php echo $current_type === 'event' ? ' active' : ''; ?>"><?php echo esc_html__('Events', 'headcount'); ?></a>
                    <a href="<?php echo $hc_listings_url(array('hc_type' => 'program')); ?>" class="hc-filter-btn<?php echo $current_type === 'program' ? ' active' : ''; ?>"><?php echo esc_html__('Programs', 'headcount'); ?></a>
                </div>
                <input type="hidden" name="hc_type" value="<?php echo esc_attr($current_type); ?>">
            </div>
            <?php endif; ?>

            <?php if ($show_search) : ?>
            <div class="hc-listings-filter-group">
                <label class="hc-listings-label" for="hc-listings-search"><?php echo esc_html__('Search', 'headcount'); ?></label>
                <input id="hc-listings-search" type="search" name="hc_search" value="<?php echo esc_attr($current_search); ?>" placeholder="<?php echo esc_attr__('Search…', 'headcount'); ?>" class="hc-listings-input">
            </div>
            <?php endif; ?>

            <?php if (!empty($categories)) : ?>
            <div class="hc-listings-filter-group">
                <label class="hc-listings-label" for="hc-listings-category"><?php echo esc_html__('Category', 'headcount'); ?></label>
                <select id="hc-listings-category" name="hc_category" class="hc-listings-input">
                    <option value=""><?php echo esc_html__('All categories', 'headcount'); ?></option>
                    <?php foreach ($categories as $cat) :
                        $val = is_array($cat) ? ($cat['value'] ?? '') : (string) $cat;
                        $label = is_array($cat) ? ($cat['label'] ?? $val) : (string) $cat;
                        ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($current_category, $val); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="hc-listings-filter-group">
                <label class="hc-listings-label" for="hc-listings-from"><?php echo esc_html__('From date', 'headcount'); ?></label>
                <input id="hc-listings-from" type="date" name="hc_date_from" value="<?php echo esc_attr($current_from); ?>" class="hc-listings-input">
            </div>
            <div class="hc-listings-filter-group">
                <label class="hc-listings-label" for="hc-listings-to"><?php echo esc_html__('To date', 'headcount'); ?></label>
                <input id="hc-listings-to" type="date" name="hc_date_to" value="<?php echo esc_attr($current_to); ?>" class="hc-listings-input">
            </div>

            <div class="hc-listings-filter-actions">
                <button type="submit" class="hc-btn-rsvp-small"><?php echo esc_html__('Apply', 'headcount'); ?></button>
                <a href="<?php echo esc_url(remove_query_arg(array('hc_type', 'hc_search', 'hc_category', 'hc_date_from', 'hc_date_to', 'hc_page'))); ?>" class="hc-listings-clear"><?php echo esc_html__('Clear', 'headcount'); ?></a>
            </div>
        </form>
    </aside>
    <?php endif; ?>

    <div class="hc-listings-main">
        <?php if (empty($items)) : ?>
            <div class="hc-empty-state hc-empty-inline" role="status">
                <h3 class="hc-empty-title"><?php echo esc_html__('Nothing to show', 'headcount'); ?></h3>
                <p class="hc-empty-text"><?php echo esc_html__('Try another filter or check back soon.', 'headcount'); ?></p>
            </div>
        <?php else : ?>
            <p class="hc-listings-count">
                <?php
                echo esc_html(sprintf(
                    /* translators: %d: number of listings */
                    _n('%d result', '%d results', (int) ($pagination['total'] ?? count($items)), 'headcount'),
                    (int) ($pagination['total'] ?? count($items))
                ));
                ?>
            </p>
            <div class="hc-events-grid hc-listings-grid">
                <?php foreach ($items as $item) :
                    $kind = (string) ($item['type'] ?? 'event');
                    $title = (string) ($item['title'] ?? '');
                    $image = (string) ($item['image_url'] ?? '');
                    $cat = (string) ($item['category'] ?? '');
                    $meta = (string) ($item['meta_line'] ?? '');
                    $location = (string) ($item['location'] ?? '');
                    $cta_url = (string) ($item['cta_url'] ?? '');
                    $cta_label = (string) ($item['cta_label'] ?? __('Details', 'headcount'));
                    $is_program = $kind === 'program';
                    ?>
                    <article class="hc-event-card<?php echo $is_program ? ' hc-program-card' : ''; ?>">
                        <div class="hc-event-image-wrap">
                            <?php if ($image !== '') : ?>
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" class="hc-event-img" loading="lazy" width="640" height="360">
                            <?php else : ?>
                                <div class="hc-event-placeholder<?php echo $is_program ? ' hc-program-placeholder' : ''; ?>">
                                    <span class="dashicons <?php echo $is_program ? 'dashicons-welcome-learn-more' : 'dashicons-calendar-alt'; ?>" aria-hidden="true"></span>
                                </div>
                            <?php endif; ?>
                            <span class="hc-type-chip<?php echo $is_program ? ' hc-type-chip--program' : ' hc-type-chip--event'; ?>">
                                <?php echo $is_program ? esc_html__('Program', 'headcount') : esc_html__('Event', 'headcount'); ?>
                            </span>
                            <?php if ($cat !== '') : ?>
                                <span class="hc-event-badge"><?php echo esc_html($cat); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="hc-event-content">
                            <h3 class="hc-event-title"><?php echo esc_html($title); ?></h3>
                            <div class="hc-event-meta">
                                <?php if ($meta !== '') : ?>
                                    <div class="hc-meta-item">
                                        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                                        <span><?php echo esc_html($meta); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($location !== '') : ?>
                                    <div class="hc-meta-item">
                                        <span class="dashicons dashicons-location" aria-hidden="true"></span>
                                        <span><?php echo esc_html($location); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="hc-event-footer">
                                <div class="hc-event-price">
                                    <?php if (!empty($item['is_free'])) : ?>
                                        <span class="hc-free-label"><?php echo esc_html__('Free', 'headcount'); ?></span>
                                    <?php else : ?>
                                        <span><?php echo esc_html((string) ($item['price_label'] ?? '')); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($cta_url !== '') : ?>
                                    <a href="<?php echo esc_url($cta_url); ?>" class="hc-btn-rsvp-small" target="_blank" rel="noopener noreferrer"><?php echo esc_html($cta_label); ?></a>
                                <?php else : ?>
                                    <span class="hc-program-link-placeholder"><?php echo esc_html($cta_label); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($pagination['show'])) :
            $current = (int) $pagination['current_page'];
            $total_pages = (int) $pagination['total_pages'];
            ?>
            <nav class="hc-pagination" aria-label="<?php echo esc_attr__('Listings pagination', 'headcount'); ?>">
                <?php if ($current > 1) : ?>
                    <a href="<?php echo $hc_listings_url(array('hc_page' => $current - 1)); ?>" class="hc-page-btn"><?php echo esc_html__('Previous', 'headcount'); ?></a>
                <?php endif; ?>
                <div class="hc-page-numbers">
                    <?php
                    $start = max(1, $current - 2);
                    $end = min($total_pages, $current + 2);
                    for ($i = $start; $i <= $end; $i++) :
                        if ($i === $current) : ?>
                            <span class="hc-page-current"><?php echo (int) $i; ?></span>
                        <?php else : ?>
                            <a href="<?php echo $hc_listings_url(array('hc_page' => $i)); ?>" class="hc-page-link"><?php echo (int) $i; ?></a>
                        <?php endif;
                    endfor; ?>
                </div>
                <?php if ($current < $total_pages) : ?>
                    <a href="<?php echo $hc_listings_url(array('hc_page' => $current + 1)); ?>" class="hc-page-btn"><?php echo esc_html__('Next', 'headcount'); ?></a>
                <?php endif; ?>
                <div class="hc-page-info">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: current page, 2: total pages, 3: total items */
                        __('Page %1$d of %2$d (%3$d items)', 'headcount'),
                        $current,
                        $total_pages,
                        (int) ($pagination['total'] ?? 0)
                    ));
                    ?>
                </div>
            </nav>
        <?php endif; ?>
    </div>
</div>
