<?php
require_once __DIR__ . '/../includes/core.php';

$ctx = require_page_access($pdo);
$canWrite  = user_can_write($ctx);
$isAdmin   = $ctx['is_admin'];
$isMR      = $ctx['is_mr'];
$isManager = $ctx['is_manager'];
$teamCodes = $ctx['team_codes'];
$empCode   = $ctx['empcode'];

// ==========================================
// AUTO-CREATE SALARY TABLE
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS monthly_salary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    empname VARCHAR(150) NOT NULL,
    salary_month INT NOT NULL,
    salary_year INT NOT NULL,
    present_days DECIMAL(4,1) DEFAULT 0,
    paid_days DECIMAL(4,1) DEFAULT 0,
    gross_earning DECIMAL(12,2) DEFAULT 0,
    total_deduction DECIMAL(12,2) DEFAULT 0,
    net_salary DECIMAL(12,2) DEFAULT 0,
    components_json TEXT,
    status ENUM('Draft','Generated','Paid') DEFAULT 'Generated',
    generated_by VARCHAR(100) DEFAULT NULL,
    generated_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_emp_month (empcode, salary_month, salary_year)
)");

// Ensure salary_components & employee_salary_structure exist (from structure page)
$pdo->exec("CREATE TABLE IF NOT EXISTS salary_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    component_code VARCHAR(30) NOT NULL UNIQUE,
    component_name VARCHAR(100) NOT NULL,
    component_type ENUM('Earning','Deduction') NOT NULL DEFAULT 'Earning',
    is_percentage TINYINT(1) DEFAULT 0,
    default_value DECIMAL(12,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_salary_structure (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    component_code VARCHAR(30) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_emp_comp (empcode, component_code)
)");

// ==========================================
// AJAX
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'generate_salary') {
            if (!$isAdmin && !$isManager) {
                echo json_encode(['status'=>'error','message'=>'Only Admin/Manager can generate salary.']);
                exit;
            }
            $month = intval($_POST['month'] ?? date('m'));
            $year  = intval($_POST['year'] ?? date('Y'));
            $empFilter = trim($_POST['empcode'] ?? '');

            // Get employees in scope
            $sql = "SELECT empcode, empname FROM employees WHERE 1=1";
            $params = [];
            if ($isManager && !empty($teamCodes)) {
                $ph = implode(',', array_fill(0, count($teamCodes), '?'));
                $sql .= " AND empcode IN ($ph)";
                $params = $teamCodes;
            }
            if ($empFilter !== '') {
                $sql .= " AND empcode = ?";
                $params[] = $empFilter;
            }
            $sql .= " ORDER BY empname";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $emps = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $generated = 0;

            foreach ($emps as $emp) {
                // Attendance summary
                $attSql = "SELECT 
                    SUM(CASE WHEN status='Present' THEN 1 WHEN status='Half Day' THEN 0.5 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status IN ('Present','Half Day','Leave','Holiday','Week Off') THEN 
                        CASE WHEN status='Half Day' THEN 0.5 ELSE 1 END ELSE 0 END) as paid_days
                    FROM attendance 
                    WHERE empcode = ? AND MONTH(att_date)=? AND YEAR(att_date)=?";
                $attStmt = $pdo->prepare($attSql);
                $attStmt->execute([$emp['empcode'], $month, $year]);
                $att = $attStmt->fetch(PDO::FETCH_ASSOC);
                $presentDays = floatval($att['present_days'] ?? 0);
                $paidDays = floatval($att['paid_days'] ?? $daysInMonth); // fallback full month if no attendance

                // Get structure
                $stStmt = $pdo->prepare("SELECT ess.component_code, ess.amount, sc.component_name, sc.component_type, sc.is_percentage
                    FROM employee_salary_structure ess
                    JOIN salary_components sc ON ess.component_code = sc.component_code
                    WHERE ess.empcode = ? AND sc.is_active = 1
                    ORDER BY sc.sort_order");
                $stStmt->execute([$emp['empcode']]);
                $struct = $stStmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($struct)) {
                    // fallback to defaults
                    $struct = $pdo->query("SELECT component_code, default_value as amount, component_name, component_type, is_percentage FROM salary_components WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
                }

                $basic = 0;
                foreach ($struct as $s) {
                    if ($s['component_code'] === 'BASIC') { $basic = floatval($s['amount']); break; }
                }

                $earnings = []; $deductions = [];
                $gross = 0; $dedTotal = 0;

                foreach ($struct as $s) {
                    $amt = floatval($s['amount']);
                    if ($s['is_percentage'] && $basic > 0) {
                        $amt = round($basic * $amt / 100, 2);
                    }
                    // Prorate by paid days
                    $finalAmt = $daysInMonth > 0 ? round($amt * ($paidDays / $daysInMonth), 2) : $amt;

                    $item = ['code'=>$s['component_code'], 'name'=>$s['component_name'], 'amount'=>$finalAmt];
                    if ($s['component_type'] === 'Earning') {
                        $earnings[] = $item;
                        $gross += $finalAmt;
                    } else {
                        $deductions[] = $item;
                        $dedTotal += $finalAmt;
                    }
                }

                $net = $gross - $dedTotal;
                $compJson = json_encode(['earnings'=>$earnings, 'deductions'=>$deductions]);

                $ins = $pdo->prepare("INSERT INTO monthly_salary 
                    (empcode, empname, salary_month, salary_year, present_days, paid_days, gross_earning, total_deduction, net_salary, components_json, status, generated_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?, 'Generated', ?)
                    ON DUPLICATE KEY UPDATE 
                        present_days=VALUES(present_days), paid_days=VALUES(paid_days),
                        gross_earning=VALUES(gross_earning), total_deduction=VALUES(total_deduction),
                        net_salary=VALUES(net_salary), components_json=VALUES(components_json),
                        status='Generated', generated_by=VALUES(generated_by), generated_on=NOW()");
                $ins->execute([
                    $emp['empcode'], $emp['empname'], $month, $year,
                    $presentDays, $paidDays, $gross, $dedTotal, $net, $compJson,
                    $ctx['fullname'] ?? $ctx['username']
                ]);
                $generated++;
            }
            $response = ['status'=>'success', 'message'=>"$generated salary record(s) generated for $month/$year."];
        }

        elseif ($action === 'fetch_salary') {
            $month = intval($_POST['month'] ?? date('m'));
            $year  = intval($_POST['year'] ?? date('Y'));

            $sql = "SELECT * FROM monthly_salary WHERE salary_month=? AND salary_year=?";
            $params = [$month, $year];

            if ($isAdmin) {
                // all
            } elseif ($isManager && !empty($teamCodes)) {
                $ph = implode(',', array_fill(0, count($teamCodes), '?'));
                $sql .= " AND empcode IN ($ph)";
                $params = array_merge($params, $teamCodes);
            } else {
                $sql .= " AND empcode = ?";
                $params[] = $empCode;
            }
            $sql .= " ORDER BY empname";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $response = ['status'=>'success', 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
        }

        elseif ($action === 'mark_paid') {
            if (!$isAdmin) {
                echo json_encode(['status'=>'error','message'=>'Only Admin can mark as Paid.']);
                exit;
            }
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE monthly_salary SET status='Paid' WHERE id=?")->execute([$id]);
            $response = ['status'=>'success', 'message'=>'Marked as Paid.'];
        }

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

$pageTitle = "Monthly Salary";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title">Generate / View Monthly Salary</div>
    <div id="msgBox" class="msg-box"></div>
    <div class="form-grid" style="grid-template-columns: 140px 100px 100px auto auto;">
        <div class="form-group">
            <label>Month</label>
            <select id="salMonth">
                <?php for ($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= $m==date('n')?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Year</label>
            <select id="salYear">
                <?php for ($y=date('Y');$y>=date('Y')-3;$y--): ?>
                    <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div></div>
        <?php if ($isAdmin || $isManager): ?>
        <button class="btn btn-primary" onclick="generateSalary()">Generate Salary</button>
        <?php endif; ?>
        <button class="btn btn-secondary" onclick="loadSalary()">Refresh</button>
    </div>
</div>

<div class="panel-box">
    <div class="panel-title">Salary Register</div>
    <div style="overflow-x:auto;">
        <table class="compact-table">
            <thead>
                <tr>
                    <th>Emp Code</th><th>Employee</th><th>Present</th><th>Paid Days</th>
                    <th>Gross</th><th>Deduction</th><th>Net Salary</th><th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody id="salTableBody">
                <tr><td colspan="9" style="text-align:center;color:#94a3b8;">Select month and click Refresh</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
function showMsg(t, type='success') {
    const b=document.getElementById('msgBox'); b.className='msg-box msg-'+type; b.innerText=t; b.style.display='block';
    setTimeout(()=>b.style.display='none',4000);
}
function generateSalary() {
    if (!confirm('Generate / re-generate salary for selected month? Existing records will be updated.')) return;
    const fd = new FormData();
    fd.append('ajax_action','generate_salary');
    fd.append('month', document.getElementById('salMonth').value);
    fd.append('year', document.getElementById('salYear').value);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        showMsg(d.message, d.status==='success'?'success':'error');
        if (d.status==='success') loadSalary();
    });
}
function loadSalary() {
    const fd = new FormData();
    fd.append('ajax_action','fetch_salary');
    fd.append('month', document.getElementById('salMonth').value);
    fd.append('year', document.getElementById('salYear').value);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        const tb = document.getElementById('salTableBody');
        if (d.status!=='success' || !d.data.length) {
            tb.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#94a3b8;">No salary records found</td></tr>';
            return;
        }
        tb.innerHTML = d.data.map(r => {
            const badge = r.status==='Paid' ? 'badge-paid' : (r.status==='Generated'?'badge-gen':'badge-draft');
            let action = `<a href="salaryslip.php?id=${r.id}" target="_blank" class="btn btn-secondary btn-sm">Payslip</a>`;
            if (IS_ADMIN && r.status !== 'Paid') {
                action += ` <button class="btn btn-success btn-sm" onclick="markPaid(${r.id})">Mark Paid</button>`;
            }
            return `<tr>
                <td>${r.empcode}</td>
                <td><strong>${r.empname}</strong></td>
                <td>${r.present_days}</td>
                <td>${r.paid_days}</td>
                <td>₹ ${Number(r.gross_earning).toLocaleString('en-IN')}</td>
                <td>₹ ${Number(r.total_deduction).toLocaleString('en-IN')}</td>
                <td><strong>₹ ${Number(r.net_salary).toLocaleString('en-IN')}</strong></td>
                <td><span class="badge ${badge}">${r.status}</span></td>
                <td>${action}</td>
            </tr>`;
        }).join('');
    });
}
function markPaid(id) {
    if (!confirm('Mark this salary as Paid?')) return;
    const fd = new FormData(); fd.append('ajax_action','mark_paid'); fd.append('id',id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        showMsg(d.message, d.status==='success'?'success':'error');
        if (d.status==='success') loadSalary();
    });
}
window.onload = loadSalary;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
