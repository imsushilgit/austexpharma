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

$canEntry = ($isAdmin || $isManager || $isMR);

// --- Fetch Team Codes for Managers ---
$teamCodes = [$empCode];
if ($isManager) {
    // ROLE SCOPE: full reporting line (code or name based), from core.php
    $teamCodes = scope_codes($ctx);
    if (empty($teamCodes)) $teamCodes = [$empCode];
}
$teamPh = implode(',', array_fill(0, count($teamCodes), '?'));

// --- Handle Add / Edit / Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEntry) {
    $todayStr = date('Y-m-d');
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        // Check date constraint before deleting
        if (!$isAdmin) {
            $check = $pdo->prepare("SELECT visit_date FROM visit_reports WHERE id=?"); $check->execute([$id]);
            $vd = $check->fetchColumn();
            if ($vd !== $todayStr) die("Strict Policy: You can only delete entries for today's date.");
        }
        $pdo->prepare("DELETE FROM visit_reports WHERE id=?")->execute([$id]);
        $_SESSION['msg'] = "Visit Report deleted."; header("Location: dailyvisit.php"); exit();
    }
    
    $visitDate = trim($_POST['visit_date']);
    $partyType = trim($_POST['party_type']);
    $partyName = trim($_POST['party_name']);
    $remarks = trim($_POST['remarks']);
    $assignedEmp = trim($_POST['assigned_emp'] ?? $empCode);
    $visitId = (int)($_POST['visit_id'] ?? 0);

    if (!$isAdmin && !in_array($assignedEmp, $teamCodes)) { die("Security Violation: Unauthorized scope."); }
    
    // Strict Same-Day Policy
    if (!$isAdmin && $visitDate !== $todayStr) {
        die("Strict Company Policy: Visit reports (DCR) must be entered on the same day (".$todayStr."). Backdating is restricted.");
    }

    if ($visitId > 0) {
        $pdo->prepare("UPDATE visit_reports SET visit_date=?, party_type=?, party_name=?, remarks=?, empcode=? WHERE id=?")->execute([$visitDate, $partyType, $partyName, $remarks, $assignedEmp, $visitId]);
        $_SESSION['msg'] = "Visit Report updated!";
    } else {
        $pdo->prepare("INSERT INTO visit_reports (empcode, visit_date, party_type, party_name, remarks) VALUES (?, ?, ?, ?, ?)")->execute([$assignedEmp, $visitDate, $partyType, $partyName, $remarks]);
        $_SESSION['msg'] = "Visit Report recorded!";
    }
    header("Location: dailyvisit.php"); exit();
}

