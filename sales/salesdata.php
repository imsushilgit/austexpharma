<?php
session_start();

// Handle Unauthenticated AJAX Requests
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
        exit();
    }
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/../includes/core.php';

// ==========================================
// 1. BACKEND AJAX HANDLER FOR FETCH & EXPORT
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // --- OPTIMIZED PAGINATED FETCH ---
    if ($_POST['ajax_action'] === 'fetch' ||$_POST['ajax_action'] === 'export') {
        $isExport    = ($_POST['ajax_action'] === 'export');
        $page        = max(1, intval($_POST['page'] ?? 1));
        $perPage     =$isExport ? 10000 : max(10, min(100, intval($_POST['per_page'] ?? 50)));$offset      = ($page - 1) *$perPage;

        $search      = trim($_POST['search'] ?? '');
        $startDate   = trim($_POST['start_date'] ?? '');
        $endDate     = trim($_POST['end_date'] ?? '');
        $partyFilter = trim($_POST['party'] ?? '');
        $fDesig      = trim($_POST['f_desig'] ?? '');

        $where  = [];
        $params = [];$joinForFilter = "";

        if ($search !== '') {$where[] = "(i.invoice_no LIKE ? OR i.partyname LIKE ?)";
            $s = '\%' .$search . '%';
            $params[] =$s; $params[] =$s;
        }
        if ($startDate !== '' && $endDate !== '') {$where[] = "i.invoice_date BETWEEN ? AND ?";
            $params[] =$startDate; $params[] =$endDate;
        } elseif ($startDate !== '') {$where[] = "i.invoice_date >= ?";
            $params[] =$startDate;
        } elseif ($endDate !== '') {$where[] = "i.invoice_date <= ?";
            $params[] =$endDate;
        }
        if ($partyFilter !== '') {$where[] = "i.partyname = ?";
            $params[] =$partyFilter;
        }

        // Designation Hierarchy Filter Logic
        if ($fDesig !== '') {$joinForFilter = " 
                LEFT JOIN assignparties af ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(af.partyname))
                LEFT JOIN employees ef ON (af.empcode = ef.empcode OR TRIM(LOWER(i.partyname)) = TRIM(LOWER(ef.empname))) 
            ";
            if ($fDesig === 'MR') {$where[] = "(ef.designation = 'MR' OR ef.designation IS NULL OR ef.designation = '')";
            } else {
                $joinForFilter .= " LEFT JOIN employees mgr ON (ef.reporting_manager = mgr.empname OR ef.sr_manager = mgr.empname) ";
                $where[] = "(ef.designation = ? OR mgr.designation = ?)";
                $params[] =$fDesig; $params[] =$fDesig;
            }
        }

        // ROLE SCOPE: admin = all, manager = team parties, MR = own parties
        $__sc = scope_party_cond($ctx, 'i.partyname', $params);
        if ($__sc !== '') { $where[] = $__sc; }

        $whereSQL = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        try {
            $gCountStmt =$pdo->prepare("SELECT COUNT(DISTINCT i.invoice_no) FROM invoices i $joinForFilter {$whereSQL}");
            $gCountStmt->execute($params);$totalRows = (int)$gCountStmt->fetchColumn();$totalPages = max(1, (int)ceil($totalRows / $perPage));

            $sql = "
                SELECT 
                    inv.invoice_no,
                    inv.invoice_date,
                    inv.partyname,
                    MAX(COALESCE(p.area, '')) as area,
                    MAX(COALESCE(e.empname, 'Unassigned')) as empname,
                    MAX(COALESCE(e.hq, '-')) as emp_hq,
                    MAX(COALESCE(e.state, '-')) as emp_state,
                    inv.total_qty,
                    inv.total_value
                FROM (
                    SELECT 
                        i.invoice_no,
                        MAX(i.invoice_date) as invoice_date,
                        MAX(i.partyname) as partyname,
                        SUM(i.total_qty) as total_qty,
                        SUM(i.total_value) as total_value
                    FROM invoices i
                    $joinForFilter
                    {$whereSQL}
                    GROUP BY i.invoice_no
                    ORDER BY MAX(i.invoice_date) DESC, i.invoice_no DESC
                    LIMIT {$perPage} OFFSET {$offset}
                ) inv
                LEFT JOIN parties p ON inv.partyname = p.partyname
                LEFT JOIN assignparties a ON inv.partyname = a.partyname
                LEFT JOIN employees e ON (a.empcode = e.empcode OR inv.partyname = e.empname)
                GROUP BY inv.invoice_no
                ORDER BY inv.invoice_date DESC, inv.invoice_no DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows =$stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($isExport) {
                echo json_encode(["status" => "success", "data" => $rows]);
                exit();
            }

            $sumStmt =$pdo->prepare("
                SELECT 
                    COALESCE(SUM(i.total_qty),0) as sum_total_qty, 
                    COALESCE(SUM(i.total_value),0) as sum_total_value 
                FROM invoices i $joinForFilter {$whereSQL}
            ");
            $sumStmt->execute($params);
            $summary =$sumStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "status"      => "success",
                "data"        => $rows,
                "page"        => $page,
                "per_page"    => $perPage,
                "total_rows"  => $totalRows,
                "total_pages" => $totalPages,
                "summary"     => [
                    "sum_total_qty"   => (int)($summary['sum_total_qty'] ?? 0),
                    "sum_total_value" => (float)($summary['sum_total_value'] ?? 0) ] ]); } catch (PDOException$e) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
        exit();
    }

    if ($_POST['ajax_action'] === 'parties') {
        $__pp = [];
        $__ps = $pdo->prepare("SELECT partyname FROM parties WHERE 1=1" . scope_party_in($ctx, 'partyname', $__pp) . " ORDER BY partyname ASC");
        $__ps->execute($__pp);
        $parties = $__ps->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(["status" => "success", "data" => $parties]);
        exit();
    }
}

