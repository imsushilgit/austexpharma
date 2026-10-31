<?php
// HARD CACHE BUSTER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../includes/core.php';

// Access Control
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$userRole = strtoupper($_SESSION['user_role'] ?? '');
$isBilling = $isAdmin || str_contains($userRole, 'BILL') || str_contains($userRole, 'ADMIN');

if (!$isBilling) {
    header('Location: ../index.php?alert=unauthorized');
    exit;
}

$by = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Billing';

// ==========================================
// 1. AUTO-CREATE SCHEMA (If missing)
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS sales_orders (
    id INT AUTO_INCREMENT PRIMARY KEY, order_no VARCHAR(40) NOT NULL UNIQUE, order_date DATE NOT NULL,
    empcode VARCHAR(50) NOT NULL, empname VARCHAR(150) NOT NULL, hq VARCHAR(100) DEFAULT NULL, state VARCHAR(100) DEFAULT NULL,
    party_name VARCHAR(200) NOT NULL, party_address TEXT, transporter_name VARCHAR(150) DEFAULT NULL, transporter_address TEXT,
    mobile VARCHAR(20) DEFAULT NULL, email VARCHAR(100) DEFAULT NULL,
    status VARCHAR(40) DEFAULT 'Draft',
    total_qty DECIMAL(12,2) DEFAULT 0, taxable_value DECIMAL(14,2) DEFAULT 0, total_gst DECIMAL(14,2) DEFAULT 0,
    grand_total DECIMAL(14,2) DEFAULT 0, total_discount DECIMAL(14,2) DEFAULT 0,
    invoice_id INT DEFAULT NULL, invoice_no VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS sales_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, product_name VARCHAR(200) NOT NULL,
    mrp DECIMAL(12,2) DEFAULT 0, rate_type VARCHAR(100) DEFAULT NULL, scheme VARCHAR(50) DEFAULT NULL,
    actual_qty DECIMAL(12,2) DEFAULT 0, bonus_qty DECIMAL(12,2) DEFAULT 0, gst_pct DECIMAL(5,2) DEFAULT 0,
    disc_pct DECIMAL(5,2) DEFAULT 0, req_rate DECIMAL(12,2) DEFAULT 0, final_rate DECIMAL(12,2) DEFAULT 0, taxable DECIMAL(14,2) DEFAULT 0
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tax_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY, invoice_no VARCHAR(50) NOT NULL UNIQUE, invoice_date DATE NOT NULL,
    invoice_type ENUM('Tax Invoice','Bill of Supply','Credit Note','Debit Note') DEFAULT 'Tax Invoice',
    order_id INT DEFAULT NULL, order_no VARCHAR(40) DEFAULT NULL, empcode VARCHAR(50) DEFAULT NULL, empname VARCHAR(150) DEFAULT NULL,
    party_name VARCHAR(200) NOT NULL, party_address TEXT, party_gstin VARCHAR(20) DEFAULT NULL, party_state VARCHAR(100) DEFAULT NULL,
    place_of_supply VARCHAR(100) DEFAULT NULL, transporter_name VARCHAR(150) DEFAULT NULL, transporter_address TEXT,
    lr_no VARCHAR(50) DEFAULT NULL, vehicle_no VARCHAR(30) DEFAULT NULL, payment_mode VARCHAR(50) DEFAULT 'Credit', due_days INT DEFAULT 30,
    taxable_value DECIMAL(14,2) DEFAULT 0, cgst_amount DECIMAL(14,2) DEFAULT 0, sgst_amount DECIMAL(14,2) DEFAULT 0, igst_amount DECIMAL(14,2) DEFAULT 0,
    total_gst DECIMAL(14,2) DEFAULT 0, total_discount DECIMAL(14,2) DEFAULT 0, round_off DECIMAL(8,2) DEFAULT 0, grand_total DECIMAL(14,2) DEFAULT 0,
    total_qty DECIMAL(12,2) DEFAULT 0, remarks TEXT, status ENUM('Generated','Cancelled','Paid') DEFAULT 'Generated',
    created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tax_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, product_name VARCHAR(200) NOT NULL, hsn VARCHAR(20) DEFAULT NULL,
    batch_no VARCHAR(50) DEFAULT NULL, expiry DATE DEFAULT NULL, mrp DECIMAL(12,2) DEFAULT 0, qty DECIMAL(12,2) DEFAULT 0, free_qty DECIMAL(12,2) DEFAULT 0,
    rate DECIMAL(12,2) DEFAULT 0, disc_pct DECIMAL(5,2) DEFAULT 0, gst_pct DECIMAL(5,2) DEFAULT 0, taxable DECIMAL(14,2) DEFAULT 0,
    cgst DECIMAL(14,2) DEFAULT 0, sgst DECIMAL(14,2) DEFAULT 0, igst DECIMAL(14,2) DEFAULT 0
)");

