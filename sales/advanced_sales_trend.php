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

// AJAX: Fetch Employees based on Designation and State filters
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'fetch_emps') {
    while(ob_get_level()) ob_end_clean(); 
    error_reporting(0); 
    header('Content-Type: application/json; charset=utf-8');
    
    $fDesig = trim($_POST['desig'] ?? '');
    $fState = trim($_POST['state'] ?? '');
    
    $conds = []; $params = [];
    if ($fDesig !== '') {
        if ($fDesig === 'MR') {
            $conds[] = "(designation = 'MR' OR designation IS NULL OR designation = '')";
        } else {
            $conds[] = "designation = ?"; $params[] = $fDesig;
        }
    }
    if ($fState !== '') {
        $conds[] = "state = ?"; $params[] = $fState;
    }
    
    // ROLE SCOPE: only employees inside the logged-in user's reporting line
    $__sc = scope_cond($ctx, 'empcode', $params); if ($__sc !== '') { $conds[] = $__sc; }
    $sql = "SELECT empcode, empname FROM employees";
    if (count($conds) > 0) { $sql .= " WHERE " . implode(" AND ", $conds); }
    $sql .= " ORDER BY empname ASC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

// AJAX: Fetch Manager's Team (Subordinates) Report on Double Click
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'fetch_team_sales') {
    while(ob_get_level()) ob_end_clean(); 
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');
    if (!can_access_emp($ctx, trim($_POST['m_code'] ?? ''))) { 
        echo json_encode(['status'=>'error','message'=>'Access Denied: this employee is outside your team.']); 
        exit(); 
    }
    
    $mCode = trim($_POST['m_code'] ?? '');
    $mName = trim($_POST['m_name'] ?? '');
    $fromDate = $_POST['from_date'] ?? date('Y-m-01');
    $toDate = $_POST['to_date'] ?? date('Y-m-t');
    
    $start_my = (int)date('Ym', strtotime($fromDate));
    $end_my = (int)date('Ym', strtotime($toDate));
    
    try {
        $sql = "
            WITH RECURSIVE Subordinates AS (
                SELECT empcode AS root_code, empname AS root_name, state AS root_state, hq AS root_hq, designation AS root_desig,
                       empcode, empname, reporting_manager, 1 AS depth
                FROM employees WHERE TRIM(reporting_manager) = ? OR TRIM(reporting_manager) = ?
                UNION ALL
                SELECT s.root_code, s.root_name, s.root_state, s.root_hq, s.root_desig,
                       e.empcode, e.empname, e.reporting_manager, s.depth + 1
                FROM employees e
                INNER JOIN Subordinates s ON (TRIM(e.reporting_manager) = TRIM(s.empcode) OR TRIM(e.reporting_manager) = TRIM(s.empname))
                WHERE s.depth < 8
            ),
            TargetData AS (
                SELECT s.root_code, SUM(t.sales_target) AS tgt
                FROM targets t
                JOIN Subordinates s ON t.empcode = s.empcode
                WHERE (t.target_year * 100 + t.target_month) BETWEEN ? AND ?
                GROUP BY s.root_code
            ),
            InvoiceRaw AS (
                SELECT COALESCE(ap.empcode, e.empcode) AS empcode, SUM(i.total_value) AS act
                FROM invoices i
                LEFT JOIN assignparties ap ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(ap.partyname))
                LEFT JOIN employees e ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(e.empname))
                WHERE i.invoice_date >= ? AND i.invoice_date <= ?
                GROUP BY COALESCE(ap.empcode, e.empcode)
            ),
            InvoiceData AS (
                SELECT s.root_code, SUM(inv.act) AS act
                FROM InvoiceRaw inv
                JOIN Subordinates s ON inv.empcode = s.empcode
                GROUP BY s.root_code
            )
            SELECT 
                sub.root_code AS empcode, sub.root_name AS empname, sub.root_state AS state, sub.root_hq AS hq, sub.root_desig AS designation,
                COALESCE(t.tgt, 0) AS target,
                COALESCE(i.act, 0) AS total_sales
            FROM (SELECT DISTINCT root_code, root_name, root_state, root_hq, root_desig FROM Subordinates) sub
            LEFT JOIN TargetData t ON sub.root_code = t.root_code
            LEFT JOIN InvoiceData i ON sub.root_code = i.root_code
            ORDER BY sub.root_name ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$mCode, $mName, $start_my, $end_my, $fromDate, $toDate]);
        $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reportData = [];
        foreach ($rawData as $row) {
            $tgt = (float)$row['target'];
            $sales = (float)$row['total_sales'];
            $ach_pct = $tgt > 0 ? round(($sales / $tgt) * 100, 1) : ($sales > 0 ? 100 : 0);
            $diff = $sales - $tgt;

            if ($tgt > 0 || $sales > 0) {
                $reportData[] = [
                    'empcode' => $row['empcode'], 'empname' => $row['empname'],
                    'hq' => $row['hq'], 'designation' => $row['designation'],
                    'target' => $tgt, 'total_sales' => $sales,
                    'diff' => $diff, 'ach_pct' => $ach_pct
                ];
            }
        }
        echo json_encode(["status" => "success", "data" => $reportData]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

// AJAX: Fetch Main Report
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'fetch_sales_trend') {
    while(ob_get_level()) ob_end_clean(); 
    error_reporting(0);
    header('Content-Type: application/json; charset=utf-8');
    
    $fromDate = $_POST['from_date'] ?? date('Y-m-01');
    $toDate = $_POST['to_date'] ?? date('Y-m-t');
    $fDesig = trim($_POST['f_desig'] ?? 'MR');
    $fState = trim($_POST['f_state'] ?? '');
    $fEmp = trim($_POST['f_emp'] ?? '');

    if (empty($fromDate)) $fromDate = date('Y-m-01');
    if (empty($toDate)) $toDate = date('Y-m-t');

    $start_my = (int)date('Ym', strtotime($fromDate));
    $end_my = (int)date('Ym', strtotime($toDate));

    $params = [];
    $anchorCond = [];
    
    // ROLE SCOPE: admin = all, manager = own team, MR = self
    $__sc = scope_cond($ctx, 'empcode', $params); if ($__sc !== '') { array_unshift($anchorCond, $__sc); }

    if (!empty($fEmp)) {
        $anchorCond[] = "empcode = ?";
        $params[] = $fEmp;
    } else {
        if ($fDesig === 'MR' || empty($fDesig)) {
            $anchorCond[] = "(designation = 'MR' OR designation IS NULL OR designation = '')";
        } else {
            $anchorCond[] = "designation = ?";
            $params[] = $fDesig;
        }
    }
    
    if (!empty($fState)) {
        $anchorCond[] = "state = ?";
        $params[] = $fState;
    }
    
    $anchorString = count($anchorCond) > 0 ? implode(" AND ", $anchorCond) : "1=1";

    try {
        $sql = "
            WITH RECURSIVE Subordinates AS (
                SELECT empcode AS root_code, empname AS root_name, state AS root_state, hq AS root_hq, designation AS root_desig,
                       empcode, empname, reporting_manager, 1 AS depth
                FROM employees WHERE $anchorString
                UNION ALL
                SELECT s.root_code, s.root_name, s.root_state, s.root_hq, s.root_desig,
                       e.empcode, e.empname, e.reporting_manager, s.depth + 1
                FROM employees e
                INNER JOIN Subordinates s ON (TRIM(e.reporting_manager) = TRIM(s.empcode) OR TRIM(e.reporting_manager) = TRIM(s.empname))
                WHERE s.depth < 8
            ),
            TargetData AS (
                SELECT s.root_code, SUM(t.sales_target) AS tgt
                FROM targets t
                JOIN Subordinates s ON t.empcode = s.empcode
                WHERE (t.target_year * 100 + t.target_month) BETWEEN ? AND ?
                GROUP BY s.root_code
            ),
            InvoiceRaw AS (
                SELECT COALESCE(ap.empcode, e.empcode) AS empcode, SUM(i.total_value) AS act
                FROM invoices i
                LEFT JOIN assignparties ap ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(ap.partyname))
                LEFT JOIN employees e ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(e.empname))
                WHERE i.invoice_date >= ? AND i.invoice_date <= ?
                GROUP BY COALESCE(ap.empcode, e.empcode)
            ),
            InvoiceData AS (
                SELECT s.root_code, SUM(inv.act) AS act
                FROM InvoiceRaw inv
                JOIN Subordinates s ON inv.empcode = s.empcode
                GROUP BY s.root_code
            )
            SELECT 
                sub.root_code AS empcode, sub.root_name AS empname, sub.root_state AS state, sub.root_hq AS hq, sub.root_desig AS designation,
                COALESCE(t.tgt, 0) AS target,
                COALESCE(i.act, 0) AS total_sales
            FROM (SELECT DISTINCT root_code, root_name, root_state, root_hq, root_desig FROM Subordinates) sub
            LEFT JOIN TargetData t ON sub.root_code = t.root_code
            LEFT JOIN InvoiceData i ON sub.root_code = i.root_code
            ORDER BY sub.root_name ASC
        ";

        $execParams = array_merge($params, [$start_my, $end_my, $fromDate, $toDate]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($execParams);
        $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reportData = [];
        foreach ($rawData as $row) {
            $tgt = (float)$row['target'];
            $sales = (float)$row['total_sales'];
            $ach_pct = $tgt > 0 ? round(($sales / $tgt) * 100, 1) : ($sales > 0 ? 100 : 0);
            $diff = $sales - $tgt;

            if ($tgt > 0 || $sales > 0) {
                $reportData[] = [
                    'empcode' => $row['empcode'],
                    'empname' => $row['empname'],
                    'hq' => $row['hq'],
                    'designation' => $row['designation'],
                    'target' => $tgt,
                    'total_sales' => $sales,
                    'diff' => $diff,
                    'ach_pct' => $ach_pct
                ];
            }
        }

        echo json_encode(["status" => "success", "data" => $reportData]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Sys Error: " . $e->getMessage()]);
    }
    exit();
}

