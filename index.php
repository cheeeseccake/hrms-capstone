<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap">

    <meta name="theme-color" content="#17365c">

    <link rel="stylesheet" href="styles.css?v=login-subtitle-20261028021227">
    <link rel="shortcut icon" href="assets/bcp-logo.png" type="image/png">

    <title>HRMS Login | Bestlink College of the Philippines</title>
    <link rel="stylesheet" href="/assets/css/hrms-montserrat.css?v=20261027-190502">

    <!-- BCP LOGIN SKELETON INITIALIZATION -->
    <script>
    (function () {
        'use strict';

        document.documentElement.classList.add(
            'bcp-login-skeleton-pending'
        );

        var released = false;

        function releaseSkeleton() {
            if (released) return;
            released = true;

            document.documentElement.classList.remove(
                'bcp-login-skeleton-pending'
            );
        }

        window.addEventListener('load', function () {
            if (window.requestAnimationFrame) {
                requestAnimationFrame(function () {
                    requestAnimationFrame(releaseSkeleton);
                });
            } else {
                releaseSkeleton();
            }
        }, { once: true });

        // Prevent a permanent skeleton if a resource fails to load.
        window.setTimeout(releaseSkeleton, 8000);
    })();
    </script>
    <!-- END BCP LOGIN SKELETON INITIALIZATION -->


<style id="system-admin-shortcut">
.system-admin-shortcut {
    position: fixed;
    top: 18px;
    right: 22px;
    z-index: 10000;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    padding: 0;
    color: #17365c;
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid #dce4ee;
    border-radius: 7px;
    text-decoration: none;
    transition: background-color .18s ease, border-color .18s ease;
}

.system-admin-shortcut:hover {
    color: #17365c;
    background: #f2f6fb;
    border-color: #9eb4d0;
    text-decoration: none;
}

.system-admin-shortcut:focus-visible {
    outline: 2px solid rgba(23, 54, 92, .3);
    outline-offset: 2px;
}

.system-admin-shortcut svg {
    display: block;
    width: 24px;
    height: 24px;
    flex-shrink: 0;
}

@media (max-width: 575px) {
    .system-admin-shortcut {
        top: 10px;
        right: 10px;
        width: 40px;
        height: 40px;
    }
}
</style>

</head>

<body>
    <a
        href="/admin-login.php"
        class="system-admin-shortcut"
        aria-label="System Administrator Login"
        title="System Administrator"
    >
        <svg xmlns="http://www.w3.org/2000/svg"
             width="24" height="24" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true">
            <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>
            <path d="M6.376 18.91a6 6 0 0 1 11.249.003"/>
            <circle cx="12" cy="11" r="4"/>
        </svg>
    </a>

    <main class="login-container">

        <section class="brand-panel" aria-label="BCP institutional information">






            <?php require __DIR__ . '/includes/public-announcements.php'; ?>
            <footer class="brand-footer">
                <span>BCP · Bulacan Campus</span>
                <span>Human Resource Management System</span>
            </footer>

        </section>

        <section class="login-panel" aria-labelledby="loginTitle">

            <div class="login-contents">

                <div class="school-logo">
                    <img
                        src="/assets/bcp-logo.png"
                        alt="BCP School Logo"
                    >
                </div>

                <div class="login-heading">
                    <h1 id="loginTitle">BESTLINK HRMS</h1>

                    <p class="login-subtitle">Sign in with your employee credentials to continue.</p>
                </div>

                <form id="loginForm">

                    <div
                        class="error-message"
                        id="errorMsg"
                        role="alert"
                        aria-live="polite"
                    ></div>

                    <div class="input-group">
                        <label for="employee_id">Employee ID</label>

                        <input
                            type="text"
                            id="employee_id"
                            name="employee_id"
                            placeholder="Enter your Employee ID"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                        >
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <div class="forgot">
                        <a href="forgot_password.php">
                            Forgotten User ID or Password?
                        </a>
                    </div>

                    <button type="submit" id="loginBtn">
                        <span>Sign in</span>
                        <span class="login-button-arrow" aria-hidden="true">&rarr;</span>
                    </button>

                </form>

                <div class="login-bottom">
                    <span class="security-indicator" aria-hidden="true"></span>
                    <span>Authorized personnel only</span>
                </div>

            </div>

            <footer class="mobile-footer">
                Bestlink College of the Philippines · Bulacan Campus
            </footer>

        </section>

    </main>

    <script src="login.js"></script>

