<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) { echo json_encode(["status" => "error", "message" => "Unauthorized access."]); exit(); }
    header("Location: ../auth/login.php"); exit();
}

require_once __DIR__ . '/../includes/core.php';
require_once __DIR__ . '/../masters/brand.php';

if (isset($_POST['ajax_action']) &&$_POST['ajax_action'] === 'fetch_achievements') {
    header('Content-Type: application/json');
    
    $month = intval($_POST['month'] ?? date('m'));
    $year = intval($_POST['year'] ?? date('Y'));
    $fDesig = trim($_POST['f_desig'] ?? 'MR');

    $params = [];

    if ($fDesig === 'MR' || empty($fDesig)) {$anchorCond = "(designation = 'MR' OR designation IS NULL OR designation = '')";
    } else {
        $anchorCond = "designation = ?";
        $params[] =$fDesig;
    }

    // ROLE SCOPE: admin = all, manager = own team, MR = self
    $__sc = scope_cond($ctx, 'empcode', $params);
    if ($__sc !== '') { $anchorCond = "($anchorCond) AND $__sc"; }

    try {
        $sql = "
            WITH RECURSIVE Subordinates AS (
                SELECT 
                    empcode AS root_code,
                    empname AS root_name,
                    state AS root_state,
                    hq AS root_hq,
                    designation AS root_desig,
                    empcode,
                    empname,
                    reporting_manager,
                    1 AS depth
                FROM employees
                WHERE $anchorCond

                UNION ALL

                SELECT 
                    s.root_code,
                    s.root_name,
                    s.root_state,
                    s.root_hq,
                    s.root_desig,
                    e.empcode,
                    e.empname,
                    e.reporting_manager,
                    s.depth + 1
                FROM employees e
                INNER JOIN Subordinates s ON (
                    TRIM(e.reporting_manager) = TRIM(s.empcode) 
                    OR TRIM(e.reporting_manager) = TRIM(s.empname)
                )
                WHERE s.depth < 8 
            ),
            TargetData AS (
                SELECT empcode, SUM(sales_target) AS sales_target, SUM(collection_target) AS collection_target
                FROM targets
                WHERE target_month = $month AND target_year =$year
                GROUP BY empcode
            ),
            InvoiceData AS (
                SELECT 
                    COALESCE(ap.empcode, e.empcode) AS empcode,
                    SUM(i.total_value) AS actual_sales
                FROM invoices i
                LEFT JOIN assignparties ap ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(ap.partyname))
                LEFT JOIN employees e ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(e.empname))
                WHERE MONTH(i.invoice_date) = $month AND YEAR(i.invoice_date) =$year
                GROUP BY COALESCE(ap.empcode, e.empcode)
            ),
            CollectionData AS (
                SELECT empcode, SUM(amount) AS actual_collection
                FROM collections
                WHERE MONTH(collection_date) = $month AND YEAR(collection_date) =$year
                GROUP BY empcode
            )
            SELECT 
                s.root_code AS empcode,
                s.root_name AS empname,
                s.root_state AS state,
                s.root_hq AS hq,
                s.root_desig AS designation,
                COALESCE(SUM(t.sales_target), 0) AS sales_target,
                COALESCE(SUM(t.collection_target), 0) AS collection_target,
                COALESCE(SUM(i.actual_sales), 0) AS actual_sales,
                COALESCE(SUM(c.actual_collection), 0) AS actual_collection
            FROM Subordinates s
            LEFT JOIN TargetData t ON s.empcode = t.empcode
            LEFT JOIN InvoiceData i ON s.empcode = i.empcode
            LEFT JOIN CollectionData c ON s.empcode = c.empcode
            GROUP BY s.root_code, s.root_name, s.root_state, s.root_hq, s.root_desig
            ORDER BY s.root_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data =$stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($data as &$row) {
            $row['sales_target'] = (float)$row['sales_target'];
            $row['actual_sales'] = (float)$row['actual_sales'];
            $row['sales_pct'] =$row['sales_target'] > 0 ? round(($row['actual_sales'] /$row['sales_target']) * 100, 1) : 0;
            
            $db_coll_target = (float)$row['collection_target'];
            $row['collection_target'] = ($db_coll_target > 0) ? $db_coll_target :$row['sales_target'];
            
            $row['actual_collection'] = (float)$row['actual_collection'];
            $row['collection_pct'] =$row['collection_target'] > 0 ? round(($row['actual_collection'] /$row['collection_target']) * 100, 1) : 0;
        }

        echo json_encode(["status" => "success", "data" => $data]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "SQL Error: " . $e->getMessage()]);
    } exit();
}

