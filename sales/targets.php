<?php
// HARD CACHE BUSTER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) { echo json_encode(["status" => "error", "message" => "Unauthorized"]); exit(); }
    header("Location: ../auth/login.php"); exit();
}

try {
    require_once __DIR__ . '/../includes/core.php';
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    if (isset($_POST['ajax_action'])) { echo json_encode(["status" => "error", "message" => "Database Connection Lost"]); exit(); }
    die("Database Link Crash");
}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    if ($action === 'save') {
        $empcode = trim($_POST['empcode'] ?? '');
        $month = intval($_POST['target_month'] ?? 0);
        $year = intval($_POST['target_year'] ?? 0);
        $sales = floatval($_POST['sales_target'] ?? 0);

        if (empty($empcode) || empty($month) || empty($year)) {
            echo json_encode(["status" => "error", "message" => "Employee, Month, and Year are mandatory."]);
            exit();
        }

        // ROLE SCOPE: only admin or a manager may set targets, and only for people inside their own team
        if (!$ctx['is_admin'] && ($ctx['is_mr'] || !can_access_emp($ctx, $empcode))) {
            echo json_encode(["status" => "error", "message" => "Access Denied: you can only set targets for your own team."]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO targets (empcode, target_month, target_year, sales_target) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                sales_target = VALUES(sales_target)
            ");
            $stmt->execute([$empcode, $month, $year, $sales]);
            echo json_encode(["status" => "success", "message" => "Target Saved Successfully!"]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Database Update Failed: " . $e->getMessage()]);
        }
        exit();
    } 
    elseif ($action === 'fetch_employees_by_filters') {
        $fDesigs = json_decode($_POST['f_desig'] ?? '[]', true);
        $fStates = json_decode($_POST['f_state'] ?? '[]', true);
        if (!is_array($fDesigs)) $fDesigs = [];
        if (!is_array($fStates)) $fStates = [];

        $whereCond = [];
        $params = [];

        if (!empty($fDesigs)) {
            $placeholders = implode(',', array_fill(0, count($fDesigs), '?'));
            if (in_array('MR', $fDesigs)) {$whereCond[] = "(e.designation IN ($placeholders) OR e.designation IS NULL OR e.designation = '')";
            } else {
                $whereCond[] = "e.designation IN ($placeholders)";
            }
            $params = array_merge($params,$fDesigs);
        }

        if (!empty($fStates)) {$placeholders = implode(',', array_fill(0, count($fStates), '?'));$whereCond[] = "e.state IN ($placeholders)";
            $params = array_merge($params,$fStates);
        }

        // ROLE SCOPE
        $__sc = scope_cond($ctx, 'e.empcode', $params);
        if ($__sc !== '') { $whereCond[] = $__sc; }

        // Only fetch employees who actually have a target entry
        $sql = "SELECT DISTINCT e.empcode, e.empname, e.hq, e.designation 
                FROM employees e
                INNER JOIN targets t ON e.empcode = t.empcode";
                
        if (!empty($whereCond)) {
            $sql .= " WHERE " . implode(" AND ", $whereCond);
        }
        $sql .= " ORDER BY e.empname ASC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit();
    }
    elseif ($action === 'fetch_targets') {
        $fYear =$_POST['f_year'] ?? date('Y');
        $selYear = intval($fYear);
        $nextYear =$selYear + 1;

        $fDesigs = json_decode($_POST['f_desig'] ?? '[]', true);
        $fEmps = json_decode($_POST['f_emp'] ?? '[]', true);
        $fStates = json_decode($_POST['f_state'] ?? '[]', true);

        if (!is_array($fDesigs))$fDesigs = [];
        if (!is_array($fEmps))$fEmps = [];
        if (!is_array($fStates))$fStates = [];

        $anchorCond = "";
        $anchorParams = [];$subStateCond = "";

        if (!empty($fStates)) {$statePlaceholders = implode(',', array_fill(0, count($fStates), '?'));$subStateCond = " AND sub.root_state IN ($statePlaceholders) ";
        }

        if (!empty($fEmps)) {$empPlaceholders = implode(',', array_fill(0, count($fEmps), '?'));$anchorCond = "empcode IN ($empPlaceholders)";
            $anchorParams =$fEmps;
        } elseif (!empty($fDesigs)) {$desigPlaceholders = implode(',', array_fill(0, count($fDesigs), '?'));$anchorCond = "designation IN ($desigPlaceholders)";
            if(in_array('MR', $fDesigs)) {
                $anchorCond = "($anchorCond OR designation IS NULL OR designation = '')";
            }
            $anchorParams =$fDesigs;
        } else {
            $anchorCond = "(designation = 'MR' OR designation IS NULL OR designation = '')";
        }

        // ROLE SCOPE: restrict the root employees of the report to the user's team
        $__sp = [];
        $__sc = scope_cond($ctx, 'empcode', $__sp);
        if ($__sc !== '') { $anchorCond = "($anchorCond) AND $__sc"; $anchorParams = array_merge($anchorParams, $__sp); }

        $params = array_merge($anchorParams, [$selYear,$nextYear]);
        if (!empty($fStates)) {$params = array_merge($params,$fStates);
        }

        $sql = "
            WITH RECURSIVE Subordinates AS (
                SELECT 
                    id AS root_id, empcode AS root_code, empname AS root_name, hq AS root_hq, state AS root_state, designation AS root_desig,
                    empcode, empname, reporting_manager, 1 AS depth
                FROM employees WHERE $anchorCond

                UNION ALL

                SELECT 
                    s.root_id, s.root_code, s.root_name, s.root_hq, s.root_state, s.root_desig,
                    e.empcode, e.empname, e.reporting_manager, s.depth + 1
                FROM employees e
                INNER JOIN Subordinates s ON (TRIM(e.reporting_manager) = TRIM(s.empname) OR TRIM(e.reporting_manager) = TRIM(s.empcode))
                WHERE s.depth < 8 
            )
            SELECT 
                sub.root_code AS empcode, sub.root_name AS empname, sub.root_hq AS hq, sub.root_state AS state, sub.root_desig AS designation,
                SUM(CASE WHEN t.target_month = 4 THEN t.sales_target ELSE 0 END) AS m4,
                SUM(CASE WHEN t.target_month = 5 THEN t.sales_target ELSE 0 END) AS m5,
                SUM(CASE WHEN t.target_month = 6 THEN t.sales_target ELSE 0 END) AS m6,
                SUM(CASE WHEN t.target_month IN (4,5,6) THEN t.sales_target ELSE 0 END) AS q1,
                SUM(CASE WHEN t.target_month = 7 THEN t.sales_target ELSE 0 END) AS m7,
                SUM(CASE WHEN t.target_month = 8 THEN t.sales_target ELSE 0 END) AS m8,
                SUM(CASE WHEN t.target_month = 9 THEN t.sales_target ELSE 0 END) AS m9,
                SUM(CASE WHEN t.target_month IN (7,8,9) THEN t.sales_target ELSE 0 END) AS q2,
                SUM(CASE WHEN t.target_month = 10 THEN t.sales_target ELSE 0 END) AS m10,
                SUM(CASE WHEN t.target_month = 11 THEN t.sales_target ELSE 0 END) AS m11,
                SUM(CASE WHEN t.target_month = 12 THEN t.sales_target ELSE 0 END) AS m12,
                SUM(CASE WHEN t.target_month IN (10,11,12) THEN t.sales_target ELSE 0 END) AS q3,
                SUM(CASE WHEN t.target_month = 1 THEN t.sales_target ELSE 0 END) AS m1,
                SUM(CASE WHEN t.target_month = 2 THEN t.sales_target ELSE 0 END) AS m2,
                SUM(CASE WHEN t.target_month = 3 THEN t.sales_target ELSE 0 END) AS m3,
                SUM(CASE WHEN t.target_month IN (1,2,3) THEN t.sales_target ELSE 0 END) AS q4,
                SUM(COALESCE(t.sales_target, 0)) AS yly,
                (CASE WHEN sub.root_desig = 'MR' OR sub.root_desig IS NULL OR sub.root_desig = '' THEN 0 ELSE 1 END) AS is_agg
            FROM (
                SELECT DISTINCT root_id, root_code, root_name, root_hq, root_state, root_desig, empcode FROM Subordinates
            ) sub
            LEFT JOIN targets t ON t.empcode = sub.empcode 
                AND ((t.target_year = ? AND t.target_month >= 4) OR (t.target_year = ? AND t.target_month <= 3))
            WHERE 1=1 $subStateCond
            GROUP BY sub.root_code, sub.root_name, sub.root_hq, sub.root_state, sub.root_desig
            HAVING SUM(COALESCE(t.sales_target, 0)) > 0
            ORDER BY sub.root_name ASC
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]); 
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Fetch failed: " . $e->getMessage()]);
        }
        exit();
    }
}

