<?php
require_once '../../config/config.php';
requireLogin();
requireDirector(); // Only directors can access settings

$pageTitle = 'System Settings';
$breadcrumbs = [
    ['text' => 'Settings']
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_company') {
        $company_name = trim($_POST['company_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        
        $stmt = $pdo->prepare("
            UPDATE company_settings 
            SET company_name = ?, address = ?, phone = ?, email = ?, website = ?
            WHERE id = 1
        ");
        
        if ($stmt->execute([$company_name, $address, $phone, $email, $website])) {
            logActivity($pdo, 'Updated company settings', 'company_settings', 1);
            setSuccessMessage('Company information updated successfully');
        } else {
            setErrorMessage('Failed to update company information');
        }
        redirect(APP_URL . '/modules/settings/settings.php');
        
    } elseif ($action === 'update_financial') {
        $vat_rate = floatval($_POST['vat_rate'] ?? 16);
        $default_labor_markup = floatval($_POST['default_labor_markup'] ?? 0);
        $default_parts_markup = floatval($_POST['default_parts_markup'] ?? 20);
        $default_subcontract_markup = floatval($_POST['default_subcontract_markup'] ?? 15);
        
        $stmt = $pdo->prepare("
            UPDATE company_settings 
            SET vat_rate = ?, default_labor_markup = ?, 
                default_isuzu_parts_markup = ?, 
                default_subcontract_parts_markup = ?, 
                default_subcontract_service_markup = ?
            WHERE id = 1
        ");
        
        if ($stmt->execute([$vat_rate, $default_labor_markup, $default_parts_markup, $default_parts_markup, $default_subcontract_markup])) {
            logActivity($pdo, 'Updated financial settings', 'company_settings', 1);
            setSuccessMessage('Financial settings updated successfully');
        } else {
            setErrorMessage('Failed to update financial settings');
        }
        redirect(APP_URL . '/modules/settings/settings.php');
        
    } elseif ($action === 'add_supplier') {
        $name = trim($_POST['name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (!empty($name)) {
            $stmt = $pdo->prepare("
                INSERT INTO suppliers (name, contact_person, phone, email, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            
            if ($stmt->execute([$name, $contact_person, $phone, $email])) {
                logActivity($pdo, 'Added supplier', 'suppliers', $pdo->lastInsertId(), "Name: $name");
                setSuccessMessage("Supplier '$name' added successfully");
            }
        }
        redirect(APP_URL . '/modules/settings/settings.php#suppliers');
        
    } elseif ($action === 'toggle_supplier') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE suppliers SET is_active = NOT is_active WHERE id = ?");
        if ($stmt->execute([$id])) {
            setSuccessMessage('Supplier status updated');
        }
        redirect(APP_URL . '/modules/settings/settings.php#suppliers');
        
    } elseif ($action === 'add_subcontractor') {
        $name = trim($_POST['name'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (!empty($name)) {
            $stmt = $pdo->prepare("
                INSERT INTO subcontractors (name, specialization, contact_person, phone, email, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            
            if ($stmt->execute([$name, $specialization, $contact_person, $phone, $email])) {
                logActivity($pdo, 'Added subcontractor', 'subcontractors', $pdo->lastInsertId(), "Name: $name");
                setSuccessMessage("Subcontractor '$name' added successfully");
            }
        }
        redirect(APP_URL . '/modules/settings/settings.php#subcontractors');
        
    } elseif ($action === 'toggle_subcontractor') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE subcontractors SET is_active = NOT is_active WHERE id = ?");
        if ($stmt->execute([$id])) {
            setSuccessMessage('Subcontractor status updated');
        }
        redirect(APP_URL . '/modules/settings/settings.php#subcontractors');
    }
}

// Load current settings
$settings = getCompanySettings($pdo);

// Load suppliers
$suppliersStmt = $pdo->query("SELECT * FROM suppliers ORDER BY name");
$suppliers = $suppliersStmt->fetchAll();

// Load subcontractors
$subcontractorsStmt = $pdo->query("SELECT * FROM subcontractors ORDER BY name");
$subcontractors = $subcontractorsStmt->fetchAll();

// Load users
$usersStmt = $pdo->query("SELECT * FROM users ORDER BY full_name");
$users = $usersStmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="margin-bottom: 20px;">
    <h1><i class="fas fa-cog"></i> System Settings</h1>
</div>

<!-- Settings Navigation Tabs -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <div style="display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">
            <a href="#company" class="settings-tab active" onclick="showTab('company', this)">
                <i class="fas fa-building"></i> Company Info
            </a>
            <a href="#financial" class="settings-tab" onclick="showTab('financial', this)">
                <i class="fas fa-dollar-sign"></i> Financial
            </a>
            <a href="#subcontractors" class="settings-tab" onclick="showTab('subcontractors', this)">
                <i class="fas fa-users"></i> Subcontractors
            </a>
        </div>
    </div>
</div>

<!-- Company Information -->
<div id="tab-company" class="settings-tab-content">
    <div class="card">
        <form method="POST">
            <input type="hidden" name="action" value="update_company">
            
            <div class="card-header">
                <h3><i class="fas fa-building"></i> Company Information</h3>
            </div>
            
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="company_name">Company Name <span class="required">*</span></label>
                        <input type="text" id="company_name" name="company_name" class="form-control" 
                               value="<?php echo e($settings['company_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" class="form-control" 
                               value="<?php echo e($settings['phone']); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo e($settings['email']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website" class="form-control" 
                               value="<?php echo e($settings['website']); ?>" placeholder="https://example.com">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" class="form-control" rows="3"><?php echo e($settings['address']); ?></textarea>
                </div>
            </div>
            
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Company Information
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Financial Settings -->
<div id="tab-financial" class="settings-tab-content" style="display: none;">
    <div class="card">
        <form method="POST">
            <input type="hidden" name="action" value="update_financial">
            
            <div class="card-header">
                <h3><i class="fas fa-dollar-sign"></i> Financial Settings</h3>
            </div>
            
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Note:</strong> These are default values. You can override them for individual items when generating invoices.
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="vat_rate">VAT Rate (%) <span class="required">*</span></label>
                        <input type="number" id="vat_rate" name="vat_rate" class="form-control" 
                               value="<?php echo e($settings['vat_rate']); ?>" step="0.01" min="0" max="100" required>
                        <small class="form-hint">Currently: <?php echo $settings['vat_rate']; ?>%</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="default_labor_markup">Default Labor Markup (%)</label>
                        <input type="number" id="default_labor_markup" name="default_labor_markup" class="form-control" 
                               value="<?php echo e($settings['default_labor_markup']); ?>" step="0.1" min="0" max="100">
                        <small class="form-hint">Recommended: 0% (pass-through)</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="default_parts_markup">Default Parts Markup (%)</label>
                        <input type="number" id="default_parts_markup" name="default_parts_markup" class="form-control" 
                               value="<?php echo e($settings['default_isuzu_parts_markup']); ?>" step="0.1" min="0" max="100">
                        <small class="form-hint">Recommended: 15-25%</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="default_subcontract_markup">Default Subcontract Markup (%)</label>
                        <input type="number" id="default_subcontract_markup" name="default_subcontract_markup" class="form-control" 
                               value="<?php echo e($settings['default_subcontract_service_markup']); ?>" step="0.1" min="0" max="100">
                        <small class="form-hint">Recommended: 10-20%</small>
                    </div>
                </div>
            </div>
            
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Financial Settings
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Subcontractors Management -->
<div id="tab-subcontractors" class="settings-tab-content" style="display: none;">
    <!-- Add Subcontractor Form -->
    <div class="card" style="margin-bottom: 20px;">
        <form method="POST">
            <input type="hidden" name="action" value="add_subcontractor">
            
            <div class="card-header">
                <h3><i class="fas fa-plus"></i> Add New Subcontractor</h3>
            </div>
            
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="sub_name">Name <span class="required">*</span></label>
                        <input type="text" id="sub_name" name="name" class="form-control" required 
                               placeholder="e.g., AutoGlass Specialists">
                    </div>
                    
                    <div class="form-group">
                        <label for="sub_specialization">Specialization</label>
                        <input type="text" id="sub_specialization" name="specialization" class="form-control" 
                               placeholder="e.g., Windscreen installation">
                    </div>
                    
                    <div class="form-group">
                        <label for="sub_contact">Contact Person</label>
                        <input type="text" id="sub_contact" name="contact_person" class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="sub_phone">Phone</label>
                        <input type="text" id="sub_phone" name="phone" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label for="sub_email">Email</label>
                        <input type="email" id="sub_email" name="email" class="form-control">
                    </div>
                </div>
            </div>
            
            <div class="card-footer">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-plus"></i> Add Subcontractor
                </button>
            </div>
        </form>
    </div>
    
    <!-- Subcontractors List -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-users"></i> Subcontractors List</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Specialization</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subcontractors as $sub): ?>
                        <tr>
                            <td><strong><?php echo e($sub['name']); ?></strong></td>
                            <td><?php echo e($sub['specialization']) ?: '-'; ?></td>
                            <td><?php echo e($sub['contact_person']) ?: '-'; ?></td>
                            <td><?php echo e($sub['phone']) ?: '-'; ?></td>
                            <td><?php echo e($sub['email']) ?: '-'; ?></td>
                            <td>
                                <?php if ($sub['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_subcontractor">
                                    <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $sub['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <i class="fas fa-<?php echo $sub['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        <?php echo $sub['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