$designations = $pdo->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation != '' ORDER BY designation ASC")->fetchAll(PDO::FETCH_COLUMN);
$states = $pdo->query("SELECT DISTINCT state FROM employees WHERE state IS NOT NULL AND state != '' ORDER BY state ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = "Advanced Sales Trend Analytics";
include __DIR__ . '/../includes/header.php';
?>


<div class="filter-panel">
    <div class="form-group">
        <label>From Date</label>
        <input type="date" id="fromDate" value="<?= date('Y-m-01') ?>">
    </div>
    <div class="form-group">
        <label>To Date</label>
        <input type="date" id="toDate" value="<?= date('Y-m-t') ?>">
    </div>
    <div class="form-group">
        <label>Designation / Role</label>
        <select id="fDesig" onchange="loadEmployees()">
            <option value="MR" selected>MR (Default)</option>
            <?php foreach($designations as $d): if($d==='MR') continue; ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?> (Cumulative Rollup)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>State Filter</label>
        <select id="fState" onchange="loadEmployees()">
            <option value="">All States</option>
            <?php foreach($states as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="flex: 1.5;">
        <label>Employee Name (Optional)</label>
        <select id="fEmp">
            <option value="">-- View All Under Selection --</option>
        </select>
    </div>
    <div class="btn-action-group">
        <button class="btn-modern btn-fetch" onclick="fetchReport()">Generate Report</button>
        <button class="btn-modern btn-export" onclick="exportToExcel()">Export Excel</button>
    </div>
</div>

<div class="data-card">
    <div class="table-responsive">
        <table class="pro-table" id="reportTable">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;">Sl No.</th>
                    <th>Employee Code</th>
                    <th>Employee Name</th>
                    <th>Designation</th>
                    <th>HQ</th>
                    <th style="text-align: right;">Target Value (₹)</th>
                    <th style="text-align: right;">Sales As On Date (₹)</th>
                    <th style="text-align: right;">Target Sales Diff. Value (₹)</th>
                    <th style="text-align: center;">Achieved %age</th>
                </tr>
            </thead>
            <tbody id="tBody">
                <tr><td colspan="9" style="padding: 15px; text-align:center; color: var(--text-muted); font-size: 11px;">Select parameters and click Generate Report</td></tr>
            </tbody>
            <tfoot id="tFoot"></tfoot>
        </table>
    </div>
</div>

<script>
    let currentData = [];

    function fmtMoney(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    
    function getBadgeClass(pct) {
        let val = parseFloat(pct) || 0;
        if (val >= 100) return 'pct-high';     
        if (val >= 85) return 'pct-mid';       
        if (val >= 75) return 'pct-sky';       
        return 'pct-low';                      
    }

    function loadEmployees() {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_emps');
        fd.append('desig', document.getElementById('fDesig').value);
        fd.append('state', document.getElementById('fState').value);

        const empDropdown = document.getElementById('fEmp');
        empDropdown.innerHTML = '<option value="">Loading...</option>';

        fetch(window.location.href + "?_nocache=" + new Date().getTime(), { method: 'POST', body: fd })
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } catch(e) { throw new Error(text.substring(0,100)); }
        })
        .then(res => {
            if(res.status === 'success') {
                let html = '<option value="">-- View All Under Selection --</option>';
                res.data.forEach(e => {
                    html += `<option value="${e.empcode}">${e.empname} (${e.empcode})</option>`;
                });
                empDropdown.innerHTML = html;
            }
        }).catch(err => {
            empDropdown.innerHTML = '<option value="">Error Loading Employees</option>';
        });
    }

    function fetchReport() {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_sales_trend');
        fd.append('from_date', document.getElementById('fromDate').value);
        fd.append('to_date', document.getElementById('toDate').value);
        fd.append('f_desig', document.getElementById('fDesig').value);
        fd.append('f_state', document.getElementById('fState').value);
        fd.append('f_emp', document.getElementById('fEmp').value);

        const tbody = document.getElementById('tBody');
        const tfoot = document.getElementById('tFoot');
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding: 15px; color:var(--primary);">Processing Sales Metrics...</td></tr>`;
        tfoot.innerHTML = '';

        fetch(window.location.href + "?_nocache=" + new Date().getTime(), { method: 'POST', body: fd })
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } 
            catch(e) { 
                console.error("Backend Error Details:", text);
                throw new Error("Invalid format received. Raw response: " + text.substring(0, 150)); 
            }
        })
        .then(res => {
            if (res.status === 'success') {
                currentData = res.data;
                if (currentData.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 15px; color:#ef4444;">No data found for the selected parameters.</td></tr>';
                    return;
                }

                let html = '';
                let t_tgt = 0, t_sales = 0;

                currentData.forEach((r, idx) => {
                    t_tgt += r.target; 
                    t_sales += r.total_sales;

                    let badgeClass = getBadgeClass(r.ach_pct);
                    let diffHtml = '';
                    if (r.diff < 0) {
                        diffHtml = `<span class="diff-short">▼ ${fmtMoney(Math.abs(r.diff))}</span>`;
                    } else if (r.diff > 0) {
                        diffHtml = `<span class="diff-excess">▲ +${fmtMoney(r.diff)}</span>`;
                    } else {
                        diffHtml = `<span style="color:var(--text-muted);">0.00</span>`;
                    }

                    let isManager = (document.getElementById('fDesig').value !== 'MR' && document.getElementById('fDesig').value !== '');
                    let clickAttr = isManager ? `ondblclick="toggleTeam(this, '${r.empcode}', '${r.empname}')" style="cursor:pointer;" title="Double click to view team"` : '';

                    html += `<tr ${clickAttr}>
                        <td class="col-id">${idx+1}</td>
                        <td class="col-empcode">${r.empcode || '-'}</td>
                        <td class="col-empname">${r.empname} ${isManager ? ' ⮟' : ''}</td>
                        <td>${r.designation}</td>
                        <td>${r.hq || '-'}</td>
                        <td class="col-money target-val">${fmtMoney(r.target)}</td>
                        <td class="col-money sales-val">${fmtMoney(r.total_sales)}</td>
                        <td class="col-money">${diffHtml}</td>
                        <td class="pct-wrapper"><div class="pct-badge ${badgeClass}">${r.ach_pct}%</div></td>
                    </tr>`;
                });
                
                tbody.innerHTML = html;

                let gt_diff = t_sales - t_tgt;
                let gt_pct = t_tgt > 0 ? ((t_sales/t_tgt)*100).toFixed(1) : 0;
                let gtBadge = getBadgeClass(gt_pct);
                
                let gtDiffHtml = gt_diff < 0 ? `<span class="diff-short">▼ ${fmtMoney(Math.abs(gt_diff))}</span>` : (gt_diff > 0 ? `<span class="diff-excess">▲ +${fmtMoney(gt_diff)}</span>` : "0.00");

                tfoot.innerHTML = `<tr class="footer-row">
                    <td colspan="5" style="text-align:right; font-size:11px; color:var(--primary);">GRAND TOTAL</td>
                    <td class="col-money" style="color:#0f172a;">${fmtMoney(t_tgt)}</td>
                    <td class="col-money" style="color:#1d4ed8;">${fmtMoney(t_sales)}</td>
                    <td class="col-money">${gtDiffHtml}</td>
                    <td class="pct-wrapper"><div class="pct-badge ${gtBadge}">${gt_pct}%</div></td>
                </tr>`;

            } else {
                tbody.innerHTML = `<tr><td colspan="9" style="color:red; text-align:center; padding:15px;">${res.message}</td></tr>`;
            }
        }).catch(err => {
            console.error(err);
            tbody.innerHTML = `<tr><td colspan="9" style="color:red; text-align:center; padding:15px;">Error Occurred: ${err.message}</td></tr>`;
        });
    }

    function toggleTeam(row, empCode, empName) {
        let nextRow = row.nextElementSibling;
        let isExpanded = false;
        
        while (nextRow && nextRow.classList.contains(`team-${empCode}`)) {
            isExpanded = true;
            let toRemove = nextRow;
            nextRow = nextRow.nextElementSibling;
            toRemove.remove();
        }
        if (isExpanded) return;
        
        let loadingRow = document.createElement('tr');
        loadingRow.className = `team-${empCode} sub-row`;
        loadingRow.innerHTML = `<td colspan="9" style="text-align:center; color:#3b82f6;">Loading team data for ${empName}...</td>`;
        row.parentNode.insertBefore(loadingRow, row.nextSibling);
        
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_team_sales');
        fd.append('m_code', empCode);
        fd.append('m_name', empName);
        fd.append('from_date', document.getElementById('fromDate').value);
        fd.append('to_date', document.getElementById('toDate').value);
        
        fetch(window.location.href + "?_nocache=" + new Date().getTime(), { method: 'POST', body: fd })
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } catch(e) { throw new Error(text.substring(0,100)); }
        })
        .then(res => {
            loadingRow.remove();
            if (res.status === 'success') {
                if (res.data.length === 0) {
                    let emptyRow = document.createElement('tr');
                    emptyRow.className = `team-${empCode} sub-row`;
                    emptyRow.innerHTML = `<td colspan="9" style="text-align:center; color:#64748b;">No active team members found.</td>`;
                    row.parentNode.insertBefore(emptyRow, row.nextSibling);
                    return;
                }
                
                let html = '';
                res.data.reverse().forEach((r, idx) => {
                    let badgeClass = getBadgeClass(r.ach_pct);
                    let diffHtml = '';
                    if (r.diff < 0) diffHtml = `<span class="diff-short">▼ ${fmtMoney(Math.abs(r.diff))}</span>`;
                    else if (r.diff > 0) diffHtml = `<span class="diff-excess">▲ +${fmtMoney(r.diff)}</span>`;
                    else diffHtml = `<span style="color:var(--text-muted);">0.00</span>`;
                    
                    html += `<tr class="team-${empCode} sub-row">
                        <td class="col-id" style="border-left: 2px dotted #3b82f6;">↳</td>
                        <td class="col-empcode">${r.empcode || '-'}</td>
                        <td class="col-empname">${r.empname}</td>
                        <td>${r.designation}</td>
                        <td>${r.hq || '-'}</td>
                        <td class="col-money target-val">${fmtMoney(r.target)}</td>
                        <td class="col-money sales-val">${fmtMoney(r.total_sales)}</td>
                        <td class="col-money">${diffHtml}</td>
                        <td class="pct-wrapper"><div class="pct-badge ${badgeClass}">${r.ach_pct}%</div></td>
                    </tr>`;
                });
                row.insertAdjacentHTML('afterend', html);
            } else {
                alert("Error: " + res.message);
            }
        }).catch(err => {
            loadingRow.remove();
            alert("Failed to load team data.");
        });
    }

    function exportToExcel() {
        if(currentData.length === 0) return alert("No data available to export.");
        
        let html = `<table border="1">
            <tr style="background:#1e2a78; color:#fff;">
                <th>Sl No.</th>
                <th>Employee Code</th>
                <th>Employee Name</th>
                <th>Designation</th>
                <th>HQ</th>
                <th>Target Value (₹)</th>
                <th>Sales As On Date (₹)</th>
                <th>Target Sales Difference Value (₹)</th>
                <th>Achieved %age</th>
            </tr>`;

        let t_tgt=0, t_sales=0;
        currentData.forEach((r, idx) => {
            t_tgt += r.target; t_sales += r.total_sales;
            let diffTxt = r.diff < 0 ? `-${Math.abs(r.diff).toFixed(2)}` : `+${r.diff.toFixed(2)}`;
            if(r.diff === 0) diffTxt = "0.00";

            html += `<tr>
                <td>${idx+1}</td>
                <td>${r.empcode || ''}</td>
                <td>${r.empname}</td>
                <td>${r.designation}</td>
                <td>${r.hq || ''}</td>
                <td>${r.target}</td>
                <td>${r.total_sales}</td>
                <td>${diffTxt}</td>
                <td>${r.ach_pct}%</td>
            </tr>`;
        });

        let gt_diff = t_sales - t_tgt;
        let diffTxt = gt_diff < 0 ? `-${Math.abs(gt_diff).toFixed(2)}` : `+${gt_diff.toFixed(2)}`;
        let gt_pct = t_tgt > 0 ? ((t_sales/t_tgt)*100).toFixed(1) : 0;
        
        html += `<tr style="background:#e2e8f0;">
            <td colspan="5" align="right">GRAND TOTAL</td>
            <td>${t_tgt}</td>
            <td>${t_sales}</td>
            <td>${diffTxt}</td>
            <td>${gt_pct}%</td>
        </tr></table>`;

        const a = document.createElement("a"); 
        a.href = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' })); 
        a.download = `Advanced_Sales_Trend_${new Date().toISOString().slice(0,10)}.xls`; 
        a.click();
    }
    
    window.onload = () => { loadEmployees(); fetchReport(); };
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
