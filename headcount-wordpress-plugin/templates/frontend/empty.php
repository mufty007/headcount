<?php
/**
 * Empty State Template
 *
 * @var string $title   Optional heading (defaults below).
 * @var string $message Main explanatory text.
 */
$title = isset($title) ? $title : __('No upcoming events', 'headcount');
$message = isset($message) ? $message : __('There are no events to show yet.', 'headcount');
?>
<div class="hc-empty-state" role="status">
    <div class="hc-empty-visual" aria-hidden="true">
        <svg class="hc-empty-svg" width="120" height="120" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="18" y="22" width="84" height="78" rx="10" stroke="currentColor" stroke-width="2.5"/>
            <path d="M18 38h84" stroke="currentColor" stroke-width="2.5"/>
            <rect x="30" y="14" width="8" height="16" rx="2" fill="currentColor"/>
            <rect x="82" y="14" width="8" height="16" rx="2" fill="currentColor"/>
            <circle cx="44" cy="62" r="4" fill="currentColor" opacity="0.35"/>
            <circle cx="60" cy="62" r="4" fill="currentColor" opacity="0.35"/>
            <circle cx="76" cy="62" r="4" fill="currentColor" opacity="0.35"/>
            <path d="M48 82h24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" opacity="0.4"/>
        </svg>
    </div>
    <h3 class="hc-empty-title"><?php echo esc_html($title); ?></h3>
    <p class="hc-empty-text"><?php echo esc_html($message); ?></p>
</div>