$designations =$pdo->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation != '' ORDER BY designation ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = "Targets & Achievements";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box" style="position: relative;">
    <div class="panel-title">
        <span>Targets vs Achievements Ledger <span id="reportPeriod" style="font-weight: normal; color: #64748b; margin-left: 8px;"></span></span>
    </div>
    
    <div class="filter-grid">
        <div class="form-group">
            <label>Select Month</label>
            <select id="monthFilter">
                <?php $months = ["1"=>"January","2"=>"February","3"=>"March","4"=>"April","5"=>"May","6"=>"June","7"=>"July","8"=>"August","9"=>"September","10"=>"October","11"=>"November","12"=>"December"];
                foreach($months as $num =>$name) { echo "<option value='$num' ".($num==date('n')?'selected':'').">$name</option>"; } ?>
            </select>
        </div>
        <div class="form-group">
            <label>Select Year</label>
            <select id="yearFilter">
                <?php $curr = date('Y'); for($y=$curr-1; $y<=$curr+1; $y++) { echo "<option value='$y' ".($y==$curr?'selected':'').">$y</option>"; } ?>
            </select>
        </div>
        <div class="form-group">
            <label>Hierarchy Level</label>
            <select id="fDesig">
                <option value="MR" selected>MR (Individual)</option>
                <?php foreach($designations as $d): if($d==='MR') continue; ?>
                    <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?> (Team Rollup)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="btn-action-row" style="margin-left: auto;">
            <button class="btn btn-primary" onclick="fetchAchievements()">Generate Report</button>
            <button class="btn btn-export" onclick="exportToExcel()">Export Excel</button>
        </div>
    </div>

    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 30px; top: 0;">Sl.No</th>
                    <th rowspan="2" style="top: 0; text-align: left;">Emp Code</th>
                    <th rowspan="2" style="top: 0; text-align: left;">Employee Name</th>
                    <th rowspan="2" style="top: 0; text-align: left;">State</th>
                    <th rowspan="2" style="top: 0; text-align: left;">HQ</th>
                    <th rowspan="2" style="top: 0; text-align: left;">Designation</th>
                    <th colspan="3" style="background: #2563eb; top: 0;">SALES PERFORMANCE</th>
                    <th colspan="3" style="background: #059669; top: 0;">COLLECTION PERFORMANCE</th>
                    <th rowspan="2" style="background: #4f46e5; top: 0;">Coll. vs Sales %</th>
                </tr>
                <tr>
                    <th style="background: #3b82f6; top: 22px; text-align: right;">Target (₹)</th>
                    <th style="background: #3b82f6; top: 22px; text-align: right;">Achieved (₹)</th>
                    <th style="background: #3b82f6; top: 22px;">%</th>
                    <th style="background: #10b981; top: 22px; text-align: right;">Target (₹)</th>
                    <th style="background: #10b981; top: 22px; text-align: right;">Collected (₹)</th>
                    <th style="background: #10b981; top: 22px;">%</th>
                </tr>
            </thead>
            <tbody id="kpiBody">
                <tr><td colspan="13" style="text-align: center; color: #64748b; padding: 20px;">Fetching Aggregated Results...</td></tr>
            </tbody>
            <tfoot id="kpiFoot"></tfoot>
        </table>
    </div>
</div>

