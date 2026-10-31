<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin']; 
$teamCodes = $ctx['team_codes']; 
$empCode = $ctx['empcode'];

$canEdit = $isAdmin || is_ops_user($ctx, ['ACCOUNTS', 'FINANCE']);

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $a = $_POST['ajax_action']; 
    
    try {
        if ($a === 'fetch') {
            $party = trim($_POST['partyname'] ?? '');
            $from = trim($_POST['from'] ?? date('Y-m-01'));
            $to = trim($_POST['to'] ?? date('Y-m-d'));
            $emp = trim($_POST['empcode'] ?? '');

            $sql = "SELECT c.*, e.empname FROM collections c LEFT JOIN employees e ON c.empcode = e.empcode WHERE c.collection_date BETWEEN ? AND ?";
            $params = [$from, $to];

            if ($party !== '') {
                $sql .= " AND c.partyname = ?";
                $params[] = $party;
            } elseif (!is_ops_user($ctx, ['ACCOUNTS', 'FINANCE'])) {
                $sql .= scope_party_in($ctx, 'c.partyname', $params);
            }

            if ($emp !== '') {
                $sql .= " AND c.empcode = ?";
                $params[] = $emp;
            } elseif (!$isAdmin && !is_ops_user($ctx, ['ACCOUNTS', 'FINANCE'])) {
                $tCodes = !empty($teamCodes) ? $teamCodes : [];
                if (!in_array($empCode, $tCodes)) { $tCodes[] = $empCode; }
                $ph = implode(',', array_fill(0, count($tCodes), '?'));
                $sql .= " AND c.empcode IN ($ph)";
                $params = array_merge($params, $tCodes);
            }

            $sql .= " ORDER BY c.collection_date DESC, c.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $total = 0;
            foreach ($rows as $row) { $total += (float)$row['amount']; }
            
            echo json_encode(['status' => 'success', 'data' => $rows, 'total' => $total]);
            exit;

        } elseif ($a === 'init_data') {
            $pp = [];
            $ps = $pdo->prepare("SELECT DISTINCT partyname FROM parties WHERE 1=1" . (is_ops_user($ctx,['ACCOUNTS','FINANCE']) ? '' : scope_party_in($ctx,'partyname',$pp)) . " ORDER BY partyname");
            $ps->execute($pp);
            $parties = $ps->fetchAll(PDO::FETCH_COLUMN);

            $empSql = "SELECT empcode, empname FROM employees WHERE (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00')";
            $ep = [];
            if (!$isAdmin && !is_ops_user($ctx, ['ACCOUNTS', 'FINANCE'])) {
                $tCodes = !empty($teamCodes) ? $teamCodes : [];
                if (!in_array($empCode, $tCodes)) { $tCodes[] = $empCode; }
                $ph = implode(',', array_fill(0, count($tCodes), '?'));
                $empSql .= " AND empcode IN ($ph)";
                $ep = $tCodes;
            }
            $empSql .= " ORDER BY empname ASC";
            $es = $pdo->prepare($empSql);
            $es->execute($ep);
            $employees = $es->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'parties' => $parties, 'employees' => $employees]);
            exit;
            
        } elseif ($a === 'delete' && $isAdmin) {
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM collections WHERE id = ?")->execute([$id]);
                echo json_encode(['status' => 'success', 'message' => 'Record permanently deleted.']);
            }
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

$pageTitle = "Collection Reports"; 
include __DIR__ . '/../includes/header.php';
?>


