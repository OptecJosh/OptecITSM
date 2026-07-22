<?php
/**
 * KPI Module Header Component. Tabs: Scorecards, Review cadence.
 */
$path_prefix = $path_prefix ?? '../';
$current_module = 'kpi';
$module_title = function_exists('t') ? t('common.modules.kpi.name') : 'KPIs';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';
require_once $path_prefix . 'includes/waffle-menu.php';
?>
<div class="header kpi-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>kpi/" class="nav-btn <?php echo $current_page === 'scorecards' ? 'active' : ''; ?>" title="Scorecards">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line><line x1="3" y1="20" x2="21" y2="20"></line>
            </svg>
            <span>Scorecards</span>
        </a>
        <a href="<?php echo BASE_URL; ?>kpi/cadence.php" class="nav-btn <?php echo $current_page === 'cadence' ? 'active' : ''; ?>" title="Review cadence">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            <span>Review cadence</span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>
<?php renderWaffleMenuJS(); ?>
