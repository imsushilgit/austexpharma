<?php
// Deep Buffer Output Initialization
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) { 
        while(ob_get_level()) ob_end_clean();
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]); 
        exit(); 
    }
    header("Location: ../auth/login.php"); exit();
}

require_once __DIR__ . '/../includes/core.php';

if (isset($_POST['ajax_action']) &&$_POST['ajax_action'] === 'fetch_emps') {
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

// HYPER-OPTIMIZED PRIMARY SALES ONLY ROLLUP FUNCTION
function getOptimizedPrimarySalesOnlyData($pdo, $fYear,$anchorCond, $params,$isManagerTeam = false, $mCode = '',$mName = '') {
    $fy_start = "$fYear-04-01";
    $fy_end = ($fYear + 1) . "-03-31";

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

    $report = [];
    $subToRoot = [];$month_keys = [4, 5, 6, 7, 8, 9, 10, 11, 12, 1, 2, 3];
    
    foreach ($employees as$e) {
        $root =$e['root_code'];
        $sub =$e['sub_code'];
        
        if (!isset($report[$root])) {
            $report[$root] = [
                'empcode' => $root, 'empname' => $e['root_name'], 'state' =>$e['root_state'],
                'hq' => $e['root_hq'], 'designation' =>$e['root_desig'],
                'months' => array_fill_keys($month_keys, 0)
            ];
        }
        if ($sub) {
            if (!isset($subToRoot[$sub])) $subToRoot[$sub] = [];
            if (!in_array($root,$subToRoot[$sub])) {$subToRoot[$sub][] =$root; }
        }
    }

    if(empty($report)) return [];

    $sqlInv = "
        WITH RawAgg AS (
            SELECT partyname, MONTH(invoice_date) AS mth, SUM(total_value) AS val
            FROM invoices WHERE invoice_date >= ? AND invoice_date <= ? GROUP BY partyname, MONTH(invoice_date)
        )
        SELECT COALESCE(ap.empcode, e.empcode) AS empcode, r.mth, SUM(r.val) AS val
        FROM RawAgg r
        LEFT JOIN assignparties ap ON TRIM(LOWER(r.partyname)) = TRIM(LOWER(ap.partyname))
        LEFT JOIN employees e ON TRIM(LOWER(r.partyname)) = TRIM(LOWER(e.empname))
        WHERE COALESCE(ap.empcode, e.empcode) IS NOT NULL
        GROUP BY COALESCE(ap.empcode, e.empcode), r.mth
    ";
    $stmtInv =$pdo->prepare($sqlInv);$stmtInv->execute([$fy_start,$fy_end]);
    while ($row =$stmtInv->fetch(PDO::FETCH_ASSOC)) {
        $sub =$row['empcode'];
        if (isset($subToRoot[$sub])) {
            foreach ($subToRoot[$sub] as$root) {
                $report[$root]['months'][$row['mth']] += (float)$row['val'];
            }
        }
    }

    usort($report, function($a,$b) { return strcmp($a['empname'],$b['empname']); });
    return array_values($report);
}

if (isset($_POST['ajax_action']) &&$_POST['ajax_action'] === 'fetch_team_primary_sales') {
    while(ob_get_level()) ob_end_clean(); error_reporting(0); header('Content-Type: application/json; charset=utf-8');
    if (!can_access_emp($ctx, trim($_POST['m_code'] ?? ''))) { echo json_encode(['status'=>'error','message'=>'Access Denied: this employee is outside your team.']); exit(); }
    try {
        $data = getOptimizedPrimarySalesOnlyData($pdo, intval($_POST['f_year'] ?? date('Y')), [], [], true, trim($_POST['m_code'] ?? ''), trim($_POST['m_name'] ?? ''));
        echo json_encode(["status" => "success", "data" => $data]);
    } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); } exit();
}

if (isset($_POST['ajax_action']) &&$_POST['ajax_action'] === 'fetch_primary_sales') {
    while(ob_get_level()) ob_end_clean(); error_reporting(0); header('Content-Type: application/json; charset=utf-8');
    $fYear = intval($_POST['f_year'] ?? date('Y'));
    $fDesig = trim($_POST['f_desig'] ?? 'MR');
    $fState = trim($_POST['f_state'] ?? '');
    $fEmp = trim($_POST['f_emp'] ?? '');

    $params = [];$anchorCond = [];
    // ROLE SCOPE: admin = all, manager = own team, MR = self
    $__sc = scope_cond($ctx, 'empcode', $params); if ($__sc !== '') { array_unshift($anchorCond, $__sc); }

    if (!empty($fEmp)) {$anchorCond[] = "empcode = ?"; $params[] =$fEmp; } 
    else {
        if ($fDesig === 'MR' || empty($fDesig)) {$anchorCond[] = "(designation = 'MR' OR designation IS NULL OR designation = '')"; } 
        else { $anchorCond[] = "designation = ?"; $params[] =$fDesig; }
    }
    if (!empty($fState)) {$anchorCond[] = "state = ?"; $params[] =$fState; }

    try {
        $data = getOptimizedPrimarySalesOnlyData($pdo,$fYear, $anchorCond,$params);
        echo json_encode(["status" => "success", "data" => $data]);
    } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); } exit();
}

