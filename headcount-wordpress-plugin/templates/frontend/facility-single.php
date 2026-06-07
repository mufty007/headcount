<?php
/**
 * Single facility (shortcode headcount_facility)
 *
 * @var array  $facility
 * @var array  $atts
 * @var string $booking_base_url
 */
if (empty($facility)) {
    return;
}
$booking_base_url = isset($booking_base_url) ? rtrim((string) $booking_base_url, '') : '';
$slug = (string) ($facility['slug'] ?? '');
$details_url = (string) ($facility['details_url'] ?? '');
$guest_url = (string) ($facility['guest_book_url'] ?? '');
$member_url = (string) ($facility['member_book_url'] ?? '');
$thumb = (string) ($facility['thumbnail_url'] ?? '');
?>
<div class="hc-single-facility-wrap">
    <?php if ($thumb !== '') : ?>
    <div class="hc-facility-single-hero">
        <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($facility['title'] ?? ''); ?>" class="hc-facility-single-image" loading="lazy" />
    </div>
    <?php endif; ?>
    <div class="hc-single-container">
        <h1 class="hc-single-title"><?php echo esc_html($facility['title'] ?? ''); ?></h1>
        <?php if (!empty($facility['formatted_price'])) : ?>
            <p class="hc-facility-price hc-facility-price--large"><?php echo esc_html($facility['formatted_price']); ?></p>
        <?php endif; ?>
        <?php if (!empty($facility['location'])) : ?>
            <p class="hc-facility-single-location">
                <span class="dashicons dashicons-location" aria-hidden="true"></span>
                <?php echo esc_html($facility['location']); ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($facility['description_html'])) : ?>
            <div class="hc-facility-single-description"><?php echo $facility['description_html']; ?></div>
        <?php endif; ?>
        <div class="hc-facility-actions hc-facility-actions--single">
            <?php if ($details_url !== '') : ?>
                <a href="<?php echo esc_url($details_url); ?>" class="hc-btn hc-btn-secondary"><?php esc_html_e('View on portal', 'headcount'); ?></a>
            <?php endif; ?>
            <?php if (!empty($facility['allow_guest_booking']) && $guest_url !== '') : ?>
                <a href="<?php echo esc_url($guest_url); ?>" class="hc-btn hc-btn-secondary"><?php esc_html_e('Book as guest', 'headcount'); ?></a>
            <?php endif; ?>
            <?php if (!empty($facility['allow_member_booking']) && $member_url !== '') : ?>
                <a href="<?php echo esc_url($member_url); ?>" class="hc-btn hc-btn-primary"><?php esc_html_e('Book as member', 'headcount'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
