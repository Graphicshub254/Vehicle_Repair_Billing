<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Dashboard';
}

$currentUser = getCurrentUser();
$currentPath = $_SERVER['PHP_SELF'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function showTab(tabName, clickedElement) {
        // Hide all tabs
        document.querySelectorAll('.settings-tab-content').forEach(tab => {
            tab.style.display = 'none';
        });
        
        // Remove active class from all tab links
        document.querySelectorAll('.settings-tab').forEach(link => {
            link.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById('tab-' + tabName).style.display = 'block';
        
        // Add active class to clicked tab
        if (clickedElement) {
            clickedElement.classList.add('active');
        } else {
            // Fallback for initial load if element is not directly clicked
            const defaultClickedElement = document.querySelector(`.settings-tab[href="#${tabName}"]`);
            if (defaultClickedElement) {
                defaultClickedElement.classList.add('active');
            }
        }
    }

    // Handle hash in URL
    window.addEventListener('load', function() {
        const hash = window.location.hash.substring(1);
        if (hash && document.getElementById('tab-' + hash)) {
            showTab(hash, document.querySelector(`.settings-tab[href="#${hash}"]`));
        } else {
            // Show default tab if no hash or hash is invalid
            showTab('company', document.querySelector('.settings-tab.active'));
        }
    });

    // Script to remember sidebar scroll position
    document.addEventListener('DOMContentLoaded', function() {
        const navbarMenu = document.querySelector('.navbar-menu');
        const scrollKey = 'sidebarScrollPosition';

        // Restore scroll position on page load
        if (navbarMenu) {
            const savedScrollPosition = localStorage.getItem(scrollKey);
            if (savedScrollPosition) {
                navbarMenu.scrollTop = parseInt(savedScrollPosition, 10);
            }

            // Save scroll position before navigating
            navbarMenu.addEventListener('click', function(event) {
                // Only save if the click leads to a navigation (i.e., not just a random click inside the menu div)
                if (event.target.closest('a')) { // Check if an anchor link was clicked or is an ancestor
                    localStorage.setItem(scrollKey, navbarMenu.scrollTop);
                }
            });
        }
    });
    </script>
</head>
<body>
    <div class="app-layout">
    <!-- Left Sidebar / Navigation -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-brand">
                <i class="fas fa-car"></i>
                <span class="brand-text"><?php echo APP_NAME; ?></span>
            </div>
            
            <div class="navbar-menu">
                <!-- Core Links -->
                <a href="<?php echo APP_URL; ?>/modules/dashboard/dashboard.php" class="nav-link <?php echo strpos($currentPath, 'dashboard') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                
                <a href="<?php echo APP_URL; ?>/modules/vehicles/vehicles.php" class="nav-link <?php echo strpos($currentPath, 'vehicles') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-car"></i> Vehicles
                </a>
                
                <a href="<?php echo APP_URL; ?>/modules/jobs/jobs.php" class="nav-link <?php echo strpos($currentPath, 'jobs') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-wrench"></i> Jobs
                </a>

                <h3 class="navbar-category-title">Operations</h3>
                <a href="<?php echo APP_URL; ?>/modules/labor/add_labor.php" class="nav-link <?php echo strpos($currentPath, 'labor') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i> Labor
                </a>
                <a href="<?php echo APP_URL; ?>/modules/invoices/invoices.php" class="nav-link <?php echo strpos($currentPath, 'invoices') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i> Invoices
                </a>

                <?php if (isProcurementOfficer() || isDirector()): ?>
                <h3 class="navbar-category-title">Quotations</h3>
                <a href="<?php echo APP_URL; ?>/modules/quotations/quotations.php" class="nav-link <?php echo strpos($currentPath, 'quotations') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All Quotations
                </a>
                <a href="<?php echo APP_URL; ?>/modules/quotations/create_quotation.php" class="nav-link <?php echo strpos($currentPath, 'quotations') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-plus"></i> Create Quotation
                </a>
                <?php if (isDirector()): ?>
                <a href="<?php echo APP_URL; ?>/modules/quotations/approve_quotation.php" class="nav-link <?php echo strpos($currentPath, 'quotations') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-check-circle"></i> Pending Approvals
                </a>
                <?php endif; ?>

                <h3 class="navbar-category-title">Subcontracts</h3>
                <a href="<?php echo APP_URL; ?>/modules/subcontracts/subcontracts.php" class="nav-link <?php echo strpos($currentPath, 'subcontracts') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All Subcontracts
                </a>
                <a href="<?php echo APP_URL; ?>/modules/subcontracts/add_subcontract.php" class="nav-link <?php echo strpos($currentPath, 'subcontracts') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-plus"></i> Add Subcontract
                </a>
                <?php endif; ?>

                <?php if (isDirector()): ?>
                <h3 class="navbar-category-title">Admin & Settings</h3>
                <a href="<?php echo APP_URL; ?>/modules/reports/reports.php" class="nav-link <?php echo strpos($currentPath, 'reports') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
                <a href="<?php echo APP_URL; ?>/modules/settings/user_management.php" class="nav-link <?php echo strpos($currentPath, 'settings') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> User Management
                </a>
                <a href="<?php echo APP_URL; ?>/modules/settings/suppliers.php" class="nav-link <?php echo strpos($currentPath, 'settings') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-truck"></i> Supplier Management
                </a>
                <a href="<?php echo APP_URL; ?>/modules/settings/call_home.php" class="nav-link <?php echo strpos($currentPath, 'settings') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-satellite-dish"></i> Call Home
                </a>
                <a href="<?php echo APP_URL; ?>/modules/settings/settings.php" class="nav-link <?php echo strpos($currentPath, 'settings') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i> General Settings
                </a>
                <a href="<?php echo APP_URL; ?>/modules/settings/audit_trail.php" class="nav-link <?php echo strpos($currentPath, 'settings') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i> Audit Trail
                </a>
                <a href="<?php echo APP_URL; ?>/modules/settings/profile.php" class="nav-link <?php echo strpos($currentPath, 'settings') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-user-circle"></i> My Profile
                </a>
                <?php endif; ?>
            </div>
            
            <div class="navbar-user">
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span class="user-name"><?php echo e($currentUser['full_name']); ?></span>
                    <span class="user-role">(<?php echo e(ucwords(str_replace('_', ' ', $currentUser['role']))); ?>)</span>
                </div>
                <a href="<?php echo APP_URL; ?>/auth/logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content Container -->
    <div class="main-content">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="<?php echo APP_URL; ?>/modules/dashboard/dashboard.php">
                <i class="fas fa-home"></i> Home
            </a>
            <?php if (isset($breadcrumbs) && is_array($breadcrumbs)): ?>
                <?php foreach ($breadcrumbs as $breadcrumb): ?>
                    <span class="separator">/</span>
                    <?php if (isset($breadcrumb['url'])): ?>
                        <a href="<?php echo $breadcrumb['url']; ?>"><?php echo e($breadcrumb['text']); ?></a>
                    <?php else: ?>
                        <span><?php echo e($breadcrumb['text']); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <span class="separator">/</span>
                <span><?php echo e($pageTitle); ?></span>
            <?php endif; ?>
        </div>
        
        <!-- Flash Messages -->
        <?php
        $successMsg = getSuccessMessage();
        $errorMsg = getErrorMessage();
        ?>
        
        <?php if ($successMsg): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo e($successMsg); ?>
            <button class="alert-close" onclick="this.parentElement.remove()">×</button>
        </div>
        <?php endif; ?>
        
        <?php if ($errorMsg): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo e($errorMsg); ?>
            <button class="alert-close" onclick="this.parentElement.remove()">×</button>
        </div>
        <?php endif; ?>
        
        <!-- Page Content Starts Here -->
