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

// --- Handle Add / Edit / Delete (Backend) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEntry) {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
        $_SESSION['msg'] = "Expense record deleted successfully!";
        header("Location: expense.php"); exit();
    }
    
    $expenseDate = trim($_POST['expense_date']);
    $expenseType = trim($_POST['expense_type']);
    $amount = (float)($_POST['amount'] ?? 0);
    $remarks = trim($_POST['remarks']);
    $assignedEmp = trim($_POST['assigned_emp'] ?? $empCode);
    $expId = (int)($_POST['exp_id'] ?? 0);

    if (!$isAdmin && !in_array($assignedEmp, $teamCodes)) {
        die("Security Violation: Unauthorized scope.");
    }

    $status = ($isAdmin || $isManager) ? 'Approved' : 'Pending';

    if ($expId > 0) {
        $pdo->prepare("UPDATE expenses SET expense_date=?, expense_type=?, amount=?, remarks=?, status=? WHERE id=?")->execute([$expenseDate, $expenseType, $amount, $remarks, $status, $expId]);
        $_SESSION['msg'] = "Expense updated!";
    } else {
        $pdo->prepare("INSERT INTO expenses (empcode, expense_date, expense_type, amount, remarks, status) VALUES (?, ?, ?, ?, ?, ?)")->execute([$assignedEmp, $expenseDate, $expenseType, $amount, $remarks, $status]);
        $_SESSION['msg'] = "Expense submitted for approval!";
    }
    header("Location: expense.php"); exit();
}

$pageTitle = "Expense Claims";
include __DIR__ . '/../includes/header.php';
?>
<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px; align-items:center;">
        <h2 style="font-family:'Sora', sans-serif; color:#1e2a78; margin:0;">💳 Expense Claims (TA/DA)</h2>
        <?php if($canEntry): ?>
            <button onclick="document.getElementById('expModal').style.display='block'" style="background:#1d4ed8; color:#fff; border:none; padding:8px 16px; border-radius:5px; font-weight:bold; cursor:pointer;">+ Submit Expense</button>
        <?php endif; ?>
    </div>
    <?php if(isset($_SESSION['msg'])): ?>
        <div style="background:#eff6ff; color:#1e40af; padding:10px; border-radius:5px; margin-bottom:15px; border:1px solid #bfdbfe;"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <table style="width:100%; border-collapse:collapse; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <thead style="background:#f8fafc; border-bottom:2px solid #e2e8f0; font-size:12px; color:#64748b;">
            <tr>
                <th style="padding:12px; text-align:left;">Date</th>
                <th style="padding:12px; text-align:left;">Employee</th>
                <th style="padding:12px; text-align:left;">Expense Type</th>
                <th style="padding:12px; text-align:right;">Amount (₹)</th>
                <th style="padding:12px; text-align:center;">Status</th>
                <?php if($canEntry): ?><th style="padding:12px; text-align:right;">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody style="font-size:13px; color:#334155;">
            <?php
            if ($isAdmin) {
                $exps = $pdo->query("SELECT x.*, e.empname FROM expenses x LEFT JOIN employees e ON x.empcode = e.empcode ORDER BY x.expense_date DESC")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("SELECT x.*, e.empname FROM expenses x LEFT JOIN employees e ON x.empcode = e.empcode WHERE x.empcode IN ($teamPh) ORDER BY x.expense_date DESC");
                $stmt->execute($teamCodes);
                $exps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach($exps as $ex): 
                $statusColor = ($ex['status'] === 'Approved') ? '#059669' : (($ex['status'] === 'Rejected') ? '#dc2626' : '#d97706');
                $statusBg = ($ex['status'] === 'Approved') ? '#ecfdf5' : (($ex['status'] === 'Rejected') ? '#fef2f2' : '#fffbeb');
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:12px; font-weight:bold;"><?= date('d-M-Y', strtotime($ex['expense_date'])) ?></td>
                <td style="padding:12px; color:#1e2a78;"><?= htmlspecialchars($ex['empname'] ?? $ex['empcode']) ?></td>
                <td style="padding:12px;"><?= htmlspecialchars($ex['expense_type']) ?></td>
                <td style="padding:12px; text-align:right; font-weight:bold; color:#059669;"><?= number_format($ex['amount'], 2) ?></td>
                <td style="padding:12px; text-align:center;"><span style="background:<?= $statusBg ?>; color:<?= $statusColor ?>; padding:4px 10px; border-radius:20px; font-size:10px; font-weight:bold;"><?= $ex['status'] ?></span></td>
                <?php if($canEntry): ?>
                <td style="padding:12px; text-align:right;">
                    <?php if($ex['status'] === 'Pending' || $isAdmin || $isManager): ?>
                        <button onclick='editExp(<?= json_encode($ex) ?>)' style="background:#eef2ff; color:#4f46e5; border:1px solid #c7d2fe; padding:4px 8px; border-radius:4px; cursor:pointer;">Edit</button>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this expense?');">
                        <input type="hidden" name="action" value="delete"> <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                        <button type="submit" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:4px 8px; border-radius:4px; cursor:pointer;">Delete</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if($canEntry): ?>
<div id="expModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background:#fff; width:400px; padding:25px; border-radius:8px; margin: 50px auto;">
        <h3 id="modalTitle" style="margin-top:0; color:#1e2a78; font-family:'Sora', sans-serif;">Submit Expense</h3>
        <form method="POST">
            <input type="hidden" name="exp_id" id="exp_id" value="0">
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Expense Date</label><input type="date" name="expense_date" id="expense_date" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="margin-bottom:12px;">
                <label style="font-size:11px; font-weight:bold; color:#64748b;">Expense Type</label>
                <select name="expense_type" id="expense_type" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
                    <option value="Travel (TA)">Travel (TA)</option>
                    <option value="Daily Allowance (DA)">Daily Allowance (DA)</option>
                    <option value="Hotel/Boarding">Hotel/Boarding</option>
                    <option value="Stationery/Misc">Stationery / Misc</option>
                </select>
            </div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Amount (₹)</label><input type="number" step="0.01" name="amount" id="amount" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Remarks (Details)</label><input type="text" name="remarks" id="remarks" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <?php if($isAdmin || $isManager): ?><div style="margin-bottom:15px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Employee Code</label><input type="text" name="assigned_emp" id="assigned_emp" value="<?= $empCode ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div><?php else: ?><input type="hidden" name="assigned_emp" value="<?= $empCode ?>"><?php endif; ?>
            <div style="display:flex; justify-content:flex-end; gap:10px;"><button type="button" onclick="document.getElementById('expModal').style.display='none'" style="padding:8px 15px; border:1px solid #cbd5e1; background:#f8fafc; cursor:pointer; border-radius:4px;">Cancel</button><button type="submit" style="padding:8px 15px; background:#1e2a78; color:#fff; border:none; cursor:pointer; border-radius:4px; font-weight:bold;">Submit</button></div>
        </form>
    </div>
</div>
<script>
function editExp(ex) { document.getElementById('modalTitle').innerText='Edit Expense'; document.getElementById('exp_id').value=ex.id; document.getElementById('expense_date').value=ex.expense_date; document.getElementById('expense_type').value=ex.expense_type; document.getElementById('amount').value=ex.amount; document.getElementById('remarks').value=ex.remarks; if(document.getElementById('assigned_emp')){document.getElementById('assigned_emp').value=ex.empcode;} document.getElementById('expModal').style.display='block'; }
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>