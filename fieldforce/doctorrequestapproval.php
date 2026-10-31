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
$isMR = ($isSales && !$isAdmin && strtoupper(trim($designation)) === 'MR');

$canApprove = ($isAdmin || $isManager);
$canViewOwn = $isMR;

if (!$canApprove && !$canViewOwn) {
    die("<div style='padding:20px; color:#dc2626; font-weight:bold; text-align:center;'>Access Denied: You do not have rights to view this page.</div>");
}

// --- Fetch Team Codes ---
$teamCodes = [$empCode];
if ($isManager) {
    // ROLE SCOPE: full reporting line (code or name based), from core.php
    $teamCodes = scope_codes($ctx);
    if (empty($teamCodes)) $teamCodes = [$empCode];
} elseif ($isMR) {
    $teamCodes = [$empCode];
}
$teamPh = implode(',', array_fill(0, count($teamCodes), '?'));

// --- Handle Approve / Reject Action (Strictly for Managers/Admins) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canApprove) {
    $action = $_POST['action'] ?? '';
    $id = (int)$_POST['id'];
    
    if ($id > 0 && in_array($action, ['Approve', 'Reject'])) {
        $stmt = $pdo->prepare("SELECT * FROM doctor_requests WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($req && $req['status'] === 'Pending') {
            if (!$isAdmin && !in_array($req['empcode'], $teamCodes)) { die("Security Violation!"); }

            if ($action === 'Approve') {
                if ($req['request_type'] === 'Add') {
                    $pdo->prepare("INSERT INTO doctors (empcode, doc_name, specialty, hq) VALUES (?, ?, ?, ?)")->execute([$req['empcode'], $req['doc_name'], $req['specialty'], $req['hq']]);
                } elseif ($req['request_type'] === 'Edit' && $req['doc_id']) {
                    $pdo->prepare("UPDATE doctors SET doc_name=?, specialty=?, hq=? WHERE id=?")->execute([$req['doc_name'], $req['specialty'], $req['hq'], $req['doc_id']]);
                } elseif ($req['request_type'] === 'Delete' && $req['doc_id']) {
                    $pdo->prepare("DELETE FROM doctors WHERE id=?")->execute([$req['doc_id']]);
                }
                $pdo->prepare("UPDATE doctor_requests SET status='Approved' WHERE id=?")->execute([$id]);
                $_SESSION['msg'] = "Doctor request approved and master list updated successfully!";
            } else {
                $pdo->prepare("UPDATE doctor_requests SET status='Rejected' WHERE id=?")->execute([$id]);
                $_SESSION['msg'] = "Doctor request has been rejected.";
            }
        }
    }
    $redirectUrl = "doctorrequestapproval.php";
    if (!empty($_GET)) { $redirectUrl .= '?' . http_build_query($_GET); }
    header("Location: " . $redirectUrl); exit();
}

// --- Filters Setup ---
$filterStatus = $_GET['status'] ?? 'Pending';
$filterType = $_GET['type'] ?? 'All';

$whereParams = [];
$whereClause = "1=1";

if ($filterStatus !== 'All') {
    $whereClause .= " AND r.status = ?";
    $whereParams[] = $filterStatus;
}
if ($filterType !== 'All') {
    $whereClause .= " AND r.request_type = ?";
    $whereParams[] = $filterType;
}
if (!$isAdmin) {
    $whereClause .= " AND r.empcode IN ($teamPh)";
    $whereParams = array_merge($whereParams, $teamCodes);
}