<script>
    let currentData = [];
    function fmtMoney(n) { return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    
    function getPctBadge(pct) {
        let val = parseFloat(pct) || 0;
        if (val >= 100) return `<span class="pct-badge pct-high">${pct}%</span>`;
        if (val >= 85) return `<span class="pct-badge pct-mid">${pct}%</span>`;
        return `<span class="pct-badge pct-low">${pct}%</span>`;
    }

    function fetchAchievements() {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_achievements');
        fd.append('month', document.getElementById('monthFilter').value);
        fd.append('year', document.getElementById('yearFilter').value);
        fd.append('f_desig', document.getElementById('fDesig').value);

        const moText = document.getElementById('monthFilter').options[document.getElementById('monthFilter').selectedIndex].text;
        document.getElementById('reportPeriod').innerText = `(${moText} ${document.getElementById('yearFilter').value})`;

        const tbody = document.getElementById('kpiBody');
        const tfoot = document.getElementById('kpiFoot');
        tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding: 20px; color:#1e2a78; font-weight:bold;">Fetching Aggregated Results...</td></tr>';

        fetch(window.location.href + "?_nocache=" + new Date().getTime(), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                currentData = res.data;
                
                if (currentData.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; color:#64748b; padding:20px;">No employees found for this designation.</td></tr>';
                    tfoot.innerHTML = ''; return;
                }

                let html = ''; let tSalesT = 0, tSalesA = 0, tColT = 0, tColA = 0;
                const isAgg = document.getElementById('fDesig').value !== 'MR';

                currentData.forEach((r, i) => {
                    tSalesT += r.sales_target; tSalesA += r.actual_sales;
                    tColT += r.collection_target; tColA += r.actual_collection;
                    
                    const nameDisp = isAgg ? `⭐ <span style="color:#0f172a;">${r.empname}</span>` : `<span style="color:#1e2a78;">${r.empname}</span>`;
                    const stateDisp = r.state ? r.state : '-';
                    const empcodeDisp = r.empcode ? r.empcode : '-';
                    
                    const collVsSalesPct = r.actual_sales > 0 ? ((r.actual_collection / r.actual_sales) * 100).toFixed(1) : 0;
                    
                    html += `<tr>
                        <td style="text-align:center; color:#64748b;">${i+1}</td>
                        <td style="font-family:monospace; color:#0ea5e9; font-weight:bold;">${empcodeDisp}</td>
                        <td><strong>${nameDisp}</strong></td>
                        <td style="color:#475569;">${stateDisp}</td>
                        <td style="font-weight:bold;">${r.hq || '-'}</td>
                        <td style="color:#1e2a78;">${r.designation || '-'}</td>
                        <td class="val-cell">${fmtMoney(r.sales_target)}</td>
                        <td class="val-cell" style="color: #2563eb;">${fmtMoney(r.actual_sales)}</td>
                        <td style="text-align:center;">${getPctBadge(r.sales_pct)}</td>
                        <td class="val-cell">${fmtMoney(r.collection_target)}</td>
                        <td class="val-cell" style="color: #059669;">${fmtMoney(r.actual_collection)}</td>
                        <td style="text-align:center;">${getPctBadge(r.collection_pct)}</td>
                        <td style="text-align:center;">${getPctBadge(collVsSalesPct)}</td>
                    </tr>`;
                });
                tbody.innerHTML = html;

                const overallSalesPct = tSalesT > 0 ? ((tSalesA / tSalesT) * 100).toFixed(1) : 0;
                const overallColPct = tColT > 0 ? ((tColA / tColT) * 100).toFixed(1) : 0;
                const overallCollVsSales = tSalesA > 0 ? ((tColA / tSalesA) * 100).toFixed(1) : 0;

                tfoot.innerHTML = `<tr>
                    <td colspan="6" style="text-align:right;">FILTERED GRAND TOTALS:</td>
                    <td class="val-cell">${fmtMoney(tSalesT)}</td>
                    <td class="val-cell" style="color:#2563eb !important;">${fmtMoney(tSalesA)}</td>
                    <td style="text-align:center;">${getPctBadge(overallSalesPct)}</td>
                    <td class="val-cell">${fmtMoney(tColT)}</td>
                    <td class="val-cell" style="color:#059669 !important;">${fmtMoney(tColA)}</td>
                    <td style="text-align:center;">${getPctBadge(overallColPct)}</td>
                    <td style="text-align:center;">${getPctBadge(overallCollVsSales)}</td>
                </tr>`;
            } else {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color:#ef4444; font-weight:bold; padding:20px;">${res.message}</td></tr>`;
                tfoot.innerHTML = '';
            }
        }).catch(err => {
            console.error(err);
            tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color:#ef4444; font-weight:bold; padding:20px;">Network Error or Data Format Issue. Check console.</td></tr>`;
            tfoot.innerHTML = '';
        });
    }

    function exportToExcel() {
        if(!currentData || currentData.length === 0) return alert("No data to export.");
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;">
            <th>Emp Code</th><th>Employee Name</th><th>State</th><th>HQ</th><th>Designation</th><th>Sales Target</th><th>Actual Sales</th><th>Sales %</th><th>Collection Target</th><th>Actual Collection</th><th>Collection %</th><th>Coll. vs Sales %</th>
        </tr>`;
        
        let tSalesT = 0, tSalesA = 0, tColT = 0, tColA = 0;
        
        currentData.forEach(r => {
            tSalesT += r.sales_target; tSalesA += r.actual_sales;
            tColT += r.collection_target; tColA += r.actual_collection;
            
            const collVsSalesPct = r.actual_sales > 0 ? ((r.actual_collection / r.actual_sales) * 100).toFixed(1) : 0;
            html += `<tr><td>${r.empcode ? r.empcode : '-'}</td><td>${r.empname}</td><td>${r.state ? r.state : '-'}</td><td>${r.hq ? r.hq : '-'}</td><td>${r.designation}</td><td>${r.sales_target}</td><td>${r.actual_sales}</td><td>${r.sales_pct}%</td><td>${r.collection_target}</td><td>${r.actual_collection}</td><td>${r.collection_pct}%</td><td>${collVsSalesPct}%</td></tr>`;
        });
        
        const overallSalesPct = tSalesT > 0 ? ((tSalesA / tSalesT) * 100).toFixed(1) : 0;
        const overallColPct = tColT > 0 ? ((tColA / tColT) * 100).toFixed(1) : 0;
        const overallCollVsSales = tSalesA > 0 ? ((tColA / tSalesA) * 100).toFixed(1) : 0;
        
        html += `<tr><td colspan='5' style='text-align:right; font-weight:bold;'>GRAND TOTAL</td><td style='font-weight:bold;'>${tSalesT}</td><td style='font-weight:bold;'>${tSalesA}</td><td style='font-weight:bold;'>${overallSalesPct}%</td><td style='font-weight:bold;'>${tColT}</td><td style='font-weight:bold;'>${tColA}</td><td style='font-weight:bold;'>${overallColPct}%</td><td style='font-weight:bold;'>${overallCollVsSales}%</td></tr>`;
        html += `</table>`;
        
        const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' })); 
        a.download = `Achievements_Export_${new Date().toISOString().slice(0,10)}.xls`; a.click();
    }

    window.onload = fetchAchievements;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>