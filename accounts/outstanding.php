<?php
ob_start();
// session_start() is removed here because core.php already handles secure sessions

require_once __DIR__ . '/../includes/core.php';

if (isset($_POST['ajax_action']) and $_POST['ajax_action'] === 'fetch_emps') {
    while(ob_get_level()) ob_end_clean(); 
    error_reporting(0); 
    header('Content-Type: application/json; charset=utf-8');
    
    $fDesig = trim($_POST['desig'] ?? '');
    $fState = trim($_POST['state'] ?? '');
    
    $conds = [];$params = [];
    if ($fDesig !== '') {
        if ($fDesig === 'MR') {$conds[] = "(designation = 'MR' OR designation IS NULL OR designation = '')";
        } else {
            $conds[] = "designation = ?"; $params[] =$fDesig;
        }
    }
    if ($fState !== '') {$conds[] = "state = ?"; $params[] =$fState;
    }
    
    // ROLE SCOPE: only employees inside the logged-in user's reporting line
    $__sc = scope_cond($ctx, 'empcode', $params); if ($__sc !== '') { $conds[] = $__sc; }
    $sql = "SELECT empcode, empname FROM employees";
    if (count($conds) > 0) {$sql .= " WHERE " . implode(" AND ", $conds); }$sql .= " ORDER BY empname ASC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

// HYPER-OPTIMIZED RAM-BASED ENGINE
function getOptimizedOutstandingData($pdo,$anchorCond, $params,$isManagerTeam = false, $mCode = '',$mName = '') {
    
    // 1. Fetch Hierarchy Context
    if ($isManagerTeam) {$sqlEmp = "
            WITH RECURSIVE Subordinates AS (
                SELECT empcode AS root_code, empname AS root_name, state AS root_state, hq AS root_hq, designation AS root_desig,
                       empcode, empname, reporting_manager, 1 AS depth
                FROM employees WHERE TRIM(LOWER(reporting_manager)) = TRIM(LOWER(?)) OR TRIM(LOWER(reporting_manager)) = TRIM(LOWER(?))
                UNION ALL
                SELECT s.root_code, s.root_name, s.root_state, s.root_hq, s.root_desig,
                       e.empcode, e.empname, e.reporting_manager, s.depth + 1
                FROM employees e
                INNER JOIN Subordinates s ON (TRIM(LOWER(e.reporting_manager)) = TRIM(LOWER(s.empcode)) OR TRIM(LOWER(e.reporting_manager)) = TRIM(LOWER(s.empname)))
                WHERE s.depth < 8 
            )
            SELECT root_code, root_name, root_state, root_hq, root_desig, empcode AS sub_code FROM Subordinates
        ";
        $stmtEmp =$pdo->prepare($sqlEmp);$stmtEmp->execute([$mCode,$mName]);
    } else {
        $anchorString = count($anchorCond) > 0 ? implode(" AND ", $anchorCond) : "1=1";
        $sqlEmp = "
            WITH RECURSIVE Subordinates AS (
                SELECT empcode AS root_code, empname AS root_name, state AS root_state, hq AS root_hq, designation AS root_desig,
                       empcode, empname, reporting_manager, 1 AS depth
                FROM employees WHERE $anchorString
                UNION ALL
                SELECT s.root_code, s.root_name, s.root_state, s.root_hq, s.root_desig,
                       e.empcode, e.empname, e.reporting_manager, s.depth + 1
                FROM employees e
                INNER JOIN Subordinates s ON (TRIM(LOWER(e.reporting_manager)) = TRIM(LOWER(s.empcode)) OR TRIM(LOWER(e.reporting_manager)) = TRIM(LOWER(s.empname)))
                WHERE s.depth < 8 
            )
            SELECT root_code, root_name, root_state, root_hq, root_desig, empcode AS sub_code FROM Subordinates
        ";
        $stmtEmp = $pdo->prepare($sqlEmp);
        $stmtEmp->execute($params);
    }
    
    $employees =$stmtEmp->fetchAll(PDO::FETCH_ASSOC);
    $subToRoot = [];$employeeMeta = [];
    
    foreach ($employees as$e) {
        $root =$e['root_code'];
        $sub =$e['sub_code'];
        $employeeMeta[$root] = [
            'empcode' => $root, 'empname' =>$e['root_name'], 
            'state' => $e['root_state'], 'hq' =>$e['root_hq']
        ];
        if ($sub) {
            if (!isset($subToRoot[$sub])) { $subToRoot[$sub] = []; }
            if (!in_array($root,$subToRoot[$sub])) {$subToRoot[$sub][] =$root; }
        }
    }

    if (empty($employeeMeta)) { return []; }

    // 2. Build Fast In-Memory String Maps (Replaces Slow SQL Joins)
    $apMap = [];
    $stmtAP =$pdo->query("SELECT empcode, partyname FROM assignparties");
    while ($row = $stmtAP->fetch(PDO::FETCH_ASSOC)) {$apMap[strtolower(trim($row['partyname']))] =$row['empcode']; }

    $empMap = [];
    $stmtE =$pdo->query("SELECT empcode, empname FROM employees");
    while ($row = $stmtE->fetch(PDO::FETCH_ASSOC)) {$empMap[strtolower(trim($row['empname']))] =$row['empcode']; }

    // 3. Load Collections (Pre-Aggregated)
    $cols = [];
    $stmtC =$pdo->query("SELECT partyname, SUM(amount) AS paid FROM collections GROUP BY partyname");
    while ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) {$cols[strtolower(trim($row['partyname']))] = (float)$row['paid']; }

    // 4. Load Invoices (Grouped to reduce iterations, Ordered for FIFO)
    $stmtI =$pdo->query("SELECT partyname, invoice_date, SUM(total_value) AS val FROM invoices GROUP BY partyname, invoice_date ORDER BY invoice_date ASC");

    $report = [];$today = new DateTime();

    // 5. High-Speed FIFO Calculation in RAM
    while ($inv =$stmtI->fetch(PDO::FETCH_ASSOC)) {
        $pNameRaw =$inv['partyname'];
        $pKey = strtolower(trim($pNameRaw));
        
        // Instant Map Lookup
        $sub = $apMap[$pKey] ?? ($empMap[$pKey] ?? null);
        
        if (!$sub || !isset($subToRoot[$sub])) { continue; }

        $invVal = (float)$inv['val'];$paidSoFar = $cols[$pKey] ?? 0;

        // FIFO Deduction
        if ($paidSoFar >= $invVal) {$cols[$pKey] -=$invVal;
            continue;
        }

        $balance = $invVal -$paidSoFar;
        $cols[$pKey] = 0; // Exhausted

        $invDate = new DateTime($inv['invoice_date']);$daysOld = $today->diff($invDate)->days;

        // Roll up to all relevant Managers in hierarchy
        foreach ($subToRoot[$sub] as $root) {$key = $root . '_' .$pKey;
            if (!isset($report[$key])) {
                $report[$key] = [
                    'empcode' => $root,
                    'empname' => $employeeMeta[$root]['empname'],
                    'hq' => $employeeMeta[$root]['hq'],
                    'state' => $employeeMeta[$root]['state'],
                    'partyname' => $pNameRaw,
                    'total_outstanding' => 0,
                    'b30' => 0, 'b60' => 0, 'b90' => 0, 'b180' => 0, 'b_above' => 0
                ];
            }

            $report[$key]['total_outstanding'] +=$balance;
            if ($daysOld <= 30) {$report[$key]['b30'] +=$balance; }
            elseif ($daysOld <= 60) {$report[$key]['b60'] +=$balance; }
            elseif ($daysOld <= 90) {$report[$key]['b90'] +=$balance; }
            elseif ($daysOld <= 180) {$report[$key]['b180'] +=$balance; }
            else { $report[$key]['b_above'] +=$balance; }
        }
    }

    usort($report, function($a, $b) {$cmp = strcmp($a['empname'],$b['empname']);
        if ($cmp === 0) { return strcmp($a['partyname'],$b['partyname']); }
        return $cmp;
    });

    return array_values($report);
}

