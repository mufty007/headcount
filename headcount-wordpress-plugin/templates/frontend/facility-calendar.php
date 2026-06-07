<?php
/**
 * Facility availability calendar (busy blocks)
 *
 * @var array  $blocks
 * @var array  $atts
 * @var string $portal_base_url
 * @var string $facility_slug
 * @var string $calendar_instance_id
 */
$portal_base_url = isset($portal_base_url) ? rtrim((string) $portal_base_url, '') : '';
$facility_slug = isset($facility_slug) ? (string) $facility_slug : '';
$calendar_instance_id = isset($calendar_instance_id) ? $calendar_instance_id : 'hc-fac-cal';
$guest_book_url = ($portal_base_url !== '' && $facility_slug !== '')
    ? $portal_base_url . '/facility-book-guest.php?facility=' . rawurlencode($facility_slug)
    : '';
?>
<div class="hc-facility-calendar-root" id="<?php echo esc_attr($calendar_instance_id); ?>" style="min-height:<?php echo esc_attr($atts['height'] ?? '500px'); ?>">
    <div class="hc-facility-cal-header">
        <h3 class="hc-facility-cal-title"><?php echo esc_html(sprintf(__('Facility schedule: %s', 'headcount'), $facility_slug)); ?></h3>
        <?php if ($guest_book_url !== '') : ?>
            <a href="<?php echo esc_url($guest_book_url); ?>" class="hc-btn hc-btn-primary"><?php esc_html_e('Request a booking', 'headcount'); ?></a>
        <?php endif; ?>
    </div>
    <p class="hc-facility-cal-note"><?php esc_html_e('Approved bookings are shown below. Submit a request for an open slot — staff approval required.', 'headcount'); ?></p>
    <?php if (empty($blocks)) : ?>
        <p class="hc-empty"><?php esc_html_e('No scheduled bookings in this period.', 'headcount'); ?></p>
    <?php else : ?>
        <ul class="hc-facility-block-list">
            <?php foreach ($blocks as $block) :
                $date = isset($block['start_date']) ? $block['start_date'] : '';
                $start = isset($block['start_time']) ? substr($block['start_time'], 0, 5) : '';
                $end = isset($block['end_time']) ? substr($block['end_time'], 0, 5) : '';
                ?>
                <li class="hc-facility-block hc-facility-block-busy">
                    <strong><?php echo esc_html($block['title'] ?? __('Booked', 'headcount')); ?></strong>
                    <span><?php echo esc_html(trim($date . ' ' . $start . ' – ' . $end)); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
