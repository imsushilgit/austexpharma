<?php
require_once __DIR__ . '/../includes/core.php';

$ctx = require_page_access($pdo);
$canWrite  = user_can_write($ctx);
$isAdmin   =$ctx['is_admin'];
$isMR      =$ctx['is_mr'];
$isManager =$ctx['is_manager'];
$teamCodes =$ctx['team_codes'];
$empCode   =$ctx['empcode'];

$pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    empname VARCHAR(150) NOT NULL,
    att_date DATE NOT NULL,
    status ENUM('Present','Absent','Half Day','Leave','Holiday','Week Off') DEFAULT 'Present',
    check_in TIME DEFAULT NULL,
    check_out TIME DEFAULT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    marked_by VARCHAR(100) DEFAULT NULL,
    UNIQUE KEY unique_att (empcode, att_date)
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$res = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'save_attendance' && ($canWrite||$isMR)) {
            $records = json_decode($_POST['records'] ?? '[]', true);
            $stmt =$pdo->prepare("INSERT INTO attendance (empcode, empname, att_date, status, check_in, check_out, remarks, marked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status=VALUES(status), check_in=VALUES(check_in), check_out=VALUES(check_out), remarks=VALUES(remarks), marked_by=VALUES(marked_by)");
            $saved = 0;
            foreach ($records as$r) {
                $code = trim($r['empcode'] ?? '');
                if (($isMR && $code !==$empCode) || ($isManager && !in_array($code, $teamCodes)) || empty($code)) continue;
                $stmt->execute([$code, trim($r['empname'] ?? ''), trim($r['att_date'] ?? ''), trim($r['status'] ?? 'Present'), !empty($r['check_in'])?$r['check_in']:null, !empty($r['check_out'])?$r['check_out']:null, trim($r['remarks'] ?? ''),$ctx['fullname'] ?? $ctx['username']]);$saved++;
            }
            $res = ['status' => 'success', 'message' => "$saved record(s) saved."];
        } elseif ($action === 'fetch_attendance') {$startDate = sprintf('%04d-%02d-01', intval($_POST['year'] ?? date('Y')), intval($_POST['month'] ?? date('m')));
            $endDate = date('Y-m-t', strtotime($startDate));$sql = "SELECT a.*, e.designation, e.hq FROM attendance a LEFT JOIN employees e ON a.empcode = e.empcode WHERE a.att_date BETWEEN ? AND ?";
            $params = [$startDate,$endDate];
            if ($isAdmin) {
                if (!empty($_POST['empcode'])) {$sql .= " AND a.empcode = ?"; $params[] =$_POST['empcode']; }
            } elseif ($isManager) {
                if (empty($teamCodes)) { echo json_encode(['status' => 'success', 'data' => []]); exit; }$sql .= " AND a.empcode IN (" . implode(',', array_fill(0, count($teamCodes), '?')) . ")";
                $params = array_merge($params,$teamCodes);
                if (!empty($_POST['empcode']) && in_array($_POST['empcode'], $teamCodes)) {$sql .= " AND a.empcode = ?"; $params[] =$_POST['empcode']; }
            } else { $sql .= " AND a.empcode = ?"; $params[] = $empCode; }$stmt = $pdo->prepare($sql . " ORDER BY a.att_date DESC, a.empname ASC");
            $stmt->execute($params);
            $res = ['status' => 'success', 'data' =>$stmt->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($action === 'get_employees_for_att') {
            $attDate =$_POST['att_date'] ?? date('Y-m-d');
            $sql = "SELECT empcode, empname, designation, hq FROM employees WHERE (resignation_date IS NULL OR (YEAR(resignation_date) = ? AND MONTH(resignation_date) = ?))";
            $params = [date('Y', strtotime($attDate)), date('m', strtotime($attDate))];
            if ($isMR) {$sql .= " AND empcode = ?"; $params[] =$empCode; }
            elseif ($isManager && !empty($teamCodes)) {$sql .= " AND empcode IN (" . implode(',', array_fill(0, count($teamCodes), '?')) . ")";
                $params = array_merge($params,$teamCodes);
            }
            $stmt = $pdo->prepare($sql . " ORDER BY empname ASC");
            $stmt->execute($params);
            $res = ['status' => 'success', 'employees' =>$stmt->fetchAll(PDO::FETCH_ASSOC)];
        }
    } catch (PDOException $e) { $res['message'] =$e->getMessage(); }
    echo json_encode($res);
    exit;
}