// ==========================================
// 2. FETCH COMPANY DETAILS DYNAMICALLY
// ==========================================
$companyState = 'Unknown'; 
try {
    $cs = $pdo->query("SELECT state FROM companies LIMIT 1")->fetchColumn();
    if ($cs) { $companyState = $cs; }
} catch (Exception $e) {}

// Auto Generate Invoice No
function nextInvoiceNo($pdo) {
    $ym = date('Y');
    $st = $pdo->prepare("SELECT COUNT(*) FROM tax_invoices WHERE DATE_FORMAT(invoice_date,'%Y')=?");
    $st->execute([$ym]);
    $n = intval($st->fetchColumn()) + 1;
    return ''.$ym.''.str_pad($n, 6, '0', STR_PAD_LEFT);
}
$autoInvoiceNo = nextInvoiceNo($pdo);

// ==========================================
// 3. AJAX BACKEND HANDLER
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status'=>'error','message'=>'Invalid request'];

    try {
        if ($action === 'pending_orders') {
            $rows = $pdo->query("SELECT id, order_no, order_date, empcode, empname, party_name, state, grand_total, status, total_qty
                FROM sales_orders WHERE status='Pending_Billing' ORDER BY order_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success', 'data'=>$rows]); exit;
        }
        
        elseif ($action === 'load_order') {
            $id = intval($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?"); $st->execute([$id]);
            $ord = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ord) { echo json_encode(['status'=>'error','message'=>'Order not found or already processed']); exit; }
            
            $it = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id=?"); $it->execute([$id]);
            $items = $it->fetchAll(PDO::FETCH_ASSOC);
            
            $party = $ord['party_name'];
            $rateMap = [];
            try {
                $rs = $pdo->prepare("SELECT product_name, rate, disc_pct, scheme, gst_pct, rate_type FROM party_rates WHERE party_name=? AND is_active=1");
                $rs->execute([$party]);
                foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) { $rateMap[$r['product_name']] = $r; }
            } catch (Exception $e) {}
            
            echo json_encode(['status'=>'success', 'order'=>$ord, 'items'=>$items, 'party_rates'=>$rateMap]); exit;
        }
        
        elseif ($action === 'save_invoice') {
            $orderId = intval($_POST['order_id'] ?? 0);
            $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
            
            if (empty($items)) { echo json_encode(['status'=>'error', 'message'=>'Add items to generate invoice.']); exit; }
            
            $orderNo = ''; $empCode = ''; $empName = '';
            if ($orderId > 0) {
                $ordSt = $pdo->prepare("SELECT * FROM sales_orders WHERE id=? AND status='Pending_Billing'"); 
                $ordSt->execute([$orderId]);
                $ord = $ordSt->fetch(PDO::FETCH_ASSOC);
                if (!$ord) { echo json_encode(['status'=>'error', 'message'=>'Linked order is no longer pending.']); exit; }
                $orderNo = $ord['order_no']; $empCode = $ord['empcode']; $empName = $ord['empname'];
            }

            $partyName = trim($_POST['party_name'] ?? '');
            $partyState = trim($_POST['party_state'] ?? '');
            
            if (empty($partyName)) { echo json_encode(['status'=>'error', 'message'=>'Party/Customer name required.']); exit; }

            $isInter = (!empty($partyState) && strcasecmp($partyState, $companyState) !== 0);
            $taxable = $cgst = $sgst = $igst = $disc = $qty = 0;
            
            foreach ($items as $it) {
                $t = floatval($it['taxable'] ?? 0);
                $g = floatval($it['gst_pct'] ?? 0);
                $taxable += $t;
                $qty += floatval($it['qty'] ?? 0) + floatval($it['free_qty'] ?? 0);
                $disc += $t * floatval($it['disc_pct'] ?? 0) / 100;
                
                if ($isInter) { $igst += $t * $g / 100; } 
                else { $cgst += $t * $g / 200; $sgst += $t * $g / 200; }
            }
            
            $totalGst = $cgst + $sgst + $igst;
            $rawGrand = $taxable + $totalGst;
            $roundOff = round($rawGrand) - $rawGrand;
            $grand = round($rawGrand);

            $invNo = trim($_POST['invoice_no'] ?? '') ?: nextInvoiceNo($pdo);
            $invDate = $_POST['invoice_date'] ?: date('Y-m-d');

            $pdo->beginTransaction();

            $pdo->prepare("INSERT INTO tax_invoices (
                invoice_no, invoice_date, invoice_type, order_id, order_no, empcode, empname,
                party_name, party_address, party_gstin, party_state, place_of_supply,
                transporter_name, transporter_address, lr_no, vehicle_no, payment_mode, due_days,
                taxable_value, cgst_amount, sgst_amount, igst_amount, total_gst, total_discount, round_off, grand_total, total_qty, remarks, created_by
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $invNo, $invDate, $_POST['invoice_type'] ?? 'Tax Invoice', $orderId > 0 ? $orderId : null, $orderNo, $empCode, $empName,
                $partyName, trim($_POST['party_address'] ?? ''), trim($_POST['party_gstin'] ?? ''), $partyState, trim($_POST['place_of_supply'] ?? $partyState),
                trim($_POST['transporter_name'] ?? ''), trim($_POST['transporter_address'] ?? ''), trim($_POST['lr_no'] ?? ''), trim($_POST['vehicle_no'] ?? ''),
                $_POST['payment_mode'] ?? 'Credit', intval($_POST['due_days'] ?? 30),
                $taxable, $cgst, $sgst, $igst, $totalGst, floatval($_POST['total_discount'] ?? 0), $roundOff, $grand, $qty, trim($_POST['remarks'] ?? ''), $by
            ]);
            
            $invId = $pdo->lastInsertId();
            $insItem = $pdo->prepare("INSERT INTO tax_invoice_items (invoice_id, product_name, hsn, batch_no, expiry, mrp, qty, free_qty, rate, disc_pct, gst_pct, taxable, cgst, sgst, igst) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            
            foreach ($items as $it) {
                $t = floatval($it['taxable'] ?? 0); 
                $g = floatval($it['gst_pct'] ?? 0);
                $lineC = $lineS = $lineI = 0;
                if ($isInter) { $lineI = $t * $g / 100; } else { $lineC = $t * $g / 200; $lineS = $t * $g / 200; }
                
                $insItem->execute([
                    $invId, $it['product_name'] ?? '', $it['hsn'] ?? '', $it['batch_no'] ?? '', $it['expiry'] ?: null,
                    floatval($it['mrp'] ?? 0), floatval($it['qty'] ?? 0), floatval($it['free_qty'] ?? 0),
                    floatval($it['rate'] ?? 0), floatval($it['disc_pct'] ?? 0), $g, $t, $lineC, $lineS, $lineI
                ]);
            }

            if ($orderId > 0) {
                $pdo->prepare("UPDATE sales_orders SET status='Invoiced', invoice_id=?, invoice_no=? WHERE id=?")->execute([$invId, $invNo, $orderId]);
            }

            $pdo->commit();
            echo json_encode(['status'=>'success', 'message'=>"Invoice Generated Successfully: $invNo", 'invoice_id'=>$invId, 'invoice_no'=>$invNo]); exit;
        }
    } catch (Exception $e) { 
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['status'=>'error', 'message'=>"System Error: " . $e->getMessage()]); exit;
    }
}

