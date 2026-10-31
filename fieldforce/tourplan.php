<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
// [FIX APPLIED]: Missing quote fixed below
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
        $pdo->prepare("DELETE FROM tour_plans WHERE id=?")->execute([$id]);
        $_SESSION['msg'] = "Tour Plan deleted successfully!";
        header("Location: tourplan.php"); exit();
    }
    
    $tourDate = trim($_POST['tour_date']);
    $workType = trim($_POST['work_type']);
    $station = trim($_POST['station']);
    $remarks = trim($_POST['remarks']);
    $assignedEmp = trim($_POST['assigned_emp'] ?? $empCode);
    $planId = (int)($_POST['plan_id'] ?? 0);

    if (!$isAdmin && !in_array($assignedEmp, $teamCodes)) {
        die("Security Violation: Unauthorized scope.");
    }

    // Default status is 'Pending' for MRs, Auto-Approved for Admin/Manager
    $status = ($isAdmin || $isManager) ? 'Approved' : 'Pending';

    if ($planId > 0) {
        $pdo->prepare("UPDATE tour_plans SET tour_date=?, work_type=?, station=?, remarks=?, status=? WHERE id=?")->execute([$tourDate, $workType, $station, $remarks, $status, $planId]);
        $_SESSION['msg'] = "Tour Plan updated!";
    } else {
        $pdo->prepare("INSERT INTO tour_plans (empcode, tour_date, work_type, station, remarks, status) VALUES (?, ?, ?, ?, ?, ?)")->execute([$assignedEmp, $tourDate, $workType, $station, $remarks, $status]);
        $_SESSION['msg'] = "Tour Plan submitted for approval!";
    }
    header("Location: tourplan.php"); exit();
}

$pageTitle = "Monthly Tour Plan (STP)";
include __DIR__ . '/../includes/header.php';
?>


<?php if(isset($_SESSION['msg'])): ?>
    <div class="msg-box msg-success"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
<?php endif; ?>

<?php if($canEntry): ?>
<div class="panel-box">
    <div class="panel-title" id="formHeader">1. Configure Tour Plan (STP)</div>
    <form method="POST" id="tpForm">
        <input type="hidden" name="plan_id" id="plan_id" value="0">
        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 1.5fr auto;">
            <div class="form-group">
                <label>Tour Date</label>
                <input type="date" name="tour_date" id="tour_date" required>
            </div>
            <div class="form-group">
                <label>Work Type</label>
                <select name="work_type" id="work_type" required>
                    <option value="Field Work">Field Work</option>
                    <option value="Meeting">Meeting</option>
                    <option value="Leave">Leave</option>
                    <option value="Transit">Transit</option>
                </select>
            </div>
            <div class="form-group">
                <label>Station / Area</label>
                <input type="text" name="station" id="station" placeholder="e.g. Patna Local" required>
            </div>
            <div class="form-group">
                <label>Remarks (Optional)</label>
                <input type="text" name="remarks" id="remarks" placeholder="Any additional notes...">
            </div>
            
            <?php if($isAdmin || $isManager): ?>
                <div class="form-group" style="display:none;" id="empGroup">
                    <label>Assign To (Emp Code)</label>
                    <input type="text" name="assigned_emp" id="assigned_emp" value="<?= $empCode ?>" required>
                </div>
            <?php else: ?>
                <input type="hidden" name="assigned_emp" value="<?= $empCode ?>">
            <?php endif; ?>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Plan</button>
                <button type="button" class="btn btn-secondary" id="btnCancel" onclick="resetForm()" style="display:none;">Cancel</button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel-box" style="padding-bottom: 8px;">
    <div class="panel-title" style="margin-bottom:0; border-bottom:none;">2. Tour Plan Directory</div>
    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Work Type</th>
                    <th>Station/Area</th>
                    <th style="text-align:center;">Status</th>
                    <?php if($canEntry): ?><th style="text-align:right;">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($isAdmin) {
                    $plans = $pdo->query("SELECT t.*, e.empname FROM tour_plans t LEFT JOIN employees e ON t.empcode = e.empcode ORDER BY t.tour_date DESC")->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $pdo->prepare("SELECT t.*, e.empname FROM tour_plans t LEFT JOIN employees e ON t.empcode = e.empcode WHERE t.empcode IN ($teamPh) ORDER BY t.tour_date DESC");
                    $stmt->execute($teamCodes);
                    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                if (empty($plans)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No tour plans found.</td></tr>
                <?php else:
                foreach($plans as $plan): 
                    $statusColor = ($plan['status'] === 'Approved') ? '#34d399' : (($plan['status'] === 'Rejected') ? '#f87171' : '#fbbf24');
                ?>
                <tr>
                    <td style="color:#bfdbfe; font-weight:bold;"><?= date('d-M-Y', strtotime($plan['tour_date'])) ?></td>
                    <td style="color:#ffffff; font-weight:600;"><?= htmlspecialchars($plan['empname'] ?? $plan['empcode']) ?></td>
                    <td style="color:#cbd5e1;"><?= htmlspecialchars($plan['work_type']) ?></td>
                    <td style="color:#e2e8f0;"><?= htmlspecialchars($plan['station']) ?></td>
                    <td style="text-align:center;">
                        <span style="color:<?= $statusColor ?>; font-weight:800; font-size:10px; text-transform:uppercase;"><?= $plan['status'] ?></span>
                    </td>
                    <?php if($canEntry): ?>
                    <td style="text-align:right;">
                        <div style="display:flex; gap:4px; justify-content:flex-end;">
                            <?php if($plan['status'] === 'Pending' || $isAdmin || $isManager): ?>
                                <button onclick='editPlan(<?= json_encode($plan) ?>)' class="btn btn-secondary" style="height:22px; padding:0 8px; font-size:9px;">Edit</button>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this Tour Plan?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $plan['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="height:22px; padding:0 8px; font-size:9px;">Del</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function editPlan(plan) {
    document.getElementById('formHeader').innerText = '1. Update Tour Plan';
    document.getElementById('plan_id').value = plan.id;
    document.getElementById('tour_date').value = plan.tour_date;
    document.getElementById('work_type').value = plan.work_type;
    document.getElementById('station').value = plan.station;
    document.getElementById('remarks').value = plan.remarks;
    
    if(document.getElementById('assigned_emp')) {
        document.getElementById('empGroup').style.display = 'flex';
        document.getElementById('assigned_emp').value = plan.empcode;
    }
    
    document.getElementById('btnSubmit').innerText = 'Update Plan';
    document.getElementById('btnSubmit').style.background = '#059669';
    document.getElementById('btnCancel').style.display = 'inline-flex';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function resetForm() {
    document.getElementById('tpForm').reset();
    document.getElementById('formHeader').innerText = '1. Configure Tour Plan (STP)';
    document.getElementById('plan_id').value = '0';
    
    if(document.getElementById('assigned_emp')) {
        document.getElementById('empGroup').style.display = 'none';
        document.getElementById('assigned_emp').value = "<?= $empCode ?>";
    }

    document.getElementById('btnSubmit').innerText = 'Save Plan';
    document.getElementById('btnSubmit').style.background = '';
    document.getElementById('btnCancel').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>