if (isset($_POST['ajax_action']) and$_POST['ajax_action'] === 'fetch_team_outstanding') {
    while(ob_get_level()) ob_end_clean(); error_reporting(0); header('Content-Type: application/json; charset=utf-8');
    if (!can_access_emp($ctx, trim($_POST['m_code'] ?? ''))) { echo json_encode(['status'=>'error','message'=>'Access Denied: this employee is outside your team.']); exit(); }
    try {
        $data = getOptimizedOutstandingData($pdo, [], [], true, trim($_POST['m_code'] ?? ''), trim($_POST['m_name'] ?? ''));
        echo json_encode(["status" => "success", "data" => $data]);
    } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); } exit();
}

if (isset($_POST['ajax_action']) and$_POST['ajax_action'] === 'fetch_outstanding') {
    while(ob_get_level()) ob_end_clean(); error_reporting(0); header('Content-Type: application/json; charset=utf-8');
    $fDesig = trim($_POST['f_desig'] ?? 'MR');
    $fState = trim($_POST['f_state'] ?? '');
    $fEmp = trim($_POST['f_emp'] ?? '');

    $params = [];$anchorCond = [];
    // ROLE SCOPE: admin = all, manager = own team, MR = self
    $__sc = scope_cond($ctx, 'empcode', $params); if ($__sc !== '') { array_unshift($anchorCond, $__sc); }

    if (!empty($fEmp)) {$anchorCond[] = "empcode = ?"; $params[] =$fEmp; } 
    else {
        if ($fDesig === 'MR' or empty($fDesig)) {$anchorCond[] = "(designation = 'MR' OR designation IS NULL OR designation = '')"; } 
        else { $anchorCond[] = "designation = ?"; $params[] =$fDesig; }
    }
    if (!empty($fState)) {$anchorCond[] = "state = ?"; $params[] =$fState; }

    try {
        $data = getOptimizedOutstandingData($pdo, $anchorCond,$params);
        echo json_encode(["status" => "success", "data" => $data]);
    } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); } exit();
}

