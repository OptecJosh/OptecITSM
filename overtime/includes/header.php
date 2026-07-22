<?php
/**
 * Overtime Module Header Component (Phase 11).
 * Nav: My overtime (all), Approvals (managers/admins — 11b), Report (11d).
 */

$path_prefix = $path_prefix ?? '../';
$current_module = 'overtime';
$module_title = function_exists('t') ? t('common.modules.overtime.name') : 'Overtime';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';

// Is this analyst a line manager (has reports) or an admin? Drives whether the
// Approvals tab shows. Cheap query; the approvals page re-checks authoritatively.
$ot_can_approve = false;
try {
    if (function_exists('sessionIsAdmin') && sessionIsAdmin()) {
        $ot_can_approve = true;
    } elseif (isset($conn) && $conn instanceof PDO) {
        $s = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE manager_id = ?");
        $s->execute([(int)$_SESSION['analyst_id']]);
        $ot_can_approve = (int)$s->fetchColumn() > 0;
    }
} catch (Throwable $e) { /* default false */ }

require_once $path_prefix . 'includes/waffle-menu.php';
?>

<div class="header overtime-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>overtime/" class="nav-btn <?php echo $current_page === 'mine' ? 'active' : ''; ?>" title="My overtime">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <span>My overtime</span>
        </a>
        <?php if ($ot_can_approve): ?>
        <a href="<?php echo BASE_URL; ?>overtime/approvals.php" class="nav-btn <?php echo $current_page === 'approvals' ? 'active' : ''; ?>" title="Approvals">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span>Approvals</span>
        </a>
        <?php endif; ?>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>

<?php renderWaffleMenuJS(); ?>
