<?php
/**
 * @var array $events
 * @var array $programs
 * @var array $facilities
 * @var array $atts
 * @var string $portal_base_url
 * @var string $booking_base_url
 * @var bool   $show_facilities_tab
 */
use Headcount\Core\Renderer;

if (!isset($events)) {
    $events = array();
}
if (!isset($programs)) {
    $programs = array();
}
if (!isset($facilities)) {
    $facilities = array();
}
$portal_base_url = isset($portal_base_url) ? rtrim((string) $portal_base_url, '/') : '';
$booking_base_url = isset($booking_base_url) ? rtrim((string) $booking_base_url, '') : $portal_base_url;
$show_facilities_tab = !empty($show_facilities_tab);
$uid = function_exists('wp_unique_id') ? wp_unique_id('hc-showcase-') : 'hc-showcase-' . uniqid('');
$tab_label = $show_facilities_tab
    ? __('Events, programs, and facilities', 'headcount')
    : __('Events and programs', 'headcount');
?>
<div class="hc-showcase hc-showcase-tabs" id="<?php echo esc_attr($uid); ?>" data-hc-showcase>
    <div class="hc-showcase-tablist" role="tablist" aria-label="<?php echo esc_attr($tab_label); ?>">
        <button type="button" class="hc-showcase-tab is-active" role="tab" aria-selected="true" aria-controls="<?php echo esc_attr($uid); ?>-panel-events" id="<?php echo esc_attr($uid); ?>-tab-events" data-tab="events"><?php echo esc_html__('Events', 'headcount'); ?></button>
        <button type="button" class="hc-showcase-tab" role="tab" aria-selected="false" aria-controls="<?php echo esc_attr($uid); ?>-panel-programs" id="<?php echo esc_attr($uid); ?>-tab-programs" data-tab="programs"><?php echo esc_html__('Programs', 'headcount'); ?></button>
        <?php if ($show_facilities_tab) : ?>
        <button type="button" class="hc-showcase-tab" role="tab" aria-selected="false" aria-controls="<?php echo esc_attr($uid); ?>-panel-facilities" id="<?php echo esc_attr($uid); ?>-tab-facilities" data-tab="facilities"><?php echo esc_html__('Facilities', 'headcount'); ?></button>
        <?php endif; ?>
    </div>
    <div class="hc-showcase-panels">
        <div class="hc-showcase-panel is-active" role="tabpanel" id="<?php echo esc_attr($uid); ?>-panel-events" aria-labelledby="<?php echo esc_attr($uid); ?>-tab-events">
            <?php
            if (empty($events)) {
                echo '<p class="hc-showcase-empty">' . esc_html__('No upcoming events.', 'headcount') . '</p>';
            } else {
                $layout = ($atts['layout'] ?? '') === 'grid' ? 'events-grid' : 'events-list';
                echo Renderer::render($layout, array(
                    'events' => $events,
                    'atts' => array('layout' => $atts['layout'] ?? 'grid', 'show_image' => 'true', 'show_rsvp' => 'false', 'show_category' => 'true', 'show_pagination' => 'false'),
                    'pagination' => array('show' => false),
                ));
            }
            ?>
        </div>
        <div class="hc-showcase-panel" role="tabpanel" id="<?php echo esc_attr($uid); ?>-panel-programs" aria-labelledby="<?php echo esc_attr($uid); ?>-tab-programs" hidden>
            <?php if (empty($programs)): ?>
                <p class="hc-showcase-empty"><?php echo esc_html__('No programs listed yet.', 'headcount'); ?></p>
            <?php else : ?>
                <?php
                echo Renderer::render('programs-grid', array(
                    'programs' => $programs,
                    'portal_base_url' => $portal_base_url,
                    'atts' => $atts ?? array(),
                ));
                ?>
            <?php endif; ?>
        </div>
        <?php if ($show_facilities_tab) : ?>
        <div class="hc-showcase-panel" role="tabpanel" id="<?php echo esc_attr($uid); ?>-panel-facilities" aria-labelledby="<?php echo esc_attr($uid); ?>-tab-facilities" hidden>
            <?php if (empty($facilities)) : ?>
                <p class="hc-showcase-empty"><?php echo esc_html__('No bookable facilities.', 'headcount'); ?></p>
            <?php else : ?>
                <?php
                echo Renderer::render('facilities-grid', array(
                    'facilities' => $facilities,
                    'atts' => $atts ?? array(),
                    'portal_base_url' => $portal_base_url,
                    'booking_base_url' => $booking_base_url,
                ));
                ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function(){
var root = document.getElementById(<?php echo json_encode($uid); ?>);
if(!root) return;
var tabs = root.querySelectorAll('.hc-showcase-tab');
var panels = root.querySelectorAll('.hc-showcase-panel');
tabs.forEach(function(tab){
  tab.addEventListener('click', function(){
    var name = tab.getAttribute('data-tab');
    tabs.forEach(function(t){ t.classList.remove('is-active'); t.setAttribute('aria-selected','false'); });
    panels.forEach(function(p){ p.classList.remove('is-active'); p.setAttribute('hidden',''); });
    tab.classList.add('is-active'); tab.setAttribute('aria-selected','true');
    var panel = root.querySelector('[role="tabpanel"][id$="-panel-' + name + '"]');
    if(panel){ panel.classList.add('is-active'); panel.removeAttribute('hidden'); }
  });
});
})();
</script>
