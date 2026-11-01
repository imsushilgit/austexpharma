<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Agar user already logged in hai, toh seedha dashboard par bhejein
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

require_once __DIR__ . '/../includes/core.php'; 

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, fullname, password, role, failed_attempts, max_attempts FROM users WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]); 
            $user = $stmt->fetch(PDO::FETCH_ASSOC); 

            if ($user) {
                if ($user['role'] === 'Locked') {
                    $error = "Your account has been locked. Please contact the Administrator.";
                } else if (password_verify($password, $user['password']) || $password === $user['password']) {
                    
                    // Plain text password ko securely hash karke DB mein update karein
                    if ($password === $user['password']) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
                    }
                    
                    $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE id = ?")->execute([$user['id']]);
                    session_regenerate_id(true);

                    // Core session variables set karein
                    $_SESSION['user_id']   = $user['id']; 
                    $_SESSION['username']  = $user['username']; 
                    $_SESSION['fullname']  = $user['fullname']; 
                    $_SESSION['user_role'] = $user['role']; 

                    // Dashboard par redirect karein
                    header("Location: ../dashboard.php"); 
                    exit();
                } else {
                    $new_attempts = ($user['failed_attempts'] ?? 0) + 1;
                    $max_attempts = $user['max_attempts'] ?? 3;
                    
                    if ($new_attempts >= $max_attempts) {
                        $pdo->prepare("UPDATE users SET failed_attempts = ?, role = 'Locked' WHERE id = ?")->execute([$new_attempts, $user['id']]);
                        $error = "Account locked due to too many failed login attempts. Contact Administrator.";
                    } else {
                        $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")->execute([$new_attempts, $user['id']]);
                        $remaining = $max_attempts - $new_attempts;
                        $error = "Invalid password. You have $remaining attempt(s) left before lock.";
                    }
                }
            } else {
                $error = "Invalid username or password."; 
            }
        } catch (PDOException $e) {
            $error = "Database error. Please try again later."; 
        }
    } else {
        $error = "Please fill in all credentials."; 
    }
}

// Fetch dynamic company info
$companyNameDisplay = 'Enterprise ERP';
$logoType = 'none';
$logoPath = '';
$logoHtml = '';

try {
    if (isset($pdo)) {
        $stmtComp = $pdo->query("SELECT companyname, logotype, logopath, logohtml FROM companies ORDER BY id ASC LIMIT 1");
        if ($cData = $stmtComp->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($cData['companyname'])) {
                $companyNameDisplay = $cData['companyname'];
            }
            $logoType = $cData['logotype'] ?? 'none';
            $logoPath = $cData['logopath'] ?? '';
            $logoHtml = $cData['logohtml'] ?? '';
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= htmlspecialchars($companyNameDisplay) ?></title> 
    
    <?php if ($logoType === 'image' && !empty($logoPath)): ?>
        <link rel="icon" href="<?= BASE_URL . ltrim(htmlspecialchars($logoPath), '/') ?>" type="image/png">
    <?php else: ?>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 38 38'><circle cx='19' cy='19' r='18' fill='%23ffffff' stroke='%231e3a8a' stroke-width='2'/><g transform='translate(19, 19) scale(0.32) translate(-38, -36)'><rect x='20' y='6' width='27' height='58' fill='%231e3a8a'/><rect x='3' y='23' width='17' height='22' fill='%231e3a8a'/><rect x='29' y='6' width='18' height='54' fill='%23ffffff'/><rect x='39' y='6' width='11' height='50' fill='%23ffffff'/><rect x='39' y='23' width='26' height='22' fill='%23ffffff'/></g></svg>" type="image/svg+xml">
    <?php endif; ?>

    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&family=Inter:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="pg-auth-login">

    <div class="login-wrapper">
        <div class="login-card">
            <div class="brand-section">
                <?php if ($logoType === 'image' && !empty($logoPath)): ?>
                    <div style="height: 50px; margin-bottom: 14px; display:flex; align-items:center; justify-content:center;">
                        <img src="<?= BASE_URL . ltrim(htmlspecialchars($logoPath), '/') ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    </div>
                <?php elseif ($logoType === 'html' && !empty($logoHtml)): ?>
                    <div style="margin-bottom: 14px; display:flex; align-items:center; justify-content:center;">
                        <?= $logoHtml ?>
                    </div>
                <?php else: ?>
                    <div class="circle">
                        <div class="cross-wrapper">
                            <div class="t-blue"></div><div class="divider"></div><div class="t-navy"></div>
                        </div>
                        <div class="whitediv"></div>
                    </div>
                <?php endif; ?>

                <div class="brand-title">
                    <?= htmlspecialchars($companyNameDisplay) ?>
                </div>
                <div class="system-subtitle">ERP Platform Login</div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Enter your username" required autocomplete="username" autofocus>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fa-regular fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="options-row" style="justify-content: flex-end;">
                    <a href="forgotpassword.php" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" name="login" class="btn-submit">Sign In</button>
            </form>
        </div>
        
        <div class="footer-text">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($companyNameDisplay) ?>. All rights reserved.
        </div>
    </div>

    <script>
        function togglePassword() {
            const password = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            
            if (password.type === 'password') {
                password.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                password.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>