<div class="dashboard-wrapper">
    <div id="msgBox" class="msg-box"></div>

    <!-- SMART FILTER BAR -->
    <div class="smart-filter">
        <div class="filter-header">
            <h2 class="filter-title">Collection Data Explorer</h2>
            <div style="display:flex; gap: 8px;">
                <?php if ($canEdit): ?>
                <a href="collections.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Entry</a>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="exportCSV()"><i class="fa-solid fa-file-excel"></i> Export CSV</button>
            </div>
        </div>
        
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="quick-chips">
                <span class="chip active" onclick="setQuickDate('mtd', this)">This Month</span>
                <span class="chip" onclick="setQuickDate('last', this)">Last Month</span>
                <span class="chip" onclick="setQuickDate('qtd', this)">This Quarter</span>
                <span class="chip" onclick="setQuickDate('ytd', this)">YTD (Fin Year)</span>
            </div>
        </div>
        
        <div class="form-grid">
            <div class="form-group">
                <label>From Date</label>
                <input type="date" id="f_from" value="<?=date('Y-m-01')?>" onchange="resetChipsAndLoad()">
            </div>
            <div class="form-group">
                <label>To Date</label>
                <input type="date" id="f_to" value="<?=date('Y-m-d')?>" onchange="resetChipsAndLoad()">
            </div>
            <div class="form-group">
                <label>Filter Party</label>
                <select id="f_party" onchange="loadData()"><option value="">-- All Accessible Parties --</option></select>
            </div>
            <div class="form-group">
                <label>Filter MR / Employee</label>
                <select id="f_emp" onchange="loadData()"><option value="">-- All Accessible MRs --</option></select>
            </div>
            <div class="form-group">
                <label>Search View</label>
                <div class="search-box">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="quickSearch" placeholder="Type to filter..." onkeyup="filterTable()">
                </div>
            </div>
        </div>
    </div>

    <!-- METRICS & DATA TABLE -->
    <div class="metric-strip">
        <div class="metric-item">
            <span class="metric-lbl">Total Period Collections</span>
            <span class="metric-val" id="totalVal" style="color:#059669;">₹ 0.00</span>
        </div>
        <div class="metric-item">
            <span class="metric-lbl">Records Found</span>
            <span class="metric-val" id="recordCount" style="color:#64748b;">0</span>
        </div>
    </div>

    <div class="table-container">
        <table class="compact-table" id="dataTable">
            <thead>
                <tr>
                    <th style="width:75px;">Date</th>
                    <th>Party Name</th>
                    <th>Credited To (MR)</th>
                    <th style="text-align:right;">Amount (₹)</th>
                    <th>Mode</th>
                    <th>Ref. No</th>
                    <th>Remarks</th>
                    <?php if($isAdmin): ?><th style="width:30px; text-align:center;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="tbody">
                <tr><td colspan="<?= $isAdmin ? 8 : 7 ?>" style="text-align:center; padding:25px; color:#94a3b8;">Loading secure data...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function showMsg(txt, type='success') {
    const b = document.getElementById('msgBox');
    b.className = 'msg-box ' + (type === 'success' ? 'msg-success' : 'msg-error');
    b.innerHTML = (type === 'success' ? '<i class="fa-solid fa-circle-check"></i> ' : '<i class="fa-solid fa-triangle-exclamation"></i> ') + txt;
    b.style.display = 'flex';
    setTimeout(() => b.style.display = 'none', 4000);
}

function initDropdowns() {
    const fd = new FormData();
    fd.append('ajax_action', 'init_data');
    fetch('', {method: 'POST', body: fd}).then(r=>r.json()).then(d => {
        if(d.status === 'success') {
            document.getElementById('f_party').innerHTML = '<option value="">-- All Parties --</option>' + (d.parties||[]).map(p => `<option value="${p}">${p}</option>`).join('');
            document.getElementById('f_emp').innerHTML = '<option value="">-- All MRs --</option>' + (d.employees||[]).map(e => `<option value="${e.empcode}">${e.empname}</option>`).join('');
            loadData();
        }
    });
}

function setQuickDate(type, elem) {
    document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
    elem.classList.add('active');
    
    const d = new Date();
    const y = d.getFullYear();
    const m = d.getMonth() + 1; // 1-12
    let from, to;
    
    if (type === 'mtd') {
        from = `${y}-${String(m).padStart(2,'0')}-01`;
        to = new Date(y, m, 0).toLocaleDateString('en-CA');
    } else if (type === 'last') {
        const lastM = m === 1 ? 12 : m - 1;
        const lastY = m === 1 ? y - 1 : y;
        from = `${lastY}-${String(lastM).padStart(2,'0')}-01`;
        to = new Date(lastY, lastM, 0).toLocaleDateString('en-CA');
    } else if (type === 'qtd') {
        const qStartMonth = Math.floor((m - 1) / 3) * 3 + 1;
        from = `${y}-${String(qStartMonth).padStart(2,'0')}-01`;
        to = new Date(y, qStartMonth + 2, 0).toLocaleDateString('en-CA');
    } else if (type === 'ytd') {
        const fyYear = m >= 4 ? y : y - 1;
        from = `${fyYear}-04-01`;
        to = `${fyYear + 1}-03-31`;
    }
    
    document.getElementById('f_from').value = from;
    document.getElementById('f_to').value = to;
    loadData();
}