$designations =$pdo->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation != '' ORDER BY designation ASC")->fetchAll(PDO::FETCH_COLUMN);
$states =$pdo->query("SELECT DISTINCT state FROM employees WHERE state IS NOT NULL AND state != '' ORDER BY state ASC")->fetchAll(PDO::FETCH_COLUMN);
$pageTitle = "Outstanding Receivables & Ageing Matrix";
include __DIR__ . '/../includes/header.php';
?>


<div class="filter-bar">
    <div class="form-group">
        <label>Hierarchy / Role</label>
        <select id="fDesig" onchange="loadEmployees()">
            <option value="MR" selected>MR (Individual)</option>
            <?php foreach($designations as $d): if($d==='MR') continue; ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?> (Team Rollup)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>State Filter</label>
        <select id="fState" onchange="loadEmployees()">
            <option value="">All States</option>
            <?php foreach($states as$s): ?>
                <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="flex: 1.5;">
        <label>Employee Name</label>
        <select id="fEmp">
            <option value="">-- View All Under Selection --</option>
        </select>
    </div>
    <div style="display: flex; gap: 8px; margin-left: auto; align-items: flex-end;">
        <button class="btn-fetch" onclick="fetchMatrix()">Generate Report</button>
        <button class="btn-export" onclick="exportToExcel()">Export Excel</button>
    </div>
</div>

