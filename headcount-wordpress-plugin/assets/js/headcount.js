/**
 * Headcount WordPress Plugin JavaScript 2.0
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Handle RSVP button clicks
        $('.hc-btn-rsvp, .hc-btn-rsvp-small, .hc-btn-rsvp-large').on('click', function (e) {
            const href = $(this).attr('href');

            // If it's an anchor, we handle it as smooth scroll
            if (href && href.startsWith('#')) {
                e.preventDefault();
                const target = $(href);
                if (target.length) {
                    $('html, body').animate({
                        scrollTop: target.offset().top - 100
                    }, 600);
                }
            } else {
                // If it's a link, we could track it here before letting the browser follow it
                console.log('Headcount: Registration/RSVP clicked');
            }
        });

        // Handle URL Hash scrolling on page load
        if (window.location.hash && $(window.location.hash).length) {
            setTimeout(function () {
                $('html, body').animate({
                    scrollTop: $(window.location.hash).offset().top - 100
                }, 800);
            }, 500);
        }
    });

})(jQuery);
