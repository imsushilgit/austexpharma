<?php
require_once __DIR__ . '/../includes/core.php';

$ctx = require_page_access($pdo);
$canWrite  = user_can_write($ctx);
$isAdmin   = $ctx['is_admin'];
$isMR      = $ctx['is_mr'];
$isManager = $ctx['is_manager'];
$teamCodes = $ctx['team_codes'];
$empCode   = $ctx['empcode'];

// Ensure attendance table exists
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_att (empcode, att_date)
)");

// Holidays table
$pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(150) NOT NULL,
    holiday_type ENUM('National','Regional','Company','Optional') DEFAULT 'Company',
    is_optional TINYINT(1) DEFAULT 0,
    year INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_date (holiday_date)
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'fetch_report') {
            $month = intval($_POST['month'] ?? date('m'));
            $year  = intval($_POST['year'] ?? date('Y'));
            $filterEmp = trim($_POST['empcode'] ?? '');
            $filterDesig = trim($_POST['designation'] ?? '');
            $filterHq = trim($_POST['hq'] ?? '');

            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $daysInMonth = (int)date('t', strtotime($startDate));
            $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

            // Employees in scope (active or resigned this month)
            $empSql = "SELECT empcode, empname, designation, hq, state, department FROM employees WHERE (resignation_date IS NULL OR (YEAR(resignation_date) = ? AND MONTH(resignation_date) = ?))";
            $empParams = [$year, $month];

            if ($isAdmin) {
                // all
            } elseif ($isManager && !empty($teamCodes)) {
                $ph = implode(',', array_fill(0, count($teamCodes), '?'));
                $empSql .= " AND empcode IN ($ph)";
                $empParams = array_merge($empParams, $teamCodes);
            } else {
                $empSql .= " AND empcode = ?";
                $empParams[] = $empCode;
            }
            if ($filterEmp !== '') {
                $empSql .= " AND empcode = ?";
                $empParams[] = $filterEmp;
            }
            if ($filterDesig !== '') {
                $empSql .= " AND designation = ?";
                $empParams[] =$filterDesig;
            }
            if ($filterHq !== '') {$empSql .= " AND hq = ?";
                $empParams[] =$filterHq;
            }
            $empSql .= " ORDER BY empname";
            $stmt = $pdo->prepare($empSql);
            $stmt->execute($empParams);
            $employees =$stmt->fetchAll(PDO::FETCH_ASSOC);

            // Attendance for month
            $codes = array_column($employees, 'empcode');$attMap = []; 
            if (!empty($codes)) {$ph = implode(',', array_fill(0, count($codes), '?'));$attSql = "SELECT empcode, att_date, status, check_in, check_out, remarks
                           FROM attendance
                           WHERE att_date BETWEEN ? AND ? AND empcode IN ($ph)";
                $attParams = array_merge([$startDate,$endDate], $codes);$attStmt = $pdo->prepare($attSql);
                $attStmt->execute($attParams);
                foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as$row) {
                    $day = (int)date('j', strtotime($row['att_date']));
                    $attMap[$row['empcode']][$day] =$row;
                }
            }

            // Holidays in month
            $holStmt =$pdo->prepare("SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN ? AND ?");
            $holStmt->execute([$startDate, $endDate]);$holidays = [];
            foreach ($holStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {$holidays[(int)date('j', strtotime($h['holiday_date']))] =$h['holiday_name'];
            }

            // Build report rows
            $report = [];
            foreach ($employees as$emp) {
                $summary = [
                    'Present' => 0, 'Absent' => 0, 'Half Day' => 0,
                    'PL' => 0, 'SL' => 0, 'CL' => 0, 'LWP' => 0, 'Leave' => 0, 
                    'PH' => 0, 'Sun' => 0, 'Unmarked' => 0
                ];$days = [];
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $dateStr = sprintf('%04d-%02d-%02d',$year, $month,$d);
                    $dow = (int)date('w', strtotime($dateStr)); // 0=Sun
                    $cell =$attMap[$emp['empcode']][$d] ?? null;

                    if ($cell) {
                        $status =$cell['status'];
                        $remarks = strtolower(trim($cell['remarks'] ?? ''));
                        
                        if ($status === 'Leave') {
                            if (strpos($remarks, 'pl') !== false) {$summary['PL']++; }
                            elseif (strpos($remarks, 'sl') !== false) {$summary['SL']++; }
                            elseif (strpos($remarks, 'cl') !== false) {$summary['CL']++; }
                            elseif (strpos($remarks, 'lwp') !== false) {$summary['LWP']++; }
                            else { $summary['Leave']++; }
                        } elseif ($status === 'Holiday') {$summary['PH']++;
                        } else {
                            if (strpos($remarks, 'pl') !== false) {$summary['PL']++; }
                            elseif (strpos($remarks, 'sl') !== false) {$summary['SL']++; }
                            elseif (strpos($remarks, 'cl') !== false) {$summary['CL']++; }
                            elseif (strpos($remarks, 'lwp') !== false) {$summary['LWP']++; }
                            else {
                                $summary[$status] = ($summary[$status] ?? 0) + ($status === 'Half Day' ? 0.5 : 1);
                            }
                        }

                        $days[$d] = [
                            'status' => $status,
                            'check_in' => $cell['check_in'],
                            'check_out' => $cell['check_out'],
                            'remarks' => $cell['remarks']
                        ];
                    } elseif (isset($holidays[$d])) {$summary['PH']++;
                        $days[$d] = ['status' => 'PH', 'name' => $holidays[$d]];
                    } elseif ($dow === 0) {$summary['Sun']++;
                        $days[$d] = ['status' => 'Sun'];
                    } else {
                        $summary['Unmarked']++;
                        $days[$d] = ['status' => 'Unmarked'];
                    }
                }

                // Calculations matching your requested column sequence
                $presentTotal = $summary['Present'] +$summary['Half Day'];
                $totalAbsent =$summary['Absent'];
                
                // Payable Days = Present + Absent(0) + LWP(0) + Sun + PH + PL + SL + CL
                $payableDays =$presentTotal + $summary['Sun'] +$summary['PH'] + $summary['PL'] +$summary['SL'] + $summary['CL'] +$summary['Leave'];

                $report[] = [
                    'empcode' => $emp['empcode'],
                    'empname' => $emp['empname'],
                    'designation' => $emp['designation'],
                    'hq' => $emp['hq'],
                    'summary' => $summary,
                    'present_total' => $presentTotal,
                    'total_absent' => $totalAbsent,
                    'payable_days' => $payableDays,
                    'days' => $days
                ];
            }

            $response = [
                'status' => 'success',
                'days_in_month' => $daysInMonth,
                'holidays' => $holidays,
                'data' => $report,
                'month' => $month,
                'year' => $year
            ];
        }

        elseif ($action === 'get_filters') {$sql = "SELECT DISTINCT designation, hq FROM employees WHERE 1=1";
            $params = [];
            if ($isManager && !empty($teamCodes)) {$ph = implode(',', array_fill(0, count($teamCodes), '?'));$sql .= " AND empcode IN ($ph)";
                $params =$teamCodes;
            } elseif ($isMR) {$sql .= " AND empcode = ?";
                $params[] =$empCode;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows =$stmt->fetchAll(PDO::FETCH_ASSOC);
            $desigs = array_values(array_unique(array_filter(array_column($rows, 'designation'))));
            $hqs = array_values(array_unique(array_filter(array_column($rows, 'hq'))));
            sort($desigs);
            sort($hqs);

            $empSql = "SELECT empcode, empname FROM employees WHERE 1=1";
            $empParams = [];
            if ($isManager && !empty($teamCodes)) {$ph = implode(',', array_fill(0, count($teamCodes), '?'));$empSql .= " AND empcode IN ($ph)";
                $empParams =$teamCodes;
            } elseif ($isMR) {$empSql .= " AND empcode = ?";
                $empParams[] =$empCode;
            }
            $empSql .= " ORDER BY empname";
            $eStmt = $pdo->prepare($empSql);
            $eStmt->execute($empParams);
            $emps =$eStmt->fetchAll(PDO::FETCH_ASSOC);

            $response = ['status' => 'success', 'designations' =>$desigs, 'hqs' => $hqs, 'employees' =>$emps];
        }

    } catch (Exception $e) {
        $response['message'] =$e->getMessage();
    }
    echo json_encode($response);
    exit;
}