$sql = "SELECT r.*, e.empname FROM doctor_requests r LEFT JOIN employees e ON r.empcode = e.empcode WHERE $whereClause ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($whereParams);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = $canApprove ? "Doctor Requests & Approvals" : "My Doctor Requests Status";
include __DIR__ . '/../includes/header.php';
?>
<div style="padding: 15px; font-family: Arial, sans-serif;">
    <!-- Compact Header Bar -->
    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; align-items: center; border-bottom: 1px dotted #1e2a78; padding-bottom: 8px;">
        <h2 style="color: #1e2a78; margin: 0; font-size: 15px; text-transform: Propercase;">
            <?= $canApprove ? '🩺 Doctor Requests Approvals' : '📋 My Request Status (Doctors)' ?>
        </h2>
        <a href="../index.php" style="background:#fff; color:#1e2a78; padding:5px 12px; border-radius:3px; text-decoration:none; font-weight:bold; font-size:11px; border: 1px dotted #1e2a78;">← Dashboard</a>
    </div>

    <!-- Compact Filters Section -->
    <div style="background: #fff; padding: 10px 15px; margin-bottom: 15px; border-radius: 3px; border: 1px dotted #1e2a78;">
        <form method="GET" style="display: flex; gap: 12px; align-items: flex-end;">
            <div>
                <label style="font-size:10px; font-weight:bold; color:#1e2a78; display:block; margin-bottom:3px; text-transform:uppercase;">Status</label>
                <select name="status" style="padding:4px 6px; border:1px dotted #1e2a78; border-radius:2px; outline:none; font-size:11px; color:#1e2a78; background:#fff;">
                    <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Rejected" <?= $filterStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="All" <?= $filterStatus === 'All' ? 'selected' : '' ?>>All Requests</option>
                </select>
            </div>
            <div>
                <label style="font-size:10px; font-weight:bold; color:#1e2a78; display:block; margin-bottom:3px; text-transform:uppercase;">Type</label>
                <select name="type" style="padding:4px 6px; border:1px dotted #1e2a78; border-radius:2px; outline:none; font-size:11px; color:#1e2a78; background:#fff;">
                    <option value="All" <?= $filterType === 'All' ? 'selected' : '' ?>>All Types</option>
                    <option value="Add" <?= $filterType === 'Add' ? 'selected' : '' ?>>Add New</option>
                    <option value="Edit" <?= $filterType === 'Edit' ? 'selected' : '' ?>>Update (Edit)</option>
                    <option value="Delete" <?= $filterType === 'Delete' ? 'selected' : '' ?>>Delete</option>
                </select>
            </div>
            <div>
                <button type="submit" style="background:#1e2a78; color:#fff; border:1px solid #1e2a78; padding:5px 12px; border-radius:2px; font-weight:bold; cursor:pointer; font-size:11px; text-transform:uppercase;">Apply</button>
            </div>
        </form>
    </div>

    <?php if(isset($_SESSION['msg'])): ?>
        <div style="background:#fff; color:#1e2a78; padding:8px 12px; border-radius:3px; margin-bottom:12px; border:1px dotted #1e2a78; font-size:11px; font-weight:bold;"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
    <?php endif; ?>

    <!-- Compact Table Design -->
    <table style="width:100%; border-collapse:collapse; background:#fff; border: 1px solid #1e2a78;">
        <thead style="background:#1e2a78; color:#fff; font-size:11px; text-transform:uppercase;">
            <tr>
                <th style="padding:8px 10px; text-align:left; border: 1px solid #1e2a78;">Date</th>
                <th style="padding:8px 10px; text-align:left; border: 1px solid #1e2a78;">Requested By</th>
                <th style="padding:8px 10px; text-align:center; border: 1px solid #1e2a78;">Type</th>
                <th style="padding:8px 10px; text-align:center; border: 1px solid #1e2a78;">Status</th>
                <th style="padding:8px 10px; text-align:left; border: 1px solid #1e2a78;">Doctor Info</th>
                <th style="padding:8px 10px; text-align:center; border: 1px solid #1e2a78;"><?= $canApprove ? 'Action' : 'Details' ?></th>
            </tr>
        </thead>
        <tbody style="font-size:11px; color:#000;">
            <?php if (empty($requests)): ?>
                <tr><td colspan="6" style="padding:15px; text-align:center; color:#64748b; font-style:italic; border: 1px dotted #cbd5e1;">No requests found matching your filters.</td></tr>
            <?php else:
                foreach($requests as $req): 
                    $typeText = ($req['request_type'] === 'Edit') ? 'UPDATE' : strtoupper($req['request_type']);
            ?>
                <tr style="border-bottom:1px dotted #cbd5e1;">
                    <td style="padding:8px 10px; font-weight:bold; color:#1e2a78; border-right: 1px dotted #cbd5e1;"><?= date('d-M-Y H:i', strtotime($req['created_at'])) ?></td>
                    <td style="padding:8px 10px; color:#000; font-weight:600; border-right: 1px dotted #cbd5e1;"><?= htmlspecialchars($req['empname'] ?? $req['empcode']) ?></td>
                    <td style="padding:8px 10px; text-align:center; border-right: 1px dotted #cbd5e1;">
                        <span style="color:#1e2a78; font-weight:bold; border: 1px dotted #1e2a78; padding:2px 6px; border-radius:2px; font-size:10px;">
                            <?= $typeText ?>
                        </span>
                    </td>
                    <td style="padding:8px 10px; text-align:center; border-right: 1px dotted #cbd5e1;">
                        <span style="color:<?= $req['status'] === 'Approved' ? '#059669' : ($req['status'] === 'Rejected' ? '#dc2626' : '#1e2a78') ?>; font-weight:bold;">
                            <?= strtoupper($req['status']) ?>
                        </span>
                    </td>
                    <td style="padding:8px 10px; color:#000; border-right: 1px dotted #cbd5e1;">
                        <span style="font-weight:bold; color:#1e2a78;"><?= htmlspecialchars($req['doc_name']) ?></span><br>
                        <span style="color:#475569; font-size:10px;"><?= htmlspecialchars($req['specialty']) ?> | HQ: <?= htmlspecialchars($req['hq']) ?></span>
                    </td>
                    <td style="padding:8px 10px; text-align:center;">
                        <?php if ($req['status'] === 'Pending'): ?>
                            <?php if ($canApprove): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $req['id'] ?>">
                                    <button type="submit" name="action" value="Approve" style="background:#fff; color:#059669; border:1px solid #059669; padding:3px 8px; border-radius:2px; cursor:pointer; font-weight:bold; font-size:10px; margin-right:3px;">Approve</button>
                                    <button type="submit" name="action" value="Reject" style="background:#fff; color:#dc2626; border:1px solid #dc2626; padding:3px 8px; border-radius:2px; cursor:pointer; font-weight:bold; font-size:10px;" onclick="return confirm('Reject this doctor request?');">Reject</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:10px; color:#1e2a78; font-style:italic;">Awaiting Manager</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:10px; color:#64748b; font-style:italic;">Processed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>