$designations =$pdo->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation != '' ORDER BY designation ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = "Invoice Master Database";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box" style="position: relative;">
    <div class="panel-title">
        <span>Invoices & Sales Ledger Database</span>
    </div>
    
    <div class="filter-grid">
        <div class="form-group" style="flex: 1.5;">
            <label>Search Invoice / Party</label>
            <input type="text" id="searchInput" placeholder="Search..." onkeydown="if(event.key==='Enter'){event.preventDefault();goPage(1);}">
        </div>
        <div class="form-group">
            <label>Start Date</label>
            <input type="date" id="startDate">
        </div>
        <div class="form-group">
            <label>End Date</label>
            <input type="date" id="endDate">
        </div>
        <div class="form-group" style="flex: 1.5;">
            <label>Party Filter</label>
            <select id="partyFilter">
                <option value="">All Parties</option>
            </select>
        </div>
        <div class="form-group">
            <label>Hierarchy / MR</label>
            <select id="fDesig" onchange="goPage(1)">
                <option value="">All Ranks</option>
                <option value="MR">MR (Individual)</option>
                <?php foreach($designations as $d): if($d==='MR') continue; ?>
                    <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?> (Team)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="min-width: 80px; flex: 0.5;">
            <label>Rows</label>
            <select id="perPage" onchange="goPage(1)">
                <option value="25">25</option>
                <option value="50" selected>50</option>
                <option value="100">100</option>
                <option value="500">500</option>
            </select>
        </div>
        <div class="btn-action-row">
            <button class="btn btn-primary" onclick="goPage(1)">Apply</button>
            <button class="btn btn-secondary" onclick="resetFilters()">Reset</button>
            <button class="btn btn-export" onclick="exportExcel()">Export</button>
        </div>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;">Sl.No</th>
                    <th>Invoice Date</th>
                    <th>Invoice No</th>
                    <th>Employee (MR)</th>
                    <th>State</th>
                    <th>MR HQ</th>
                    <th>Party Name</th>
                    <th>Area</th>
                    <th style="text-align:right;">Total Qty</th>
                    <th style="text-align:right;">Total Value (₹)</th>
                </tr>
            </thead>
            <tbody id="invoiceTableBody">
                <tr><td colspan="10" style="text-align:center; color:#64748b; padding:20px;">Loading Sales Ledger…</td></tr>
            </tbody>
            <tfoot id="tableFoot"></tfoot>
        </table>
    </div>
    <div class="pagination-bar">
        <div class="pag-info" id="pagInfo">Total: 0</div>
        <div class="pag-btns" id="pagBtns"></div>
    </div>