$pageTitle = "Advanced Attendance Report";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title">Attendance Report Filters</div>
    <div class="form-grid" style="grid-template-columns: 90px 80px 1.2fr 1fr 1fr auto auto;">
        <div class="form-group">
            <label>Month</label>
            <select id="rptMonth">
                <?php for ($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= $m==date('n')?'selected':'' ?>><?= date('M', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Year</label>
            <select id="rptYear">
                <?php for ($y=date('Y');$y>=date('Y')-2;$y--): ?>
                    <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Employee</label>
            <select id="rptEmp"><option value="">All</option></select>
        </div>
        <div class="form-group">
            <label>Designation</label>
            <select id="rptDesig"><option value="">All</option></select>
        </div>
        <div class="form-group">
            <label>HQ</label>
            <select id="rptHq"><option value="">All</option></select>
        </div>
        <button class="btn btn-primary" onclick="loadReport()">Generate</button>
        <button class="btn btn-success" onclick="exportExcel()">Export</button>
    </div>
</div>

<div class="tabs">
    <button class="tab-btn active" onclick="switchRptTab('summary')">Summary Report</button>
    <button class="tab-btn" onclick="switchRptTab('matrix')">Date-wise Matrix</button>
</div>

<div class="legend">
    <span><i class="c-P" style="background:#d1fae5; color:#065f46;">P</i> Present</span>
    <span><i class="c-A" style="background:#fee2e2; color:#991b1b;">A</i> Absent</span>
    <span><i class="c-H" style="background:#fef3c7; color:#92400e;">H</i> Half Day</span>
    <span><i class="c-L" style="background:#e0f2fe; color:#0369a1;">PL/SL/CL/LWP</i> Leaves</span>
    <span><i class="c-PH" style="background:#ff4500; color:#ffffff;">PH</i> Public Holiday</span>
    <span><i class="c-Sun" style="background:#dc2626; color:#ffffff;">Sun</i> Sunday</span>
    <span><i class="c-U" style="background:#ffffff; color:#94a3b8;">-</i> Unmarked</span>
</div>

<div id="tab-summary" class="tab-content active">
    <div class="panel-box">
        <div class="panel-title">Monthly Summary & Totals</div>
        <div style="overflow-x:auto;">
            <table class="summary-table" id="summaryTable">
                <thead>
                    <tr>
                        <th>Code</th><th>Employee</th><th>Desig</th><th>HQ</th>
                        <th>Absent</th><th>LWP</th><th>Sun</th><th>PH</th>
                        <th>PL</th><th>Cl</th><th>SL</th><th>Present</th><th>Payab</th>
                    </tr>
                </thead>
                <tbody id="summaryBody">
                    <tr><td colspan="13" style="text-align:center;color:#94a3b8;">Click Generate to load report</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="tab-matrix" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">Month-wise Date-wise Attendance Matrix</div>
        <div class="matrix-wrap">
            <table class="att-matrix" id="matrixTable">
                <thead id="matrixHead"></thead>
                <tbody id="matrixBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
let reportData = null;

function switchRptTab(t) {
    document.querySelectorAll('.tab-content').forEach(e => e.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(e => e.classList.remove('active'));
    document.getElementById('tab-' + t).classList.add('active');
    event.target.classList.add('active');
}

function statusClass(status, remarks = '', isSunday = false, isHoliday = false) {
    if (isSunday || status === 'Sunday' || status === 'Week Off') return 'c-Sun';
    if (isHoliday || status === 'Holiday' || status === 'PH') return 'c-PH';
    
    remarks = (remarks || '').toLowerCase();
    if (status === 'Present' || status === 'Half Day') return 'c-P';
    if (status === 'Absent') return 'c-A';
    if (status === 'Leave' || remarks.includes('pl') || remarks.includes('sl') || remarks.includes('cl') || remarks.includes('lwp')) return 'c-L';
    return 'c-U';
}

function statusShort(status, remarks = '', isSunday = false, isHoliday = false) {
    if (isSunday || status === 'Sunday' || status === 'Week Off') return 'S';
    if (isHoliday || status === 'Holiday' || status === 'PH') return 'PH';

    remarks = (remarks || '').toLowerCase();
    if (status === 'Present') return 'P';
    if (status === 'Half Day') return 'H';
    if (status === 'Absent') return 'A';
    if (status === 'Leave' || remarks.includes('pl') || remarks.includes('sl') || remarks.includes('cl') || remarks.includes('lwp')) {
        if (remarks.includes('pl')) return 'PL';
        if (remarks.includes('sl')) return 'SL';
        if (remarks.includes('cl')) return 'CL';
        if (remarks.includes('lwp')) return 'LWP';
        return 'L';
    }
    return '-';
}

function loadFilters() {
    const fd = new FormData(); fd.append('ajax_action', 'get_filters');
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if (d.status !== 'success') return;
        const empSel = document.getElementById('rptEmp');
        empSel.innerHTML = '<option value="">All</option>';
        (d.employees||[]).forEach(e => {
            empSel.innerHTML += `<option value="${e.empcode}">${e.empname} (${e.empcode})</option>`;
        });
        const dSel = document.getElementById('rptDesig');
        dSel.innerHTML = '<option value="">All</option>';
        (d.designations||[]).forEach(x => dSel.innerHTML += `<option value="${x}">${x}</option>`);
        const hSel = document.getElementById('rptHq');
        hSel.innerHTML = '<option value="">All</option>';
        (d.hqs||[]).forEach(x => hSel.innerHTML += `<option value="${x}">${x}</option>`);
    });
}

function loadReport() {
    const fd = new FormData();
    fd.append('ajax_action', 'fetch_report');
    fd.append('month', document.getElementById('rptMonth').value);
    fd.append('year', document.getElementById('rptYear').value);
    fd.append('empcode', document.getElementById('rptEmp').value);
    fd.append('designation', document.getElementById('rptDesig').value);
    fd.append('hq', document.getElementById('rptHq').value);

    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if (d.status !== 'success') {
            alert(d.message || 'Failed');
            return;
        }
        reportData = d;
        renderSummary(d);
        renderMatrix(d);
    });
}

