<?php
$adminHeaderData = $_SESSION['lc_admin'] ?? [];

$adminHeaderName = htmlspecialchars(
    (string) ($adminHeaderData['display_name'] ?? 'Administrator'),
    ENT_QUOTES,
    'UTF-8'
);

$adminHeaderRole = htmlspecialchars(
    (string) ($adminHeaderData['role_name'] ?? 'Administrator'),
    ENT_QUOTES,
    'UTF-8'
);

$adminHeaderCsrf = htmlspecialchars(
    lcAdminCsrfToken(),
    ENT_QUOTES,
    'UTF-8'
);
?>

<header class="topbar">
    <div class="brand">
        BESTLINK HRMS
        <span>Legal and Compliance Administration</span>
    </div>

    <div class="account-menu">
        <button type="button"
                class="account-trigger"
                aria-label="Open account menu"
                aria-expanded="false"
                aria-haspopup="true">
            <span class="account-user-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg"
                     width="24"
                     height="24"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2"
                     stroke-linecap="round"
                     stroke-linejoin="round"
                     class="lucide lucide-contact-round preview-icon">
                    <path d="M16 2v2"/>
                    <path d="M17.915 21a6 6 0 10-12 0"/>
                    <path d="M8 2v2"/>
                    <circle cx="12" cy="11" r="4"/>
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                </svg>
            </span>
        </button>

        <div class="account-dropdown" hidden>
            <div class="account-dropdown-info">
                <strong><?= $adminHeaderName ?></strong>
                <small><?= $adminHeaderRole ?></small>
            </div>

            <form method="POST" action="/admin-logout.php">
                <input type="hidden"
                       name="csrf_token"
                       value="<?= $adminHeaderCsrf ?>">

                <button class="logout" type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg"
                         width="18"
                         height="18"
                         viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="2"
                         stroke-linecap="round"
                         stroke-linejoin="round"
                         aria-hidden="true">
                        <path d="m16 17 5-5-5-5"/>
                        <path d="M21 12H9"/>
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    </svg>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </div>
</header>