<style id="exact-brand-alignment">
body .brand-panel {
    position: relative !important;
    box-sizing: border-box !important;
}

body .brand-panel > .brand-identity {
    position: absolute !important;
    top: 40px !important;
    left: 48px !important;
    right: 48px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
}

body .brand-panel > .brand-identity img {
    display: block !important;
    flex: 0 0 72px !important;
    width: 72px !important;
    height: 72px !important;
    min-width: 72px !important;
    max-width: 72px !important;
    min-height: 72px !important;
    max-height: 72px !important;
    margin: 0 !important;
    padding: 0 !important;
    object-fit: contain !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}

body .brand-panel > .brand-identity .brand-school-name {
    display: block !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    font-size: 17px !important;
    font-weight: 700 !important;
    line-height: 1.45 !important;
    letter-spacing: .025em !important;
}

body .brand-panel > .brand-identity .brand-school-name > span {
    display: block !important;
}

body .brand-panel > .brand-footer {
    position: absolute !important;
    right: 48px !important;
    bottom: 32px !important;
    left: 48px !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-end !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    font-size: 11px !important;
    line-height: 1.6 !important;
}

body .brand-panel > .brand-footer span {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    max-width: none !important;
}

body .brand-panel > .brand-footer span:last-child {
    text-align: right !important;
}

@media (max-width: 760px) {
    body .brand-panel > .brand-identity {
        top: 24px !important;
        left: 22px !important;
        right: 22px !important;
        gap: 12px !important;
    }

    body .brand-panel > .brand-identity img {
        flex-basis: 56px !important;
        width: 56px !important;
        height: 56px !important;
        min-width: 56px !important;
        max-width: 56px !important;
        min-height: 56px !important;
        max-height: 56px !important;
    }

    body .brand-panel > .brand-identity .brand-school-name {
        font-size: 14px !important;
    }

    body .brand-panel > .brand-footer {
        right: 22px !important;
        bottom: 20px !important;
        left: 22px !important;
    }
}
</style>


<style>

/* AUTH PAGE NAVIGATION TRANSITION */
@keyframes authPanelEnter {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

body.auth-transition-enabled .login-container > .login-panel,
body.auth-transition-enabled .forgot-page.login-container > .forgot-card {
    animation: authPanelEnter 360ms cubic-bezier(.22, 1, .36, 1) both;
}

body.auth-transition-enabled.auth-page-leaving .login-container > .login-panel,
body.auth-transition-enabled.auth-page-leaving .forgot-page.login-container > .forgot-card {
    opacity: 0;
    transform: translateY(6px);
    transition: opacity 160ms ease, transform 160ms ease;
}

@media (prefers-reduced-motion: reduce) {
    body.auth-transition-enabled .login-container > .login-panel,
    body.auth-transition-enabled .forgot-page.login-container > .forgot-card {
        animation: none !important;
        transition: none !important;
    }
}

</style>
<script>

/* AUTH PAGE NAVIGATION TRANSITION SCRIPT */
(function () {
    'use strict';

    document.body.classList.add('auth-transition-enabled');

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.target && link.target !== '_self') return;
        if (link.hasAttribute('download')) return;

        let destination;
        try {
            destination = new URL(link.href, window.location.href);
        } catch (_) {
            return;
        }

        if (destination.origin !== window.location.origin) return;

        const currentPath = window.location.pathname.toLowerCase();
        const targetPath = destination.pathname.toLowerCase();
        const authPaths = ['/index.php', '/forgot_password.php', '/'];
        if (!authPaths.includes(currentPath) || !authPaths.includes(targetPath)) return;
        if (destination.href === window.location.href) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        event.preventDefault();
        document.body.classList.add('auth-page-leaving');

        window.setTimeout(function () {
            window.location.href = destination.href;
        }, 160);
    });
})();

</script>

<style>
/* EMPLOYEE ID INPUT USER ICON */
body .input-group:has(#employee_id) {
    position: relative !important;
}