function renderSummary(d) {
    const tb = document.getElementById('summaryBody');
    if (!d.data.length) {
        tb.innerHTML = '<tr><td colspan="13" style="text-align:center;color:#94a3b8;">No employees found</td></tr>';
        return;
    }
    tb.innerHTML = d.data.map(r => {
        const s = r.summary;
        return `<tr>
            <td>${r.empcode}</td>
            <td><strong>${r.empname}</strong></td>
            <td>${r.designation||'-'}</td>
            <td>${r.hq||'-'}</td>
            <td style="color:#991b1b;font-weight:700;">${r.total_absent}</td>
            <td style="color:#991b1b;">${s.LWP||0}</td>
            <td style="color:#dc2626;font-weight:700;">${s.Sun||0}</td>
            <td style="color:#ff4500;font-weight:700;">${s.PH||0}</td>
            <td style="color:#0369a1;">${s.PL||0}</td>
            <td style="color:#0369a1;">${s.CL||0}</td>
            <td style="color:#0369a1;">${s.SL||0}</td>
            <td style="color:#065f46;font-weight:700;">${r.present_total}</td>
            <td><strong>${r.payable_days}</strong></td>
        </tr>`;
    }).join('');
}

function renderMatrix(d) {
    const days = d.days_in_month;
    const year = d.year;
    const month = d.month;
    const dayNames = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    // Row 1: Day Names (Su, Mo, Tu...)
    let headRow1 = '<tr><th class="name-col" rowspan="2">Employee</th>';
    // Row 2: Date Numbers (1, 2, 3...)
    let headRow2 = '<tr>';

    for (let i = 1; i <= days; i++) {
        const dateObj = new Date(year, month - 1, i);
        const dName = dayNames[dateObj.getDay()];
        const isSun = (dateObj.getDay() === 0);
        const hol = d.holidays[i];

        let thStyle = '';
        if (isSun) thStyle = 'background:#dc2626; color:#ffffff;';
        else if (hol) thStyle = 'background:#ff4500; color:#ffffff;';

        headRow1 += `<th style="${thStyle}">${dName}</th>`;
        headRow2 += `<th style="${thStyle}">${i}</th>`;
    }

    // Required trailing columns matching requested order sequence
    const trailingHeaders = ['Absent', 'LWP', 'Sun', 'PH', 'PL', 'Cl', 'SL', 'Present', 'Payab'];
    trailingHeaders.forEach(th => {
        headRow1 += `<th rowspan="2">${th}</th>`;
    });

    headRow1 += '</tr>';
    headRow2 += '</tr>';

    document.getElementById('matrixHead').innerHTML = headRow1 + headRow2;

    const tb = document.getElementById('matrixBody');
    if (!d.data.length) {
        tb.innerHTML = `<tr><td colspan="${days + trailingHeaders.length + 1}" style="text-align:center;color:#94a3b8;">No data</td></tr>`;
        return;
    }

    tb.innerHTML = d.data.map(r => {
        let cells = '';
        for (let i = 1; i <= days; i++) {
            const day = r.days[i] || {status:'Unmarked'};
            const dateObj = new Date(year, month - 1, i);
            const isSun = (dateObj.getDay() === 0);
            const isHol = !!d.holidays[i];

            const cls = statusClass(day.status, day.remarks, isSun, isHol);
            const short = statusShort(day.status, day.remarks, isSun, isHol);
            const tip = day.name || day.remarks || day.status;
            cells += `<td class="${cls}" title="${tip}">${short}</td>`;
        }
        const s = r.summary;
        return `<tr>
            <td class="name-col">${r.empname}</td>
            ${cells}
            <td style="font-weight:700;color:#991b1b;">${r.total_absent}</td>
            <td style="font-weight:700;color:#991b1b;">${s.LWP||0}</td>
            <td style="font-weight:700;color:#dc2626;">${s.Sun||0}</td>
            <td style="font-weight:700;color:#ff4500;">${s.PH||0}</td>
            <td style="font-weight:700;color:#0369a1;">${s.PL||0}</td>
            <td style="font-weight:700;color:#0369a1;">${s.CL||0}</td>
            <td style="font-weight:700;color:#0369a1;">${s.SL||0}</td>
            <td style="font-weight:700;color:#065f46;">${r.present_total}</td>
            <td style="font-weight:700;color:#1e2a78;">${r.payable_days}</td>
        </tr>`;
    }).join('');
}

function exportExcel() {
    if (!reportData || !reportData.data.length) {
        alert('Generate report first');
        return;
    }
    const d = reportData;
    let csv = 'Emp Code,Employee,Designation,HQ,Absent,LWP,Sun,PH,PL,Cl,SL,Present,Payab\n';
    d.data.forEach(r => {
        const s = r.summary;
        csv += `"${r.empcode}","${r.empname}","${r.designation||''}","${r.hq||''}",${r.total_absent},${s.LWP||0},${s.Sun||0},${s.PH||0},${s.PL||0},${s.CL||0},${s.SL||0},${r.present_total},${r.payable_days}\n`;
    });
    const blob = new Blob([csv], {type:'text/csv'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `Attendance_Summary_Report_${d.year}_${String(d.month).padStart(2,'0')}.csv`;
    a.click();
}

window.onload = () => { loadFilters(); loadReport(); };
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>