<?php
// auth/login-disclaimer.php
// Login disclaimer modal/content

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['show_login_disclaimer'])) {
    unset($_SESSION['show_login_disclaimer']);
?>
<link rel="stylesheet" href="/hrms-capstone/auth/login-disclaimer.css?v=<?php echo filemtime(__DIR__ . '/login-disclaimer.css'); ?>">
<div id="loginDisclaimerModal" tabindex="-1" aria-labelledby="loginDisclaimerModalLabel" aria-hidden="true" role="dialog">
    <div class="login-disclaimer-modal-dialog modal-dialog-centered">
        <div class="login-disclaimer-modal-content">
            <div class="login-disclaimer-modal-header">
                <div>
                    <p class="login-disclaimer-eyebrow">School HR Clinic</p>
                    <h5 class="login-disclaimer-modal-title" id="loginDisclaimerModalLabel">System Notice</h5>
                </div>
                <button type="button" class="login-disclaimer-close-btn" aria-label="Close" id="loginDisclaimerClose">&times;</button>
            </div>
            <div class="login-disclaimer-modal-body">
                <p>Welcome to the School HR Clinic Management System. This system supports authorized school personnel with clinic and employee health services.</p>
                <p><strong>System updates:</strong> The system is being improved over time, so features, screens, and procedures may change. Please follow the latest instructions and contact your system administrator if you notice an issue.</p>
                <p class="mb-0"><strong>Privacy reminder:</strong> Health and employee information is confidential. Access only the information needed for your duties, keep your login details private, and log out after each session. Follow your school's policies when handling records.</p>
            </div>
            <div class="login-disclaimer-modal-footer">
                <button type="button" class="login-disclaimer-btn login-disclaimer-btn-primary" id="loginDisclaimerConfirm">I Understand</button>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var modalEl = document.getElementById('loginDisclaimerModal');
    var closeBtn = document.getElementById('loginDisclaimerClose');
    var confirmBtn = document.getElementById('loginDisclaimerConfirm');
    
    if (!modalEl) return;
    
    // Show modal
    modalEl.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Focus trap
    var focusableElements = modalEl.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    var firstFocusable = focusableElements[0];
    var lastFocusable = focusableElements[focusableElements.length - 1];
    
    if (firstFocusable) firstFocusable.focus();
    
    function handleKeydown(e) {
        if (e.key === 'Tab') {
            if (e.shiftKey && document.activeElement === firstFocusable) {
                e.preventDefault();
                lastFocusable.focus();
            } else if (!e.shiftKey && document.activeElement === lastFocusable) {
                e.preventDefault();
                firstFocusable.focus();
            }
        }
        if (e.key === 'Escape') {
            closeModal();
        }
    }
    
    function closeModal() {
        modalEl.classList.remove('show');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', handleKeydown);
    }
    
    document.addEventListener('keydown', handleKeydown);
    
    // Close on button clicks
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (confirmBtn) confirmBtn.addEventListener('click', closeModal);
    
    // Close on backdrop click
    modalEl.addEventListener('click', function(e) {
        if (e.target === modalEl) {
            closeModal();
        }
    });
})();
</script>
<?php
}