</div>

<script>
let currentPage = 1; let totalPages = 1;

function escapeHtml(t) { return String(t ? t : '').replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;"); }
function formatDate(d) { if (!d || d==='0000-00-00') return '-'; const p=String(d).split(/[-/]/); return p.length===3 && p[0].length===4 ? `${p[2].padStart(2,'0')}-${p[1].padStart(2,'0')}-${p[0]}` : d; }
function fmtNum(n) { return Number(n ? n : 0).toLocaleString('en-IN'); }
function fmtMoney(n) { return '₹' + Number(n ? n : 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function showLoading(on) {
    document.getElementById('loadingOverlay').classList.toggle('show', on);
}

function goPage(page) {
    showLoading(true);
    currentPage = page;
    const fd = new FormData();
    fd.append('ajax_action', 'fetch');
    fd.append('page', currentPage);
    fd.append('per_page', document.getElementById('perPage').value);
    fd.append('search', document.getElementById('searchInput').value.trim());
    fd.append('start_date', document.getElementById('startDate').value);
    fd.append('end_date', document.getElementById('endDate').value);
    fd.append('party', document.getElementById('partyFilter').value);
    fd.append('f_desig', document.getElementById('fDesig').value);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            showLoading(false);
            if (res.status === 'success') { 
                totalPages = res.total_pages; 
                renderTable(res.data, res.summary); 
                renderPagination(res); 
            } else { 
                if(typeof AppAlert !== 'undefined') AppAlert.error(res.message); else alert(res.message); 
            }
        }).catch(err => { 
            showLoading(false); 
            if(typeof AppAlert !== 'undefined') AppAlert.error("Network connection error."); else alert("Network connection error."); 
        });
}

function renderTable(data, summary) {
    const tbody = document.getElementById('invoiceTableBody');
    const tfoot = document.getElementById('tableFoot');
    if (!data.length) { tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; color:#64748b; padding:20px;">No invoices match the selected filters.</td></tr>'; tfoot.innerHTML = ''; return; }

    let html = '';
    const startIdx = (currentPage - 1) * parseInt(document.getElementById('perPage').value);
    data.forEach((inv, i) => {
        html += `<tr>
            <td style="text-align:center; color:#64748b;">${startIdx + i + 1}</td>
            <td style="font-weight:bold;">${formatDate(inv.invoice_date)}</td>
            <td><strong style="color:#0ea5e9; font-family:monospace;">${escapeHtml(inv.invoice_no)}</strong></td>
            <td><strong style="color:#1e2a78;">${escapeHtml(inv.empname)}</strong></td>
            <td style="color:#475569; font-weight:bold;">${escapeHtml(inv.emp_state)}</td>
            <td style="color:#64748b; font-weight:bold;">${escapeHtml(inv.emp_hq)}</td>
            <td><strong style="color:#0f172a;">${escapeHtml(inv.partyname)}</strong></td>
            <td>${escapeHtml(inv.area)}</td>
            <td style="text-align:right; font-weight:bold; color:#1e2a78;">${fmtNum(inv.total_qty)}</td>
            <td class="val-cell">${fmtMoney(inv.total_value)}</td>
        </tr>`;
    }); tbody.innerHTML = html;

    tfoot.innerHTML = `<tr><td colspan="8" style="text-align:right;">FILTERED GRAND TOTAL:</td><td style="text-align:right; color:#1e2a78;">${fmtNum(summary.sum_total_qty)}</td><td class="val-cell">${fmtMoney(summary.sum_total_value)}</td></tr>`;
}

function renderPagination(res) {
    const info = document.getElementById('pagInfo'); const btns = document.getElementById('pagBtns');
    const from = res.total_rows===0?0:((res.page-1)*res.per_page)+1; const to=Math.min(res.page*res.per_page, res.total_rows);
    info.textContent = `Showing ${fmtNum(from)} - ${fmtNum(to)} of ${fmtNum(res.total_rows)} Records (Page ${res.page}/${res.total_pages})`;

    let html = `<button ${res.page<=1?'disabled':''} onclick="goPage(1)">«</button><button ${res.page<=1?'disabled':''} onclick="goPage(${res.page-1})">‹</button>`;
    let start = Math.max(1, res.page-2); let end = Math.min(res.total_pages, res.page+2);
    for(let p=start; p<=end; p++) html += `<button class="${p===res.page?'active-page':''}" onclick="goPage(${p})">${p}</button>`;
    html += `<button ${res.page>=res.total_pages?'disabled':''} onclick="goPage(${res.page+1})">›</button><button ${res.page>=res.total_pages?'disabled':''} onclick="goPage(${res.total_pages})">»</button>`;
    btns.innerHTML = html;
}

function resetFilters() {
    document.getElementById('searchInput').value = ''; 
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = ''; 
    document.getElementById('partyFilter').value = '';
    document.getElementById('fDesig').value = '';
    document.getElementById('perPage').value = '50'; 
    goPage(1);
}

function exportExcel() {
    const fd = new FormData(); fd.append('ajax_action', 'export');
    fd.append('search', document.getElementById('searchInput').value.trim()); 
    fd.append('start_date', document.getElementById('startDate').value);
    fd.append('end_date', document.getElementById('endDate').value); 
    fd.append('party', document.getElementById('partyFilter').value);
    fd.append('f_desig', document.getElementById('fDesig').value);

    showLoading(true);
    fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
        showLoading(false);
        if (res.status!=='success'||!res.data.length) {
            if(typeof AppAlert !== 'undefined') AppAlert.warning('No records to export.'); else alert('No records to export.');
            return;
        }
        let html = `<table border="1"><tr style="background:#1e2a78;color:white;"><th>Sl No.</th><th>Invoice Date</th><th>Invoice Number</th><th>Employee (MR)</th><th>State</th><th>MR HQ</th><th>Party Name</th><th>Area</th><th>Total Qty</th><th>Total Value</th></tr>`;
        res.data.forEach((inv, i) => {
            html += `<tr><td>${i+1}</td><td>${formatDate(inv.invoice_date)}</td><td>${escapeHtml(inv.invoice_no)}</td><td>${escapeHtml(inv.empname)}</td><td>${escapeHtml(inv.emp_state)}</td><td>${escapeHtml(inv.emp_hq)}</td><td>${escapeHtml(inv.partyname)}</td><td>${escapeHtml(inv.area)}</td><td>${inv.total_qty}</td><td>${inv.total_value}</td></tr>`;
        });
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([html+"</table>"], { type: 'application/vnd.ms-excel' }));
        a.download = `Invoices_Sales_Export_${new Date().toISOString().slice(0,10)}.xls`; a.click();
    }).catch(err => {
        showLoading(false);
        if(typeof AppAlert !== 'undefined') AppAlert.error("Export failed. Network error."); else alert("Export failed.");
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const fd = new FormData(); fd.append('ajax_action', 'parties');
    fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(res => {
        if(res.status==='success') {
            const sel = document.getElementById('partyFilter');
            res.data.forEach(p => { const o = document.createElement('option'); o.value = p; o.textContent = p; sel.appendChild(o); });
        }
    }).catch(err => console.error(err));
    goPage(1);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>