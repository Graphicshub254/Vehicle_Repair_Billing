<?php
require_once '../../config/config.php';
requireLogin();
requireDirector(); // Only directors can manage users

$pageTitle = 'User Management';
$breadcrumbs = [
    ['text' => 'Settings', 'url' => APP_URL . '/modules/settings/settings.php'],
    ['text' => 'Users']
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        
        // Validation
        if (empty($username) || empty($password) || empty($full_name)) {
            setErrorMessage('Username, password, and full name are required');
        } elseif ($password !== $confirm_password) {
            setErrorMessage('Passwords do not match');
        } elseif (strlen($password) < 6) {
            setErrorMessage('Password must be at least 6 characters');
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($stmt->fetch()) {
                setErrorMessage('Username already exists');
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, email, role, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                
                if ($stmt->execute([$username, $hashed_password, $full_name, $email, $role])) {
                    $userId = $pdo->lastInsertId();
                    logActivity($pdo, 'Created user', 'users', $userId, "Username: $username, Role: $role");
                    setSuccessMessage("User '$username' created successfully");
                    redirect(APP_URL . '/modules/settings/user_management.php');
                } else {
                    setErrorMessage('Failed to create user');
                }
            }
        }
    } elseif ($action === 'update_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $password = $_POST['password'] ?? '';
        
        if (empty($full_name)) {
            setErrorMessage('Full name is required');
        } else {
            // Build update query
            if (!empty($password)) {
                if (strlen($password) < 6) {
                    setErrorMessage('Password must be at least 6 characters');
                    redirect(APP_URL . '/modules/settings/user_management.php');
                    exit();
                }
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, role = ?, password = ?
                    WHERE id = ? AND id != ?
                ");
                $stmt->execute([$full_name, $email, $role, $hashed_password, $user_id, $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, role = ?
                    WHERE id = ? AND id != ?
                ");
                $stmt->execute([$full_name, $email, $role, $user_id, $_SESSION['user_id']]);
            }
            
            if ($stmt->rowCount() > 0) {
                logActivity($pdo, 'Updated user', 'users', $user_id, "Full name: $full_name, Role: $role");
                setSuccessMessage('User updated successfully');
            } else {
                setErrorMessage('No changes made or cannot modify your own account');
            }
            redirect(APP_URL . '/modules/settings/user_management.php');
        }
    } elseif ($action === 'toggle_status') {
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if ($user_id === $_SESSION['user_id']) {
            setErrorMessage('Cannot deactivate your own account');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
            if ($stmt->execute([$user_id])) {
                logActivity($pdo, 'Toggled user status', 'users', $user_id);
                setSuccessMessage('User status updated');
            }
        }
        redirect(APP_URL . '/modules/settings/user_management.php');
    } elseif ($action === 'reset_failed_logins') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            logActivity($pdo, 'Reset failed logins', 'users', $user_id);
            setSuccessMessage('Failed login attempts reset');
        }
        redirect(APP_URL . '/modules/settings/user_management.php');
    }
}

// Get action
$viewAction = $_GET['action'] ?? 'list';
$userId = intval($_GET['id'] ?? 0);

// Load user for edit
$editUser = null;
if ($viewAction === 'edit' && $userId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $editUser = $stmt->fetch();
    
    if (!$editUser) {
        setErrorMessage('User not found');
        redirect(APP_URL . '/modules/settings/user_management.php');
    }
}

// Load all users
$usersStmt = $pdo->query("SELECT * FROM users ORDER BY full_name");
$users = $usersStmt->fetchAll();

include '../../includes/header.php';
?>

<?php if ($viewAction === 'list'): ?>
<!-- List View -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-users"></i> User Management</h1>
    <div>
        <a href="<?php echo APP_URL; ?>/modules/settings/settings.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Settings
        </a>
        <a href="?action=create" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Add New User
        </a>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Failed Logins</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <strong><?php echo e($user['full_name']); ?></strong>
                            <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                <span class="badge badge-info">You</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($user['username']); ?></td>
                        <td><?php echo e($user['email']) ?: '-'; ?></td>
                        <td>
                            <?php if ($user['role'] === 'director'): ?>
                                <span class="badge badge-danger">
                                    <i class="fas fa-crown"></i> Director
                                </span>
                            <?php elseif ($user['role'] === 'procurement_officer'): ?>
                                <span class="badge badge-warning">
                                    <i class="fas fa-clipboard"></i> Procurement Officer
                                </span>
                            <?php else: ?>
                                <span class="badge badge-info">
                                    <i class="fas fa-user"></i> User
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['last_login']): ?>
                                <?php echo formatDateTime($user['last_login']); ?>
                            <?php else: ?>
                                <span class="text-muted">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['failed_login_attempts'] > 0): ?>
                                <span class="badge badge-danger">
                                    <?php echo $user['failed_login_attempts']; ?> attempts
                                </span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="?action=edit&id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                
                                <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $user['is_active'] ? 'btn-secondary' : 'btn-success'; ?>" 
                                            title="<?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                        <i class="fas fa-<?php echo $user['is_active'] ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($user['failed_login_attempts'] > 0): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="reset_failed_logins">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-info" title="Reset Failed Logins">
                                        <i class="fas fa-redo"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($viewAction === 'create'): ?>