$pageTitle = "Daily Visit Report (DCR)";
include __DIR__ . '/../includes/header.php';
?>
<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px; align-items:center;">
        <h2 style="font-family:'Sora', sans-serif; color:#1e2a78; margin:0;">📝 Daily Visit Report</h2>
        <?php if($canEntry): ?>
            <button onclick="document.getElementById('visitModal').style.display='block'" style="background:#059669; color:#fff; border:none; padding:8px 16px; border-radius:5px; font-weight:bold; cursor:pointer;">+ Log Today's Visit</button>
        <?php endif; ?>
    </div>
    <?php if(isset($_SESSION['msg'])): ?>
        <div style="background:#d1fae5; color:#065f46; padding:10px; border-radius:5px; margin-bottom:15px; border:1px solid #a7f3d0;"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <div style="background:#fef2f2; color:#991b1b; padding:10px; font-size:11px; margin-bottom:15px; border:1px solid #fecaca; border-radius:4px;"><b>Note:</b> According to company policy, you can only enter, edit or delete records for today's date (<?= date('d-M-Y') ?>).</div>
    
    <table style="width:100%; border-collapse:collapse; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <thead style="background:#f8fafc; border-bottom:2px solid #e2e8f0; font-size:12px; color:#64748b;">
            <tr><th style="padding:12px; text-align:left;">Date</th><th style="padding:12px; text-align:left;">Employee</th><th style="padding:12px; text-align:left;">Visit Type</th><th style="padding:12px; text-align:left;">Party Name (Doctor/Chemist)</th><th style="padding:12px; text-align:left;">Remarks</th><?php if($canEntry): ?><th style="padding:12px; text-align:right;">Actions</th><?php endif; ?></tr>
        </thead>
        <tbody style="font-size:13px; color:#334155;">
            <?php
            if ($isAdmin) {
                $visits = $pdo->query("SELECT v.*, e.empname FROM visit_reports v LEFT JOIN employees e ON v.empcode = e.empcode ORDER BY v.visit_date DESC, v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("SELECT v.*, e.empname FROM visit_reports v LEFT JOIN employees e ON v.empcode = e.empcode WHERE v.empcode IN ($teamPh) ORDER BY v.visit_date DESC, v.id DESC");
                $stmt->execute($teamCodes); $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $todayCheck = date('Y-m-d');
            foreach($visits as $v): 
                $canModify = ($isAdmin || $v['visit_date'] === $todayCheck);
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:12px; font-weight:bold;"><?= date('d-M-Y', strtotime($v['visit_date'])) ?></td>
                <td style="padding:12px; color:#1e2a78;"><?= htmlspecialchars($v['empname'] ?? $v['empcode']) ?></td>
                <td style="padding:12px;"><span style="background:#f1f5f9; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:bold;"><?= htmlspecialchars($v['party_type']) ?></span></td>
                <td style="padding:12px;"><?= htmlspecialchars($v['party_name']) ?></td>
                <td style="padding:12px;"><?= htmlspecialchars($v['remarks']) ?></td>
                <?php if($canEntry): ?>
                <td style="padding:12px; text-align:right;">
                    <?php if($canModify): ?>
                        <button onclick='editVisit(<?= json_encode($v) ?>)' style="background:#eef2ff; color:#4f46e5; border:1px solid #c7d2fe; padding:4px 8px; border-radius:4px; cursor:pointer;">Edit</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button type="submit" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:4px 8px; border-radius:4px; cursor:pointer;">Del</button></form>
                    <?php else: ?>
                        <span style="font-size:10px; color:#94a3b8;">Locked</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if($canEntry): ?>
<div id="visitModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background:#fff; width:400px; padding:25px; border-radius:8px; margin: 50px auto;">
        <h3 id="modalTitle" style="margin-top:0; color:#1e2a78; font-family:'Sora', sans-serif;">Log Visit Details</h3>
        <form method="POST">
            <input type="hidden" name="visit_id" id="visit_id" value="0">
            <div style="margin-bottom:12px;">
                <label style="font-size:11px; font-weight:bold; color:#64748b;">Visit Date</label>
                <input type="date" name="visit_date" id="visit_date" value="<?= date('Y-m-d') ?>" <?= $isAdmin ? '' : 'readonly' ?> style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px; background:#f8fafc;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:11px; font-weight:bold; color:#64748b;">Type of Party</label>
                <select name="party_type" id="party_type" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
                    <option value="Doctor">Doctor</option><option value="Chemist">Chemist / Pharmacy</option><option value="Stockist">Stockist / Distributor</option>
                </select>
            </div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Name of Party</label><input type="text" name="party_name" id="party_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Discussion / Remarks</label><input type="text" name="remarks" id="remarks" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <?php if($isAdmin || $isManager): ?><div style="margin-bottom:15px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Log for Team Member (EmpCode)</label><input type="text" name="assigned_emp" id="assigned_emp" value="<?= $empCode ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div><?php else: ?><input type="hidden" name="assigned_emp" value="<?= $empCode ?>"><?php endif; ?>
            <div style="display:flex; justify-content:flex-end; gap:10px;"><button type="button" onclick="document.getElementById('visitModal').style.display='none'" style="padding:8px 15px; border:1px solid #cbd5e1; background:#f8fafc; cursor:pointer; border-radius:4px;">Cancel</button><button type="submit" style="padding:8px 15px; background:#1e2a78; color:#fff; border:none; cursor:pointer; border-radius:4px; font-weight:bold;">Save Log</button></div>
        </form>
    </div>
</div>
<script>
function editVisit(v) { document.getElementById('modalTitle').innerText='Edit Visit'; document.getElementById('visit_id').value=v.id; document.getElementById('visit_date').value=v.visit_date; document.getElementById('party_type').value=v.party_type; document.getElementById('party_name').value=v.party_name; document.getElementById('remarks').value=v.remarks; if(document.getElementById('assigned_emp')){document.getElementById('assigned_emp').value=v.empcode;} document.getElementById('visitModal').style.display='block'; }
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>