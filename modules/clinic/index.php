<?php
require_once 'classes/Page.php';

$pageController = new Page();
$currentPage = $pageController->getPage();

include 'includes/sidebar.php';
include 'includes/header.php';
?>

<main class="main-content">
    <div class="container">
        <?php $pageController->render(); ?>
    </div>
</main>

<?php
include __DIR__ . '/lib/includes/calendar_modal.php';
include 'includes/footer.php';