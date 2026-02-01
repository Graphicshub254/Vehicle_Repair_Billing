<?php
require_once '../../config/config.php';
requireLogin();

$pageTitle = 'My Profile';
$breadcrumbs = [
    ['text' => 'My Profile']
];

// Load current user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($full_name)) {
            setErrorMessage('Full name is required');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
            if ($stmt->execute([$full_name, $email, $_SESSION['user_id']])) {
                $_SESSION['user_name'] = $full_name; // Update session
                logActivity($pdo, 'Updated profile', 'users', $_SESSION['user_id']);
                setSuccessMessage('Profile updated successfully');
                redirect(APP_URL . '/modules/settings/profile.php');
            } else {
                setErrorMessage('Failed to update profile');
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password)) {
            setErrorMessage('All password fields are required');
        } elseif ($new_password !== $confirm_password) {
            setErrorMessage('New passwords do not match');
        } elseif (strlen($new_password) < 6) {
            setErrorMessage('New password must be at least 6 characters');
        } else {
            // Verify current password
            if (password_verify($current_password, $currentUser['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                
                if ($stmt->execute([$hashed_password, $_SESSION['user_id']])) {
                    logActivity($pdo, 'Changed password', 'users', $_SESSION['user_id']);
                    setSuccessMessage('Password changed successfully');
                    redirect(APP_URL . '/modules/settings/profile.php');
                } else {
                    setErrorMessage('Failed to change password');
                }
            } else {
                setErrorMessage('Current password is incorrect');
            }
        }
    }
}

// Reload user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch();

// Get user's recent activity
$stmt = $pdo->prepare("
    SELECT * FROM activity_log 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$recentActivity = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div class="page-header" style="margin-bottom: 20px;">
    <h1><i class="fas fa-user-circle"></i> My Profile</h1>
</div>

<!-- User Info Card -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <div style="display: flex; align-items: center; gap: 30px;">
            <div style="width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 42px; font-weight: bold;">
                <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
            </div>
            <div style="flex: 1;">
                <h2 style="margin: 0 0 10px 0;"><?php echo e($currentUser['full_name']); ?></h2>
                <p style="margin: 0; color: #6b7280;">
                    <i class="fas fa-user"></i> @<?php echo e($currentUser['username']); ?>
                    <span style="margin: 0 10px;">•</span>
                    <?php if ($currentUser['role'] === 'director'): ?>
                        <span class="badge badge-danger">
                            <i class="fas fa-crown"></i> Director
                        </span>
                    <?php elseif ($currentUser['role'] === 'procurement_officer'): ?>
                        <span class="badge badge-warning">
                            <i class="fas fa-clipboard"></i> Procurement Officer
                        </span>
                    <?php else: ?>
                        <span class="badge badge-info">
                            <i class="fas fa-user"></i> User
                        </span>
                    <?php endif; ?>
                </p>
                <p style="margin: 10px 0 0 0; color: #6b7280;">
                    <i class="fas fa-clock"></i> Member since <?php echo formatDate($currentUser['created_at']); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Update Profile -->
<div class="card" style="margin-bottom: 20px;">
    <form method="POST">
        <input type="hidden" name="action" value="update_profile">
        
        <div class="card-header">
            <h3><i class="fas fa-edit"></i> Update Profile Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="full_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="form-control" 
                           value="<?php echo e($currentUser['full_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?php echo e($currentUser['email']); ?>">
                </div>
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" class="form-control" value="<?php echo e($currentUser['username']); ?>" disabled>
                    <small class="form-hint">Username cannot be changed</small>
                </div>
            </div>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Profile
            </button>
        </div>
    </form>
</div>

<!-- Change Password -->
<div class="card" style="margin-bottom: 20px;">
    <form method="POST">
        <input type="hidden" name="action" value="change_password">
        
        <div class="card-header">
            <h3><i class="fas fa-lock"></i> Change Password</h3>
        </div>
        
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="current_password">Current Password <span class="required">*</span></label>
                    <input type="password" id="current_password" name="current_password" class="form-control" 
                           required autocomplete="current-password">
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password <span class="required">*</span></label>
                    <input type="password" id="new_password" name="new_password" class="form-control" 
                           required minlength="6" autocomplete="new-password">
                    <small class="form-hint">Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                           required minlength="6" autocomplete="new-password">
                </div>
            </div>
        </div>
        
        <div class="card-footer">
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-key"></i> Change Password
            </button>
        </div>
    </form>
</div>

<!-- Account Information -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-info-circle"></i> Account Information</h3>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label>Last Login</label>
                <p>
                    <?php if ($currentUser['last_login']): ?>
                        <?php echo formatDateTime($currentUser['last_login']); ?>
                    <?php else: ?>
                        <span class="text-muted">Never logged in before</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="form-group">
                <label>Last Failed Login</label>
                <p>
                    <?php if ($currentUser['last_failed_login']): ?>
                        <?php echo formatDateTime($currentUser['last_failed_login']); ?>
                        <br><small class="text-muted"><?php echo $currentUser['failed_login_attempts']; ?> failed attempt(s)</small>
                    <?php else: ?>
                        <span class="text-muted">No failed attempts</span>
                    <?php endif; ?>
                </p>
            </div>
            
            <div class="form-group">
                <label>Account Status</label>
                <p>
                    <?php if ($currentUser['is_active']): ?>
                        <span class="badge badge-success">Active</span>
                    <?php else: ?>
                        <span class="badge badge-secondary">Inactive</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> My Recent Activity</h3>
    </div>
    <div class="card-body">
        <?php if (empty($recentActivity)): ?>
            <p class="text-center text-muted">No recent activity</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentActivity as $activity): ?>
                    <tr>
                        <td><?php echo formatDateTime($activity['created_at']); ?></td>
                        <td>
                            <span class="badge badge-info">
                                <?php echo e($activity['action']); ?>
                            </span>
                        </td>
                        <td>
                            <code style="font-size: 12px;"><?php echo e($activity['table_name']); ?></code>
                        </td>
                        <td>
                            <small><?php echo e($activity['details']); ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
