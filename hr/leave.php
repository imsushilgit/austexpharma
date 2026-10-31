<?php
require_once __DIR__ . '/../includes/core.php';

// Role-based access control
$ctx = require_page_access($pdo);
$canWrite  = user_can_write($ctx);
$isAdmin   = $ctx['is_admin'];
$isMR      = $ctx['is_mr'];
$isManager = $ctx['is_manager'];
$teamCodes = $ctx['team_codes'];
$empCode   = $ctx['empcode'];
$empName   = $ctx['empname'];

// ==========================================
// AUTO-CREATE TABLES
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS leave_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leave_code VARCHAR(20) NOT NULL UNIQUE,
    leave_name VARCHAR(100) NOT NULL,
    max_days_per_year INT DEFAULT 0,
    is_paid TINYINT(1) DEFAULT 1,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Seed default leave types if empty
$count = $pdo->query("SELECT COUNT(*) FROM leave_types")->fetchColumn();
if ($count == 0) {
    $pdo->exec("INSERT INTO leave_types (leave_code, leave_name, max_days_per_year, is_paid, description) VALUES
        ('CL', 'Casual Leave', 12, 1, 'Casual leave for personal work'),
        ('SL', 'Sick Leave', 10, 1, 'Medical / health related leave'),
        ('EL', 'Earned Leave', 15, 1, 'Earned / Privilege leave'),
        ('LWP', 'Leave Without Pay', 30, 0, 'Unpaid leave'),
        ('ML', 'Maternity Leave', 90, 1, 'Maternity leave for female employees')
    ");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS leave_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    empname VARCHAR(150) NOT NULL,
    leave_type VARCHAR(20) NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    days DECIMAL(4,1) NOT NULL,
    reason TEXT,
    status ENUM('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending',
    applied_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by VARCHAR(100) DEFAULT NULL,
    approved_on DATETIME DEFAULT NULL,
    approver_remarks VARCHAR(255) DEFAULT NULL
)");

// ==========================================
// AJAX HANDLER
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        // ---- APPLY LEAVE ----
        if ($action === 'apply_leave') {
            $leaveType = trim($_POST['leave_type'] ?? '');
            $fromDate  = trim($_POST['from_date'] ?? '');
            $toDate    = trim($_POST['to_date'] ?? '');
            $reason    = trim($_POST['reason'] ?? '');
            $applyFor  = trim($_POST['empcode'] ?? $empCode); // Manager can apply for team

            if (empty($leaveType) || empty($fromDate) || empty($toDate)) {
                echo json_encode(['status' => 'error', 'message' => 'Leave type and dates are required.']);
                exit;
            }

            // Permission: MR can apply only for self
            if ($isMR && $applyFor !== $empCode) {
                echo json_encode(['status' => 'error', 'message' => 'You can apply leave only for yourself.']);
                exit;
            }
            if ($isManager && !in_array($applyFor, $teamCodes) && $applyFor !== $empCode) {
                echo json_encode(['status' => 'error', 'message' => 'You can apply leave only for your team.']);
                exit;
            }

            $days = (strtotime($toDate) - strtotime($fromDate)) / 86400 + 1;
            if ($days < 0.5) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid date range.']);
                exit;
            }

            // Get empname
            $nameStmt = $pdo->prepare("SELECT empname FROM employees WHERE empcode = ?");
            $nameStmt->execute([$applyFor]);
            $applyName = $nameStmt->fetchColumn() ?: $empName;

            $stmt = $pdo->prepare("
                INSERT INTO leave_applications (empcode, empname, leave_type, from_date, to_date, days, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $stmt->execute([$applyFor, $applyName, $leaveType, $fromDate, $toDate, $days, $reason]);

            $response = ['status' => 'success', 'message' => 'Leave application submitted successfully.'];
        }

        // ---- APPROVE / REJECT ----
        elseif ($action === 'update_status') {
            if (!$isAdmin && !$isManager) {
                echo json_encode(['status' => 'error', 'message' => 'Only Managers and Admins can approve/reject leave.']);
                exit;
            }

            $id = intval($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            $remarks = trim($_POST['remarks'] ?? '');

            if (!in_array($status, ['Approved', 'Rejected', 'Cancelled'])) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid status.']);
                exit;
            }

            // Manager can only act on team members
            if ($isManager) {
                $check = $pdo->prepare("SELECT empcode FROM leave_applications WHERE id = ?");
                $check->execute([$id]);
                $appEmp = $check->fetchColumn();
                if (!$appEmp || !in_array($appEmp, $teamCodes)) {
                    echo json_encode(['status' => 'error', 'message' => 'You can only approve leave of your team members.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("
                UPDATE leave_applications 
                SET status = ?, approved_by = ?, approved_on = NOW(), approver_remarks = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $ctx['fullname'] ?? $ctx['username'], $remarks, $id]);

            $response = ['status' => 'success', 'message' => "Leave has been $status."];
        }

        // ---- FETCH LEAVES ----
        elseif ($action === 'fetch_leaves') {
            $statusFilter = trim($_POST['status'] ?? '');
            $sql = "SELECT * FROM leave_applications WHERE 1=1";
            $params = [];

            if ($isAdmin) {
                // all
            } elseif ($isManager) {
                if (empty($teamCodes)) {
                    echo json_encode(['status' => 'success', 'data' => []]);
                    exit;
                }
                $ph = implode(',', array_fill(0, count($teamCodes), '?'));
                $sql .= " AND empcode IN ($ph)";
                $params = $teamCodes;
            } else {
                $sql .= " AND empcode = ?";
                $params[] = $empCode;
            }

            if ($statusFilter !== '') {
                $sql .= " AND status = ?";
                $params[] = $statusFilter;
            }

            $sql .= " ORDER BY applied_on DESC LIMIT 200";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response = ['status' => 'success', 'data' => $data];
        }

        // ---- LEAVE BALANCE (simple calculation) ----
        elseif ($action === 'fetch_balance') {
            $targetEmp = trim($_POST['empcode'] ?? $empCode);

            if ($isMR && $targetEmp !== $empCode) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
                exit;
            }

            $types = $pdo->query("SELECT * FROM leave_types ORDER BY leave_name")->fetchAll(PDO::FETCH_ASSOC);
            $year = date('Y');

            $balance = [];
            foreach ($types as $t) {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(days), 0) 
                    FROM leave_applications 
                    WHERE empcode = ? AND leave_type = ? AND status = 'Approved'
                    AND YEAR(from_date) = ?
                ");
                $stmt->execute([$targetEmp, $t['leave_code'], $year]);
                $used = (float)$stmt->fetchColumn();

                $balance[] = [
                    'leave_code' => $t['leave_code'],
                    'leave_name' => $t['leave_name'],
                    'max_days'   => (int)$t['max_days_per_year'],
                    'used'       => $used,
                    'remaining'  => max(0, $t['max_days_per_year'] - $used),
                    'is_paid'    => (bool)$t['is_paid']
                ];
            }
            $response = ['status' => 'success', 'data' => $balance];
        }

    } catch (PDOException $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// Leave types for dropdown
$leaveTypes = $pdo->query("SELECT * FROM leave_types ORDER BY leave_name")->fetchAll(PDO::FETCH_ASSOC);

// Employees for apply-on-behalf (Manager/Admin)
$empSql = "SELECT empcode, empname FROM employees WHERE 1=1";
$empParams = [];
if ($isMR) {
    $empSql .= " AND empcode = ?";
    $empParams[] = $empCode;
} elseif ($isManager && !empty($teamCodes)) {
    $ph = implode(',', array_fill(0, count($teamCodes), '?'));
    $empSql .= " AND empcode IN ($ph)";
    $empParams = $teamCodes;
}
$empSql .= " ORDER BY empname";
$stmtE = $pdo->prepare($empSql);
$stmtE->execute($empParams);
$employeesList = $stmtE->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Leave Management";
include __DIR__ . '/../includes/header.php';
?>


<div class="tabs">
    <button class="tab-btn active" onclick="switchTab('apply')">Apply Leave</button>
    <button class="tab-btn" onclick="switchTab('myleaves')">My / Team Leaves</button>
    <button class="tab-btn" onclick="switchTab('balance')">Leave Balance</button>
</div>

<div id="msgBox" class="msg-box"></div>

<!-- TAB 1: APPLY -->
<div id="tab-apply" class="tab-content active">
    <div class="panel-box">
        <div class="panel-title">Apply for Leave</div>
        <form id="leaveForm" onsubmit="applyLeave(event)">
            <div class="form-grid" style="grid-template-columns: 1.2fr 1fr 1fr 1fr;">
                <?php if ($isAdmin || $isManager): ?>
                <div class="form-group">
                    <label>Apply For Employee</label>
                    <select name="empcode" id="applyEmp" required>
                        <?php foreach ($employeesList as $e): ?>
                            <option value="<?= htmlspecialchars($e['empcode']) ?>" <?= $e['empcode'] === $empCode ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['empname']) ?> (<?= htmlspecialchars($e['empcode']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="empcode" value="<?= htmlspecialchars($empCode) ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Leave Type</label>
                    <select name="leave_type" id="leaveType" required>
                        <option value="">Select Type</option>
                        <?php foreach ($leaveTypes as $lt): ?>
                            <option value="<?= htmlspecialchars($lt['leave_code']) ?>">
                                <?= htmlspecialchars($lt['leave_name']) ?> (Max <?= $lt['max_days_per_year'] ?> days)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>From Date</label>
                    <input type="date" name="from_date" id="fromDate" required>
                </div>
                <div class="form-group">
                    <label>To Date</label>
                    <input type="date" name="to_date" id="toDate" required>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label>Reason</label>
                <textarea name="reason" id="reason" placeholder="Enter reason for leave..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit Leave Application</button>
        </form>
    </div>
</div>

<!-- TAB 2: LIST -->
<div id="tab-myleaves" class="tab-content">
    <div class="panel-box">
        <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
            <span>Leave Applications</span>
            <select id="statusFilter" onchange="loadLeaves()" style="height:26px; font-size:11px;">
                <option value="">All Status</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
                <option value="Cancelled">Cancelled</option>
            </select>
        </div>
        <div style="overflow-x:auto;">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Emp Code</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Applied On</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="leaveTableBody">
                    <tr><td colspan="10" style="text-align:center;color:#94a3b8;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 3: BALANCE -->
<div id="tab-balance" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">Leave Balance (Current Year)</div>
        <div style="overflow-x:auto;">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Leave Type</th>
                        <th>Max Days / Year</th>
                        <th>Used</th>
                        <th>Remaining</th>
                        <th>Paid / Unpaid</th>
                    </tr>
                </thead>
                <tbody id="balanceTableBody">
                    <tr><td colspan="5" style="text-align:center;color:#94a3b8;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const CAN_APPROVE = <?= ($isAdmin || $isManager) ? 'true' : 'false' ?>;

function showMsg(text, type = 'success') {
    const box = document.getElementById('msgBox');
    box.className = 'msg-box msg-' + type;
    box.innerText = text;
    box.style.display = 'block';
    setTimeout(() => box.style.display = 'none', 4000);
}

function switchTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.target.classList.add('active');

    if (tab === 'myleaves') loadLeaves();
    if (tab === 'balance') loadBalance();
}

function applyLeave(e) {
    e.preventDefault();
    const fd = new FormData(document.getElementById('leaveForm'));
    fd.append('ajax_action', 'apply_leave');

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            showMsg(data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') {
                document.getElementById('leaveForm').reset();
                loadLeaves();
            }
        });
}

function statusBadge(s) {
    const map = {
        'Pending': 'badge-pending',
        'Approved': 'badge-approved',
        'Rejected': 'badge-rejected',
        'Cancelled': 'badge-cancelled'
    };
    return `<span class="badge ${map[s] || ''}">${s}</span>`;
}

function loadLeaves() {
    const status = document.getElementById('statusFilter').value;
    const fd = new FormData();
    fd.append('ajax_action', 'fetch_leaves');
    fd.append('status', status);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('leaveTableBody');
            if (data.status !== 'success' || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#94a3b8;">No leave applications found</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(r => {
                let actions = '-';
                if (CAN_APPROVE && r.status === 'Pending') {
                    actions = `
                        <button class="btn btn-success btn-sm" onclick="updateStatus(${r.id}, 'Approved')">Approve</button>
                        <button class="btn btn-danger btn-sm" onclick="updateStatus(${r.id}, 'Rejected')">Reject</button>
                    `;
                }
                return `<tr>
                    <td>${r.empcode}</td>
                    <td><strong>${r.empname}</strong></td>
                    <td>${r.leave_type}</td>
                    <td>${r.from_date}</td>
                    <td>${r.to_date}</td>
                    <td>${r.days}</td>
                    <td title="${r.reason || ''}">${(r.reason || '-').substring(0, 30)}</td>
                    <td>${statusBadge(r.status)}</td>
                    <td>${r.applied_on ? r.applied_on.substring(0, 10) : '-'}</td>
                    <td>${actions}</td>
                </tr>`;
            }).join('');
        });
}

function updateStatus(id, status) {
    const remarks = prompt(status + ' Remarks (optional):') || '';
    const fd = new FormData();
    fd.append('ajax_action', 'update_status');
    fd.append('id', id);
    fd.append('status', status);
    fd.append('remarks', remarks);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            showMsg(data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') loadLeaves();
        });
}

function loadBalance() {
    const fd = new FormData();
    fd.append('ajax_action', 'fetch_balance');

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('balanceTableBody');
            if (data.status !== 'success') {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#94a3b8;">Failed to load</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(r => `
                <tr>
                    <td><strong>${r.leave_name}</strong> (${r.leave_code})</td>
                    <td>${r.max_days}</td>
                    <td>${r.used}</td>
                    <td style="font-weight:700; color:${r.remaining > 0 ? '#059669' : '#dc2626'}">${r.remaining}</td>
                    <td>${r.is_paid ? 'Paid' : 'Unpaid'}</td>
                </tr>
            `).join('');
        });
}

// Default dates
document.getElementById('fromDate').value = new Date().toISOString().split('T')[0];
document.getElementById('toDate').value = new Date().toISOString().split('T')[0];
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