$empSql = "SELECT empcode, empname, designation, hq FROM employees WHERE (resignation_date IS NULL OR (YEAR(resignation_date) = ? AND MONTH(resignation_date) = ?))";
$empParams = [date('Y'), date('m')];
if ($isMR) {$empSql .= " AND empcode = ?"; $empParams[] =$empCode; }
elseif ($isManager && !empty($teamCodes)) {$empSql .= " AND empcode IN (" . implode(',', array_fill(0, count($teamCodes), '?')) . ")";
    $empParams = array_merge($empParams,$teamCodes);
}
$stmtEmp = $pdo->prepare($empSql . " ORDER BY empname ASC");
$stmtEmp->execute($empParams);
$employeesList =$stmtEmp->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Attendance";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel">
    <div class="panel-title">Mark Attendance</div>
    <div id="msgBox" class="msg-box"></div>
    <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <div>
            <label style="font-size:9px; font-weight:bold; color:#1e2a78;">DATE</label>
            <input type="date" id="attDate" class="input-sm" value="<?= date('Y-m-d') ?>">
        </div>
        <div>
            <label style="font-size:9px; font-weight:bold; color:#1e2a78;">MONTH/YEAR</label>
            <input type="month" id="viewMonth" class="input-sm" value="<?= date('Y-m') ?>" onchange="loadAttendance()">
        </div>
        <div style="flex:1; min-width:150px;">
            <label style="font-size:9px; font-weight:bold; color:#1e2a78;">FILTER EMPLOYEE</label>
            <select id="filterEmp" class="input-sm" style="width:100%;" onchange="loadAttendance()">
                <option value="">All Active/Resigned This Month</option>
                <?php foreach ($employeesList as$e): ?>
                    <option value="<?= htmlspecialchars($e['empcode']) ?>"><?= htmlspecialchars($e['empname']) ?> (<?= htmlspecialchars($e['empcode']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-navy" onclick="loadEmployeesForMark()">Load Sheet</button>
        <button class="btn btn-navy" style="background:#059669; border-color:#059669;" onclick="saveAttendance()" <?= (!$canWrite && !$isMR) ? 'disabled' : '' ?>>Save</button>
    </div>
</div>

<div class="panel" id="markingPanel" style="display:none;">
    <div class="panel-title">Entry Sheet: <span id="markDateLabel"></span></div>
    <div style="overflow-x:auto;">
        <table class="compact-table">
            <thead>
                <tr>
                    <th>#</th><th>Code</th><th>Name</th><th>Designation</th><th>HQ</th><th>Status</th><th>In</th><th>Out</th><th>Remarks</th>
                </tr>
            </thead>
            <tbody id="markTableBody"></tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Attendance Logs</div>
    <div style="overflow-x:auto;">
        <table class="compact-table">
            <thead>
                <tr>
                    <th>Date</th><th>Code</th><th>Name</th><th>Desig</th><th>HQ</th><th>Status</th><th>In</th><th>Out</th><th>Remarks</th><th>By</th>
                </tr>
            </thead>
            <tbody id="attTableBody">
                <tr><td colspan="10" style="text-align:center;color:#64748b;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
const CAN_WRITE = <?= ($canWrite || $isMR) ? 'true' : 'false' ?>;

function showMsg(text, type='success') {
    const box = document.getElementById('msgBox');
    box.style.cssText = type === 'success' ? 'background:#d1fae5; color:#065f46; border:1px solid #a7f3d0;' : 'background:#fee2e2; color:#991b1b; border:1px solid #fecaca;';
    box.innerText = text; box.style.display = 'block';
    setTimeout(() => box.style.display = 'none', 3000);
}

function loadEmployeesForMark() {
    const date = document.getElementById('attDate').value;
    if (!date) return;
    const fd = new FormData();
    fd.append('ajax_action', 'get_employees_for_att');
    fd.append('att_date', date);
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') { showMsg(data.message || 'Error', 'error'); return; }
            const tbody = document.getElementById('markTableBody');
            tbody.innerHTML = '';
            data.employees.forEach((e, idx) => {
                tbody.innerHTML += `
                    <tr data-empcode="${e.empcode}" data-empname="${e.empname}">
                        <td>${idx + 1}</td>
                        <td>${e.empcode}</td>
                        <td><strong>${e.empname}</strong></td>
                        <td>${e.designation || '-'}</td>
                        <td>${e.hq || '-'}</td>
                        <td>
                            <select class="input-sm att-status">
                                <option value="Present">Present</option>
                                <option value="Absent">Absent</option>
                                <option value="Half Day">Half Day</option>
                                <option value="Leave">Leave</option>
                                <option value="Holiday">Holiday</option>
                                <option value="Week Off">Week Off</option>
                            </select>
                        </td>
                        <td><input type="time" class="input-sm att-in" value="09:30" style="width:70px;"></td>
                        <td><input type="time" class="input-sm att-out" value="18:00" style="width:70px;"></td>
                        <td><input type="text" class="input-sm att-remarks" placeholder="Opt" style="width:100px;"></td>
                    </tr>`;
            });
            document.getElementById('markDateLabel').innerText = date;
            document.getElementById('markingPanel').style.display = 'block';
        });
}