<!-- Create User Form -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-user-plus"></i> Add New User</h1>
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<div class="card">
    <form method="POST">
        <input type="hidden" name="action" value="create_user">
        
        <div class="card-header">
            <h3>User Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="username">Username <span class="required">*</span></label>
                    <input type="text" id="username" name="username" class="form-control" required 
                           placeholder="e.g., jdoe" autocomplete="off">
                    <small class="form-hint">Used for login, cannot be changed later</small>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required 
                           placeholder="e.g., John Doe">
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           placeholder="user@example.com">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <input type="password" id="password" name="password" class="form-control" required 
                           minlength="6" autocomplete="new-password">
                    <small class="form-hint">Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required 
                           minlength="6" autocomplete="new-password">
                </div>
                
                <div class="form-group">
                    <label for="role">Role <span class="required">*</span></label>
                    <select id="role" name="role" class="form-control" required>
                        <option value="user">User</option>
                        <option value="procurement_officer">Procurement Officer</option>
                        <option value="director">Director</option>
                    </select>
                </div>
            </div>
            
            <div class="alert alert-info">
                <strong>Role Permissions:</strong>
                <ul style="margin: 10px 0 0 20px;">
                    <li><strong>User:</strong> Create jobs, add labor, view reports</li>
                    <li><strong>Procurement Officer:</strong> All user permissions + create quotations/subcontracts</li>
                    <li><strong>Director:</strong> Full access + approve quotations/subcontracts, see exact profits</li>
                </ul>
            </div>
        </div>
        
        <div class="card-footer">
            <a href="?" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Create User
            </button>
        </div>
    </form>
</div>

<?php elseif ($viewAction === 'edit' && $editUser): ?>
<!-- Edit User Form -->
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1><i class="fas fa-user-edit"></i> Edit User</h1>
    <a href="?" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($editUser['id'] === $_SESSION['user_id']): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Note:</strong> You cannot change your own role or deactivate your own account. 
    Use <a href="<?php echo APP_URL; ?>/modules/settings/profile.php">Profile Settings</a> to update your details.
</div>
<?php endif; ?>

<div class="card">
    <form method="POST">
        <input type="hidden" name="action" value="update_user">
        <input type="hidden" name="user_id" value="<?php echo $editUser['id']; ?>">
        
        <div class="card-header">
            <h3>User Information</h3>
        </div>
        
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" class="form-control" value="<?php echo e($editUser['username']); ?>" disabled>
                    <small class="form-hint">Username cannot be changed</small>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="form-control" required 
                           value="<?php echo e($editUser['full_name']); ?>">
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?php echo e($editUser['email']); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" class="form-control" 
                           minlength="6" autocomplete="new-password">
                    <small class="form-hint">Leave blank to keep current password</small>
                </div>
                
                <div class="form-group">
                    <label for="role">Role <span class="required">*</span></label>
                    <select id="role" name="role" class="form-control" required 
                            <?php echo $editUser['id'] === $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                        <option value="user" <?php echo $editUser['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="procurement_officer" <?php echo $editUser['role'] === 'procurement_officer' ? 'selected' : ''; ?>>Procurement Officer</option>
                        <option value="director" <?php echo $editUser['role'] === 'director' ? 'selected' : ''; ?>>Director</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <p>
                        <?php if ($editUser['is_active']): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Inactive</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Last Login</label>
                    <p><?php echo $editUser['last_login'] ? formatDateTime($editUser['last_login']) : 'Never'; ?></p>
                </div>
                
                <div class="form-group">
                    <label>Failed Login Attempts</label>
                    <p>
                        <?php if ($editUser['failed_login_attempts'] > 0): ?>
                            <span class="badge badge-danger"><?php echo $editUser['failed_login_attempts']; ?> attempts</span>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="form-group">
                    <label>Account Created</label>
                    <p><?php echo formatDateTime($editUser['created_at']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="card-footer">
            <a href="?" class="btn btn-secondary">Cancel</a>
            <?php if ($editUser['id'] !== $_SESSION['user_id']): ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update User
            </button>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