// Data For Lookups
$parties = $pdo->query("SELECT id, partycode, partyname, state, hq, area, contact FROM parties ORDER BY partyname ASC")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT id, productcode, product_name, packing, hsnsac, defaultcgst, defaultsgst FROM products WHERE status='active' ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$batches = [];
try {
    $b = $pdo->query("SELECT product_name, batch_no, mrp, expiry_date FROM product_batches ORDER BY expiry_date")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($b as $row) { $batches[$row['product_name']][] = $row; }
} catch (Exception $e) {}

$pageTitle = "ERP Billing Terminal";
include __DIR__ . '/../includes/header.php';
?>


<div class="erp-workspace">
    <div id="alertBanner" style="display:none; padding: 10px; margin-bottom: 10px; font-weight: bold; font-size: 11px; border: 1.5px solid var(--navy-900);"></div>
    
    <div class="hotkey-bar">
        <span><i class="fa-solid fa-keyboard"></i> TERMINAL HOTKEYS:</span>
        <span>[F3] Item Lookup &nbsp;|&nbsp; [F4] Party Lookup &nbsp;|&nbsp; [F8] Recall &nbsp;|&nbsp; [F9] Hold &nbsp;|&nbsp; [F10] Save &nbsp;|&nbsp; [ESC] Clear Form</span>
    </div>

    <!-- MAIN TERMINAL -->
    <form id="invoiceForm" autocomplete="off">
        <input type="hidden" name="order_id" id="orderId" value="">
        <input type="hidden" id="companyState" value="<?= htmlspecialchars($companyState) ?>">

        <div class="erp-card">
            <div class="erp-header">
                <span id="formTitleText">SALES BILLING ENGINE (INDEPENDENT)</span>
                <label style="font-size:9px; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                    <input type="checkbox" name="autoprint" id="autoprint" value="1" checked> AUTO PRINT
                </label>
            </div>
            <div class="compact-header-grid">
                <div>
                    <label class="erp-label">Party / Customer (F4) *</label>
                    <input type="text" name="party_name" id="party" class="erp-input text-left" placeholder="Search Party (F4)..." onclick="openCustomerSearch()" readonly required>
                </div>
                <div>
                    <label class="erp-label">Invoice No</label>
                    <input type="text" name="invoice_no" id="invNo" class="erp-input readonly-cell" value="<?= htmlspecialchars($autoInvoiceNo) ?>" readonly tabindex="-1">
                </div>
                <div>
                    <label class="erp-label">Date</label>
                    <input type="date" name="invoice_date" id="invDate" class="erp-input" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label class="erp-label">Type</label>
                    <select name="invoice_type" id="invType" class="erp-input">
                        <option value="Tax Invoice">Tax Invoice</option>
                        <option value="Bill of Supply">Bill of Supply</option>
                    </select>
                </div>
                <div>
                    <label class="erp-label">Order Ref</label>
                    <input type="text" name="orderno" id="ordNo" class="erp-input readonly-cell" placeholder="Manual" readonly tabindex="-1">
                </div>
                <div>
                    <label class="erp-label">Address</label>
                    <input type="text" name="party_address" id="paddr" class="erp-input">
                </div>
                <div>
                    <label class="erp-label">GSTIN</label>
                    <input type="text" name="party_gstin" id="pgstin" class="erp-input">
                </div>
                <div>
                    <label class="erp-label">State (POS) *</label>
                    <input type="text" name="party_state" id="pstate" class="erp-input" required onblur="recalcTotals()">
                </div>
                <div>
                    <label class="erp-label">Transport / LR / Veh</label>
                    <input type="text" name="transporter_name" id="trans" class="erp-input" placeholder="Transport Info...">
                </div>
            </div>
        </div>

        <div class="erp-card">
            <div class="erp-header">
                <span>Billing Grid (F3 to Search Item Directory)</span>
                <span style="font-size: 9px; font-weight: normal;">* Press Enter on Qty to lock row and add new</span>
            </div>
            <div style="width: 100%; overflow: visible; min-height: 150px;">
                <table class="erp-billing-table" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width: 25%; text-align: left; padding-left: 6px;">Product Description</th>
                            <th style="width: 10%;">HSN</th>
                            <th style="width: 12%;">Batch</th>
                            <th style="width: 8%;">Exp</th>
                            <th style="width: 8%;">MRP</th>
                            <th style="width: 6%;">Qty</th>
                            <th style="width: 5%;">Free</th>
                            <th style="width: 8%;">Rate</th>
                            <th style="width: 5%;">Disc%</th>
                            <th style="width: 5%;">GST%</th>
                            <th style="width: 8%; text-align: right; padding-right: 6px;">Taxable</th>
                            <th style="width: 2%;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody"></tbody>
                </table>
            </div>
        </div>
            
        <div class="summary-container">
            <div class="summary-row-compact" style="border-bottom: 1px dotted var(--navy-900); padding-bottom: 2px;">
                <span><b>Items:</b> <span id="statItems">0</span></span>
                <span><b>Qty:</b> <span id="statQty">0</span></span>
                <span><b>Gross Taxable:</b> ₹ <span id="tTaxable">0.00</span></span>
                <span><b>Bill Disc (-):</b> <input type="number" step="0.01" name="total_discount" id="billdiscount" class="erp-input" value="0.00" oninput="recalcTotals()"></span>
                <span style="font-size: 13px; color: #dc2626;"><b>NET GRAND:</b> ₹ <span id="tGrand">0.00</span></span>
            </div>
            
            <div class="summary-row-compact" style="border-bottom: 1px dotted var(--navy-900); padding-bottom: 2px;">
                <span><b>SGST:</b> ₹ <span id="tSgst">0.00</span></span>
                <span><b>CGST:</b> ₹ <span id="tCgst">0.00</span></span>
                <span><b>IGST:</b> ₹ <span id="tIgst">0.00</span></span>
                <span><b>Round Off:</b> <span id="tRound">0.00</span></span>
                <span style="flex-grow: 1; justify-content: flex-end;"><b>Remarks:</b> <input type="text" name="remarks" id="remarks" class="erp-input" style="width: 250px; text-align: left;" placeholder="Internal notes..."></span>
            </div>
            
            <div class="summary-row-compact" style="justify-content: flex-end; gap: 8px; margin-top: 4px;">
                <button type="button" class="erp-btn btn-outline" onclick="holdBill()">HOLD (F9)</button>
                <button type="button" class="erp-btn btn-outline" onclick="recallBill()">RECALL (F8)</button>
                <button type="button" class="erp-btn btn-outline" onclick="clearTerminal()">CLEAR (ESC)</button>
                <button type="button" class="erp-btn" id="btnSubmit" onclick="submitInvoice()">
                    GENERATE INVOICE (F10)
                </button>
            </div>
        </div>
    </form>

    <!-- PENDING ORDERS QUEUE -->
    <div class="erp-card">
        <div class="erp-header">PENDING ORDERS QUEUE (CLICK TO PROCESS)</div>
        <div id="queueContainer" class="queue-grid"></div>
    </div>