body .input-group:has(#employee_id)::after {
    content: "";
    position: absolute;
    left: 14px;
    top: 39px;
    width: 19px;
    height: 19px;
    pointer-events: none;
    z-index: 2;
    background: no-repeat center / contain url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M16 2v2'/%3E%3Cpath d='M7 21v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2'/%3E%3Cpath d='M8 2v2'/%3E%3Ccircle cx='12' cy='10' r='3'/%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3C/svg%3E");
}

body input#employee_id {
    padding-left: 44px !important;
}
</style>

<style>
/* LOGIN TYPOGRAPHY AND SPACING MATCH */
html body .login-panel .login-contents {
    width: 100%;
    max-width: 440px;
    margin-left: auto;
    margin-right: auto;
    box-sizing: border-box;
}

html body .login-panel .school-logo {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 0 auto 24px;
}

html body .login-panel .login-heading {
    margin: 0 0 27px;
    text-align: center;
}

html body .login-panel .login-heading h1 {
    margin: 0;
    color: #102944;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 27px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -.45px;
    text-align: center;
}

html body .login-panel .login-subtitle {
    margin: 8px 0 0;
    color: #66758a;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 13px;
    font-weight: 400;
    line-height: 1.75;
    text-align: center;
}

html body .login-panel form {
    width: 100%;
    margin: 0;
}

html body .login-panel .input-group {
    position: relative;
    margin: 0 0 21px;
}

html body .login-panel .input-group label {
    display: block;
    margin: 0 0 8px;
    color: #344054;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 13px;
    font-weight: 600;
}

html body .login-panel .input-group input {
    display: block;
    width: 100%;
    min-width: 0;
    min-height: 49px;
    box-sizing: border-box;
    padding: 12px 14px;
    border: 1px solid #d5dee8;
    border-radius: 4px;
    background-color: #fff;
    color: #202b3c;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.5;
    box-shadow: none;
    transition: border-color .15s ease, box-shadow .15s ease;
}

html body .login-panel .input-group input#employee_id {
    padding-left: 44px;
}

html body .login-panel .input-group input::placeholder {
    color: #98a2b3;
    opacity: 1;
}

html body .login-panel .input-group input:hover {
    border-color: #aab9ca;
}

html body .login-panel .input-group input:focus,
html body .login-panel .input-group input:focus-visible {
    border-color: #285b91;
    outline: none;
    box-shadow: 0 0 0 3px rgba(40, 91, 145, .12);
}

html body .login-panel .forgot {
    margin: -3px 0 20px;
    text-align: right;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 13px;
    line-height: 1.5;
}

html body .login-panel .forgot a {
    color: #285b91;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
}

html body .login-panel .forgot a:hover {
    color: #17365c;
    text-decoration: underline;
}

html body .login-panel button#loginBtn {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 49px;
    margin-top: 5px;
    padding: 12px 18px;
    border: 1px solid #17365c;
    border-radius: 4px;
    background-color: #17365c;
    color: #fff;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 14px;
    font-weight: 650;
    line-height: 1.5;
    cursor: pointer;
    transition: background-color .15s ease, border-color .15s ease;
}

html body .login-panel button#loginBtn:hover {
    border-color: #204a78;
    background-color: #204a78;
}

html body .login-panel button#loginBtn:focus-visible {
    outline: 3px solid rgba(40, 91, 145, .22);
    outline-offset: 2px;
}

html body .login-panel .login-bottom {
    margin-top: 25px;
    color: #66758a;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 12px;
    font-weight: 400;
    line-height: 1.6;
    text-align: center;
}

@media (max-width: 760px) {
    html body .login-panel .login-contents {
        max-width: 440px;
    }

    html body .login-panel .login-heading {
        margin-bottom: 24px;
    }
}
</style>


<style>
/* LOGIN FORM COMPACT WIDTH */
@media (min-width: 761px) {
    html body > main.login-container > .login-panel > .login-contents form#loginForm {
        display: block !important;
        width: 100% !important;
        max-width: 360px !important;
        margin-left: auto !important;
        margin-right: auto !important;
        box-sizing: border-box !important;
    }
}
</style>

</body>
</html>
