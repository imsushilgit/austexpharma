<?php
session_start();
require_once __DIR__ . '/../includes/core.php';

// Update device session on logout
if (isset($pdo) && isset($_SESSION['device_session_id'])) {
    try {
        $logoutLocation = "IP: " . $_SERVER['REMOTE_ADDR'];
        
        $stmt = $pdo->prepare("UPDATE device_sessions 
                               SET logout_time = NOW(), 
                                   logout_location = ?, 
                                   status = 'Completed' 
                               WHERE id = ?");
        $stmt->execute([$logoutLocation, $_SESSION['device_session_id']]);
    } catch (PDOException $e) {
        // Silent fail
    }
}

// Clear all session data
$_SESSION = [];

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Fetch company info for display
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
    <meta http-equiv="refresh" content="2;url=login.php">
    <title>Logging out | <?= htmlspecialchars($companyNameDisplay) ?></title>
    
    <?php if ($logoType === 'image' && !empty($logoPath)): ?>
        <link rel="icon" href="<?= BASE_URL . ltrim(htmlspecialchars($logoPath), '/') ?>" type="image/png">
    <?php else: ?>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 38 38'><circle cx='19' cy='19' r='18' fill='%23ffffff' stroke='%231e3a8a' stroke-width='2'/><g transform='translate(19, 19) scale(0.32) translate(-38, -36)'><rect x='20' y='6' width='27' height='58' fill='%231e3a8a'/><rect x='3' y='23' width='17' height='22' fill='%231e3a8a'/><rect x='29' y='6' width='18' height='54' fill='%23ffffff'/><rect x='39' y='6' width='11' height='50' fill='%23ffffff'/><rect x='39' y='23' width='26' height='22' fill='%23ffffff'/></g></svg>" type="image/svg+xml">
    <?php endif; ?>

    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="pg-auth-logout">

    <div class="logout-card">
        <div class="spinner-box">
            <div class="spinner"></div>
            <i class="fa-solid fa-lock lock-icon"></i>
        </div>
        <h2>Logging Out</h2>
        <p>Securing your session and redirecting...</p>
    </div>

</body>
</html>