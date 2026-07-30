<?php
/**
 * Public Privacy Policy — IMCA community platform
 */
require_once __DIR__ . '/includes/public-site.php';

imca_public_page_start('Privacy Policy', 'privacy');
?>
        <p class="mt-3 text-sm text-slate-500">Effective date: <?= e($LEGAL_EFFECTIVE) ?></p>

        <div class="legal-prose mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p>
                This Privacy Policy explains how <?= e($ORG_FULL_NAME) ?> (“<?= e($APP_NAME) ?>,” “we,” “us,” or “our”)
                collects, uses, and shares information when you use our community platform for events, programs,
                facility bookings, membership, and related services (the “Services”), including portals hosted under
                domains associated with <a href="<?= e($ORG_WEBSITE) ?>" target="_blank" rel="noopener noreferrer">imcaindy.org</a>.
            </p>

            <h2>1. Who we are</h2>
            <p>
                <?= e($ORG_FULL_NAME) ?> operates Masjid Al-Fajr and community programs in Indianapolis.
                Contact: <a href="mailto:<?= e($ORG_EMAIL) ?>"><?= e($ORG_EMAIL) ?></a>,
                <a href="tel:<?= e($ORG_PHONE_TEL) ?>"><?= e($ORG_PHONE) ?></a>,
                <?= e($ORG_ADDRESS) ?>.
            </p>

            <h2>2. Information we collect</h2>
            <ul>
                <li><strong>Account &amp; profile:</strong> name, email, phone, gender (if provided), password or magic-link credentials, household/family details you choose to add.</li>
                <li><strong>Community activity:</strong> event RSVPs, check-ins, program registrations, facility booking requests, tags/groups assigned by administrators, payment and donation-related records.</li>
                <li><strong>Communications:</strong> messages you send us, email campaign engagement (opens/clicks where enabled), and unsubscribe preferences.</li>
                <li><strong>Technical data:</strong> IP address, browser/device information, cookies or similar storage needed for login sessions, security, and basic analytics.</li>
            </ul>

            <h2>3. How we use information</h2>
            <ul>
                <li>Operate membership, events, programs, facilities, payments, and check-in features.</li>
                <li>Send transactional notices (RSVP confirmations, reminders, receipts, password resets) and optional community emails you have not opted out of.</li>
                <li>Improve safety, prevent abuse, and maintain system reliability.</li>
                <li>Comply with legal obligations and protect the rights of <?= e($APP_NAME) ?> and our community.</li>
            </ul>

            <h2>4. Sharing</h2>
            <p>We do not sell your personal information. We may share data with:</p>
            <ul>
                <li><strong>Service providers</strong> that help us run the Services (for example hosting, email delivery, and payment processors), under contractual confidentiality and use limits.</li>
                <li><strong>Authorized <?= e($APP_NAME) ?> staff and volunteers</strong> who need access to administer programs and events.</li>
                <li><strong>Legal authorities</strong> when required by law or to protect people and property.</li>
            </ul>

            <h2>5. Payments</h2>
            <p>
                Card payments are processed by our payment provider. We do not store full card numbers on our servers.
                Payment metadata (amount, status, receipt references) may be retained for accounting and support.
            </p>

            <h2>6. Cookies &amp; sessions</h2>
            <p>
                We use essential cookies/session storage to keep you signed in, remember preferences (such as theme),
                and protect against CSRF and other abuse. Disabling these may prevent the Services from working correctly.
            </p>

            <h2>7. Retention</h2>
            <p>
                We keep information for as long as your account remains active or as needed to provide the Services,
                meet legal/accounting requirements, resolve disputes, and enforce our agreements. You may request deletion
                subject to legitimate retention needs (for example past attendance or financial records).
            </p>

            <h2>8. Your choices</h2>
            <ul>
                <li>Update profile details in the member portal when signed in.</li>
                <li>Unsubscribe from marketing/community campaign emails via the link in those emails.</li>
                <li>Contact us to request access, correction, or deletion of personal data where applicable.</li>
            </ul>

            <h2>9. Children’s privacy</h2>
            <p>
                Family and youth program features may include information about minors provided by a parent or guardian.
                Parents/guardians are responsible for information they submit about children in their household.
            </p>

            <h2>10. Security</h2>
            <p>
                We use reasonable administrative and technical safeguards. No method of transmission or storage is
                completely secure; please use a strong unique password and keep account access private.
            </p>

            <h2>11. Changes</h2>
            <p>
                We may update this Policy from time to time. The effective date above will change when we do.
                Continued use of the Services after an update means you accept the revised Policy.
            </p>

            <h2>12. Contact</h2>
            <p>
                Questions about privacy:
                <a href="mailto:<?= e($ORG_EMAIL) ?>"><?= e($ORG_EMAIL) ?></a> ·
                <a href="tel:<?= e($ORG_PHONE_TEL) ?>"><?= e($ORG_PHONE) ?></a> ·
                <a href="<?= e($legalUrls['support']) ?>">Support page</a> ·
                <a href="<?= e($ORG_WEBSITE) ?>" target="_blank" rel="noopener noreferrer"><?= e($ORG_WEBSITE) ?></a>
            </p>
        </div>
<?php
imca_public_page_end();
