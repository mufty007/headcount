<?php
/**
 * @var array $facilities
 * @var array $atts
 * @var string $portal_base_url
 * @var string $booking_base_url
 */
if (!isset($facilities)) {
    $facilities = array();
}
$portal_base_url = isset($portal_base_url) ? rtrim((string) $portal_base_url, '') : '';
$booking_base_url = isset($booking_base_url) ? rtrim((string) $booking_base_url, '') : $portal_base_url;
?>
<div class="hc-events-grid hc-facilities-grid">
    <?php if (empty($facilities)) : ?>
        <p class="hc-empty"><?php esc_html_e('No bookable facilities at this time.', 'headcount'); ?></p>
    <?php endif; ?>
    <?php foreach ($facilities as $fac) :
        $slug = isset($fac['slug']) ? (string) $fac['slug'] : '';
        $details_url = ($booking_base_url !== '' && $slug !== '')
            ? $booking_base_url . '/facility-details.php?facility=' . rawurlencode($slug)
            : '';
        $guest_url = ($booking_base_url !== '' && $slug !== '')
            ? $booking_base_url . '/facility-book-guest.php?facility=' . rawurlencode($slug)
            : '';
        $member_url = ($booking_base_url !== '' && $slug !== '')
            ? $booking_base_url . '/facility-book.php?facility=' . rawurlencode($slug)
            : '';
        $login_url = ($booking_base_url !== '')
            ? $booking_base_url . '/login.php?redirect=' . rawurlencode('facility-book.php?facility=' . $slug)
            : '';
        $thumb = !empty($fac['thumbnail_url']) ? (string) $fac['thumbnail_url'] : '';
        ?>
        <article class="hc-event-card hc-facility-card">
            <?php if ($details_url !== '') : ?>
                <a href="<?php echo esc_url($details_url); ?>" class="hc-facility-thumb-link">
            <?php endif; ?>
            <?php if ($thumb !== '') : ?>
                <img src="<?php echo esc_url($thumb); ?>" alt="" class="hc-facility-thumb" loading="lazy">
            <?php endif; ?>
            <?php if ($details_url !== '') : ?>
                </a>
            <?php endif; ?>
            <div class="hc-event-content">
                <h3 class="hc-event-title">
                    <?php if ($details_url !== '') : ?>
                        <a href="<?php echo esc_url($details_url); ?>"><?php echo esc_html($fac['title'] ?? $fac['name'] ?? ''); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($fac['title'] ?? $fac['name'] ?? ''); ?>
                    <?php endif; ?>
                </h3>
                <?php if (!empty($fac['is_paid']) && !empty($fac['hourly_rate'])) : ?>
                    <p class="hc-facility-price"><?php echo esc_html(sprintf('$%s / hr', number_format((float) $fac['hourly_rate'], 2))); ?></p>
                <?php endif; ?>
                <?php if (!empty($fac['location'])) : ?>
                    <div class="hc-event-meta">
                        <div class="hc-meta-item">
                            <span class="dashicons dashicons-location" aria-hidden="true"></span>
                            <span><?php echo esc_html($fac['location']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($fac['description'])) : ?>
                    <p class="hc-event-excerpt"><?php echo esc_html(wp_trim_words($fac['description'], 24)); ?></p>
                <?php endif; ?>
                <div class="hc-facility-actions">
                    <?php if ($details_url !== '') : ?>
                        <a href="<?php echo esc_url($details_url); ?>" class="hc-btn hc-btn-secondary"><?php esc_html_e('View details', 'headcount'); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($fac['allow_guest_booking']) && $guest_url !== '') : ?>
                        <a href="<?php echo esc_url($guest_url); ?>" class="hc-btn hc-btn-secondary"><?php esc_html_e('Book as guest', 'headcount'); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($fac['allow_member_booking'])) : ?>
                        <?php if (is_user_logged_in() && $member_url !== '') : ?>
                            <a href="<?php echo esc_url($member_url); ?>" class="hc-btn hc-btn-primary"><?php esc_html_e('Book as member', 'headcount'); ?></a>
                        <?php elseif ($login_url !== '') : ?>
                            <a href="<?php echo esc_url($login_url); ?>" class="hc-btn hc-btn-primary"><?php esc_html_e('Log in to book', 'headcount'); ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($fac['allow_guest_booking'])) : ?>
                    <p class="hc-facility-guest-note"><?php esc_html_e('Guests can request a booking without an account. To manage bookings online, complete your profile and become a member.', 'headcount'); ?></p>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
