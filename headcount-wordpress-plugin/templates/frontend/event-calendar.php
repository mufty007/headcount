<?php
/**
 * Event Calendar Template
 *
 * Table-based month grid + embedded critical CSS so layout survives aggressive themes.
 *
 * @var array  $events
 * @var array  $atts
 * @var string $event_details_url
 * @var string $portal_base_url
 */
$event_details_url = isset($event_details_url) ? $event_details_url : '';
$portal_base_url = isset($portal_base_url) ? rtrim((string) $portal_base_url, '/') : '';
$calendar_instance_id = isset($calendar_instance_id) ? $calendar_instance_id : 'hc-calendar';

global $headcount_calendar_critical_css_printed;
if (empty($headcount_calendar_critical_css_printed)) {
    $headcount_calendar_critical_css_printed = true;
?>
<style id="headcount-calendar-critical">
/* Embedded once per shortcode: high specificity + !important so themes cannot collapse layout */
.hc-calendar-root.hc-calendar-container .hc-calendar-header {
    display: flex !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    margin: 0 0 18px 0 !important;
    width: 100% !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-nav {
    display: flex !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 12px 20px !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-month-title {
    margin: 0 !important;
    font-size: clamp(1.15rem, 2.5vw, 1.5rem) !important;
    font-weight: 800 !important;
    line-height: 1.2 !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-btn,
.hc-calendar-root.hc-calendar-container .hc-cal-today {
    appearance: none !important;
    -webkit-appearance: none !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 !important;
    font-family: inherit !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    line-height: 1 !important;
    cursor: pointer !important;
    border-radius: 8px !important;
    box-sizing: border-box !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-btn {
    width: 40px !important;
    height: 40px !important;
    padding: 0 !important;
    background: #fff !important;
    border: 1px solid #e5e7eb !important;
    color: #111827 !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-today {
    padding: 10px 18px !important;
    flex-shrink: 0 !important;
    background: #fff !important;
    border: 1px solid #e5e7eb !important;
    color: #4f46e5 !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table-wrap {
    width: 100% !important;
    overflow: hidden !important;
    border: 1px solid #e5e7eb !important;
    border-radius: 12px !important;
    background: #e5e7eb !important;
}
.hc-calendar-root.hc-calendar-container table.hc-calendar-table {
    display: table !important;
    width: 100% !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    border-spacing: 0 !important;
    margin: 0 !important;
    font-family: inherit !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table thead {
    display: table-header-group !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table tbody {
    display: table-row-group !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table tr {
    display: table-row !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table th,
.hc-calendar-root.hc-calendar-container .hc-calendar-table td {
    display: table-cell !important;
    width: 14.285% !important;
    box-sizing: border-box !important;
    vertical-align: top !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table thead th {
    padding: 10px 6px !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.04em !important;
    text-align: center !important;
    color: #6b7280 !important;
    background: #f9fafb !important;
    border: none !important;
    border-bottom: 1px solid #e5e7eb !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table tbody td {
    min-height: 88px !important;
    height: 1px !important;
    padding: 6px 8px 8px !important;
    background: #fff !important;
    border: 1px solid #f3f4f6 !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table tbody td.hc-day-empty {
    background: #fafafa !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table tbody td.hc-has-events {
    background: #fafbff !important;
}
.hc-calendar-root.hc-calendar-container .hc-calendar-table tbody td.hc-day-today .hc-day-num {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 28px !important;
    height: 28px !important;
    border-radius: 999px !important;
    background: #6366f1 !important;
    color: #fff !important;
}
.hc-calendar-root.hc-calendar-container .hc-day-cell-inner {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    min-height: 76px !important;
}
.hc-calendar-root.hc-calendar-container .hc-day-num {
    font-size: 13px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    color: #111827 !important;
}
.hc-calendar-root.hc-calendar-container .hc-day-events {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    min-height: 0 !important;
    overflow: hidden !important;
}
.hc-calendar-root.hc-calendar-container button.hc-cal-event-link {
    display: block !important;
    width: 100% !important;
    margin: 0 !important;
    padding: 4px 6px !important;
    font-family: inherit !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    line-height: 1.3 !important;
    color: #4f46e5 !important;
    text-align: left !important;
    text-decoration: none !important;
    background: #eef2ff !important;
    border: none !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    appearance: none !important;
    -webkit-appearance: none !important;
}
.hc-calendar-root.hc-calendar-container button.hc-cal-event-link:hover {
    background: #e0e7ff !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-empty-banner {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    text-align: center !important;
    gap: 8px !important;
    margin: 0 0 20px 0 !important;
    padding: 24px 20px !important;
    background: linear-gradient(180deg, #f8fafc 0%, #f5f3ff 100%) !important;
    border: 1px solid #e0e7ff !important;
    border-radius: 12px !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-empty-banner svg {
    flex-shrink: 0 !important;
    color: #6366f1 !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-empty-title {
    margin: 0 !important;
    font-size: 17px !important;
    font-weight: 800 !important;
    color: #111827 !important;
}
.hc-calendar-root.hc-calendar-container .hc-cal-empty-desc {
    margin: 0 !important;
    max-width: 28em !important;
    font-size: 14px !important;
    line-height: 1.55 !important;
    color: #6b7280 !important;
}
@media (max-width: 640px) {
    .hc-calendar-root.hc-calendar-container .hc-calendar-table tbody td {
        padding: 4px !important;
    }
    .hc-calendar-root.hc-calendar-container button.hc-cal-event-link {
        font-size: 10px !important;
        padding: 3px 4px !important;
    }
}
</style>
<?php
}
?>
<div class="hc-calendar-wrapper">
    <div id="<?php echo esc_attr($calendar_instance_id); ?>" class="hc-calendar-container hc-calendar-root" style="min-height: <?php echo esc_attr($atts['height']); ?>">
        <div class="hc-loader" role="status"><?php echo esc_html__('Loading calendar…', 'headcount'); ?></div>
    </div>
    <div id="<?php echo esc_attr($calendar_instance_id); ?>-preview"
         class="hc-cal-preview-backdrop"
         hidden
         role="presentation"
         aria-hidden="true">
        <div class="hc-cal-preview-dialog"
             role="dialog"
             aria-modal="true"
             aria-labelledby="<?php echo esc_attr($calendar_instance_id); ?>-preview-title"
             tabindex="-1">
            <button type="button" class="hc-cal-preview-close" aria-label="<?php echo esc_attr__('Close preview', 'headcount'); ?>">&times;</button>
            <div class="hc-cal-preview-image-wrap">
                <img src="" alt="" class="hc-cal-preview-img" width="640" height="360" loading="lazy" decoding="async" hidden>
            </div>
            <p class="hc-cal-preview-category" hidden></p>
            <h3 id="<?php echo esc_attr($calendar_instance_id); ?>-preview-title" class="hc-cal-preview-title"></h3>
            <p class="hc-cal-preview-meta"></p>
            <p class="hc-cal-preview-excerpt"></p>
            <a href="#" class="hc-cal-preview-cta hc-btn-rsvp-small" target="_blank" rel="noopener noreferrer" hidden><?php echo esc_html__('View details & RSVP', 'headcount'); ?></a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const events = <?php echo json_encode($events); ?>;
    const container = document.getElementById(<?php echo json_encode($calendar_instance_id); ?>);
    const eventDetailsBase = <?php echo json_encode(esc_url_raw($event_details_url)); ?>;
    const portalBase = <?php echo json_encode($portal_base_url); ?>;
    const previewBackdrop = document.getElementById(<?php echo json_encode($calendar_instance_id . '-preview'); ?>);
    const previewDialog = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-dialog') : null;
    const previewImg = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-img') : null;
    const previewCategory = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-category') : null;
    const previewTitle = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-title') : null;
    const previewMeta = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-meta') : null;
    const previewExcerpt = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-excerpt') : null;
    const previewCta = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-cta') : null;
    const previewCloseBtn = previewBackdrop ? previewBackdrop.querySelector('.hc-cal-preview-close') : null;

    let currentMonth = new Date().getMonth();
    let currentYear = new Date().getFullYear();

    function escapeHtml(str) {
        if (str == null) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function eventHref(ev) {
        if (!ev) {
            return '';
        }
        var idStr = String(ev.id);
        if (ev.calendar_item_type === 'program') {
            var parts = idStr.split('_');
            var pid = parts.length >= 2 ? parts[1] : idStr.replace(/^p_/, '');
            if (portalBase) {
                return portalBase + '/program-details.php?id=' + encodeURIComponent(pid);
            }
            return '';
        }
        var eid = idStr.replace(/^e_/, '');
        if (eventDetailsBase) {
            return eventDetailsBase + (eventDetailsBase.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(eid);
        }
        return '?event_id=' + encodeURIComponent(eid);
    }

    var weekLabels = [
        <?php echo json_encode(esc_html__('Sun', 'headcount')); ?>,
        <?php echo json_encode(esc_html__('Mon', 'headcount')); ?>,
        <?php echo json_encode(esc_html__('Tue', 'headcount')); ?>,
        <?php echo json_encode(esc_html__('Wed', 'headcount')); ?>,
        <?php echo json_encode(esc_html__('Thu', 'headcount')); ?>,
        <?php echo json_encode(esc_html__('Fri', 'headcount')); ?>,
        <?php echo json_encode(esc_html__('Sat', 'headcount')); ?>
    ];

    var emptySvg = '<svg width="56" height="56" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="18" y="22" width="84" height="78" rx="10" stroke="currentColor" stroke-width="2.5"/><path d="M18 38h84" stroke="currentColor" stroke-width="2.5"/><rect x="30" y="14" width="8" height="16" rx="2" fill="currentColor"/><rect x="82" y="14" width="8" height="16" rx="2" fill="currentColor"/><circle cx="44" cy="62" r="4" fill="currentColor" opacity="0.35"/><circle cx="60" cy="62" r="4" fill="currentColor" opacity="0.35"/><circle cx="76" cy="62" r="4" fill="currentColor" opacity="0.35"/></svg>';

    function findEventById(id) {
        var i;
        for (i = 0; i < events.length; i++) {
            if (String(events[i].id) === String(id)) return events[i];
        }
        return null;
    }

    function closePreview() {
        if (!previewBackdrop) return;
        previewBackdrop.setAttribute('hidden', '');
        previewBackdrop.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function openPreview(eventId) {
        var ev = findEventById(eventId);
        if (!ev || !previewBackdrop || !previewTitle) return;

        if (previewImg) {
            previewImg.onerror = function() {
                this.setAttribute('hidden', '');
                this.removeAttribute('src');
            };
            if (ev.banner_image) {
                previewImg.alt = ev.title || '';
                previewImg.src = ev.banner_image;
                previewImg.removeAttribute('hidden');
            } else {
                previewImg.removeAttribute('src');
                previewImg.setAttribute('hidden', '');
            }
        }
        if (previewCategory) {
            if (ev.category) {
                previewCategory.textContent = ev.category;
                previewCategory.removeAttribute('hidden');
            } else {
                previewCategory.setAttribute('hidden', '');
            }
        }
        previewTitle.textContent = ev.title || '';
        var metaParts = [];
        if (ev.formatted_date) metaParts.push(ev.formatted_date);
        if (ev.formatted_time) metaParts.push(ev.formatted_time);
        if (ev.location) metaParts.push(ev.location);
        if (previewMeta) previewMeta.textContent = metaParts.join(' · ');
        if (previewExcerpt) {
            previewExcerpt.textContent = ev.excerpt || '';
            previewExcerpt.style.display = ev.excerpt ? 'block' : 'none';
        }
        if (previewCta) {
            var href = eventHref(ev);
            if (!href) {
                previewCta.setAttribute('hidden', '');
            } else {
                previewCta.href = href;
                previewCta.textContent = (ev.calendar_item_type === 'program')
                    ? <?php echo json_encode(__('Learn more', 'headcount')); ?>
                    : <?php echo json_encode(__('View details & RSVP', 'headcount')); ?>;
                previewCta.removeAttribute('hidden');
            }
        }

        previewBackdrop.removeAttribute('hidden');
        previewBackdrop.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (previewDialog) previewDialog.focus();
    }

    if (previewBackdrop) {
        previewBackdrop.addEventListener('click', function(e) {
            if (e.target === previewBackdrop) closePreview();
        });
        if (previewCloseBtn) {
            previewCloseBtn.addEventListener('click', closePreview);
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && previewBackdrop && !previewBackdrop.hasAttribute('hidden')) {
                closePreview();
            }
        });
    }

    function render() {
        var daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
        var firstDay = new Date(currentYear, currentMonth, 1).getDay();
        var monthName = new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(currentYear, currentMonth));

        var noEventsBanner = events.length === 0
            ? '<div class="hc-cal-empty-banner" role="status">' + emptySvg +
              '<p class="hc-cal-empty-title">' + <?php echo json_encode(esc_html__('Nothing scheduled yet', 'headcount')); ?> + '</p>' +
              '<p class="hc-cal-empty-desc">' + <?php echo json_encode(esc_html__('When your organization publishes events, they will show on the dates below. You can still browse the month.', 'headcount')); ?> + '</p>' +
              '</div>'
            : '';

        var html = noEventsBanner +
            '<div class="hc-calendar-header">' +
                '<div class="hc-cal-nav">' +
                    '<button type="button" class="hc-cal-btn" onclick="hcPrevMonth()" aria-label="' + <?php echo json_encode(esc_attr__('Previous month', 'headcount')); ?> + '">&#10094;</button>' +
                    '<h3 class="hc-cal-month-title">' + escapeHtml(monthName) + ' ' + currentYear + '</h3>' +
                    '<button type="button" class="hc-cal-btn" onclick="hcNextMonth()" aria-label="' + <?php echo json_encode(esc_attr__('Next month', 'headcount')); ?> + '">&#10095;</button>' +
                '</div>' +
                '<button type="button" class="hc-cal-today" onclick="hcToday()">' + <?php echo json_encode(esc_html__('Today', 'headcount')); ?> + '</button>' +
            '</div>' +
            '<div class="hc-calendar-table-wrap">' +
            '<table class="hc-calendar-table" role="grid" aria-label="' + <?php echo json_encode(esc_attr__('Month calendar', 'headcount')); ?> + '">' +
            '<thead><tr>';

        var d;
        for (d = 0; d < 7; d++) {
            html += '<th scope="col">' + escapeHtml(weekLabels[d]) + '</th>';
        }
        html += '</tr></thead><tbody>';

        var cells = [];
        for (d = 0; d < firstDay; d++) {
            cells.push({ kind: 'pad' });
        }
        for (d = 1; d <= daysInMonth; d++) {
            cells.push({ kind: 'day', day: d });
        }
        var total = cells.length;
        var trailing = (7 - (total % 7)) % 7;
        for (d = 0; d < trailing; d++) {
            cells.push({ kind: 'pad' });
        }

        var row;
        for (row = 0; row < cells.length; row += 7) {
            html += '<tr>';
            var col;
            for (col = 0; col < 7; col++) {
                var cell = cells[row + col];
                if (cell.kind === 'pad') {
                    html += '<td class="hc-day-empty"></td>';
                    continue;
                }
                var day = cell.day;
                var dateStr = currentYear + '-' + String(currentMonth + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                var dayEvents = events.filter(function(e) { return e.event_date === dateStr; });
                var isToday = day === new Date().getDate() &&
                    currentMonth === new Date().getMonth() &&
                    currentYear === new Date().getFullYear();
                var tdClass = 'hc-cal-day' +
                    (isToday ? ' hc-day-today' : '') +
                    (dayEvents.length > 0 ? ' hc-has-events' : '');

                var linksHtml = '';
                var j;
                for (j = 0; j < dayEvents.length; j++) {
                    var e = dayEvents[j];
                    linksHtml += '<button type="button" class="hc-cal-event-link" data-hc-event-id="' + escapeHtml(String(e.id)) + '" title="' + escapeHtml(e.title) + '">' +
                        escapeHtml(e.title) + '</button>';
                }

                html += '<td class="' + tdClass + '"><div class="hc-day-cell-inner">' +
                    '<span class="hc-day-num">' + day + '</span>' +
                    '<div class="hc-day-events">' + linksHtml + '</div></div></td>';
            }
            html += '</tr>';
        }

        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    if (container) {
        container.addEventListener('click', function(ev) {
            var btn = ev.target.closest('.hc-cal-event-link');
            if (!btn || !btn.getAttribute('data-hc-event-id')) return;
            ev.preventDefault();
            openPreview(btn.getAttribute('data-hc-event-id'));
        });
    }

    window.hcPrevMonth = function() {
        currentMonth--;
        if (currentMonth < 0) { currentMonth = 11; currentYear--; }
        render();
    };

    window.hcNextMonth = function() {
        currentMonth++;
        if (currentMonth > 11) { currentMonth = 0; currentYear++; }
        render();
    };

    window.hcToday = function() {
        currentMonth = new Date().getMonth();
        currentYear = new Date().getFullYear();
        render();
    };

    render();
});
</script>