try { $allEmployees =$pdo->query("SELECT empcode, empname, hq FROM employees WHERE department LIKE '%Sales%' ORDER BY empname ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {$allEmployees = []; }
try { $states =$pdo->query("SELECT statename FROM states ORDER BY statename ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {$states = []; }

$designations = ['GM', 'NSM', 'ZSM', 'SM', 'DSM', 'Sr. RSM', 'RSM', 'ARM', 'ASM', 'MR'];
$months = ["4"=>"April", "5"=>"May", "6"=>"June", "7"=>"July", "8"=>"August", "9"=>"September", "10"=>"October", "11"=>"November", "12"=>"December", "1"=>"January", "2"=>"February", "3"=>"March"];

$pageTitle = "Organization Targets Studio";
include __DIR__ . '/../includes/header.php';
?>


<div class="targets-container">
    <div class="panel-box">
        <div class="panel-title" id="formHeader">Assign Target (Single Month)</div>
        <form id="targetForm" class="form-grid">
            <div class="form-group">
                <label>Employee</label>
                <select name="empcode" id="tEmp" required>
                    <option value="">Select Employee</option>
                    <?php foreach($allEmployees as$e): ?>
                        <option value="<?= htmlspecialchars($e['empcode']) ?>"><?= htmlspecialchars($e['empname']) ?> (<?= htmlspecialchars($e['hq']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Target Month</label>
                <select name="target_month" id="tMonth">
                    <?php foreach($months as $num =>$name){ echo "<option value='$num' ".($num==date('n')?'selected':'').">$name</option>"; } ?>
                </select>
            </div>
            <div class="form-group">
                <label>Target Year</label>
                <select name="target_year" id="tYear">
                    <?php $y=date('Y'); echo "<option value='$y'>$y</option><option value='".($y+1)."'>".($y+1)."</option>"; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Sales Target (₹)</label>
                <input type="number" step="0.01" name="sales_target" id="tSales" required placeholder="0.00">
            </div>
            <div class="btn-action-row">
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save</button>
            </div>
        </form>
    </div>

    <div class="panel-box">
        <div class="panel-title">Master Targets Matrix</div>
        <div class="filter-grid">
            <div class="form-group">
                <label>Financial Year</label>
                <select id="fYear" onchange="fetchData()">
                    <option value="<?= date('Y')-1 ?>"><?= (date('Y')-1) . "-" . substr(date('Y'),2,2) ?></option>
                    <option value="<?= date('Y') ?>" selected><?= date('Y') . "-" . substr(date('Y')+1,2,2) ?></option>
                    <option value="<?= date('Y')+1 ?>"><?= (date('Y')+1) . "-" . substr(date('Y')+2,2,2) ?></option>
                </select>
            </div>
            <div class="form-group">
                <label>Role</label>
                <div class="dropdown-check-list" id="list_fDesig">
                    <span class="anchor">Select Role</span>
                    <ul class="items">
                        <li><label><input type="checkbox" value="MR" onchange="updateAnchorText('list_fDesig', 'Select Role'); updateEmployeeDropdown();"/> MR (Individual)</label></li>
                        <?php foreach($designations as $d): if($d==='MR') continue; ?>
                            <li><label><input type="checkbox" value="<?= htmlspecialchars($d) ?>" onchange="updateAnchorText('list_fDesig', 'Select Role'); updateEmployeeDropdown();"/> <?= htmlspecialchars($d) ?> (Team)</label></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="form-group">
                <label>State</label>
                <div class="dropdown-check-list" id="list_fState">
                    <span class="anchor">Select State</span>
                    <ul class="items">
                        <?php foreach($states as$s): ?>
                            <li><label><input type="checkbox" value="<?= htmlspecialchars($s['statename']) ?>" onchange="updateAnchorText('list_fState', 'Select State'); updateEmployeeDropdown();"/> <?= htmlspecialchars($s['statename']) ?></label></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="form-group">
                <label>Employee Manager</label>
                <div class="dropdown-check-list" id="list_fEmp">
                    <span class="anchor">Select Employee</span>
                    <ul class="items"><li><span style="font-size:9px; color:#64748b;">Loading...</span></li></ul>
                </div>
            </div>
            <div style="display:flex; gap:6px; align-items:center;">
                <button type="button" class="btn btn-clear" onclick="clearAllFilters()" title="Reset Filters">✕</button>
                <button type="button" class="btn btn-export" onclick="exportToExcel()">Export Matrix</button>
            </div>
        </div>

        <div class="table-container">
            <table class="compact-table" id="matrixTable">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>Empcode</th>
                        <th>Emp Name</th>
                        <th>HQ</th>
                        <th>State</th>
                        <th>April</th>
                        <th>May</th>
                        <th>June</th>
                        <th class="col-qtr">Qtr-1</th>
                        <th>July</th>
                        <th>Aug</th>
                        <th>Sep</th>
                        <th class="col-qtr">QTR-2</th>
                        <th>Oct</th>
                        <th>Nov</th>
                        <th>Dec</th>
                        <th class="col-qtr">Qtr-3</th>
                        <th>Jan</th>
                        <th>Feb</th>
                        <th>March</th>
                        <th class="col-qtr">Qtr-4</th>
                        <th class="col-yly">Yly</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="22" style="text-align:center; padding: 20px;">Fetching matrix data...</td></tr>
                </tbody>
                <tfoot id="tableFooter"></tfoot>
            </table>
        </div>
    </div>
</div>

<script>
    let currentData = [];
    let fetchController = null;

    function fmt(n) { return Number(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}); }

    function getMultiValues(listId) {
        const checkboxes = document.querySelectorAll(`#${listId} ul.items input[type="checkbox"]:checked`);
        return Array.from(checkboxes).map(cb => cb.value);
    }

    function updateAnchorText(listId, defaultText) {
        const list = document.getElementById(listId);
        const checked = list.querySelectorAll('ul.items input[type="checkbox"]:checked');
        const anchor = list.querySelector('.anchor');
        if (checked.length === 0) anchor.innerText = defaultText;
        else if (checked.length === 1) anchor.innerText = checked[0].parentElement.innerText.trim();
        else anchor.innerText = checked.length + " Selected";
    }

    function clearAllFilters() {
        document.querySelectorAll('.dropdown-check-list input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.getElementById('fYear').selectedIndex = 1; 
        updateAnchorText('list_fDesig', 'Select Role');
        updateAnchorText('list_fState', 'Select State');
        updateAnchorText('list_fEmp', 'Select Employee');
        updateEmployeeDropdown();
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('.dropdown-check-list .anchor').forEach(anchor => {
            anchor.onclick = function(e) {
                let parent = this.parentElement;
                let wasVisible = parent.classList.contains('visible');
                document.querySelectorAll('.dropdown-check-list').forEach(list => list.classList.remove('visible'));
                if (!wasVisible) parent.classList.add('visible');
            }
        });
        document.addEventListener('click', function(e) {
            document.querySelectorAll('.dropdown-check-list').forEach(list => {
                if (!list.contains(e.target)) list.classList.remove('visible');
            });
        });
        updateEmployeeDropdown();
    });

    function updateEmployeeDropdown() {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_employees_by_filters');
        fd.append('f_desig', JSON.stringify(getMultiValues('list_fDesig')));
        fd.append('f_state', JSON.stringify(getMultiValues('list_fState')));

        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
            const empList = document.querySelector('#list_fEmp ul.items');
            if (res.status === 'success') {
                let html = '';
                res.data.forEach(e => {
                    html += `<li><label><input type="checkbox" value="${e.empcode}" onchange="updateAnchorText('list_fEmp', 'Select Employee'); fetchData();"/> ${e.empname} (${e.hq || '-'})</label></li>`;
                });
                empList.innerHTML = html || '<li><span style="font-size:9px; padding:4px;">No records</span></li>';
                updateAnchorText('list_fEmp', 'Select Employee');
                fetchData(); 
            }
        });
    }

    function fetchData() {
        if (fetchController) { try { fetchController.abort(); } catch(e) {} }
        if (typeof AbortController !== 'undefined') { fetchController = new AbortController(); }

        const fd = new FormData(); 
        fd.append('ajax_action', 'fetch_targets'); 
        fd.append('f_year', document.getElementById('fYear').value); 
        fd.append('f_desig', JSON.stringify(getMultiValues('list_fDesig')));
        fd.append('f_emp', JSON.stringify(getMultiValues('list_fEmp'))); 
        fd.append('f_state', JSON.stringify(getMultiValues('list_fState'))); 
        
        document.getElementById('tableBody').innerHTML = '<tr><td colspan="22" style="text-align:center; padding:20px;">Generating Matrix...</td></tr>';
        
        const fetchOptions = { method: 'POST', body: fd };
        if (fetchController) { fetchOptions.signal = fetchController.signal; }

        fetch(window.location.href, fetchOptions).then(r => r.json()).then(res => { 
            if (res.status === 'success') { 
                currentData = res.data; 
                renderTable(); 
            }
        }).catch(err => { if (err.name !== 'AbortError') console.error(err); });
    }

    function renderTable() {
        const tbody = document.getElementById('tableBody');
        const tfoot = document.getElementById('tableFooter');
        
        if (!currentData || currentData.length === 0) { 
            tbody.innerHTML = '<tr><td colspan="22" style="text-align:center; color: #94a3b8; padding:20px;">No active targets found for the selected criteria.</td></tr>'; 
            tfoot.innerHTML = '';
            return; 
        }
        
        let html = '';
        let gt = { m4:0, m5:0, m6:0, q1:0, m7:0, m8:0, m9:0, q2:0, m10:0, m11:0, m12:0, q3:0, m1:0, m2:0, m3:0, q4:0, yly:0 };

        currentData.forEach((t, i) => {
            const isAgg = t.is_agg == 1;
            const nameDisp = isAgg ? `<strong style='color:#0f172a;'>⭐ ${t.empname}</strong>` : `<strong style='color:#1e2a78;'>${t.empname}</strong>`;
            
            gt.m4+=Number(t.m4); gt.m5+=Number(t.m5); gt.m6+=Number(t.m6); gt.q1+=Number(t.q1);
            gt.m7+=Number(t.m7); gt.m8+=Number(t.m8); gt.m9+=Number(t.m9); gt.q2+=Number(t.q2);
            gt.m10+=Number(t.m10); gt.m11+=Number(t.m11); gt.m12+=Number(t.m12); gt.q3+=Number(t.q3);
            gt.m1+=Number(t.m1); gt.m2+=Number(t.m2); gt.m3+=Number(t.m3); gt.q4+=Number(t.q4); gt.yly+=Number(t.yly);

            html += `<tr>
                <td style="text-align:center;">${i+1}</td>
                <td>${t.empcode}</td>
                <td>${nameDisp}</td>
                <td>${t.hq || '-'}</td>
                <td>${t.state || '-'}</td>
                <td>${fmt(t.m4)}</td><td>${fmt(t.m5)}</td><td>${fmt(t.m6)}</td><td class="col-qtr">${fmt(t.q1)}</td>
                <td>${fmt(t.m7)}</td><td>${fmt(t.m8)}</td><td>${fmt(t.m9)}</td><td class="col-qtr">${fmt(t.q2)}</td>
                <td>${fmt(t.m10)}</td><td>${fmt(t.m11)}</td><td>${fmt(t.m12)}</td><td class="col-qtr">${fmt(t.q3)}</td>
                <td>${fmt(t.m1)}</td><td>${fmt(t.m2)}</td><td>${fmt(t.m3)}</td><td class="col-qtr">${fmt(t.q4)}</td>
                <td class="col-yly">${fmt(t.yly)}</td>
            </tr>`;
        }); 
        
        tbody.innerHTML = html;
        tfoot.innerHTML = `<tr>
            <td colspan="5" style="text-align:right;">GRAND TOTAL</td>
            <td>${fmt(gt.m4)}</td><td>${fmt(gt.m5)}</td><td>${fmt(gt.m6)}</td><td class="col-qtr">${fmt(gt.q1)}</td>
            <td>${fmt(gt.m7)}</td><td>${fmt(gt.m8)}</td><td>${fmt(gt.m9)}</td><td class="col-qtr">${fmt(gt.q2)}</td>
            <td>${fmt(gt.m10)}</td><td>${fmt(gt.m11)}</td><td>${fmt(gt.m12)}</td><td class="col-qtr">${fmt(gt.q3)}</td>
            <td>${fmt(gt.m1)}</td><td>${fmt(gt.m2)}</td><td>${fmt(gt.m3)}</td><td class="col-qtr">${fmt(gt.q4)}</td>
            <td class="col-yly">${fmt(gt.yly)}</td>
        </tr>`;
    }

    function submitForm(e) {
        e.preventDefault();
        const fd = new FormData(document.getElementById('targetForm')); 
        fd.append('ajax_action', 'save'); 
        document.getElementById('btnSubmit').innerText = '...';
        
        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(data => { 
            if (data.status === 'success') { 
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                document.getElementById('targetForm').reset(); 
                fetchData(); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).finally(() => { document.getElementById('btnSubmit').innerText = 'Save'; });
    }

    function exportToExcel() {
        if (!currentData || currentData.length === 0) return;
        let tbl = document.getElementById("matrixTable").cloneNode(true);
        let a = document.createElement("a"); 
        a.href = URL.createObjectURL(new Blob([`<table border="1">${tbl.innerHTML}</table>`], { type: 'application/vnd.ms-excel' })); 
        a.download = `Targets_Matrix_${document.getElementById('fYear').value}.xls`; 
        a.click();
    }
    
    document.getElementById('targetForm').addEventListener('submit', submitForm);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>