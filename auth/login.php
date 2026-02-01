<?php
require_once '../config/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(APP_URL . '/modules/dashboard/dashboard.php');
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        // Find user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Login successful - reset failed attempts and update last login
            $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL, last_login = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['last_activity'] = time();
            
            // Remember me (optional feature)
            if ($remember) {
                // Set cookie for 30 days
                setcookie('remember_user', $user['id'], time() + (30 * 24 * 60 * 60), '/');
            }
            
            // Log the login
            logActivity($pdo, 'User logged in', 'users', $user['id']);
            
            // Redirect to dashboard
            redirect(APP_URL . '/modules/dashboard/dashboard.php');
        } else {
            $error = 'Invalid username or password';
            
            // If user exists, increment failed login attempts
            if ($user) {
                $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = failed_login_attempts + 1, last_failed_login = CURRENT_TIMESTAMP WHERE username = ?");
                $stmt->execute([$username]);
            }
            
            // Log failed login attempt with NULL user_id (system event)
            try {
                $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (NULL, 'Failed login attempt', ?, ?)");
                $stmt->execute(['Username: ' . $username, getClientIP()]);
            } catch (Exception $e) {
                // Silently fail if logging doesn't work
            }
        }
    }
}

// Check for timeout message
$timeoutMessage = isset($_GET['timeout']) ? 'Your session has expired. Please login again.' : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 420px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header i {
            font-size: 50px;
            color: #667eea;
            margin-bottom: 15px;
        }
        
        .login-header h1 {
            font-size: 24px;
            color: #1f2937;
            margin: 0 0 5px 0;
        }
        
        .login-header p {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }
        
        .login-form {
            margin-top: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        
        .input-group input {
            padding-left: 40px;
        }
        
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .remember-forgot label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-car"></i>
            <h1><?php echo APP_NAME; ?></h1>
            <p>Please login to continue</p>
        </div>
        
        <?php if (isset($error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($timeoutMessage): ?>
        <div class="alert alert-warning">
            <i class="fas fa-clock"></i>
            <?php echo htmlspecialchars($timeoutMessage); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="login-form">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-control" 
                        placeholder="Enter your username"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                        required 
                        autofocus
                    >
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Enter your password"
                        required
                    >
                </div>
            </div>
            
            <div class="remember-forgot">
                <label>
                    <input type="checkbox" name="remember" id="remember">
                    Remember me
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block btn-lg">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>
        
        <div class="login-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
            <p>Default credentials: admin / admin123</p>
        </div>
    </div>
</body>
</html>