<div class="panel">
    <div class="trend-table-wrapper" id="tableWrapper">
        <table class="compact-table" id="matrixTable">
            <thead id="tHead">
                <tr>
                    <th class="sticky-col bg-main" style="left:0; min-width:60px;">Emp Code</th>
                    <th class="sticky-col bg-main" style="left:60px; min-width:140px;">Employee Name</th>
                    <th class="bg-main">HQ</th>
                    <th class="bg-main" style="min-width:150px;">Party Name</th>
                    <th class="bg-age1">0 - 30 Days</th>
                    <th class="bg-age2">31 - 60 Days</th>
                    <th class="bg-age3">61 - 90 Days</th>
                    <th class="bg-age4">91 - 180 Days</th>
                    <th class="bg-age4">Above 180 Days</th>
                    <th class="bg-ytd">Total Outstanding</th>
                </tr>
            </thead>
            <tbody id="tBody"><tr><td colspan="10" style="padding: 15px; text-align:center;">Loading Framework...</td></tr></tbody>
            <tfoot id="tFoot"></tfoot>
        </table>
    </div>
</div>

<script>
    let currentData = [];

    function fmt(n) { return Number(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}); }

    function loadEmployees() {
        const fd = new FormData(); fd.append('ajax_action', 'fetch_emps'); fd.append('desig', document.getElementById('fDesig').value); fd.append('state', document.getElementById('fState').value);
        document.getElementById('fEmp').innerHTML = '<option value="">Loading...</option>';
        fetch(location.href+"?_nocache="+new Date().getTime(), {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            if(res.status==='success') {
                let h = '<option value="">-- View All Under Selection --</option>';
                res.data.forEach(e => h+=`<option value="${e.empcode}">${e.empname} (${e.empcode})</option>`);
                document.getElementById('fEmp').innerHTML = h;
            }
        });
    }

    function renderRows(data, isTeamRow=false, mCode='') {
        let html=''; let gt = {b30:0, b60:0, b90:0, b180:0, b_above:0, tot:0};
        let isManager = (!isTeamRow && document.getElementById('fDesig').value !== 'MR' && document.getElementById('fDesig').value !== '');

        let renderData = data;
        if(isManager && !isTeamRow) {
            let grouped = {};
            data.forEach(r => {
                if(!grouped[r.empcode]) {
                    grouped[r.empcode] = { empcode:r.empcode, empname:r.empname, hq:r.hq, state:r.state, partyname:'TEAM ROLLUP', b30:0, b60:0, b90:0, b180:0, b_above:0, total_outstanding:0 };
                }
                grouped[r.empcode].b30 += r.b30; grouped[r.empcode].b60 += r.b60; grouped[r.empcode].b90 += r.b90;
                grouped[r.empcode].b180 += r.b180; grouped[r.empcode].b_above += r.b_above; grouped[r.empcode].total_outstanding += r.total_outstanding;
            });
            renderData = Object.values(grouped);
        }

        renderData.forEach(r => {
            if(r.total_outstanding <= 0) return; 
            
            gt.b30 += r.b30; gt.b60 += r.b60; gt.b90 += r.b90; gt.b180 += r.b180; gt.b_above += r.b_above; gt.tot += r.total_outstanding;
            
            let clk = (isManager && !isTeamRow) ? `ondblclick="toggleTeam(this, '${r.empcode}', '${r.empname}')" style="cursor:pointer;" title="Double click to view party breakdown"` : '';
            let cls = isTeamRow ? `class="team-${mCode} sub-row"` : '';
            let bg = isTeamRow ? 'background:#eff6ff;' : '';
            
            html += `<tr ${cls} ${clk}>
                <td class="sticky-col" style="left:0; color:#1e2a78; ${isTeamRow?'border-left:2px dotted #3b82f6;':''}">${isTeamRow?'↳ ':''}${r.empcode||'-'}</td>
                <td class="sticky-col" style="left:60px; color:#1e2a78; ${bg}">${r.empname} ${(isManager&&!isTeamRow)?' ⮟':''}</td>
                <td style="${bg}">${r.hq||'-'}</td><td style="${bg}; font-weight: ${isTeamRow?'normal':'bold'};">${r.partyname||'-'}</td>`;

            html += `<td class="val-act" style="${bg}">${fmt(r.b30)}</td>`;
            html += `<td class="val-act" style="${bg}">${fmt(r.b60)}</td>`;
            html += `<td class="val-danger" style="${bg}">${fmt(r.b90)}</td>`;
            html += `<td class="val-danger" style="${bg}; background:#fef2f2;">${fmt(r.b180)}</td>`;
            html += `<td class="val-danger" style="${bg}; background:#fef2f2;">${fmt(r.b_above)}</td>`;
            html += `<td class="val-act" style="${bg}; background:#f8fafc; color:#0f172a;">${fmt(r.total_outstanding)}</td></tr>`;
        });
        return {html, gt};
    }

    function fetchMatrix() {
        const fd = new FormData(); fd.append('ajax_action', 'fetch_outstanding'); fd.append('f_desig', document.getElementById('fDesig').value); fd.append('f_state', document.getElementById('fState').value); fd.append('f_emp', document.getElementById('fEmp').value);
        document.getElementById('tBody').innerHTML = `<tr><td colspan="10" style="text-align:center; padding: 15px;">Fast Aggregation in Progress...</td></tr>`;

        fetch(location.href+"?_nocache="+new Date().getTime(), {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            if (res.status === 'success') {
                currentData = res.data;
                let {html, gt} = renderRows(res.data, false);
                if(html === '') { html = '<tr><td colspan="10" style="text-align:center; padding:15px;">No outstanding balances found.</td></tr>'; gt = {b30:0,b60:0,b90:0,b180:0,b_above:0,tot:0}; }
                document.getElementById('tBody').innerHTML = html;

                let fHtml = `<tr><td class="sticky-col" style="left:0;" colspan="2">GRAND TOTAL</td><td>-</td><td>-</td>`;
                fHtml += `<td class="val-act">${fmt(gt.b30)}</td><td class="val-act">${fmt(gt.b60)}</td><td class="val-danger">${fmt(gt.b90)}</td>`;
                fHtml += `<td class="val-danger" style="background:#fef2f2;">${fmt(gt.b180)}</td><td class="val-danger" style="background:#fef2f2;">${fmt(gt.b_above)}</td>`;
                fHtml += `<td class="val-act" style="background:#f8fafc; color:#0f172a;">${fmt(gt.tot)}</td></tr>`;
                document.getElementById('tFoot').innerHTML = fHtml;
            }
        });
    }

    function toggleTeam(row, mCode, mName) {
        let nR = row.nextElementSibling; let isExp = false;
        while(nR && nR.classList.contains(`team-${mCode}`)) { isExp=true; let tR=nR; nR=nR.nextElementSibling; tR.remove(); }
        if(isExp) return;
        
        let lR = document.createElement('tr'); lR.className=`team-${mCode} sub-row`; lR.innerHTML=`<td colspan="10" style="text-align:center; color:#3b82f6;">Loading Party Breakdown...</td>`; row.parentNode.insertBefore(lR, row.nextSibling);
        
        const fd = new FormData(); fd.append('ajax_action', 'fetch_team_outstanding'); fd.append('m_code', mCode); fd.append('m_name', mName);
        fetch(location.href+"?_nocache="+new Date().getTime(), {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            lR.remove();
            if(res.status==='success') {
                let {html} = renderRows(res.data, true, mCode);
                if(html === '') html = `<tr class="team-${mCode} sub-row"><td colspan="10" style="text-align:center; color:#64748b;">No outstanding records found.</td></tr>`;
                row.insertAdjacentHTML('afterend', html);
            }
        });
    }

    function exportToExcel() {
        if(currentData.length===0) return alert("No data");
        let tbl = document.getElementById("matrixTable").cloneNode(true);
        let a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([`<table border="1">${tbl.innerHTML}</table>`], {type:'application/vnd.ms-excel'})); a.download = `Outstanding_Ageing_Report.xls`; a.click();
    }
    
    document.addEventListener("DOMContentLoaded", () => { loadEmployees(); fetchMatrix(); });
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>