function saveAttendance() {
    if (!CAN_WRITE) { alert('Unauthorized'); return; }
    const date = document.getElementById('attDate').value;
    const rows = document.querySelectorAll('#markTableBody tr');
    if (!rows.length) return;

    const records = [];
    rows.forEach(row => {
        records.push({
            empcode: row.dataset.empcode,
            empname: row.dataset.empname,
            att_date: date,
            status: row.querySelector('.att-status').value,
            check_in: row.querySelector('.att-in').value,
            check_out: row.querySelector('.att-out').value,
            remarks: row.querySelector('.att-remarks').value
        });
    });

    const fd = new FormData();
    fd.append('ajax_action', 'save_attendance');
    fd.append('records', JSON.stringify(records));
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            showMsg(data.message, data.status === 'success' ? 'success' : 'error');
            if (data.status === 'success') { loadAttendance(); document.getElementById('markingPanel').style.display = 'none'; }
        });
}

function loadAttendance() {
    const [year, month] = (document.getElementById('viewMonth').value || '').split('-');
    if (!year || !month) return;
    const fd = new FormData();
    fd.append('ajax_action', 'fetch_attendance');
    fd.append('month', month);
    fd.append('year', year);
    fd.append('empcode', document.getElementById('filterEmp').value);
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('attTableBody');
            if (data.status !== 'success' || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#64748b;">No records found</td></tr>';
                return;
            }
            tbody.innerHTML = data.data.map(r => `
                <tr>
                    <td>${r.att_date}</td>
                    <td>${r.empcode}</td>
                    <td><strong>${r.empname}</strong></td>
                    <td>${r.designation || '-'}</td>
                    <td>${r.hq || '-'}</td>
                    <td><b>${r.status}</b></td>
                    <td>${r.check_in || '-'}</td>
                    <td>${r.check_out || '-'}</td>
                    <td>${r.remarks || '-'}</td>
                    <td>${r.marked_by || '-'}</td>
                </tr>
            `).join('');
        });
}

window.onload = loadAttendance;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>