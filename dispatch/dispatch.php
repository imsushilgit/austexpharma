<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action']) or isset($_GET['action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]); exit();
    }
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/../includes/core.php';

// ROLE SCOPE: admin & dispatch/logistics staff see the full queue; sales roles only their team's records
function dispatch_queue_rows($pdo, $ctx) {
    $p = [];
    $sql = "SELECT * FROM dispatch_records WHERE 1=1";
    if (!is_ops_user($ctx, ['DISPATCH','LOGISTICS','WAREHOUSE'])) { $sql .= scope_in($ctx, 'empcode', $p); }
    $st = $pdo->prepare($sql . " ORDER BY id DESC");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function getNormalizedStateAliases($inputState) {
    $normalized = trim(strtolower($inputState));
    $stateAliases = [
        'bihar' => ['bihar', 'br', 'bih'],
        'jharkhand' => ['jharkhand', 'jh', 'jhk'],
        'west bengal' => ['west bengal', 'wb', 'w.b.', 'westbengal'],
        'uttar pradesh' => ['uttar pradesh', 'up', 'u.p.', 'uttarpradesh'],
        'delhi' => ['delhi', 'dl'],
        'maharashtra' => ['maharashtra', 'mh'],
        'gujarat' => ['gujarat', 'gj'],
        'rajasthan' => ['rajasthan', 'rj'],
        'odisha' => ['odisha', 'or', 'orissa', 'od'],
        'assam' => ['assam', 'as'],
        'madhya pradesh' => ['madhya pradesh', 'mp', 'm.p.', 'madhyapradesh'],
        'punjab' => ['punjab', 'pb'],
        'haryana' => ['haryana', 'hr']
    ];

    if (array_key_exists($normalized, $stateAliases)) {
        return $stateAliases[$normalized];
    }
    return [$normalized];
}

// ==========================================
// 1. AUTO-CREATE / UPDATE DISPATCH TABLE SCHEMA
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS dispatch_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatch_no VARCHAR(20) UNIQUE NOT NULL,
    state VARCHAR(100) NOT NULL,
    empcode VARCHAR(50) NOT NULL,
    empname VARCHAR(150) NOT NULL,
    hq VARCHAR(100) NOT NULL,
    partyname VARCHAR(150) NOT NULL,
    area VARCHAR(100) NOT NULL,
    transporter VARCHAR(150) NOT NULL,
    invoice_no VARCHAR(50) NOT NULL,
    cases INT NOT NULL,
    dispatch_date DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'Pending',
    hold_reason TEXT DEFAULT NULL,
    picked_by VARCHAR(100) DEFAULT NULL,
    matched_by VARCHAR(100) DEFAULT NULL,
    packed_by VARCHAR(100) DEFAULT NULL,
    dispatched_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ==========================================
// 2. BACKEND AJAX HANDLER FOR DISPATCH DATA
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'get_employees_by_state') {
            $stateInput = trim($_POST['state'] ?? '');
            $salesOnly = isset($_POST['sales_only']) && $_POST['sales_only'] === 'true';

            $sql = "SELECT empcode, empname as name, department, state, hq, designation FROM employees WHERE 1=1";
            $params = [];

            if (!empty($stateInput)) {
                $aliases = getNormalizedStateAliases($stateInput);
                $stateClause = [];
                
                foreach ($aliases as $index => $alias) {
                    $stateClause[] = "TRIM(LOWER(state)) LIKE ?";
                    $stateClause[] = "TRIM(LOWER(hq)) LIKE ?";
                    $params[] = "%$alias%";
                    $params[] = "%$alias%";
                }
                $sql .= " AND (" . implode(" OR ", $stateClause) . ")";
            }
            
            if ($salesOnly) {
                $sql .= " AND department LIKE '%Sales%'";
            }
            $sql .= " ORDER BY empname ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(["status" => "success", "employees" => $employees]);
            exit();

        } elseif ($action === 'get_mr_invoices') {
            $empcode = trim($_POST['empcode'] ?? '');
            
            if (empty($empcode)) {
                echo json_encode(["status" => "success", "invoices" => [], "parties" => []]);
                exit();
            }

            $empStmt = $pdo->prepare("SELECT empname, hq, state, designation FROM employees WHERE empcode = ?");
            $empStmt->execute([$empcode]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
            
            $empname = $emp ? trim($emp['empname']) : '';
            $hq = $emp ? trim($emp['hq']) : '';
            $state = $emp ? trim($emp['state']) : '';
            $designation = $emp ? strtoupper(trim($emp['designation'])) : '';

            if ($designation !== 'MR') {
                $hq = 'MANAGER_SKIP_HQ';
                $state = 'MANAGER_SKIP_STATE';
            }

            $stateAliasesList = getNormalizedStateAliases($state);
            $stateCriteriaArr = ["TRIM(LOWER(p.state)) = ?"];
            $stateParams = [strtolower($state)];

            foreach ($stateAliasesList as $alias) {
                $stateCriteriaArr[] = "TRIM(LOWER(p.state)) = ?";
                $stateParams[] = strtolower($alias);
            }
            $stateCriteriaClause = "(" . implode(" OR ", $stateCriteriaArr) . ")";

            $invSql = "
                SELECT DISTINCT i.invoice_no, i.invoice_date, i.partyname, i.product_name, i.batch_no, i.qty, i.free_qty, i.total_qty, i.mrp, i.total_value,
                       COALESCE(p.area, '') as area, COALESCE(p.hq, '') as hq
                FROM invoices i
                LEFT JOIN parties p ON TRIM(LOWER(i.partyname)) = TRIM(LOWER(p.partyname))
                WHERE i.invoice_no NOT IN (
                    SELECT invoice_no FROM dispatch_records 
                    WHERE status IN ('Dispatched', 'Delivered') 
                    AND invoice_no IS NOT NULL AND invoice_no != ''
                )
                AND (
                    TRIM(LOWER(i.partyname)) IN (SELECT TRIM(LOWER(partyname)) FROM assignparties WHERE empcode = ?)
                    OR (p.hq IS NOT NULL AND p.hq != '' AND (TRIM(LOWER(p.hq)) = TRIM(LOWER(?)) OR TRIM(LOWER(p.hq)) = TRIM(LOWER(?))))
                    OR (p.hq IS NOT NULL AND p.hq != '' AND $stateCriteriaClause)
                    OR TRIM(LOWER(i.partyname)) = TRIM(LOWER(?))
                )
                ORDER BY i.invoice_no ASC;
            ";

            $execParams = array_merge([$empcode, $hq, $state], $stateParams, [$empname]);

            $invStmt = $pdo->prepare($invSql);
            $invStmt->execute($execParams);
            $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

            $parties = [];
            $seenParties = [];
            foreach ($invoices as $inv) {
                $pName = trim($inv['partyname']);
                if (!in_array($pName, $seenParties)) {
                    $seenParties[] = $pName;
                    $parties[] = [
                        'partyname' => $pName,
                        'hq' => $inv['hq'],
                        'area' => $inv['area']
                    ];
                }
            }

            echo json_encode(["status" => "success", "invoices" => $invoices, "parties" => $parties]);
            exit();

        } elseif ($action === 'save_dispatch') {
            $id = $_POST['record_id'] ?? '';
            $dispatch_no = trim($_POST['dispatch_no'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $empcode = trim($_POST['empcode'] ?? '');
            $empname = trim($_POST['empname'] ?? '');
            $hq = trim($_POST['hq'] ?? '');
            $partyname = trim($_POST['partyname'] ?? '');
            $area = trim($_POST['area'] ?? '');
            $transporter = trim($_POST['transporter'] ?? '');
            $invoice_no = trim($_POST['invoice_no'] ?? '');
            $cases = intval($_POST['cases'] ?? 0);
            $dispatch_date = trim($_POST['dispatch_date'] ?? '');
            $status = trim($_POST['status'] ?? 'Pending');
            $hold_reason = trim($_POST['hold_reason'] ?? '');
            
            $picked_by = trim($_POST['picked_by'] ?? '');
            $matched_by = trim($_POST['matched_by'] ?? '');
            $packed_by = trim($_POST['packed_by'] ?? '');
            $dispatched_by = trim($_POST['dispatched_by'] ?? '');

            if (empty($dispatch_no) or empty($state) or empty($empcode) or empty($partyname) or empty($transporter) or empty($invoice_no) or empty($cases) or empty($dispatch_date)) {
                echo json_encode(["status" => "error", "message" => "All mandatory fields must be filled completely."]);
                exit();
            }

            if ($status === 'Hold' && empty($hold_reason)) {
                echo json_encode(["status" => "error", "message" => "Please provide a reason for placing this dispatch on Hold."]);
                exit();
            }

            if (!empty($id)) {
                $stmt = $pdo->prepare("UPDATE dispatch_records SET state=?, empcode=?, empname=?, hq=?, partyname=?, area=?, transporter=?, invoice_no=?, cases=?, dispatch_date=?, status=?, hold_reason=?, picked_by=?, matched_by=?, packed_by=?, dispatched_by=? WHERE id=?");
                $stmt->execute([$state, $empcode, $empname, $hq, $partyname, $area, $transporter, $invoice_no, $cases, $dispatch_date, $status, $hold_reason, $picked_by, $matched_by, $packed_by, $dispatched_by, $id]);
                $response = ["status" => "success", "message" => "Dispatch record {$dispatch_no} updated successfully!"];
            } else {
                $stmt = $pdo->prepare("INSERT INTO dispatch_records (dispatch_no, state, empcode, empname, hq, partyname, area, transporter, invoice_no, cases, dispatch_date, status, hold_reason, picked_by, matched_by, packed_by, dispatched_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$dispatch_no, $state, $empcode, $empname, $hq, $partyname, $area, $transporter, $invoice_no, $cases, $dispatch_date, $status, $hold_reason, $picked_by, $matched_by, $packed_by, $dispatched_by]);
                $response = ["status" => "success", "message" => "New dispatch {$dispatch_no} logged successfully!"];
            }

        } elseif ($action === 'delete_dispatch') {
            $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : [];
            
            if (!empty($ids) && is_array($ids)) {
                $inQuery = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM dispatch_records WHERE id IN ($inQuery)");
                $stmt->execute($ids);
                $response = ["status" => "success", "message" => count($ids) . " Dispatch record(s) successfully deleted."];
            } else {
                $response = ["status" => "error", "message" => "No valid records selected for deletion."];
            }
        }

        if ($response['status'] === 'success') {
            $response['queue'] = dispatch_queue_rows($pdo, $ctx);
            $lastDisp = $pdo->query("SELECT dispatch_no FROM dispatch_records ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($lastDisp) {
                $num = intval(str_replace('DISP', '', $lastDisp)) + 1;
                $response['next_dispatch'] = 'DISP' . str_pad($num, 4, '0', STR_PAD_LEFT);
            } else {
                $response['next_dispatch'] = 'DISP0001';
            }
        }
    } catch (PDOException $e) {
        $response['message'] = "Database error. Please try again. Detailed error: " . $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

$dbStates = $pdo->query("SELECT statename FROM states ORDER BY statename ASC")->fetchAll(PDO::FETCH_ASSOC);
$dbTransporters = $pdo->query("SELECT transportername, servicearea FROM transporters ORDER BY transportername ASC")->fetchAll(PDO::FETCH_ASSOC);
$dbSenders = $pdo->query("SELECT sendername FROM senders ORDER BY sendername ASC")->fetchAll(PDO::FETCH_COLUMN);

$lastDisp = $pdo->query("SELECT dispatch_no FROM dispatch_records ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextDispatchNo = $lastDisp ? 'DISP' . str_pad(intval(str_replace('DISP', '', $lastDisp)) + 1, 4, '0', STR_PAD_LEFT) : 'DISP0001';
$dbQueue = dispatch_queue_rows($pdo, $ctx);

$pageTitle = "Dispatch Operations";
include __DIR__ . '/../includes/header.php';
?>


<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<div class="panel-box">
    <div class="panel-title" id="formHeader">1. Operational Dispatch & Warehouse Audit Entry</div>
    <form id="dispatchForm" onsubmit="submitDispatch(event)" class="compact-form">
        <input type="hidden" name="record_id" id="recordId" value="">
        
        <div class="form-grid" style="grid-template-columns: 0.8fr 0.9fr 1.4fr 0.9fr 1.4fr 1fr;">
            <div class="form-group">
                <label>Dispatch No</label>
                <input type="text" name="dispatch_no" id="dispatchNo" value="<?= $nextDispatchNo ?>" readonly style="font-family: monospace; font-weight:bold; color:#0ea5e9;">
            </div>
            <div class="form-group">
                <label>State Filter</label>
                <select name="state" id="stateFilter" onchange="populateEmployeeDropdown()" required><option value="">Select State</option></select>
            </div>
            <div class="form-group">
                <label style="display:flex; justify-content:space-between; align-items:center;">
                    Employee / Manager
                    <span style="display:flex; align-items:center; gap:4px; font-size:8px; color:#0ea5e9; text-transform:none;">
                        <input type="checkbox" id="deptSalesCheck" onchange="populateEmployeeDropdown()" style="width:10px; height:10px; margin:0; cursor:pointer;"> Sales Only
                    </span>
                </label>
                <select name="empcode" id="empSelect" onchange="onEmployeeSelect()" required><option value="">Select Employee</option></select>
                <input type="hidden" name="empname" id="hiddenEmpName">
            </div>
            <div class="form-group">
                <label>HQ</label>
                <input type="text" name="hq" id="hq" readonly required tabindex="-1">
            </div>
            <div class="form-group">
                <label>Pending Invoice</label>
                <select name="invoice_no" id="invoiceNo" onchange="onInvoiceSelect()" required><option value="">Select Invoice</option></select>
            </div>
            <div class="form-group">
                <label>Party Name</label>
                <select name="partyname" id="partySelect" required><option value="">Select Party</option></select>
            </div>
        </div>

        <div class="form-grid" style="grid-template-columns: 1fr 1fr 0.7fr 0.9fr 1fr;">
            <div class="form-group">
                <label>Delivery Area</label>
                <input type="text" name="area" id="area" readonly tabindex="-1">
            </div>
            <div class="form-group">
                <label>Transporter</label>
                <select name="transporter" id="transporterSelect" required><option value="">Select Transporter</option></select>
            </div>
            <div class="form-group">
                <label>Cases</label>
                <input type="number" name="cases" id="caseQty" min="1" placeholder="Qty" required>
            </div>
            <div class="form-group">
                <label>Dispatch Date</label>
                <input type="date" name="dispatch_date" id="dispatchDate" value="<?= date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Status Stamp</label>
                <select name="status" id="statusSelect" onchange="toggleHoldReason()" required>
                    <option value="Pending">Pending</option>
                    <option value="Invoiced">Invoiced</option>
                    <option value="Dispatched">Dispatched</option>
                    <option value="Delivered">Delivered</option>
                    <option value="Hold">Hold</option>
                </select>
            </div>
        </div>

        <div id="holdReasonRow" class="form-grid" style="grid-template-columns: 1fr; display: none;">
            <div class="form-group">
                <label style="color: #dc2626;">Hold Reason / Remarks</label>
                <input type="text" name="hold_reason" id="holdReasonInput" placeholder="Specify hold reason..." style="border-color: #fca5a5; background: #fef2f2;">
            </div>
        </div>

        <div class="form-grid" style="grid-template-columns: repeat(4, 1fr); background: #f8fafc; padding: 8px; border: 1px dotted #cbd5e1; border-radius: 4px;">
            <div class="form-group">
                <label>Picked By</label>
                <select name="picked_by" id="pickedBy"><option value="">Select Staff</option>
                    <?php foreach($dbSenders as $staff): ?><option value="<?= htmlspecialchars($staff) ?>"><?= htmlspecialchars($staff) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Matched By</label>
                <select name="matched_by" id="matchedBy"><option value="">Select Staff</option>
                    <?php foreach($dbSenders as $staff): ?><option value="<?= htmlspecialchars($staff) ?>"><?= htmlspecialchars($staff) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Packed By</label>
                <select name="packed_by" id="packedBy"><option value="">Select Staff</option>
                    <?php foreach($dbSenders as $staff): ?><option value="<?= htmlspecialchars($staff) ?>"><?= htmlspecialchars($staff) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Dispatched By</label>
                <select name="dispatched_by" id="dispatchedBy"><option value="">Select Staff</option>
                    <?php foreach($dbSenders as $staff): ?><option value="<?= htmlspecialchars($staff) ?>"><?= htmlspecialchars($staff) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="invoiceItemsPanel">
            <div style="font-size: 10px; color: #1e2a78; font-weight: bold; text-transform: uppercase; margin-bottom: 6px; display: flex; justify-content: space-between;">
                <span id="lblInvTitle">Invoice Details</span>
                <span id="lblInvParty" style="color: #0ea5e9;"></span>
            </div>
            <table class="compact-table" style="background: #fff; width: 100%;">
                <thead>
                    <tr>
                        <th style="text-align:left;">Product Name</th>
                        <th>Batch</th>
                        <th style="text-align:center;">Billed</th>
                        <th style="text-align:center;">Free</th>
                        <th style="text-align:center;">Total</th>
                        <th style="text-align:right;">MRP</th>
                        <th style="text-align:right;">Value (₹)</th>
                    </tr>
                </thead>
                <tbody id="invoiceItemsBody"></tbody>
            </table>
        </div>

        <div class="btn-action-row">
            <button type="submit" class="btn btn-primary" id="btnSubmitForm">Save Record</button>
            <button type="button" class="btn btn-secondary" id="btnCancel" onclick="resetForm(true)" style="display:none;">Cancel Edit</button>
            <button type="button" class="btn btn-secondary" id="btnClear" onclick="resetForm(false)">Clear Form</button>
        </div>
    </form>
</div>

<div class="panel-box" style="padding-bottom: 0;">
    <div class="panel-title" style="margin-bottom: 8px;">
        <span>2. Master Dispatch Database Ledger</span>
        <div style="display:flex; gap: 6px;">
            <button class="btn-export" onclick="exportToExcel()">Export Excel</button>
            <button class="btn-secondary" style="height: 22px; padding: 0 10px; font-size: 9px;" onclick="copyTableAsImage()">Copy Image</button>
            <button class="btn-warning" id="btnGenerateMsg" onclick="generateSelectedMessage()" disabled style="opacity: 0.5;">Generate Alert</button>
            <button class="btn-danger" id="btnDeleteSelected" onclick="deleteSelected()" disabled style="opacity: 0.5;">Delete Selected</button>
        </div>
    </div>
    
    <div class="table-container" style="border-bottom: none; border-radius: 4px 4px 0 0;">
        <table class="compact-table" id="recordTable">
            <thead>
                <tr>
                    <th class="checkbox-cell"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                    <th>Disp No</th>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Party Name</th>
                    <th>Employee (MR)</th>
                    <th>HQ / Area</th>
                    <th>Transporter</th>
                    <th>Cases</th>
                    <th style="text-align:center;">Stamp Status</th>
                    <th style="text-align: center;">Print</th>
                </tr>
                <tr class="search-row" data-html2canvas-ignore="true">
                    <td></td>
                    <td><input type="text" placeholder="Search..." data-key="dispatch_no" class="col-search"></td>
                    <td><input type="text" placeholder="Search..." data-key="dispatch_date" class="col-search"></td>
                    <td><input type="text" placeholder="Search..." data-key="invoice_no" class="col-search"></td>
                    <td><input type="text" placeholder="Search..." data-key="partyname" class="col-search"></td>
                    <td><input type="text" placeholder="Search..." data-key="empname" class="col-search"></td>
                    <td><input type="text" placeholder="Search..." data-key="hq" class="col-search"></td>
                    <td><input type="text" placeholder="Search..." data-key="transporter" class="col-search"></td>
                    <td></td>
                    <td><input type="text" placeholder="Search..." data-key="status" class="col-search"></td>
                    <td></td>
                </tr>
            </thead>
            <tbody id="queueTbody"></tbody>
        </table>
    </div>
    
    <div class="pagination-bar" data-html2canvas-ignore="true">
        <div class="page-info" id="pageInfoText">Total: 0 - Page 1 of 1</div>
        <div style="display:flex; gap:6px;">
            <button class="btn-secondary" style="height:22px; padding:0 10px; font-size:9px;" id="btnPrev" onclick="changePage(-1)">Prev</button>
            <button class="btn-secondary" style="height:22px; padding:0 10px; font-size:9px;" id="btnNext" onclick="changePage(1)">Next</button>
        </div>
    </div>
</div>

<div class="panel-box" id="messagePanel" style="display:none;">
    <div class="panel-title">3. Generated Courier Tracking Alerts</div>
    <textarea id="messageBox" readonly></textarea>
    <div class="btn-action-row" style="margin-top: 8px;">
        <button class="btn btn-warning" onclick="copyMessage()">Copy to Clipboard</button>
        <button class="btn btn-secondary" onclick="document.getElementById('messagePanel').style.display='none'">Close</button>
    </div>
</div>

<div id="compactTableContainer"></div>

<script>
    const dbStates = <?= json_encode($dbStates); ?>;
    const dbTransporters = <?= json_encode($dbTransporters); ?>;
    
    let allDispatchRecords = <?= json_encode($dbQueue); ?>;
    let filteredRecords = [];
    let defaultDispatchNo = "<?= $nextDispatchNo ?>";
    
    let currentEmployees = [];
    let currentMRInvoices = [];
    let currentMRParties = [];
    
    let currentPage = 1;
    const recordsPerPage = 50;

    const stateAliases = {
        'bihar': ['bihar', 'br', 'bih'],
        'jharkhand': ['jharkhand', 'jh', 'jhk'],
        'west bengal': ['west bengal', 'wb'],
        'uttar pradesh': ['uttar pradesh', 'up'],
        'delhi': ['delhi', 'dl'],
        'maharashtra': ['maharashtra', 'mh'],
        'gujarat': ['gujarat', 'gj'],
        'rajasthan': ['rajasthan', 'rj'],
        'odisha': ['odisha', 'or', 'orissa'],
        'assam': ['assam', 'as'],
        'madhya pradesh': ['madhya pradesh', 'mp'],
        'punjab': ['punjab', 'pb'],
        'haryana': ['haryana', 'hr']
    };

    function escapeHtml(text) {
        if (!text && text !== 0) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
    
    function formatDate(dateString) {
        if(!dateString) return '';
        const parts = dateString.split('-');
        if(parts.length !== 3) return dateString;
        return `${parts[2]}-${parts[1]}-${parts[0]}`;
    }

    function initLoad() {
        const stateFilter = document.getElementById('stateFilter');
        dbStates.forEach(s => stateFilter.appendChild(new Option(s.statename, s.statename)));
        populateEmployeeDropdown();
        filteredRecords = [...allDispatchRecords];
        renderLedger();
    }

    function populateEmployeeDropdown(selectedCode = '') {
        const stateVal = (document.getElementById('stateFilter').value ? document.getElementById('stateFilter').value : '').trim().toLowerCase();
        const salesOnly = document.getElementById('deptSalesCheck').checked;
        const empSelect = document.getElementById('empSelect');
        
        empSelect.innerHTML = '<option value="">Loading...</option>';

        const formData = new FormData();
        formData.append('ajax_action', 'get_employees_by_state');
        formData.append('state', stateVal);
        formData.append('sales_only', salesOnly);

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            empSelect.innerHTML = '<option value="">Select Employee</option>';
            if (data.status === 'success' && data.employees) {
                currentEmployees = data.employees;
                data.employees.forEach(e => {
                    const desigLabel = e.designation ? ` [${e.designation}]` : '';
                    const opt = new Option(`${e.name} (${e.hq})${desigLabel}`, e.empcode);
                    if(e.empcode === selectedCode) opt.selected = true;
                    empSelect.appendChild(opt);
                });
            }
            onEmployeeSelect(selectedCode);
        })
        .catch(() => {
            empSelect.innerHTML = '<option value="">Select Employee</option>';
        });
    }

    function onEmployeeSelect(selectedInvoice = '', selectedParty = '', selectedTransporter = '') {
        const empSelect = document.getElementById("empSelect");
        const empCode = empSelect.value;
        
        const emp = currentEmployees.find(e => e.empcode === empCode);
        document.getElementById("hiddenEmpName").value = emp ? emp.name : '';

        const hqInput = document.getElementById("hq");
        const partySelect = document.getElementById("partySelect");
        const invSelect = document.getElementById("invoiceNo");
        
        hqInput.value = '';
        partySelect.innerHTML = '<option value="">Select Party</option>';
        invSelect.innerHTML = '<option value="">Select Pending Invoice</option>';
        document.getElementById("area").value = '';
        document.getElementById("transporterSelect").innerHTML = '<option value="">Select Transporter</option>';
        document.getElementById('invoiceItemsPanel').style.display = 'none';
        
        if (!empCode) return;

        if (emp) {
            const eHq = (emp.hq ? emp.hq : '').trim();
            const eState = (emp.state ? emp.state : '').trim();
            const isHQAState = Object.keys(stateAliases).some(key => stateAliases[key].includes(eHq.toLowerCase()));
            hqInput.value = isHQAState ? eState : eHq;
        }

        const formData = new FormData();
        formData.append('ajax_action', 'get_mr_invoices');
        formData.append('empcode', empCode);

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                currentMRInvoices = data.invoices ? data.invoices : [];
                currentMRParties = data.parties ? data.parties : [];

                const uniqueInvoices = [...new Set(currentMRInvoices.map(i => i.invoice_no))];
                uniqueInvoices.forEach(invNo => {
                    const opt = new Option(invNo, invNo);
                    if(invNo === selectedInvoice) opt.selected = true;
                    invSelect.appendChild(opt);
                });

                if (selectedInvoice) onInvoiceSelect(selectedParty, selectedTransporter);
            }
        });
    }

    function onInvoiceSelect(preselectedParty = '', preselectedTransporter = '') {
        const invNo = document.getElementById("invoiceNo").value;
        const panel = document.getElementById('invoiceItemsPanel');
        const partySelect = document.getElementById("partySelect");
        
        partySelect.innerHTML = '<option value="">Select Party</option>';
        document.getElementById("area").value = '';
        document.getElementById("transporterSelect").innerHTML = '<option value="">Select Transporter</option>';
        
        if (!invNo) {
            panel.style.display = 'none';
            return;
        }

        const invItems = currentMRInvoices.filter(i => i.invoice_no === invNo);
        
        if (invItems.length > 0) {
            const rawPartyName = preselectedParty ? preselectedParty : invItems[0].partyname;
            const empName = document.getElementById("hiddenEmpName").value;
            
            partySelect.innerHTML = '';
            partySelect.appendChild(new Option(`${rawPartyName} (MR: ${empName})`, rawPartyName));
            partySelect.value = rawPartyName;
            
            populateArea(rawPartyName, preselectedTransporter);
            showInvoiceDetails(invNo, invItems);
        } else {
            panel.style.display = 'none';
        }
    }

    function populateArea(pName, selectedTransporter = '') {
        const areaInput = document.getElementById("area");
        const transSelect = document.getElementById("transporterSelect");
        
        areaInput.value = '';
        transSelect.innerHTML = '<option value="">Select Transporter</option>';
        
        if (!pName) return;

        const pNameLower = pName.trim().toLowerCase();
        const party = currentMRParties.find(p => (p.partyname ? p.partyname : '').trim().toLowerCase() === pNameLower);
        
        if (party) {
            areaInput.value = party.area ? party.area : '';
            
            dbTransporters.forEach(t => {
                const opt = new Option(t.transportername, t.transportername);
                if (t.transportername === selectedTransporter) {
                    opt.selected = true;
                } else if (!selectedTransporter && t.servicearea && party.area && t.servicearea.toLowerCase().includes(party.area.toLowerCase())) {
                    opt.selected = true;
                }
                transSelect.appendChild(opt);
            });
        } else {
            dbTransporters.forEach(t => transSelect.appendChild(new Option(t.transportername, t.transportername)));
        }
    }

    function toggleHoldReason() {
        const status = document.getElementById('statusSelect').value;
        const row = document.getElementById('holdReasonRow');
        const input = document.getElementById('holdReasonInput');
        if (status === 'Hold') {
            row.style.display = 'grid';
            input.required = true;
        } else {
            row.style.display = 'none';
            input.required = false;
            input.value = '';
        }
    }

    function showInvoiceDetails(invNo, items) {
        const panel = document.getElementById('invoiceItemsPanel');
        const tbody = document.getElementById('invoiceItemsBody');

        document.getElementById('lblInvTitle').innerText = `Invoice Items: ${invNo}`;
        document.getElementById('lblInvParty').innerText = `Party: ${items[0].partyname}`;

        let html = '';
        let tVal = 0, tQty = 0;
        
        items.forEach(i => {
            html += `<tr>
                <td style="text-align:left; font-weight:bold; color:var(--navy);">${escapeHtml(i.product_name)}</td>
                <td style="font-family:monospace; color:#64748b;">${escapeHtml(i.batch_no)}</td>
                <td style="text-align:center;">${i.qty}</td>
                <td style="text-align:center;">${i.free_qty}</td>
                <td style="text-align:center; font-weight:bold;">${i.total_qty}</td>
                <td style="text-align:right;">₹${i.mrp}</td>
                <td style="text-align:right; font-weight:bold;">₹${parseFloat(i.total_value).toFixed(2)}</td>
            </tr>`;
            tQty += parseInt(i.total_qty);
            tVal += parseFloat(i.total_value);
        });
        
        html += `<tr style="background:#e0f2fe; font-weight:bold;">
            <td colspan="4" style="text-align:right; border:none; padding-right:10px;">Total Valuation:</td>
            <td style="text-align:center; border:none;">${tQty} Units</td>
            <td style="border:none;"></td>
            <td style="text-align:right; border:none; color: #065f46; font-size:10px;">₹${tVal.toFixed(2)}</td>
        </tr>`;

        tbody.innerHTML = html;
        panel.style.display = 'block';
    }

    function renderLedger() {
        const tbody = document.getElementById("queueTbody");
        const totalRecords = filteredRecords.length;
        
        let totalPages = Math.ceil(totalRecords / recordsPerPage);
        if (totalPages < 1) totalPages = 1;
        
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;

        document.getElementById('pageInfoText').innerText = `Total: ${totalRecords} - Page ${currentPage} of ${totalPages}`;
        document.getElementById('btnPrev').disabled = currentPage === 1;
        document.getElementById('btnNext').disabled = currentPage === totalPages;
        
        if (totalRecords === 0) {
            tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; padding: 20px; color: #64748b;">No records found.</td></tr>`;
            return;
        }

        const startIndex = (currentPage - 1) * recordsPerPage;
        const endIndex = startIndex + recordsPerPage;
        const pageData = filteredRecords.slice(startIndex, endIndex);

        let html = '';
        pageData.forEach(r => {
            let statusDisplay = r.status;
            if(r.status === 'Hold' && r.hold_reason) {
                statusDisplay = `Hold: ${escapeHtml(r.hold_reason)}`;
            }

            html += `<tr class="clickable-row" onclick="editRecord(${r.id})">
                <td class="checkbox-cell" onclick="event.stopPropagation()" data-html2canvas-ignore="true">
                    <input type="checkbox" class="row-checkbox" value="${r.id}" onchange="updateMessageButton()">
                </td>
                <td style="color: #0ea5e9; font-family: monospace; font-weight:bold;">${escapeHtml(r.dispatch_no)}</td>
                <td>${formatDate(r.dispatch_date)}</td>
                <td style="color: #1e2a78; font-family: monospace; font-weight:bold;">${escapeHtml(r.invoice_no)}</td>
                
                <td style="font-weight: 600;">${escapeHtml(r.partyname)}</td>
                <td><strong style="color:#059669;">${escapeHtml(r.empname)}</strong></td>
                
                <td><span style="font-weight: 600;">${escapeHtml(r.hq)}</span> <br><span style="color:#64748b; font-size:8px;">${escapeHtml(r.area)}</span></td>
                <td>${escapeHtml(r.transporter)}</td>
                <td style="text-align: center; font-weight: bold;">${r.cases}</td>
                
                <td style="text-align: center;"><span class="stamp stamp-${r.status}">${escapeHtml(statusDisplay)}</span></td>
                
                <td style="text-align: center; display: flex; justify-content: center; gap: 3px;" onclick="event.stopPropagation()" data-html2canvas-ignore="true">
                    <form action="dispatchreceipt.php" method="POST" target="_blank" style="margin:0; padding:0;">
                        <input type="hidden" name="id" value="${r.id}">
                        <button type="submit" style="background:#e2e8f0; color:#0f172a; border:1px solid #cbd5e1; height:18px; padding:0 6px; font-size:8px; border-radius:2px; cursor:pointer;">Print</button>
                    </form>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;
        document.getElementById("selectAll").checked = false;
        updateMessageButton();
    }

    function editRecord(id) {
        const r = allDispatchRecords.find(record => record.id == id);
        if(!r) return;

        document.getElementById('recordId').value = r.id;
        document.getElementById('dispatchNo').value = r.dispatch_no;
        document.getElementById('stateFilter').value = r.state;
        document.getElementById('deptSalesCheck').checked = false;
        
        populateEmployeeDropdown(r.empcode);
        setTimeout(() => {
            document.getElementById('invoiceNo').value = r.invoice_no;
            onInvoiceSelect(r.partyname, r.transporter);
        }, 300);

        document.getElementById('caseQty').value = r.cases;
        document.getElementById('dispatchDate').value = r.dispatch_date;
        document.getElementById('statusSelect').value = r.status;
        toggleHoldReason();
        document.getElementById('holdReasonInput').value = r.hold_reason ? r.hold_reason : '';
        
        document.getElementById('pickedBy').value = r.picked_by ? r.picked_by : '';
        document.getElementById('matchedBy').value = r.matched_by ? r.matched_by : '';
        document.getElementById('packedBy').value = r.packed_by ? r.packed_by : '';
        document.getElementById('dispatchedBy').value = r.dispatched_by ? r.dispatched_by : '';
        
        document.getElementById('btnSubmitForm').innerText = 'Update Record';
        document.getElementById('btnCancel').style.display = 'inline-flex';
        document.getElementById('btnClear').style.display = 'none';

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function changePage(direction) {
        currentPage += direction;
        renderLedger();
    }

    function applyColumnSearch() {
        const inputs = document.querySelectorAll('.col-search');
        let filters = {};
        
        inputs.forEach(input => {
            const val = input.value.trim().toLowerCase();
            if (val) filters[input.dataset.key] = val;
        });

        filteredRecords = allDispatchRecords.filter(r => {
            for (const key in filters) {
                let cellData = (r[key] ? r[key] : '').toString().toLowerCase();
                if (key === 'dispatch_date') cellData = formatDate(r[key]); 
                if (!cellData.includes(filters[key])) return false; 
            }
            return true;
        });

        currentPage = 1; 
        renderLedger();
    }

    document.querySelectorAll('.col-search').forEach(input => {
        input.addEventListener('keyup', applyColumnSearch);
    });

    function updateMessageButton() {
        const count = document.querySelectorAll(".row-checkbox:checked").length;
        
        const btnAlert = document.getElementById("btnGenerateMsg");
        btnAlert.disabled = count === 0; 
        btnAlert.style.opacity = count > 0 ? "1" : "0.5";

        const btnDel = document.getElementById("btnDeleteSelected");
        if(btnDel) {
            btnDel.disabled = count === 0; 
            btnDel.style.opacity = count > 0 ? "1" : "0.5";
        }
    }

    function toggleSelectAll() {
        const state = document.getElementById("selectAll").checked;
        document.querySelectorAll(".row-checkbox").forEach(cb => { cb.checked = state; }); 
        updateMessageButton();
    }

    function submitDispatch(e) {
        e.preventDefault();
        const empCode = document.getElementById('empSelect').value;
        const invNo = document.getElementById('invoiceNo').value;
        const partyName = document.getElementById('partySelect').value;

        if (!empCode) {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Please select an Employee (MR).');
            return;
        }
        if (!invNo) {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Please select a Pending Invoice.');
            return;
        }
        if (!partyName) {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Party name is required.');
            return;
        }

        const formData = new FormData(document.getElementById('dispatchForm'));
        formData.append('ajax_action', 'save_dispatch');

        document.getElementById('btnSubmitForm').innerText = 'Saving...';
        
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json()).then(data => {
            if (data.status === 'success') {
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                allDispatchRecords = data.queue;
                defaultDispatchNo = data.next_dispatch;
                resetForm(true);
                document.querySelectorAll('.col-search').forEach(inp => inp.value = '');
                applyColumnSearch(); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error. Check DB connection.');
        }).finally(() => {
            document.getElementById('btnSubmitForm').innerText = document.getElementById('recordId').value ? 'Update Record' : 'Save Record';
        });
    }

    function deleteSelected() {
        const boxes = document.querySelectorAll(".row-checkbox:checked");
        if (boxes.length === 0) {
            if (typeof AppAlert !== 'undefined') AppAlert.warning("Please select at least one record to delete.");
            return;
        }
        
        if (confirm(`Are you sure you want to permanently delete ${boxes.length} selected record(s)?`)) {
            const ids = Array.from(boxes).map(cb => parseInt(cb.value));
            const formData = new FormData();
            formData.append('ajax_action', 'delete_dispatch');
            formData.append('ids', JSON.stringify(ids));

            fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json()).then(data => {
                if (data.status === 'success') {
                    allDispatchRecords = data.queue;
                    defaultDispatchNo = data.next_dispatch;
                    resetForm(true);
                    applyColumnSearch(); 
                    if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                } else {
                    if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
                }
            });
        }
    }

    function resetForm(fullReset = false) {
        document.getElementById('dispatchForm').reset();
        document.getElementById('recordId').value = '';
        document.getElementById('dispatchNo').value = defaultDispatchNo;
        document.getElementById('hiddenEmpName').value = '';
        document.getElementById('invoiceItemsPanel').style.display = 'none';
        document.getElementById('holdReasonRow').style.display = 'none';
        
        document.getElementById('btnSubmitForm').innerText = 'Save Record';
        document.getElementById('btnCancel').style.display = 'none';
        document.getElementById('btnClear').style.display = 'inline-flex';
        
        if(fullReset) {
            document.getElementById('stateFilter').value = '';
            document.getElementById('deptSalesCheck').checked = false;
            document.getElementById('empSelect').innerHTML = '<option value="">Select Employee</option>';
            document.getElementById('partySelect').innerHTML = '<option value="">Select Party</option>';
            document.getElementById('transporterSelect').innerHTML = '<option value="">Select Transporter</option>';
            document.getElementById('invoiceNo').innerHTML = '<option value="">Select Invoice</option>';
            populateEmployeeDropdown();
        }
    }

    function generateSelectedMessage() {
        const boxes = document.querySelectorAll(".row-checkbox:checked");
        const ids = Array.from(boxes).map(cb => parseInt(cb.value));
        const records = filteredRecords.filter(r => ids.includes(parseInt(r.id)));
        if(records.length === 0) return;

        const byEmp = {}; 
        records.forEach(r => { 
            if(!byEmp[r.empname]) byEmp[r.empname] = []; 
            byEmp[r.empname].push(r); 
        });
        
        let msg = `=============================\nCORPORATE DISPATCH ALERT\nCompany\n=============================\n`;
        for (const [emp, recs] of Object.entries(byEmp)) {
            msg += `\nDear Mr. ${emp},\nWe have updated the dispatch status for your assigned parties:\n\n`;
            recs.forEach(r => {
                msg += `🏢 Party: M/s ${r.partyname} (${r.area})\n`;
                msg += `📄 Invoice No: ${r.invoice_no} - 📦 Cases: ${r.cases}\n`;
                msg += `🚚 Transporter: ${r.transporter} - 📅 Date: ${formatDate(r.dispatch_date)}\n`;
                msg += `📌 Status: ${r.status.toUpperCase()}${r.hold_reason ? ' ('+r.hold_reason+')' : ''}\n`;
                msg += `----------------------------------------\n`;
            });
        }
        msg += `\nPlease track accordingly.\n\nWarm Regards,\nDispatch Operations\nCompany `;
        
        document.getElementById("messageBox").value = msg; 
        document.getElementById("messagePanel").style.display = "block"; 
        document.getElementById("messagePanel").scrollIntoView({ behavior: 'smooth', block: 'end' });
    }

    function copyTableAsImage() {
        const boxes = document.querySelectorAll(".row-checkbox:checked");
        if (boxes.length === 0) {
            if (typeof AppAlert !== 'undefined') AppAlert.warning("Please select at least one row from the ledger to copy.");
            return;
        }
        const ids = Array.from(boxes).map(cb => parseInt(cb.value));
        const records = filteredRecords.filter(r => ids.includes(parseInt(r.id)));
        
        let html = `<div style="font-family: Arial, sans-serif; padding: 20px; background: white; border: 3px solid #1e2a78; border-radius: 8px;">
            <div style="text-align: center; margin-bottom: 15px;">
                <h2 style="margin: 0 0 5px 0; color: #1e2a78; text-transform: uppercase;">Company Name DISPATCH SUMMARY</h2>
                <p style="margin: 0; font-size: 12px; color: #555;">Generated on ${new Date().toLocaleDateString('en-GB')}</p>
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <tr style="background: #1e2a78; color: white;">
                <th style="padding: 8px; border: 1px solid #1e2a78;">Dispatch No</th>
                <th style="padding: 8px; border: 1px solid #1e2a78;">Date</th>
                <th style="padding: 8px; border: 1px solid #1e2a78;">Invoice No</th>
                <th style="padding: 8px; border: 1px solid #1e2a78;">Party Details</th>
                <th style="padding: 8px; border: 1px solid #1e2a78;">Employee (MR)</th>
                <th style="padding: 8px; border: 1px solid #1e2a78;">Transporter</th>
                <th style="padding: 8px; border: 1px solid #1e2a78; text-align:center;">Cases</th>
                <th style="padding: 8px; border: 1px solid #1e2a78; text-align:center;">Status</th>
            </tr>`;
        
        records.forEach(r => {
            html += `<tr>
                <td style="border: 1px solid #ccc; padding: 6px; font-weight:bold; color: #0ea5e9;">${r.dispatch_no}</td>
                <td style="border: 1px solid #ccc; padding: 6px;">${formatDate(r.dispatch_date)}</td>
                <td style="border: 1px solid #ccc; padding: 6px; font-weight:bold;">${r.invoice_no}</td>
                <td style="border: 1px solid #ccc; padding: 6px;"><b>${r.partyname}</b><br><small>${r.area}</small></td>
                <td style="border: 1px solid #ccc; padding: 6px;">${r.empname}</td>
                <td style="border: 1px solid #ccc; padding: 6px;">${r.transporter}</td>
                <td style="border: 1px solid #ccc; padding: 6px; text-align:center; font-weight:bold;">${r.cases}</td>
                <td style="border: 1px solid #ccc; padding: 6px; text-align:center; font-weight:bold;">${r.status}</td>
            </tr>`;
        });
        html += `</table></div>`;
        
        const container = document.getElementById("compactTableContainer"); 
        container.innerHTML = html; 
        container.style.display = "block";
        
        html2canvas(container.firstElementChild, { scale: 2 }).then(canvas => {
            container.style.display = "none";
            canvas.toBlob(blob => navigator.clipboard.write([new ClipboardItem({'image/png': blob})]).then(() => {
                if (typeof AppAlert !== 'undefined') AppAlert.success("Image copied to clipboard successfully!");
            }).catch(() => {
                if (typeof AppAlert !== 'undefined') AppAlert.error("Clipboard denied.");
            }));
        });
    }

    function exportToExcel() {
        if(filteredRecords.length === 0) {
            if (typeof AppAlert !== 'undefined') AppAlert.warning("No data available to export.");
            return;
        }
        let html = `<table border="1">
            <tr style="background:#1e2a78; color:white;">
                <th>Dispatch No</th><th>Date</th><th>Invoice No</th><th>Party Name</th><th>Employee (MR)</th><th>HQ</th><th>Delivery Area</th><th>Transporter</th><th>Cases</th><th>Status</th>
            </tr>`;
        filteredRecords.forEach(r => {
            html += `<tr><td>${r.dispatch_no}</td><td>${formatDate(r.dispatch_date)}</td><td>${r.invoice_no}</td><td>${r.partyname}</td><td>${r.empname}</td><td>${r.hq}</td><td>${r.area}</td><td>${r.transporter}</td><td>${r.cases}</td><td>${r.status}</td></tr>`
        });
        html += `</table>`;
        const a = document.createElement("a"); 
        a.href = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' })); 
        a.download = `Dispatch_Export_Report.xls`; 
        a.click();
    }

    function copyMessage() { 
        document.getElementById("messageBox").select(); 
        document.execCommand("copy"); 
        if (typeof AppAlert !== 'undefined') AppAlert.success("Alert text copied successfully!"); 
    }

    window.onload = initLoad;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>