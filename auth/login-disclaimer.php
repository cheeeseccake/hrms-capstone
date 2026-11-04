<?php
/*
 * HRMS Login Disclaimer
 * Shows once after a successful login.
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (
    empty($_SESSION['show_login_disclaimer']) ||
    !empty($_SESSION['disclaimer_acknowledged'])
) {
    return;
}

if (
    empty($_SESSION['disclaimer_csrf_token']) ||
    !is_string($_SESSION['disclaimer_csrf_token'])
) {
    $_SESSION['disclaimer_csrf_token'] = bin2hex(random_bytes(32));
}
?>

<div
    id="hrmsLoginDisclaimer"
    class="hrms-disclaimer-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="hrmsDisclaimerTitle"
    aria-describedby="hrmsDisclaimerText"
>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<div class="hrms-disclaimer-modal">

        <div class="hrms-disclaimer-header">
            <div class="hrms-disclaimer-icon" aria-hidden="true">
                <span>!</span>
            </div>

            <div>
                <h2 id="hrmsDisclaimerTitle">System Disclaimer</h2>
                <p>Important information before continuing</p>
            </div>
        </div>

        <div class="hrms-disclaimer-body" id="hrmsDisclaimerText">

            <p>
                This Human Resource Management System is intended to support
                the management, monitoring, documentation, and organization
                of human resource, compliance, and legal-related information.
            </p>

            <p>
                The information, recommendations, assessments, references,
                suggested actions, and decision-support outputs provided by
                this system are intended for administrative and informational
                purposes only.
            </p>

            <p>
                <strong>
                    System information and functionality may be updated,
                    modified, or changed from time to time.
                </strong>
                Changes may be made to reflect updates to organizational
                policies, procedures, labor laws, regulations, compliance
                requirements, or system functionality.
            </p>

            <div class="hrms-disclaimer-important">
                <div class="hrms-disclaimer-important-title">
                    Important Legal Notice
                </div>

                <p>
                    Any recommendation, assessment, suggested action,
                    legal reference, or decision-support output generated
                    or displayed by this system should not be considered
                    a final legal determination, legal advice, or a substitute
                    for professional legal judgment.
                </p>

                <p>
                    <strong>
                        Information or decisions provided by this system do
                        not replace the review, professional judgment, or
                        final decision of a qualified lawyer, legal counsel,
                        or other authorized legal professional.
                    </strong>
                </p>

                <p>
                    Legal, disciplinary, employment, or compliance decisions
                    requiring professional judgment must be reviewed and
                    determined by the appropriate authorized personnel and,
                    when necessary, a qualified lawyer or legal counsel.
                </p>
            </div>

            <p>
                Users are responsible for reviewing relevant information,
                protecting confidential records, and using the system only
                for legitimate and authorized organizational purposes.
            </p>

            <p>
                By continuing, you acknowledge that you have read and
                understood this disclaimer and agree to use the system
                responsibly and in accordance with applicable organizational
                policies, procedures, laws, and regulations.
            </p>

        </div>

        <div class="hrms-disclaimer-footer">
            <button
                type="button"
                id="hrmsDisclaimerAccept"
                class="hrms-disclaimer-accept"
            >
                I Understand
            </button>
        </div>

    </div>
</div>

<style>
<?php readfile(__DIR__ . '/../assets/css/hrms-neomorphism.css'); ?>
</style>

<script>
(function () {
    function placeDisclaimerOnTop() {
        var overlay = document.getElementById('hrmsLoginDisclaimer');

        if (overlay && document.body && overlay.parentElement !== document.body) {
            document.body.appendChild(overlay);
        }
    }

    // Run immediately when possible, including when this component
    // is included after DOMContentLoaded.
    placeDisclaimerOnTop();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', placeDisclaimerOnTop);
    }
})();
</script>

<script>
(function () {
    'use strict';

    var overlay = document.getElementById('hrmsLoginDisclaimer');
    var acceptButton = document.getElementById('hrmsDisclaimerAccept');
    var csrfToken = <?= json_encode($_SESSION['disclaimer_csrf_token']) ?>;

    if (!overlay || !acceptButton) {
        return;
    }

    var originalOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function closeDisclaimer() {
        overlay.remove();
        document.body.style.overflow = originalOverflow || '';

        document.dispatchEvent(new CustomEvent('hrms:disclaimerAccepted'));
    }

    acceptButton.addEventListener('click', function () {
        if (acceptButton.disabled) {
            return;
        }

        acceptButton.disabled = true;

        fetch('/auth/acknowledge-disclaimer.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.success) {
                    throw new Error(
                        data.message || 'Acknowledgment could not be saved.'
                    );
                }

                return data;
            });
        })
        .then(function (data) {
            window.location.replace(
                data.redirect || '/modules/compliance/index.php?page=dashboard-overview'
            );
        })
        .catch(function (error) {
            acceptButton.disabled = false;

            alert(
                error.message ||
                'Unable to confirm acknowledgment. Please try again.'
            );
        });
    });

    acceptButton.focus();
})();
</script>