$designations =$pdo->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation != '' ORDER BY designation ASC")->fetchAll(PDO::FETCH_COLUMN);
$states =$pdo->query("SELECT DISTINCT state FROM employees WHERE state IS NOT NULL AND state != '' ORDER BY state ASC")->fetchAll(PDO::FETCH_COLUMN);
$pageTitle = "Primary Sales Monthly Report (Values Only)";
include __DIR__ . '/../includes/header.php';
?>


<div class="filter-bar">
    <div class="form-group">
        <label>Financial Year</label>
        <select id="yearFilter">
            <?php $curr = date('Y');$m = date('n'); $y = ($m>=4)?$curr:$curr-1; 
            for($i=$y-1; $i<=$y+1; $i++) { echo "<option value='$i' ".($i==$y?'selected':'').">$i - ".substr($i+1,2,2)."</option>"; } ?>
        </select>
    </div>
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
            <thead id="tHead"></thead>
            <tbody id="tBody"><tr><td style="padding: 15px; text-align:center;">Loading Framework...</td></tr></tbody>
            <tfoot id="tFoot"></tfoot>
        </table>
    </div>
</div>

<script>
    const mKeys = [4,5,6,7,8,9,10,11,12,1,2,3];
    
    // Dynamic Month-Year label generation based on selected Financial Year
    function getMonthNames(fYear) {
        let nxtYear = fYear + 1;
        let y2 = String(fYear).slice(-2);
        let y3 = String(nxtYear).slice(-2);
        return {
            4: `Apr-${y2}`, 5: `May-${y2}`, 6: `Jun-${y2}`, 
            7: `Jul-${y2}`, 8: `Aug-${y2}`, 9: `Sep-${y2}`, 
            10: `Oct-${y2}`, 11: `Nov-${y2}`, 12: `Dec-${y2}`, 
            1: `Jan-${y3}`, 2: `Feb-${y3}`, 3: `Mar-${y3}`
        };
    }

    const blocks = [{key:'q1',label:'Q1',m:[4,5,6]}, {key:'q2',label:'Q2',m:[7,8,9]}, {key:'hly',label:'HLY',m:[4,5,6,7,8,9]}, {key:'q3',label:'Q3',m:[10,11,12]}, {key:'q4',label:'Q4',m:[1,2,3]}, {key:'yr',label:'Yearly Total',m:[4,5,6,7,8,9,10,11,12,1,2,3]}];
    let currentData = [];

    function fmt(n) { return Number(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}); }

    function buildHeaders() {
        let fYear = parseInt(document.getElementById('yearFilter').value);
        let mNames = getMonthNames(fYear);

        let r1 = `<tr><th class="sticky-col bg-main" style="left:0; min-width:60px;">Emp Code</th><th class="sticky-col bg-main" style="left:60px; min-width:140px;">Employee Name</th><th class="bg-main">HQ</th><th class="bg-main">State</th>`;
        mKeys.forEach(m => r1 += `<th class="bg-main">${mNames[m]}</th>`);
        blocks.forEach(b => r1 += `<th class="bg-main">${b.label}</th>`);
        r1 += `<th class="bg-main">YTD</th></tr>`;
        document.getElementById('tHead').innerHTML = r1;
    }

    function getYtdMonths(selYear) {
        let d = new Date(); let cM = d.getMonth()+1; let cY = d.getFullYear(); let ytd = [];
        if(cY > selYear+1) return mKeys; if(cY < selYear) return []; 
        for(let m of mKeys) { ytd.push(m); if(m===cM && ((cM>=4 && cY===selYear) || (cM<=3 && cY===selYear+1))) break; }
        return ytd;
    }

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

    function renderRows(data, ytdAllowed, isTeamRow=false, mCode='') {
        let html=''; let gt = {m:{}, b:{}, ytd:0};
        mKeys.forEach(k => gt.m[k] = 0); blocks.forEach(b => gt.b[b.key] = 0);
        let isManager = (!isTeamRow && document.getElementById('fDesig').value !== 'MR' && document.getElementById('fDesig').value !== '');

        data.forEach(r => {
            let clk = isManager ? `ondblclick="toggleTeam(this, '${r.empcode}', '${r.empname}')" style="cursor:pointer;" title="Double click to view team"` : '';
            let cls = isTeamRow ? `class="team-${mCode} sub-row"` : '';
            let bg = isTeamRow ? 'background:#eff6ff;' : '';
            
            html += `<tr ${cls} ${clk}>
                <td class="sticky-col" style="left:0; color:#1e2a78; ${isTeamRow?'border-left:2px dotted #3b82f6;':''}">${isTeamRow?'↳ ':''}${r.empcode||'-'}</td>
                <td class="sticky-col" style="left:60px; color:#1e2a78; ${bg}">${r.empname} ${isManager?' ⮟':''}</td>
                <td style="${bg}">${r.hq||'-'}</td><td style="${bg}">${r.state||'-'}</td>`;

            mKeys.forEach(k => {
                let a = r.months[k]; gt.m[k] += a;
                html += `<td class="val-act" style="${bg}">${fmt(a)}</td>`;
            });
            blocks.forEach(b => {
                let ba = 0; b.m.forEach(m => { ba += r.months[m]; }); gt.b[b.key] += ba;
                let cbg = isTeamRow ? ((b.key==='yr')?'background:#fef2f2;':'background:#dbeafe;') : ((b.key==='yr')?'background:#fef2f2;':'background:#f8fafc;');
                html += `<td class="val-act" style="${cbg}">${fmt(ba)}</td>`;
            });
            let ya = 0; ytdAllowed.forEach(m => { ya += r.months[m]; }); gt.ytd += ya;
            let ybg = isTeamRow ? 'background:#d1fae5;' : 'background:#ecfdf5;';
            html += `<td class="val-act" style="${ybg}">${fmt(ya)}</td></tr>`;
        });
        return {html, gt};
    }

    function fetchMatrix() {
        buildHeaders();
        const fd = new FormData(); fd.append('ajax_action', 'fetch_primary_sales'); fd.append('f_year', document.getElementById('yearFilter').value); fd.append('f_desig', document.getElementById('fDesig').value); fd.append('f_state', document.getElementById('fState').value); fd.append('f_emp', document.getElementById('fEmp').value);
        document.getElementById('tBody').innerHTML = `<tr><td colspan="30" style="text-align:center; padding: 15px;">Fast Aggregation in Progress...</td></tr>`;

        fetch(location.href+"?_nocache="+new Date().getTime(), {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            if (res.status === 'success') {
                currentData = res.data;
                if(res.data.length===0) { document.getElementById('tBody').innerHTML='<tr><td colspan="30" style="text-align:center; padding:15px;">No records found.</td></tr>'; document.getElementById('tFoot').innerHTML=''; return; }
                let ytdAllowed = getYtdMonths(parseInt(document.getElementById('yearFilter').value));
                let {html, gt} = renderRows(res.data, ytdAllowed, false);
                document.getElementById('tBody').innerHTML = html;

                let fHtml = `<tr><td class="sticky-col" style="left:0;" colspan="2">GRAND TOTAL</td><td>-</td><td>-</td>`;
                mKeys.forEach(k => fHtml += `<td class="val-act">${fmt(gt.m[k])}</td>`);
                blocks.forEach(b => {
                    let bg = (b.key==='yr')?'background:#fef2f2;':'';
                    fHtml += `<td class="val-act" style="${bg}">${fmt(gt.b[b.key])}</td>`;
                });
                fHtml += `<td class="val-act" style="background:#ecfdf5;">${fmt(gt.ytd)}</td></tr>`;
                document.getElementById('tFoot').innerHTML = fHtml;
            }
        });
    }

    function toggleTeam(row, mCode, mName) {
        let nR = row.nextElementSibling; let isExp = false;
        while(nR && nR.classList.contains(`team-${mCode}`)) { isExp=true; let tR=nR; nR=nR.nextElementSibling; tR.remove(); }
        if(isExp) return;
        
        let lR = document.createElement('tr'); lR.className=`team-${mCode} sub-row`; lR.innerHTML=`<td colspan="30" style="text-align:center; color:#3b82f6;">Loading Team...</td>`; row.parentNode.insertBefore(lR, row.nextSibling);
        
        const fd = new FormData(); fd.append('ajax_action', 'fetch_team_primary_sales'); fd.append('m_code', mCode); fd.append('m_name', mName); fd.append('f_year', document.getElementById('yearFilter').value);
        fetch(location.href+"?_nocache="+new Date().getTime(), {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            lR.remove();
            if(res.status==='success') {
                if(res.data.length===0) { row.insertAdjacentHTML('afterend', `<tr class="team-${mCode} sub-row"><td colspan="30" style="text-align:center; color:#64748b;">No active team members.</td></tr>`); return; }
                let {html} = renderRows(res.data.reverse(), getYtdMonths(parseInt(document.getElementById('yearFilter').value)), true, mCode);
                row.insertAdjacentHTML('afterend', html);
            }
        });
    }

    function exportToExcel() {
        if(currentData.length===0) return alert("No data");
        let tbl = document.getElementById("matrixTable").cloneNode(true);
        let a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([`<table border="1">${tbl.innerHTML}</table>`], {type:'application/vnd.ms-excel'})); a.download = `Primary_Sales_Values.xls`; a.click();
    }
    
    document.addEventListener("DOMContentLoaded", () => { loadEmployees(); fetchMatrix(); });
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>