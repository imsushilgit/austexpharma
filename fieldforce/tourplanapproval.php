<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context & Permissions ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.designation, e.department, e.empcode FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$isAdmin = ($userMeta['role'] ?? '') === 'Admin';
$empCode = $userMeta['empcode'] ?? $userMeta['username'] ?? '';
$designation = $userMeta['designation'] ?? 'MR';
$department = strtoupper(trim($userMeta['department'] ?? 'SALES'));

$isSales = ($department === 'SALES' || $department === 'MARKETING' || empty($department));
$isManager = ($isSales && !$isAdmin && strtoupper(trim($designation)) !== 'MR');

// ONLY ADMIN OR MANAGER CAN ACCESS APPROVALS
if (!$isAdmin && !$isManager) {
    die("<div style='padding:20px; color:#dc2626; font-weight:bold; text-align:center;'>Access Denied: You do not have manager approval rights.</div>");
}

// --- Fetch Team Codes for Managers ---
$teamCodes = [$empCode];
if ($isManager) {
    // ROLE SCOPE: full reporting line (code or name based), from core.php
    $teamCodes = scope_codes($ctx);
    if (empty($teamCodes)) $teamCodes = [$empCode];
}
$teamPh = implode(',', array_fill(0, count($teamCodes), '?'));

// --- Handle Approve / Reject Action ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)$_POST['id'];
    
    if ($id > 0 && in_array($action, ['Approve', 'Reject'])) {
        // Security check: Only update if it belongs to their team (if manager)
        if (!$isAdmin) {
            $chk = $pdo->prepare("SELECT empcode FROM tour_plans WHERE id=?"); $chk->execute([$id]);
            $owner = $chk->fetchColumn();
            if (!in_array($owner, $teamCodes)) { die("Security Violation!"); }
        }
        $newStatus = ($action === 'Approve') ? 'Approved' : 'Rejected';
        $pdo->prepare("UPDATE tour_plans SET status=? WHERE id=?")->execute([$newStatus, $id]);
        $_SESSION['msg'] = "Tour Plan has been $newStatus successfully!";
    }
    header("Location: tourplanapproval.php"); exit();
}

$pageTitle = "Pending Tour Plan Approvals";
include __DIR__ . '/../includes/header.php';
?>
<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px; align-items:center;">
        <h2 style="font-family:'Sora', sans-serif; color:#d97706; margin:0;">📅 Tour Plan Approvals</h2>
        <a href="../dashboard/dashboard.php" style="background:#f1f5f9; color:#475569; padding:8px 16px; border-radius:5px; text-decoration:none; font-weight:bold; font-size:12px;">← Back to Dashboard</a>
    </div>

    <?php if(isset($_SESSION['msg'])): ?>
        <div style="background:#ecfdf5; color:#065f46; padding:10px; border-radius:5px; margin-bottom:15px; border:1px solid #a7f3d0;"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
    <?php endif; ?>

    <table style="width:100%; border-collapse:collapse; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <thead style="background:#fffbeb; border-bottom:2px solid #fde68a; font-size:12px; color:#92400e;">
            <tr>
                <th style="padding:12px; text-align:left;">Tour Date</th>
                <th style="padding:12px; text-align:left;">Employee Name</th>
                <th style="padding:12px; text-align:left;">Work Type</th>
                <th style="padding:12px; text-align:left;">Station</th>
                <th style="padding:12px; text-align:left;">Remarks</th>
                <th style="padding:12px; text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody style="font-size:13px; color:#334155;">
            <?php
            if ($isAdmin) {
                $plans = $pdo->query("SELECT t.*, e.empname FROM tour_plans t LEFT JOIN employees e ON t.empcode = e.empcode WHERE t.status = 'Pending' ORDER BY t.tour_date ASC")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("SELECT t.*, e.empname FROM tour_plans t LEFT JOIN employees e ON t.empcode = e.empcode WHERE t.status = 'Pending' AND t.empcode IN ($teamPh) ORDER BY t.tour_date ASC");
                $stmt->execute($teamCodes); $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($plans)): ?>
                <tr><td colspan="6" style="padding:20px; text-align:center; color:#94a3b8; font-style:italic;">No pending tour plans for approval. 🎉</td></tr>
            <?php else:
                foreach($plans as $plan): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px; font-weight:bold;"><?= date('d-M-Y', strtotime($plan['tour_date'])) ?></td>
                    <td style="padding:12px; color:#1e2a78; font-weight:600;"><?= htmlspecialchars($plan['empname'] ?? $plan['empcode']) ?></td>
                    <td style="padding:12px;"><span style="background:#f1f5f9; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:bold;"><?= htmlspecialchars($plan['work_type']) ?></span></td>
                    <td style="padding:12px;"><?= htmlspecialchars($plan['station']) ?></td>
                    <td style="padding:12px; color:#64748b; font-size:11px;"><?= htmlspecialchars($plan['remarks']) ?></td>
                    <td style="padding:12px; text-align:center;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="id" value="<?= $plan['id'] ?>">
                            <button type="submit" name="action" value="Approve" style="background:#059669; color:#fff; border:none; padding:5px 12px; border-radius:4px; cursor:pointer; font-weight:bold; font-size:11px; margin-right:5px;">Approve</button>
                            <button type="submit" name="action" value="Reject" style="background:#dc2626; color:#fff; border:none; padding:5px 12px; border-radius:4px; cursor:pointer; font-weight:bold; font-size:11px;" onclick="return confirm('Are you sure you want to reject this plan?');">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>