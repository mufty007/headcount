<?php
/**
 * Public Terms of Service — IMCA community platform
 */
require_once __DIR__ . '/includes/public-site.php';

imca_public_page_start('Terms of Service', 'terms');
?>
        <p class="mt-3 text-sm text-slate-500">Effective date: <?= e($LEGAL_EFFECTIVE) ?></p>

        <div class="legal-prose mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p>
                These Terms of Service (“Terms”) govern your use of the <?= e($ORG_FULL_NAME) ?> (“<?= e($APP_NAME) ?>,” “we,” “us”)
                community platform for events, programs, facility bookings, membership, communications, and related tools
                (the “Services”). By creating an account, registering for an event or program, booking a facility, or
                otherwise using the Services, you agree to these Terms.
            </p>
            <p>
                Our public website is <a href="<?= e($ORG_WEBSITE) ?>" target="_blank" rel="noopener noreferrer">imcaindy.org</a>.
                If you do not agree, do not use the Services.
            </p>

            <h2>1. Eligibility &amp; accounts</h2>
            <ul>
                <li>You must provide accurate registration information and keep it up to date.</li>
                <li>You are responsible for activity under your account and for protecting your login credentials.</li>
                <li>Parents or guardians are responsible for accounts and registrations created for minors in their care.</li>
                <li>We may suspend or terminate accounts that violate these Terms or pose a risk to the community.</li>
            </ul>

            <h2>2. Community use</h2>
            <p>You agree to use the Services in a respectful manner consistent with <?= e($APP_NAME) ?>’s mission and applicable law. You will not:</p>
            <ul>
                <li>Harass, threaten, or discriminate against others.</li>
                <li>Submit false RSVPs, registrations, or payment information.</li>
                <li>Attempt to access systems or data you are not authorized to use.</li>
                <li>Interfere with platform security, email delivery, or payment processing.</li>
                <li>Use the Services for commercial spam or unrelated solicitation without permission.</li>
            </ul>

            <h2>3. Events, programs &amp; facilities</h2>
            <ul>
                <li>Event and program details (time, location, capacity, fees, rules) are set by <?= e($APP_NAME) ?> and may change.</li>
                <li>RSVP or registration does not guarantee entry if capacity, eligibility, or safety rules apply.</li>
                <li>Facility bookings are subject to availability, approval, fees, deposits, and house rules communicated at booking time.</li>
                <li>You must follow venue guidance, staff instructions, and any posted code of conduct while on <?= e($APP_NAME) ?> premises.</li>
            </ul>

            <h2>4. Payments, donations &amp; refunds</h2>
            <ul>
                <li>Fees shown at checkout are due as indicated. Payments are processed by our payment provider.</li>
                <li>Refunds, transfers, and cancellations follow the policy stated for that event, program, or booking, or as otherwise required by law.</li>
                <li>Contact <a href="<?= e($legalUrls['support']) ?>">Support</a> for billing questions and include your receipt or confirmation details.</li>
            </ul>

            <h2>5. Communications</h2>
            <p>
                We may send transactional messages related to your account and registrations. Community or campaign emails
                may be sent to members who have not unsubscribed. You can opt out of non-essential campaign emails using
                unsubscribe links. Operational messages needed to provide the Services may still be sent.
            </p>

            <h2>6. Privacy</h2>
            <p>
                Our collection and use of personal information is described in the
                <a href="<?= e($legalUrls['privacy']) ?>">Privacy Policy</a>, which is incorporated into these Terms.
            </p>

            <h2>7. Intellectual property</h2>
            <p>
                The Services, branding, and content provided by <?= e($APP_NAME) ?> are owned by <?= e($ORG_FULL_NAME) ?>
                or its licensors. You may not copy or reuse them except as needed to use the Services or with written permission.
                Content you submit (for example feedback or form responses) may be used by <?= e($APP_NAME) ?> to operate and improve community programs.
            </p>

            <h2>8. Disclaimers</h2>
            <p>
                The Services are provided “as is” and “as available.” We do not guarantee uninterrupted or error-free operation.
                To the fullest extent permitted by law, <?= e($APP_NAME) ?> disclaims warranties of merchantability, fitness for a
                particular purpose, and non-infringement.
            </p>

            <h2>9. Limitation of liability</h2>
            <p>
                To the fullest extent permitted by law, <?= e($APP_NAME) ?> and its officers, employees, and volunteers are not
                liable for indirect, incidental, special, consequential, or punitive damages, or for lost profits or data,
                arising from your use of the Services. Our aggregate liability for claims relating to the Services is limited
                to the greater of (a) amounts you paid to us through the Services for the transaction giving rise to the claim
                in the twelve months before the claim, or (b) fifty U.S. dollars ($50), except where liability cannot be limited by law.
            </p>

            <h2>10. Indemnity</h2>
            <p>
                You agree to indemnify and hold harmless <?= e($APP_NAME) ?> from claims arising out of your misuse of the Services,
                violation of these Terms, or infringement of another’s rights, except to the extent caused by our willful misconduct.
            </p>

            <h2>11. Changes</h2>
            <p>
                We may update these Terms and the Services. Material changes will be reflected by updating the effective date
                on this page. Continued use after changes constitutes acceptance.
            </p>

            <h2>12. Governing law</h2>
            <p>
                These Terms are governed by the laws of the State of Indiana, without regard to conflict-of-law rules,
                except where mandatory consumer protections apply.
            </p>

            <h2>13. Contact</h2>
            <p>
                <?= e($ORG_FULL_NAME) ?><br>
                <?= e($ORG_ADDRESS) ?><br>
                <a href="mailto:<?= e($ORG_EMAIL) ?>"><?= e($ORG_EMAIL) ?></a> ·
                <a href="tel:<?= e($ORG_PHONE_TEL) ?>"><?= e($ORG_PHONE) ?></a> ·
                <a href="<?= e($ORG_WEBSITE) ?>" target="_blank" rel="noopener noreferrer"><?= e($ORG_WEBSITE) ?></a>
            </p>
        </div>
<?php
imca_public_page_end();
