<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once('includes/core.php');

// --- User Context ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.empcode 
    FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL 
    WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
$empCode  = $userMeta['empcode'] ?? $userMeta['username'] ?? '';

// Mark as read
if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_on = NOW() WHERE id = ? AND empcode = ?")
        ->execute([(int)$_GET['read'], $empCode]);
    header("Location: notifications.php"); exit();
}

// Mark all read
if (isset($_GET['readall'])) {
    $pdo->prepare("UPDATE user_notifications SET is_read = 1, read_on = NOW() WHERE empcode = ? AND is_read = 0")
        ->execute([$empCode]);
    header("Location: notifications.php"); exit();
}

// Fetch notifications
$stmt = $pdo->prepare("SELECT * FROM user_notifications WHERE empcode = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$empCode]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$unreadCount = 0;
foreach ($notifications as $n) if (!$n['is_read']) $unreadCount++;

$pageTitle = "Notifications";
require_once 'header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-bell"></i> Notifications 
            <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger"><?= $unreadCount ?></span>
            <?php endif; ?>
        </h1>
        <p class="text-muted mb-0">Approvals, rejections and system alerts</p>
    </div>
    <?php if ($unreadCount > 0): ?>
        <a href="?readall=1" class="btn btn-outline-primary btn-sm">Mark All as Read</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="list-group list-group-flush">
        <?php if (empty($notifications)): ?>
            <div class="list-group-item text-center text-muted py-5">
                <i class="fas fa-bell-slash fa-2x mb-2"></i><br>No notifications yet.
            </div>
        <?php else: foreach ($notifications as $n): ?>
            <div class="list-group-item list-group-item-action <?= $n['is_read'] ? '' : 'list-group-item-primary' ?>">
                <div class="d-flex w-100 justify-content-between">
                    <h6 class="mb-1">
                        <?php if (!$n['is_read']): ?><span class="badge bg-primary me-1">New</span><?php endif; ?>
                        <?= htmlspecialchars($n['title']) ?>
                    </h6>
                    <small class="text-muted"><?= date('d-M-Y H:i', strtotime($n['created_at'])) ?></small>
                </div>
                <p class="mb-1"><?= nl2br(htmlspecialchars($n['message'])) ?></p>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">
                        <?= $n['related_module'] ? 'Module: ' . htmlspecialchars($n['related_module']) : '' ?>
                    </small>
                    <?php if (!$n['is_read']): ?>
                        <a href="?read=<?= $n['id'] ?>" class="btn btn-sm btn-outline-secondary">Mark Read</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