</div>

<!-- CUSTOMER SEARCH MODAL (F4) -->
<div id="modalParties" class="modals-overlay">
    <div class="modal-window">
        <div class="modals-header">
            <span><i class="fa-solid fa-users"></i> PARTY DIRECTORY [↑/↓, ENTER, ESC]</span>
            <button type="button" class="erp-btn btn-outline" style="height: 20px; font-size: 9px;" onclick="closeCustomerSearch()">CLOSE</button>
        </div>
        <div style="padding: 8px 12px; background: #f0f4f8; border-bottom: 2px solid var(--navy-900);">
            <input type="text" id="advCustSearchInput" class="erp-input" style="height: 32px; font-size: 13px;" placeholder="Search Party..." autocomplete="off" oninput="renderCustSearch(this.value)" onkeydown="handleCustKey(event)">
        </div>
        <div style="flex-grow: 1; overflow-y: auto;">
            <table class="erp-data-table modals-table">
                <thead><tr><th>Code</th><th style="text-align: left;">Party Name</th><th>State</th><th>HQ / Area</th></tr></thead>
                <tbody id="advCustSearchBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- PRODUCT SEARCH MODAL (F3) -->
<div id="modalProducts" class="modals-overlay">
    <div class="modal-window">
        <div class="modals-header">
            <span><i class="fa-solid fa-boxes-stacked"></i> ITEM DIRECTORY [↑/↓, ENTER, ESC]</span>
            <button type="button" class="erp-btn btn-outline" style="height: 20px; font-size: 9px;" onclick="closeProductSearch()">CLOSE</button>
        </div>
        <div style="padding: 8px 12px; background: #f0f4f8; border-bottom: 2px solid var(--navy-900);">
            <input type="text" id="advProdSearchInput" class="erp-input" style="height: 32px; font-size: 13px;" placeholder="Search Item..." autocomplete="off" oninput="renderProdSearch(this.value)" onkeydown="handleProdKey(event)">
        </div>
        <div style="flex-grow: 1; overflow-y: auto;">
            <table class="erp-data-table modals-table">
                <thead><tr><th>Code</th><th style="text-align: left;">Product Name</th><th>Packing</th><th>HSN</th></tr></thead>
                <tbody id="advProdSearchBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const APP_STATE = <?= json_encode($companyState) ?>;
    let productsList = <?= json_encode($products) ?>;
    let partiesList = <?= json_encode($parties) ?>;
    let batchesList = <?= json_encode($batches) ?>;
    let partyRates = {};
    
    let isGridUnlocked = false;
    let rowIndex = 0;
    let custIndex = -1;
    let prodIndex = -1;

    function showAlert(msg, isError = false) {
        const b = document.getElementById('alertBanner');
        b.style.display = 'block';
        b.style.background = isError ? '#ffffff' : 'var(--navy-900)';
        b.style.color = isError ? '#dc2626' : '#ffffff';
        b.style.borderColor = isError ? '#dc2626' : 'var(--navy-900)';
        b.innerHTML = msg;
        setTimeout(() => b.style.display = 'none', 4000);
    }

    function init() {
        loadQueue();
    }

    function loadQueue() {
        const fd = new FormData(); fd.append('ajax_action', 'pending_orders');
        fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
            const q = document.getElementById('queueContainer');
            if(!d.data || d.data.length===0) { q.innerHTML='<div style="padding:10px; font-size:10px;">No pending orders.</div>'; return; }
            q.innerHTML = d.data.map(o => `
                <div class="queue-card" onclick="loadOrder(${o.id})">
                    <div class="qc-badge">${o.status}</div>
                    <div class="qc-title">${o.party_name}</div>
                    <div class="qc-detail"><span>Ord: ${o.order_no}</span> <span>${o.order_date}</span></div>
                    <div class="qc-detail"><span>MR: ${o.empname}</span> <strong>₹${o.grand_total}</strong></div>
                </div>
            `).join('');
        });
    }

    // ----- CUSTOMER MODAL F4 -----
    function openCustomerSearch() {
        document.getElementById('modalParties').style.display = 'block';
        document.getElementById('advCustSearchInput').value = '';
        renderCustSearch('');
        setTimeout(() => document.getElementById('advCustSearchInput').focus(), 50);
    }
    function closeCustomerSearch() { document.getElementById('modalParties').style.display = 'none'; }
    function renderCustSearch(term) {
        const t = term.toLowerCase().trim();
        const matches = partiesList.filter(p => (p.partyname||'').toLowerCase().includes(t) || (p.partycode||'').toLowerCase().includes(t));
        const tb = document.getElementById('advCustSearchBody');
        tb.innerHTML = matches.slice(0, 50).map(p => `
            <tr class="adv-row" onclick="selectParty('${p.partyname.replace(/'/g,"\\'")}', '${p.state||''}')">
                <td>${p.partycode||'-'}</td><td style="text-align:left;">${p.partyname}</td><td>${p.state||'-'}</td><td>${p.hq||'-'}</td>
            </tr>
        `).join('');
        custIndex = 0; highlightRow('advCustSearchBody', custIndex);
    }
    function selectParty(name, state) {
        document.getElementById('party').value = name;
        document.getElementById('pstate').value = state;
        closeCustomerSearch();
        isGridUnlocked = true;
        document.getElementById('itemsBody').innerHTML = '';
        rowIndex = 0;
        addRow();
        recalcTotals();
    }
    function handleCustKey(e) {
        const rows = document.getElementById('advCustSearchBody').querySelectorAll('tr');
        if(e.key==='Escape') closeCustomerSearch();
        if(e.key==='ArrowDown') { e.preventDefault(); custIndex=(custIndex+1)%rows.length; highlightRow('advCustSearchBody', custIndex); }
        if(e.key==='ArrowUp') { e.preventDefault(); custIndex=(custIndex-1+rows.length)%rows.length; highlightRow('advCustSearchBody', custIndex); }
        if(e.key==='Enter') { e.preventDefault(); if(rows[custIndex]) rows[custIndex].click(); }
    }

    // ----- PRODUCT MODAL F3 -----
    function openProductSearch() {
        if(!isGridUnlocked) { alert("Select party first."); return; }
        document.getElementById('modalProducts').style.display = 'block';
        document.getElementById('advProdSearchInput').value = '';
        renderProdSearch('');
        setTimeout(() => document.getElementById('advProdSearchInput').focus(), 50);
    }
    function closeProductSearch() { document.getElementById('modalProducts').style.display = 'none'; }
    function renderProdSearch(term) {
        const t = term.toLowerCase().trim();
        const matches = productsList.filter(p => (p.product_name||'').toLowerCase().includes(t) || (p.productcode||'').toLowerCase().includes(t));
        const tb = document.getElementById('advProdSearchBody');
        tb.innerHTML = matches.slice(0, 50).map(p => `
            <tr class="adv-row" onclick="selectProd('${p.product_name.replace(/'/g,"\\'")}', '${p.hsnsac||''}', ${p.defaultcgst}, ${p.defaultsgst})">
                <td>${p.productcode||'-'}</td><td style="text-align:left;">${p.product_name}</td><td>${p.packing||'-'}</td><td>${p.hsnsac||'-'}</td>
            </tr>
        `).join('');
        prodIndex = 0; highlightRow('advProdSearchBody', prodIndex);
    }
    function selectProd(name, hsn, cgst, sgst) {
        closeProductSearch();
        let targetIdx = rowIndex - 1;
        if(document.getElementById(`prod_${targetIdx}`).value !== '') { addRow(); targetIdx++; }
        
        document.getElementById(`prod_${targetIdx}`).value = name;
        document.getElementById(`hsn_${targetIdx}`).value = hsn;
        document.getElementById(`gst_${targetIdx}`).value = parseFloat(cgst||0) + parseFloat(sgst||0);
        
        // Load Batch Options
        const bSelect = document.getElementById(`batch_${targetIdx}`);
        let bOpts = '<option value="">- Batch -</option>';
        if(batchesList[name]) {
            batchesList[name].forEach(b => {
                bOpts += `<option value="${b.batch_no}" data-mrp="${b.mrp}" data-exp="${b.expiry_date}">${b.batch_no}</option>`;
            });
        }
        bSelect.innerHTML = bOpts;
        if(bSelect.options.length > 1) { bSelect.selectedIndex = 1; onBatchSelect(bSelect, targetIdx); }
        
        document.getElementById(`qty_${targetIdx}`).focus();
    }
    function handleProdKey(e) {
        const rows = document.getElementById('advProdSearchBody').querySelectorAll('tr');
        if(e.key==='Escape') closeProductSearch();
        if(e.key==='ArrowDown') { e.preventDefault(); prodIndex=(prodIndex+1)%rows.length; highlightRow('advProdSearchBody', prodIndex); }
        if(e.key==='ArrowUp') { e.preventDefault(); prodIndex=(prodIndex-1+rows.length)%rows.length; highlightRow('advProdSearchBody', prodIndex); }
        if(e.key==='Enter') { e.preventDefault(); if(rows[prodIndex]) rows[prodIndex].click(); }
    }

    function highlightRow(tbId, idx) {
        const rows = document.getElementById(tbId).querySelectorAll('tr');
        rows.forEach(r => { r.style.background = ''; r.style.color = ''; });
        if(rows[idx]) { rows[idx].style.background = 'var(--navy-900)'; rows[idx].style.color = '#fff'; rows[idx].scrollIntoView({block:'nearest'}); }
    }

    // ----- GRID LOGIC -----
    function addRow() {
        const tb = document.getElementById('itemsBody');
        const tr = document.createElement('tr');
        tr.id = `r_${rowIndex}`;
        tr.innerHTML = `
            <td><input type="text" id="prod_${rowIndex}" class="erp-input text-left" placeholder="(F3) Select Item" onclick="openProductSearch()" readonly required></td>
            <td><input type="text" id="hsn_${rowIndex}" class="erp-input readonly-cell" readonly tabindex="-1"></td>
            <td><select id="batch_${rowIndex}" class="erp-input" onchange="onBatchSelect(this, ${rowIndex})"><option value="">-</option></select></td>
            <td><input type="date" id="exp_${rowIndex}" class="erp-input"></td>
            <td><input type="number" id="mrp_${rowIndex}" class="erp-input" value="0"></td>
            <td><input type="number" id="qty_${rowIndex}" class="erp-input" value="0" oninput="calcRow(${rowIndex})" onkeydown="if(event.key==='Enter'){event.preventDefault(); addRow(); openProductSearch();}"></td>
            <td><input type="number" id="free_${rowIndex}" class="erp-input" value="0"></td>
            <td><input type="number" id="rate_${rowIndex}" class="erp-input" value="0" oninput="calcRow(${rowIndex})"></td>
            <td><input type="number" id="disc_${rowIndex}" class="erp-input" value="0" oninput="calcRow(${rowIndex})"></td>
            <td><input type="number" id="gst_${rowIndex}" class="erp-input" value="12" oninput="calcRow(${rowIndex})"></td>
            <td><input type="text" id="tax_${rowIndex}" class="erp-input readonly-cell" style="text-align:right;" readonly tabindex="-1"></td>
            <td><button type="button" onclick="this.closest('tr').remove(); recalcTotals();" style="border:none;background:none;color:#dc2626;cursor:pointer;">✕</button></td>
        `;
        tb.appendChild(tr);
        rowIndex++;
    }

    function onBatchSelect(sel, idx) {
        const opt = sel.options[sel.selectedIndex];
        if(opt && opt.value !== '') {
            document.getElementById(`mrp_${idx}`).value = opt.getAttribute('data-mrp');
            document.getElementById(`exp_${idx}`).value = opt.getAttribute('data-exp');
            // Mock Rate logic: 80% of MRP
            let mrp = parseFloat(opt.getAttribute('data-mrp')) || 0;
            document.getElementById(`rate_${idx}`).value = (mrp * 0.8).toFixed(2);
            calcRow(idx);
        }
    }

    function calcRow(idx) {
        const qty = parseFloat(document.getElementById(`qty_${idx}`).value) || 0;
        const rate = parseFloat(document.getElementById(`rate_${idx}`).value) || 0;
        const disc = parseFloat(document.getElementById(`disc_${idx}`).value) || 0;
        let tax = qty * rate * (1 - disc/100);
        document.getElementById(`tax_${idx}`).value = tax.toFixed(2);
        recalcTotals();
    }

    function recalcTotals() {
        let tax = 0, qty = 0, items = 0;
        let cgst = 0, sgst = 0, igst = 0;
        const isInter = document.getElementById('pstate').value.trim().toLowerCase() !== APP_STATE.toLowerCase();

        document.querySelectorAll('#itemsBody tr').forEach(tr => {
            const idx = tr.id.split('_')[1];
            const t = parseFloat(document.getElementById(`tax_${idx}`).value) || 0;
            const q = parseFloat(document.getElementById(`qty_${idx}`).value) || 0;
            const g = parseFloat(document.getElementById(`gst_${idx}`).value) || 0;
            
            if(q > 0 && t > 0) {
                tax += t; qty += q; items++;
                if(isInter) { igst += t * g/100; } else { cgst += t * g/200; sgst += t * g/200; }
            }
        });

        const billDisc = parseFloat(document.getElementById('billdiscount').value) || 0;
        const totalTax = cgst + sgst + igst;
        const rawGrand = (tax - billDisc) + totalTax;
        const grand = Math.round(rawGrand);

        document.getElementById('tTaxable').innerText = tax.toFixed(2);
        document.getElementById('tCgst').innerText = cgst.toFixed(2);
        document.getElementById('tSgst').innerText = sgst.toFixed(2);
        document.getElementById('tIgst').innerText = igst.toFixed(2);
        document.getElementById('tRound').innerText = (grand - rawGrand).toFixed(2);
        document.getElementById('tGrand').innerText = grand.toFixed(2);
        document.getElementById('statItems').innerText = items;
        document.getElementById('statQty').innerText = qty;
    }

    // ----- LOAD ORDER -----
    function loadOrder(id) {
        const fd = new FormData(); fd.append('ajax_action', 'load_order'); fd.append('id', id);
        fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
            if(d.status!=='success'){ showAlert(d.message, true); return; }
            const o = d.order;
            document.getElementById('orderId').value = o.id;
            document.getElementById('ordNo').value = o.order_no;
            document.getElementById('party').value = o.party_name;
            document.getElementById('paddr').value = o.party_address || '';
            document.getElementById('pstate').value = o.state || '';
            document.getElementById('trans').value = o.transporter_name || '';
            
            isGridUnlocked = true;
            document.getElementById('itemsBody').innerHTML = '';
            rowIndex = 0;
            
            (d.items || []).forEach(it => {
                addRow();
                const idx = rowIndex - 1;
                document.getElementById(`prod_${idx}`).value = it.product_name;
                document.getElementById(`mrp_${idx}`).value = it.mrp || 0;
                document.getElementById(`qty_${idx}`).value = it.actual_qty || 0;
                document.getElementById(`free_${idx}`).value = it.bonus_qty || 0;
                document.getElementById(`rate_${idx}`).value = it.final_rate || 0;
                document.getElementById(`disc_${idx}`).value = it.disc_pct || 0;
                document.getElementById(`gst_${idx}`).value = it.gst_pct || 12;
                document.getElementById(`tax_${idx}`).value = it.taxable || 0;
            });
            recalcTotals();
            showAlert('Order Loaded successfully.');
        });
    }

    // ----- SAVE INVOICE -----
    function submitInvoice() {
        const party = document.getElementById('party').value.trim();
        if(!party) { showAlert('Party name is required.', true); return; }
        
        const items = [];
        document.querySelectorAll('#itemsBody tr').forEach(tr => {
            const idx = tr.id.split('_')[1];
            const prod = document.getElementById(`prod_${idx}`).value;
            if(!prod) return;
            items.push({
                product_name: prod, hsn: document.getElementById(`hsn_${idx}`).value,
                batch_no: document.getElementById(`batch_${idx}`).value, expiry: document.getElementById(`exp_${idx}`).value,
                mrp: document.getElementById(`mrp_${idx}`).value, qty: document.getElementById(`qty_${idx}`).value,
                free_qty: document.getElementById(`free_${idx}`).value, rate: document.getElementById(`rate_${idx}`).value,
                disc_pct: document.getElementById(`disc_${idx}`).value, gst_pct: document.getElementById(`gst_${idx}`).value,
                taxable: document.getElementById(`tax_${idx}`).value
            });
        });
        
        if(items.length === 0) { showAlert('No items to bill.', true); return; }
        if(!confirm('Save and Generate Invoice?')) return;

        const fd = new FormData(document.getElementById('invoiceForm'));
        fd.append('ajax_action', 'save_invoice');
        fd.append('items_json', JSON.stringify(items));
        fd.append('party_name', party);
        fd.append('party_address', document.getElementById('paddr').value);
        fd.append('party_gstin', document.getElementById('pgstin').value);
        fd.append('party_state', document.getElementById('pstate').value);
        fd.append('place_of_supply', document.getElementById('pstate').value);
        fd.append('transporter_name', document.getElementById('trans').value);

        fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
            if(d.status==='success') {
                showAlert(d.message);
                if(document.getElementById('autoprint').checked) {
                    window.open(`billing_print.php?print=${d.invoice_id}`, '_blank');
                }
                clearTerminal();
            } else {
                showAlert(d.message, true);
            }
        });
    }

    function clearTerminal() {
        document.getElementById('invoiceForm').reset();
        document.getElementById('itemsBody').innerHTML = '';
        document.getElementById('orderId').value = '';
        document.getElementById('ordNo').value = '';
        isGridUnlocked = false;
        recalcTotals();
        loadQueue();
    }

    // Hold/Recall Fake Logic for UI completeness
    function holdBill() { showAlert('Bill suspended to memory.'); clearTerminal(); }
    function recallBill() { showAlert('Recalling last suspended bill...', true); }

    // Hotkeys Binding
    document.addEventListener('keydown', e => {
        if(e.key === 'F3') { e.preventDefault(); openProductSearch(); }
        if(e.key === 'F4') { e.preventDefault(); openCustomerSearch(); }
        if(e.key === 'F8') { e.preventDefault(); recallBill(); }
        if(e.key === 'F9') { e.preventDefault(); holdBill(); }
        if(e.key === 'F10') { e.preventDefault(); submitInvoice(); }
        if(e.key === 'Escape') { 
            if(document.getElementById('modalParties').style.display==='block') { closeCustomerSearch(); return; }
            if(document.getElementById('modalProducts').style.display==='block') { closeProductSearch(); return; }
            e.preventDefault(); clearTerminal(); 
        }
    });

    window.onload = init;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>