function resetChipsAndLoad() {
    document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
    loadData();
}

function loadData() {
    const fd = new FormData();
    fd.append('ajax_action', 'fetch');
    fd.append('from', document.getElementById('f_from').value);
    fd.append('to', document.getElementById('f_to').value);
    fd.append('partyname', document.getElementById('f_party').value);
    fd.append('empcode', document.getElementById('f_emp').value);

    fetch('', {method: 'POST', body: fd}).then(r=>r.json()).then(d => {
        if(d.status === 'success') {
            document.getElementById('totalVal').innerText = '₹ ' + Number(d.total || 0).toLocaleString('en-IN', {minimumFractionDigits:2});
            document.getElementById('recordCount').innerText = (d.data || []).length;
            
            const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
            const rows = d.data || [];
            
            if(rows.length === 0) {
                document.getElementById('tbody').innerHTML = `<tr><td colspan="${isAdmin ? 8 : 7}" style="text-align:center; padding:25px; color:#94a3b8; font-style:italic;">No records found for the selected criteria.</td></tr>`;
                return;
            }
            
            document.getElementById('tbody').innerHTML = rows.map(r => {
                const mrName = r.empname ? r.empname : (r.empcode || '-');
                return `<tr class="data-row">
                    <td class="date-cell">${r.collection_date}</td>
                    <td style="font-weight:800; color:#1e2a78;">${r.partyname}</td>
                    <td style="font-weight:600;">${mrName}</td>
                    <td class="val-cell">₹${Number(r.amount).toLocaleString('en-IN', {minimumFractionDigits:2})}</td>
                    <td>${r.payment_mode || '-'}</td>
                    <td style="font-family:monospace; color:#64748b;">${r.reference_no || '-'}</td>
                    <td style="color:#64748b;">${r.remarks || ''}</td>
                    ${isAdmin ? `<td style="text-align:center;"><button class="btn-danger" onclick="deleteCol(${r.id})" title="Delete"><i class="fa-solid fa-trash-can"></i></button></td>` : ''}
                </tr>`;
            }).join('');
        }
    });
}

function filterTable() {
    const input = document.getElementById("quickSearch").value.toLowerCase();
    const rows = document.querySelectorAll("#tbody .data-row");
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? "" : "none";
    });
}

<?php if($isAdmin): ?>
function deleteCol(id) {
    if(!confirm('Permanently delete this record? This affects the Party Ledger.')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete');
    fd.append('id', id);
    fetch('', {method: 'POST', body: fd}).then(r=>r.json()).then(d => {
        if(d.status === 'success') { showMsg(d.message, 'success'); loadData(); }
        else { showMsg(d.message, 'error'); }
    });
}
<?php endif; ?>

function exportCSV() {
    const rows = document.querySelectorAll("#dataTable tr");
    let csv = [];
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].style.display === "none") continue; 
        
        let row = [], cols = rows[i].querySelectorAll("td, th");
        const colLen = (<?= $isAdmin ? 'true' : 'false' ?> && i > 0) ? cols.length - 1 : cols.length;
        for (let j = 0; j < colLen; j++) {
            let data = cols[j].innerText.replace(/"/g, '""').replace(/₹/g, '').trim();
            if(<?= $isAdmin ? 'true' : 'false' ?> && i === 0 && j === cols.length - 1) continue;
            row.push('"' + data + '"');
        }
        csv.push(row.join(","));
    }
    const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    const link = document.createElement("a");
    link.download = "Collections_Export_" + new Date().toISOString().slice(0, 10) + ".csv";
    link.href = window.URL.createObjectURL(csvFile);
    link.click();
}

window.onload = initDropdowns;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>