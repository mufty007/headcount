<?php
/**
 * @var array $programs
 * @var array $atts
 * @var string $portal_base_url
 */
if (!isset($programs)) {
    $programs = array();
}
$portal_base_url = isset($portal_base_url) ? rtrim((string) $portal_base_url, '/') : '';

/**
 * @param array $prog
 */
$hc_program_location_line = static function ($prog) {
    if (!empty($prog['is_virtual'])) {
        $loc = isset($prog['location']) ? trim((string) $prog['location']) : '';
        return $loc !== '' ? __('Virtual · ', 'headcount') . $loc : __('Virtual', 'headcount');
    }
    return isset($prog['location']) ? trim((string) $prog['location']) : '';
};

/**
 * @param array $prog
 */
$hc_program_next_session_label = static function ($prog) {
    if (empty($prog['next_session']['session_date'])) {
        return '';
    }
    $ts = strtotime($prog['next_session']['session_date']);
    if ($ts === false) {
        return '';
    }
    $label = date_i18n(get_option('date_format', 'M j, Y'), $ts);
    $timeRaw = isset($prog['next_session']['start_time']) ? trim((string) $prog['next_session']['start_time']) : '';
    if ($timeRaw !== '') {
        $tts = strtotime($timeRaw);
        if ($tts !== false) {
            $label .= ' ' . date_i18n(get_option('time_format', 'g:i A'), $tts);
        }
    }
    return $label;
};
?>
<div class="hc-events-grid hc-programs-grid">
    <?php foreach ($programs as $prog) :
        $pid = (int) ($prog['id'] ?? 0);
        $prog_url = ($portal_base_url !== '' && $pid > 0)
            ? $portal_base_url . '/program-details.php?id=' . $pid
            : '';
        $location_line = $hc_program_location_line($prog);
        $next_label = $hc_program_next_session_label($prog);
        $is_free = (($prog['pricing_type'] ?? 'free') === 'free');
        $price_amount = isset($prog['price_amount']) ? (float) $prog['price_amount'] : 0.0;
        ?>
        <article class="hc-event-card hc-program-card">
            <div class="hc-event-image-wrap">
                <?php if (!empty($prog['banner_image_url']) || !empty($prog['banner_image'])) : ?>
                    <img src="<?php echo esc_url($prog['banner_image_url'] ?? $prog['banner_image']); ?>" alt="<?php echo esc_attr($prog['title'] ?? ''); ?>" class="hc-event-img" loading="lazy" width="640" height="360">
                <?php else : ?>
                    <div class="hc-event-placeholder hc-program-placeholder">
                        <span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($prog['category_name'])) : ?>
                    <span class="hc-event-badge"><?php echo esc_html($prog['category_name']); ?></span>
                <?php endif; ?>
            </div>
            <div class="hc-event-content">
                <h3 class="hc-event-title"><?php echo esc_html($prog['title'] ?? ''); ?></h3>

                <?php if ($location_line !== '' || $next_label !== '') : ?>
                    <div class="hc-event-meta">
                        <?php if ($location_line !== '') : ?>
                            <div class="hc-meta-item">
                                <span class="dashicons dashicons-location" aria-hidden="true"></span>
                                <span><?php echo esc_html($location_line); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($next_label !== '') : ?>
                            <div class="hc-meta-item">
                                <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                                <span><?php echo esc_html($next_label); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="hc-event-footer">
                    <div class="hc-event-price">
                        <?php if ($is_free) : ?>
                            <span class="hc-free-label"><?php echo esc_html__('Free', 'headcount'); ?></span>
                        <?php else : ?>
                            <span><?php echo esc_html('$' . number_format($price_amount, 2)); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($prog_url !== '') : ?>
                        <a href="<?php echo esc_url($prog_url); ?>" class="hc-btn-rsvp-small" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Learn more', 'headcount'); ?></a>
                    <?php else : ?>
                        <span class="hc-program-link-placeholder" title="<?php echo esc_attr__('Set Member portal base URL or Event details URL in Headcount settings.', 'headcount'); ?>"><?php echo esc_html__('Learn more', 'headcount'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
