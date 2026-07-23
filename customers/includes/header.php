<?php
/**
 * Customers Module Header Component.
 */
$path_prefix = $path_prefix ?? '../';
$current_module = 'customers';
$module_title = function_exists('t') ? t('common.modules.customers.name') : 'Customers';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = $current_page ?? '';
require_once $path_prefix . 'includes/waffle-menu.php';
?>
<div class="header customers-header">
    <div class="waffle-menu-container">
        <?php renderWaffleMenuButton(); ?>
        <?php renderWaffleMenuPanel($modules, $current_module, $path_prefix); ?>
        <span class="module-title"><?php echo htmlspecialchars($module_title); ?></span>
    </div>
    <nav class="header-nav">
        <a href="<?php echo BASE_URL; ?>customers/" class="nav-btn <?php echo $current_page === 'customers' ? 'active' : ''; ?>" title="Customers">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span>Customers</span>
        </a>
    </nav>
    <?php renderHeaderRight($analyst_name, $path_prefix); ?>
</div>
<?php renderWaffleMenuJS(); ?>
