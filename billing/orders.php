<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);

// Strict Context & Permission Setup
$isAdmin = $ctx['is_admin']; 
$isManager = $ctx['is_manager']; 
$isMR = $ctx['is_mr'];
$canWrite = user_can_write($ctx);

$teamCodes = $ctx['team_codes'] ?? []; 
$empCode = $ctx['empcode']; 
$empName = $ctx['empname'] ?? $ctx['fullname'] ?? '';
$myState = $ctx['state'] ?? '';

function nextOrderNo($pdo) {
    $y = date('Y'); $m = date('m');
    $st = $pdo->prepare("SELECT order_no FROM sales_orders WHERE order_no LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute(['ORD-'.$y.$m.'-%']);
    $lastOrd = $st->fetchColumn();
    if ($lastOrd) {
        $parts = explode('-', $lastOrd);
        $n = intval(end($parts)) + 1;
    } else {
        $n = 1;
    }
    return 'ORD-'.$y.$m.'-'.str_pad($n, 4, '0', STR_PAD_LEFT);
}

if (isset($_POST['ajax_action'])) {
    verify_csrf();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status'=>'error','message'=>'Invalid Action'];
    
    try {
        if ($action === 'get_metadata') {
            $prods = [];
            try { $prods = $pdo->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
            
            // STRICT PARTY FETCHING (With Employee Mapping for Admin Filters)
            $parties = [];
            try { 
                $partiesSql = "
                    SELECT p.*, ap.empcode AS assigned_emp 
                    FROM parties p 
                    LEFT JOIN assignparties ap ON p.partyname = ap.partyname 
                    WHERE 1=1
                "; 
                $pp = [];
                // If MR, strictly fetch only assigned parties
                if ($isMR && !$isAdmin) { 
                    $partiesSql .= " AND ap.empcode=?"; 
                    $pp[] = $empCode; 
                } 
                // If Manager, strictly fetch only team's assigned parties
                elseif ($isManager && !$isAdmin) {
                    if (!empty($teamCodes)) {
                        $ph = implode(',', array_fill(0, count($teamCodes), '?'));
                        $partiesSql .= " AND ap.empcode IN ($ph)";
                        $pp = $teamCodes;
                    } else {
                        $partiesSql .= " AND ap.empcode=?"; 
                        $pp[] = $empCode;
                    }
                }
                $partiesSql .= " ORDER BY p.partyname";
                $stP = $pdo->prepare($partiesSql); $stP->execute($pp);
                $parties = $stP->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e) {}
            
            $trans = [];
            try { $trans = $pdo->query("SELECT * FROM transporters")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
            
            // STRICT MR/EMPLOYEE FETCHING
            $mrs = [];
            try { 
                $mrsSql = "SELECT * FROM employees WHERE (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00')";
                $mp = [];
                if ($isMR && !$isAdmin) { 
                    $mrsSql .= " AND empcode=?"; 
                    $mp[] = $empCode; 
                } 
                elseif ($isManager && !$isAdmin) { 
                    $validCodes = array_merge([$empCode], $teamCodes);
                    $ph = implode(',', array_fill(0, count($validCodes), '?'));
                    $mrsSql .= " AND empcode IN ($ph)"; 
                    $mp = $validCodes;
                }
                $mrsSql .= " ORDER BY empname";
                $stM = $pdo->prepare($mrsSql); $stM->execute($mp);
                $mrs = $stM->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            echo json_encode([
                'status'=>'success', 
                'products'=>$prods, 
                'parties'=>$parties, 
                'transporters'=>$trans, 
                'mrs'=>$mrs, 
                'my_state'=>$myState, 
                'my_empcode'=>$empCode,
                'can_write'=>$canWrite
            ]); exit;
        }
        
        elseif ($action === 'save_db_order') {
            if (!$canWrite) { echo json_encode(['status'=>'error','message'=>'You only have View access. Edits are disabled.']); exit; }

            $id = intval($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'Draft';
            
            if ($status === 'Submitted') {
                $status = 'Pending_Manager';
            }
            
            $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
            if (empty(trim($_POST['party_name'] ?? ''))) { echo json_encode(['status'=>'error','message'=>'Party name required']); exit; }
            if (empty($items)) { echo json_encode(['status'=>'error','message'=>'Add at least one product line']); exit; }

            $totQty=$tax=$gst=$disc=$grand=0;
            foreach ($items as $it) {
                $totQty += floatval($it['actual_qty']??0) + floatval($it['bonus_qty']??0);
                $tax += floatval($it['taxable']??0);
                $g = floatval($it['taxable']??0) * floatval($it['gst_pct']??0) / 100;
                $gst += $g;
                $disc += floatval($it['taxable']??0) * floatval($it['disc_pct']??0) / 100;
            }
            $grand = $tax + $gst;
            $currentDateTime = date('Y-m-d H:i:s');

            if($id > 0) {
                if (!$isAdmin) {
                    $chk = $pdo->prepare("SELECT empcode FROM sales_orders WHERE id=?");
                    $chk->execute([$id]);
                    $ordOwner = $chk->fetchColumn();
                    if ($ordOwner !== $empCode && !in_array($ordOwner, $teamCodes)) {
                        echo json_encode(['status'=>'error','message'=>'Unauthorized to update this order.']); exit;
                    }
                }

                $pdo->prepare("UPDATE sales_orders SET order_date=?,empcode=?,empname=?,hq=?,state=?,party_name=?,party_address=?,
                    transporter_name=?,transporter_address=?,mobile=?,email=?,status=?,total_qty=?,taxable_value=?,total_gst=?,grand_total=?,total_discount=?, updated_at=? WHERE id=?")
                    ->execute([
                        $_POST['order_date']?:date('Y-m-d'), $_POST['empcode']?:$empCode, $_POST['empname']?:$empName, trim($_POST['hq']??''), trim($_POST['state']??''),
                        trim($_POST['party_name']??''), trim($_POST['party_address']??''),
                        trim($_POST['transporter_name']??''), trim($_POST['transporter_address']??''),
                        trim($_POST['mobile']??''), trim($_POST['email']??''),
                        $status, $totQty, $tax, $gst, $grand, $disc, $currentDateTime, $id
                    ]);
                $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$id]);
                $oid = $id;
                $ono = $_POST['order_no'];
            } else {
                $ono = nextOrderNo($pdo);
                $pdo->prepare("INSERT INTO sales_orders (order_no,order_date,empcode,empname,hq,state,party_name,party_address,
                    transporter_name,transporter_address,mobile,email,status,total_qty,taxable_value,total_gst,grand_total,total_discount, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $ono, $_POST['order_date']?:date('Y-m-d'), $_POST['empcode']?:$empCode, $_POST['empname']?:$empName, trim($_POST['hq']??''), trim($_POST['state']??''),
                        trim($_POST['party_name']??''), trim($_POST['party_address']??''),
                        trim($_POST['transporter_name']??''), trim($_POST['transporter_address']??''),
                        trim($_POST['mobile']??''), trim($_POST['email']??''),
                        $status, $totQty, $tax, $gst, $grand, $disc, $currentDateTime
                    ]);
                $oid = $pdo->lastInsertId();
            }

            $ins = $pdo->prepare("INSERT INTO sales_order_items (order_id,product_name,mrp,rate_type,scheme,actual_qty,bonus_qty,gst_pct,disc_pct,req_rate,final_rate,taxable) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($items as $it) {
                $ins->execute([
                    $oid, $it['product_name']??'', floatval($it['mrp']??0), $it['rate_type']??'', $it['scheme']??'',
                    floatval($it['actual_qty']??0), floatval($it['bonus_qty']??0), floatval($it['gst_pct']??0),
                    floatval($it['disc_pct']??0), floatval($it['req_rate']??0), floatval($it['final_rate']??0), floatval($it['taxable']??0)
                ]);
            }
            echo json_encode(['status'=>'success','message'=>'Order processed successfully! Order No: '.$ono,'id'=>$oid,'order_no'=>$ono]); exit;
        }

        elseif ($action === 'fetch_list') {
            $sql = "SELECT * FROM sales_orders WHERE 1=1"; $p=[];
            if ($isMR && !$isAdmin) { 
                $sql .= " AND empcode=?"; 
                $p[] = $empCode; 
            } 
            elseif ($isManager && !$isAdmin) { 
                $validCodes = array_merge([$empCode], $teamCodes);
                $ph = implode(',', array_fill(0, count($validCodes), '?'));
                $sql .= " AND empcode IN ($ph)"; 
                $p = $validCodes; 
            } 
            $sql .= " ORDER BY created_at DESC LIMIT 200";
            $st=$pdo->prepare($sql); $st->execute($p);
            echo json_encode(['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }

        elseif ($action === 'load_order') {
            $id=intval($_POST['id']??0);
            
            $sql = "SELECT * FROM sales_orders WHERE id=?"; $p = [$id];
            if ($isMR && !$isAdmin) { $sql .= " AND empcode=?"; $p[] = $empCode; }
            elseif ($isManager && !$isAdmin) { 
                $validCodes = array_merge([$empCode], $teamCodes);
                $ph = implode(',', array_fill(0, count($validCodes), '?'));
                $sql .= " AND empcode IN ($ph)"; 
                $p = array_merge([$id], $validCodes);
            }

            $st=$pdo->prepare($sql); $st->execute($p);
            $ord=$st->fetch(PDO::FETCH_ASSOC);
            if (!$ord) { echo json_encode(['status'=>'error','message'=>'Not found or Access Denied']); exit; }
            $it=$pdo->prepare("SELECT * FROM sales_order_items WHERE order_id=?"); $it->execute([$id]);
            echo json_encode(['status'=>'success','order'=>$ord,'items'=>$it->fetchAll(PDO::FETCH_ASSOC)]); exit;
        }
        
        elseif ($action === 'cancel_order') {
            if (!$canWrite) { echo json_encode(['status'=>'error','message'=>'View only access. Cannot cancel.']); exit; }
            $id=intval($_POST['id']??0);
            
            $sql = "UPDATE sales_orders SET status='Cancelled' WHERE id=? AND status IN ('Draft', 'Pending_Manager')";
            $p = [$id];
            
            if (!$isAdmin) {
                $sql .= " AND empcode=?";
                $p[] = $empCode;
            }

            $st=$pdo->prepare($sql); 
            $st->execute($p);
            
            if ($st->rowCount() > 0) {
                echo json_encode(['status'=>'success','message'=>'Order Cancelled']); 
            } else {
                echo json_encode(['status'=>'error','message'=>'Order could not be cancelled. It may already be approved.']);
            }
            exit;
        }

    } catch (Exception $e) { $response['message']=$e->getMessage(); }
    echo json_encode($response); exit;
}

$pageTitle = "Order Sheet";
include __DIR__ . '/../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap" rel="stylesheet">

<div class="calc-wrapper">
    
    <div class="tabs">
        <button class="tab-btn active" onclick="sw('new')">Order Worksheet</button>
        <button class="tab-btn" onclick="sw('list')">Approval Queue</button>
    </div>

    <div id="tab-new" class="tab-content active">

        <?php if(!$canWrite): ?>
        <div style="background:#fee2e2; color:#b91c1c; padding:8px; border:1px solid #fca5a5; margin-bottom:8px; font-weight:600; text-align:center;">
            View Only Access: You do not have permission to create or edit orders.
        </div>
        <?php endif; ?>

        <div class="action-bar <?php if(!$canWrite) echo 'no-write'; ?>">
            <button class="btn" onclick="addSingleRow()">➕ Add Row</button>
            <button class="btn" style="background:#e2e8f0; color:var(--navy);" onclick="clearForm()">🔄 Clear</button>
            <button class="btn" style="background:#c9982e; border-color:#c9982e;" onclick="submitOrderProcess('Draft')">💾 Save Draft</button>
            <button class="btn" style="background:#00aeea; border-color:#00aeea;" onclick="submitOrderProcess('Submitted')">🚀 Submit Order</button>
        </div>

        <div class="header-grid <?php if(!$canWrite) echo 'no-write'; ?>">
            <div class="header-col">
                <label>Party Details</label>
                <select id="stateFilter" onchange="filterDropdowns()"><option value="">-- State --</option></select>
                <!-- Party dropdown auto filtered by state & employee -->
                <select id="partyName" onchange="autoFillParty()"><option value="">-- Party --</option></select>
                <input type="text" id="partyAddress" placeholder="Party Address Appended Here" readonly>
                <input type="text" id="orderNumber" readonly placeholder="Invoice Order No (Auto)" style="background:#f1f5f9; color:#64748b;">
            </div>
            <div class="header-col">
                <label>Transporter Details</label>
                <select id="transporterName" onchange="autoFillTransporter()"><option value="">-- Transporter --</option></select>
                <input type="text" id="transporterAddress" placeholder="Transporter Address Appended Here" readonly>
                <input type="text" id="orderDate" readonly title="Order Date">
            </div>
            <div class="header-col">
                <label>Employee Details</label>
                <!-- onchange triggers both Employee AutoFill and Party Re-Filtering -->
                <select id="mrName" onchange="onMrChange()"><option value="">-- Employee --</option></select>
                <div style="display:flex; gap:4px;">
                    <input type="text" id="hqName" placeholder="HQ Appended Here" readonly>
                    <input type="text" id="mobileNumber" placeholder="Mobile Appended Here" readonly>
                </div>
                <input type="email" id="emailId" placeholder="Email Appended Here" readonly>
            </div>
        </div>

        <input type="hidden" id="oid">

        <div class="table-container <?php if(!$canWrite) echo 'no-write'; ?>">
            <table id="orderTable">
                <thead>
                    <tr>
                        <th class="col-sl">Sl</th>
                        <th class="col-product">Product Name</th>
                        <th class="col-mrp">MRP</th>
                        <th class="col-rate-type">Rate Type</th>
                        <th class="col-scheme">Scheme</th>
                        <th class="col-qty">Act Qty</th>
                        <th class="col-qty">Bns Qty</th>
                        <th class="col-gst">GST %</th>
                        <th class="col-req">Disc %</th>
                        <th class="col-req">Req Rate</th>
                        <th class="col-req">Final Rate</th>
                        <th class="col-req">Taxable</th>
                        <th style="width: 20px;"></th>
                    </tr>
                </thead>
                <tbody id="itemBody"></tbody>
            </table>
        </div>

        <div class="totals-section">
            <div class="totals-col">
                <div style="border-bottom: 1px dotted var(--navy); padding-bottom: 2px; color: var(--navy);">Rate Guidelines & Formulas</div>
                <div style="font-size:9.5px; margin-top:2px;">PTR/PTS + Bonus: (PTR/PTS &times; Act) &divide; Tot</div>
                <div style="font-size:9.5px;">PTR/PTS + Disc: Base - Disc %</div>
                <div style="font-size:9.5px;">Flat Rate: Rate / (1 + GST%)</div>
            </div>
            
            <div class="totals-col right-align">
                <div class="totals-row"><span>Total Products:</span> <span id="displayTotalItems">0</span></div>
                <div class="totals-row"><span>Total Quantity:</span> <span id="displayTotalQty">0</span></div>
                <div class="totals-row"><span>Total Discount:</span> <span id="displayTotalDiscount">0.00</span></div>
                <div class="totals-row"><span>Taxable Value:</span> <span id="displayActualValue">0.00</span></div>
                <div class="totals-row" style="border-bottom:none;"><span>Total GST:</span> <span id="displayTotalGST">0.00</span></div>
            </div>

            <div class="grand-total-box">
                <span>Grand Total Amount:</span> &nbsp;<span id="displayGrandTotal">0.00</span>
            </div>
        </div>
    </div> 

    <!-- TAB 2: QUEUE -->
    <div id="tab-list" class="tab-content">
        <div class="action-bar">
            <button class="btn" onclick="loadList()">Refresh Queue</button>
        </div>
        <div class="table-container" style="max-height: 400px;">
            <table>
                <thead>
                    <tr>
                        <th>Order No</th><th>Submission Date</th><th>Employee</th><th>Party</th><th>Qty</th><th>Total</th><th>Status</th><th>Action</th>
                    </tr>
                </thead>
                <tbody id="listBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- PREVIEW MODAL -->
<div id="previewModal" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <span class="modal-title" id="modalTitle">Order Summary</span>
        <div id="previewDetails"></div>
        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px; padding-top:8px; border-top:1px dotted var(--navy);">
            <button class="btn" style="background:#e2e8f0; color:var(--navy);" onclick="closePreview()">Close / Go Back</button>
            <button id="modalConfirmBtn" class="btn" style="background:#00aeea; border-color:#00aeea; display:none;" onclick="submitConfirmed()">Confirm & Submit</button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
const CAN_WRITE = <?= $canWrite ? 'true' : 'false' ?>;

let dbData = { products: [], parties: [], transporters: [], mrs: [] };
let schemeOptionsHTML = `<option value="0">None</option><option value="5_1">5+1</option>`;
for (let i = 1; i <= 30; i++) schemeOptionsHTML += `<option value="10_${i}">10+${i}</option>`;
let currentRowCount = 0;
let pendingSubmitStatus = '';

function fetchWithCsrf(action, formData = new FormData()) {
    formData.append('ajax_action', action);
    formData.append('csrf_token', CSRF_TOKEN);
    return fetch('', { method: 'POST', body: formData }).then(r => r.json());
}

function sw(t){ 
    document.querySelectorAll('.tab-content').forEach(e=>e.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));
    document.getElementById('tab-'+t).classList.add('active');
    event.target.classList.add('active');
    if(t==='list') loadList(); 
}

function formatState(val) {
    if(!val) return '';
    return val.trim().split(/\s+/).map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ');
}

function initData() {
    fetchWithCsrf('get_metadata').then(d => {
        if(d.status !== 'success') return;
        dbData = d;
        
        const stateSet = new Set();
        (d.parties||[]).forEach(p => { let s = formatState(p.state); if(s) stateSet.add(s); });
        (d.transporters||[]).forEach(t => { let s = formatState(t.state); if(s) stateSet.add(s); });
        (d.mrs||[]).forEach(m => { let s = formatState(m.state); if(s) stateSet.add(s); });
        
        const stSel = document.getElementById('stateFilter');
        Array.from(stateSet).sort().forEach(s => stSel.innerHTML += `<option value="${s}">${s}</option>`);
        
        let mySt = formatState(d.my_state);
        if(mySt && stateSet.has(mySt)) { stSel.value = mySt; }
        
        filterDropdowns(); // Populates MRs and Transporters, then triggers filterParties
        
        document.getElementById("orderNumber").value = "ORD" + new Date().toISOString().replace(/[-:T]/g,'').slice(0,14);
        
        const now = new Date();
        document.getElementById("orderDate").value = `${now.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })} ${now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true })}`;
        
        if(CAN_WRITE && document.getElementById('itemBody').children.length === 0) {
            for(let i=0; i<5; i++) addSingleRow();
        }
    });
}

function filterDropdowns() {
    const st = document.getElementById('stateFilter').value;
    
    // Transporters
    const tSel = document.getElementById('transporterName');
    const curT = tSel.value;
    tSel.innerHTML = '<option value="">-- Transporter --</option>';
    (dbData.transporters||[]).forEach(t => {
        let tName = t.transportername || t.transporter_name || t.name || '';
        let tState = formatState(t.state);
        let tAddr = t.servicearea || t.address || '';
        if(t.phone) tAddr += ' (Ph: ' + t.phone + ')';
        if (!st || tState === st || !t.state) tSel.innerHTML += `<option value="${tName}" data-addr="${tAddr}">${tName}</option>`;
    });
    tSel.value = [...tSel.options].some(o => o.value === curT) ? curT : "";
    autoFillTransporter();

    // Employees
    const mSel = document.getElementById('mrName');
    const curM = mSel.value;
    mSel.innerHTML = '<option value="">-- Employee --</option>';
    (dbData.mrs||[]).forEach(m => {
        let mCode = m.empcode || m.emp_code || '';
        let mName = m.empname || m.emp_name || m.name || '';
        let mState = formatState(m.state);
        let mHq = m.hq || m.headquarter || '';
        let mMob = m.mobile || m.phone || '';
        let mEm = m.email || '';
        
        if(!st || mState === st) mSel.innerHTML += `<option value="${mCode}" data-name="${mName}" data-hq="${mHq}" data-mob="${mMob}" data-em="${mEm}">${mName} (${mCode})</option>`;
    });
    if(curM && [...mSel.options].some(o => o.value === curM)) mSel.value = curM;
    else if(dbData.my_empcode && [...mSel.options].some(o => o.value === dbData.my_empcode)) mSel.value = dbData.my_empcode;
    else mSel.value = "";
    autoFillMR();
    
    // Auto-trigger Party Filtering
    filterParties();
}

function onMrChange() {
    autoFillMR();
    filterParties();
}

function filterParties() {
    const st = document.getElementById('stateFilter').value;
    const emp = document.getElementById('mrName').value;
    
    const pSel = document.getElementById('partyName');
    const curP = pSel.value;
    pSel.innerHTML = '<option value="">-- Party --</option>';
    
    const added = new Set();
    (dbData.parties||[]).forEach(p => {
        let pName = p.partyname || p.party_name || p.name || '';
        let pState = formatState(p.state);
        let pEmp = p.assigned_emp || '';
        
        let match = true;
        
        // Match State (allow if party has no state defined in DB, or if it matches)
        if (st && pState && pState !== st) match = false;
        
        // STRICT MATCH: If employee is selected, party MUST be assigned to this specific employee
        if (emp && pEmp !== emp) match = false;
        
        if (match && !added.has(pName)) {
            added.add(pName);
            let pAddr = [p.hq, p.area].filter(Boolean).join(', ') || p.address || p.party_address || '';
            pSel.innerHTML += `<option value="${pName}" data-addr="${pAddr}">${pName}</option>`;
        }
    });
    pSel.value = [...pSel.options].some(o => o.value === curP) ? curP : "";
    autoFillParty();
}

function autoFillParty() {
    const opt = document.getElementById('partyName').options[document.getElementById('partyName').selectedIndex];
    document.getElementById('partyAddress').value = opt && opt.value ? opt.getAttribute('data-addr') : '';
}

function autoFillTransporter() {
    const opt = document.getElementById('transporterName').options[document.getElementById('transporterName').selectedIndex];
    document.getElementById('transporterAddress').value = opt && opt.value ? opt.getAttribute('data-addr') : '';
}

function autoFillMR() {
    const opt = document.getElementById('mrName').options[document.getElementById('mrName').selectedIndex];
    if(opt && opt.value) {
        document.getElementById('hqName').value = opt.getAttribute('data-hq');
        document.getElementById('mobileNumber').value = opt.getAttribute('data-mob');
        document.getElementById('emailId').value = opt.getAttribute('data-em');
    } else {
        document.getElementById('hqName').value = ""; document.getElementById('mobileNumber').value = ""; document.getElementById('emailId').value = "";
    }
}

function prodOptions(sel) {
    return `<option value="">-- Select Product --</option>` + dbData.products.map(p => {
        let n = p.product_name || p.productname || p.name || '';
        let m = p.mrp || p.rate || p.price || 0; 
        let defaultG = parseFloat(p.defaultsgst||0) + parseFloat(p.defaultcgst||0);
        let g = p.gst || p.gst_pct || (defaultG > 0 ? defaultG : 12);
        return `<option value="${n}" data-mrp="${m}" data-gst="${g}" ${n===sel?'selected':''}>${n}</option>`;
    }).join('');
}

function addSingleRow(data={}) {
    currentRowCount++;
    const tr = document.createElement("tr");
    tr.innerHTML = `
        <td class="col-sl">${currentRowCount}</td>
        <td class="col-product"><select class="product-name" onchange="onProdChange(this)">${prodOptions(data.product_name||'')}</select></td>
        <td><input type="number" class="mrp-input" value="${data.mrp||''}" oninput="calcRow(this)"></td>
        <td>
          <select class="rate-type col-rate-type" onchange="onRateTypeChange(this)">
            <option value="PTR + Bonus" ${data.rate_type==='PTR + Bonus'?'selected':''}>PTR + Bonus</option>
            <option value="PTS + Bonus" ${data.rate_type==='PTS + Bonus'?'selected':''}>PTS + Bonus</option>
            <option value="PTR + Bonus + Disc" ${data.rate_type==='PTR + Bonus + Disc'?'selected':''}>PTR+Bns+Disc</option>
            <option value="PTS + Bonus + Disc" ${data.rate_type==='PTS + Bonus + Disc'?'selected':''}>PTS+Bns+Disc</option>
            <option value="Flat Rate" ${data.rate_type==='Flat Rate'?'selected':''}>Flat Rate</option>
            <option value="% Discount on MRP" ${data.rate_type==='% Discount on MRP'?'selected':''}>% Disc on MRP</option>
          </select>
        </td>
        <td><select class="bonus-scheme" onchange="calcRow(this)">${schemeOptionsHTML}</select></td>
        <td><input type="number" min="0" class="actual-qty" value="${data.actual_qty||''}" oninput="calcRow(this)"></td>
        <td><input type="number" min="0" class="bonus-qty" value="${data.bonus_qty||''}" readonly tabindex="-1"></td>
        <td>
          <select class="gst-rate" onchange="calcRow(this)">
            <option value="0" ${data.gst_pct==0?'selected':''}>0%</option>
            <option value="5" ${(data.gst_pct||12)==5?'selected':''}>5%</option>
            <option value="12" ${(data.gst_pct||12)==12?'selected':''}>12%</option>
            <option value="18" ${(data.gst_pct||12)==18?'selected':''}>18%</option>
          </select>
        </td>
        <td><input type="number" min="0" class="disc-perc" value="${data.disc_pct||''}" readonly tabindex="-1" oninput="calcRow(this)"></td>
        <td><input type="number" min="0" class="req-rate-perc" value="${data.req_rate||''}" readonly tabindex="-1" oninput="calcRow(this)"></td>
        <td><input type="number" class="final-rate" value="${data.final_rate||''}" readonly tabindex="-1"></td>
        <td><input type="number" class="total-value" value="${data.taxable||''}" readonly tabindex="-1" style="color:var(--navy);"></td>
        <td><button type="button" class="btn" style="padding:0 5px; background:#ef4444; border-color:#ef4444;" onclick="this.closest('tr').remove(); reindex(); calcTotals();">×</button></td>
    `;
    if(data.scheme) tr.querySelector('.bonus-scheme').value = data.scheme;
    document.getElementById('itemBody').appendChild(tr);
    onRateTypeChange(tr.querySelector('.rate-type'));
}

function onProdChange(sel) {
    const tr = sel.closest('tr');
    if(sel.value) {
        const opt = sel.options[sel.selectedIndex];
        tr.querySelector('.mrp-input').value = opt.getAttribute('data-mrp');
        tr.querySelector('.gst-rate').value = opt.getAttribute('data-gst') || 12;
    } else {
        tr.querySelector('.mrp-input').value = "";
    }
    onRateTypeChange(tr.querySelector('.rate-type'));
}

function onRateTypeChange(sel) {
    const tr = sel.closest('tr');
    const rt = sel.value;
    const sch = tr.querySelector('.bonus-scheme');
    const disc = tr.querySelector('.disc-perc');
    const req = tr.querySelector('.req-rate-perc');

    sch.disabled = !rt.includes('+ Bonus');
    if(sch.disabled) sch.value = "0";

    disc.readOnly = !(rt.includes('+ Disc') || rt === '% Discount on MRP');
    if(disc.readOnly) disc.value = "";

    req.readOnly = (rt !== 'Flat Rate' && rt !== 'Special rate');
    if(req.readOnly) req.value = "";

    calcRow(sel);
}

function calcRow(el) {
    const tr = el.closest('tr');
    const mrp = parseFloat(tr.querySelector('.mrp-input').value) || 0;
    const rt = tr.querySelector('.rate-type').value;
    const sch = tr.querySelector('.bonus-scheme').value;
    const actQty = Math.max(0, parseFloat(tr.querySelector('.actual-qty').value) || 0);
    const gst = parseFloat(tr.querySelector('.gst-rate').value) || 0;
    const disc = Math.max(0, parseFloat(tr.querySelector('.disc-perc').value) || 0);
    const req = Math.max(0, parseFloat(tr.querySelector('.req-rate-perc').value) || 0);

    let bns = 0;
    if(!tr.querySelector('.bonus-scheme').disabled && sch !== "0" && actQty > 0) {
        const p = sch.split('_');
        if(actQty >= parseInt(p[0])) bns = Math.floor(actQty / parseInt(p[0])) * parseInt(p[1]);
    }
    tr.querySelector('.bonus-qty').value = bns > 0 ? bns : "";
    const totQty = actQty + bns;

    tr.dataset.rActualValue = 0; tr.dataset.rDiscount = 0; tr.dataset.rGst = 0;

    if(mrp > 0 && actQty > 0) {
        const ptr = mrp * 0.7651; const pts = ptr * 0.8299;
        let base = 0;
        if(rt.includes('PTR')) base = ptr;
        else if(rt.includes('PTS')) base = pts;
        else if(rt === '% Discount on MRP') base = mrp;
        else if(rt === 'Flat Rate') base = req > 0 ? (req / (1 + (gst/100))) : 0;

        let eff = base;
        if(!tr.querySelector('.bonus-scheme').disabled && totQty > 0) eff = (base * actQty) / totQty;
        
        let dAmt = 0;
        if(!tr.querySelector('.disc-perc').readOnly && disc > 0) {
            dAmt = eff * (disc/100);
            eff -= dAmt;
        }

        tr.querySelector('.final-rate').value = eff > 0 ? eff.toFixed(2) : "";
        const tax = Math.round((actQty * eff) * 100) / 100;
        tr.querySelector('.total-value').value = tax.toFixed(2);
        tr.dataset.rActualValue = tax;
        tr.dataset.rGst = Math.round((tax * (gst/100)) * 100) / 100;
        tr.dataset.rDiscount = dAmt * actQty;
    } else {
        tr.querySelector('.final-rate').value = "";
        tr.querySelector('.total-value').value = "";
    }
    calcTotals();
}

function calcTotals() {
    let tItems=0, sAct=0, sBns=0, tAct=0, tDisc=0, tGst=0;
    document.querySelectorAll('#itemBody tr').forEach(tr => {
        const act = parseFloat(tr.querySelector('.actual-qty').value) || 0;
        if(act > 0 && tr.querySelector('.product-name').value) {
            tItems++; sAct += act; sBns += parseFloat(tr.querySelector('.bonus-qty').value)||0;
            tAct += parseFloat(tr.dataset.rActualValue)||0;
            tDisc += parseFloat(tr.dataset.rDiscount)||0;
            tGst += parseFloat(tr.dataset.rGst)||0;
        }
    });

    document.getElementById('displayTotalItems').innerText = tItems;
    document.getElementById('displayTotalQty').innerText = `${sAct + sBns} (Act: ${sAct} + Bns: ${sBns})`;
    document.getElementById('displayActualValue').innerText = '₹' + tAct.toFixed(2);
    document.getElementById('displayTotalDiscount').innerText = '₹' + tDisc.toFixed(2);
    document.getElementById('displayTotalGST').innerText = '₹' + tGst.toFixed(2);
    document.getElementById('displayGrandTotal').innerText = '₹' + (tAct + tGst).toFixed(2);
}

function reindex() {
    document.querySelectorAll('#itemBody tr').forEach((r, i) => r.querySelector('.col-sl').innerText = i + 1);
}

function validateOrderForm() {
    if (!CAN_WRITE) { alert("You have view-only rights."); return false; }
    
    const state = document.getElementById('stateFilter');
    const party = document.getElementById('partyName');
    const transporter = document.getElementById('transporterName');
    const mr = document.getElementById('mrName');

    if (!state.value) { alert("Validation Error: Please select a State."); state.focus(); return false; }
    if (!party.value) { alert("Validation Error: Please select a Party."); party.focus(); return false; }
    if (!transporter.value) { alert("Validation Error: Please select a Transporter."); transporter.focus(); return false; }
    if (!mr.value) { alert("Validation Error: Please select an Employee."); mr.focus(); return false; }
    
    let hasValidProd = false;
    document.querySelectorAll('#itemBody tr').forEach(tr => {
        const prod = tr.querySelector('.product-name').value;
        const actQty = parseFloat(tr.querySelector('.actual-qty').value) || 0;
        if (prod && actQty > 0) hasValidProd = true;
    });
    
    if(!hasValidProd) { alert("Validation Error: Please add at least one product with an actual quantity greater than 0."); return false; }
    
    return true;
}

function submitOrderProcess(status) {
    if (!validateOrderForm()) return;
    pendingSubmitStatus = status;

    if (status === 'Submitted') {
        const state = document.getElementById('stateFilter').value;
        const party = document.getElementById('partyName').value;
        const transporter = document.getElementById('transporterName').value;
        const mrSel = document.getElementById('mrName');
        const mrText = mrSel.options[mrSel.selectedIndex]?.text || '';
        
        let validItems = [];
        document.querySelectorAll('#itemBody tr').forEach(tr => {
            const prod = tr.querySelector('.product-name').value;
            const actQty = parseFloat(tr.querySelector('.actual-qty').value) || 0;
            const bnsQty = parseFloat(tr.querySelector('.bonus-qty').value) || 0;
            const rate = tr.querySelector('.final-rate').value;
            const tax = tr.querySelector('.total-value').value;
            if (prod && actQty > 0) validItems.push({ name: prod, qty: (actQty + bnsQty), rate: rate, taxable: tax });
        });

        const gTotal = document.getElementById('displayGrandTotal').innerText;

        let itemRows = validItems.map(item => `
            <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 4px; text-align: left;">${item.name}</td>
                <td style="padding: 4px; text-align: center;">${item.qty}</td>
                <td style="padding: 4px; text-align: right;">₹${item.rate}</td>
                <td style="padding: 4px; text-align: right;">₹${item.taxable}</td>
            </tr>
        `).join('');

        document.getElementById('modalTitle').innerText = "Order Summary Verification";
        document.getElementById('previewDetails').innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; background:#f8fafc; padding:8px; border:1px solid #cbd5e1;">
                <div><span>State:</span> <strong>${state}</strong></div>
                <div><span>Employee:</span> <strong>${mrText}</strong></div>
                <div style="grid-column: span 2;"><span>Party:</span> <strong>${party}</strong></div>
                <div style="grid-column: span 2;"><span>Transporter:</span> <strong>${transporter}</strong></div>
            </div>
            
            <div style="max-height: 180px; overflow-y: auto; border: 1px solid #cbd5e1; margin-bottom: 10px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                    <thead style="background: var(--navy); color:white; position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 4px; text-align: left;">Product Name</th>
                            <th style="padding: 4px;">Total Qty</th>
                            <th style="padding: 4px; text-align: right;">Final Rate</th>
                            <th style="padding: 4px; text-align: right;">Taxable</th>
                        </tr>
                    </thead>
                    <tbody>${itemRows}</tbody>
                </table>
            </div>
            
            <div style="display:flex; justify-content:space-between; align-items:center; background:#e0f2fe; padding:8px; border:1px solid #38bdf8;">
                <span>Grand Total (Inc. GST):</span> 
                <span style="font-size:15px; color:var(--navy);">${gTotal}</span>
            </div>
        `;
        document.getElementById('modalConfirmBtn').style.display = 'block';
        document.getElementById('previewModal').style.display = 'flex';
    } else {
        saveToDB('Draft');
    }
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
}

function submitConfirmed() {
    closePreview();
    saveToDB(pendingSubmitStatus);
}

function saveToDB(status) {
    if (!CAN_WRITE) { alert("You have view-only access."); return; }
    const party = document.getElementById('partyName').value;
    
    const items = [];
    document.querySelectorAll('#itemBody tr').forEach(tr => {
        const prod = tr.querySelector('.product-name').value;
        const act = parseFloat(tr.querySelector('.actual-qty').value) || 0;
        if(prod && act > 0) {
            items.push({
                product_name: prod, mrp: tr.querySelector('.mrp-input').value,
                rate_type: tr.querySelector('.rate-type').value, scheme: tr.querySelector('.bonus-scheme').value,
                actual_qty: act, bonus_qty: tr.querySelector('.bonus-qty').value || 0,
                gst_pct: tr.querySelector('.gst-rate').value, disc_pct: tr.querySelector('.disc-perc').value || 0,
                req_rate: tr.querySelector('.req-rate-perc').value || 0, final_rate: tr.querySelector('.final-rate').value,
                taxable: tr.querySelector('.total-value').value
            });
        }
    });

    const mrOpt = document.getElementById('mrName').options[document.getElementById('mrName').selectedIndex];

    const fd = new FormData();
    fd.append('id', document.getElementById('oid').value);
    fd.append('order_no', document.getElementById('orderNumber').value);
    fd.append('status', status);
    fd.append('order_date', document.getElementById('orderDate').value);
    fd.append('empcode', mrOpt ? mrOpt.value : '');
    fd.append('empname', mrOpt ? mrOpt.getAttribute('data-name') : '');
    fd.append('hq', document.getElementById('hqName').value);
    fd.append('state', document.getElementById('stateFilter').value);
    fd.append('mobile', document.getElementById('mobileNumber').value);
    fd.append('email', document.getElementById('emailId').value);
    fd.append('party_name', party);
    fd.append('party_address', document.getElementById('partyAddress').value);
    fd.append('transporter_name', document.getElementById('transporterName').value);
    fd.append('transporter_address', document.getElementById('transporterAddress').value);
    fd.append('items_json', JSON.stringify(items));

    fetchWithCsrf('save_db_order', fd).then(d => {
        if(d.status === 'success') {
            document.getElementById('oid').value = d.id;
            alert(d.message);
            if(status === 'Submitted') sw('list');
        } else {
            alert(d.message);
        }
    });
}

function clearForm() {
    if (!CAN_WRITE) return;
    if(!confirm("Clear all data?")) return;
    document.getElementById('oid').value = "";
    document.querySelectorAll('.header-col input:not([readonly]), .header-col select').forEach(el => el.value="");
    document.getElementById('itemBody').innerHTML = "";
    currentRowCount = 0;
    for(let i=0; i<5; i++) addSingleRow();
    
    document.getElementById("orderNumber").value = "ORD" + new Date().toISOString().replace(/[-:T]/g,'').slice(0,14);
    
    const mSel = document.getElementById('mrName');
    if(dbData.my_empcode && [...mSel.options].some(o => o.value === dbData.my_empcode)) {
        mSel.value = dbData.my_empcode;
        autoFillMR();
    }
    calcTotals();
}

function loadList() {
    fetchWithCsrf('fetch_list').then(d => {
        document.getElementById('listBody').innerHTML = (d.data||[]).map(o => {
            let isEditable = CAN_WRITE && (o.status === 'Draft' || o.status === 'Submitted');
            let subTime = o.created_at ? o.created_at : '-';
            let act = `<button class="btn" style="padding:3px 6px; font-size:9px;" onclick="viewOrderDetails(${o.id})">View</button>`;
            if (isEditable) {
                act += ` <button class="btn" style="padding:3px 6px; font-size:9px; background:#f59e0b; border-color:#f59e0b;" onclick="editOrder(${o.id})">Edit</button>`;
                act += ` <button class="btn" style="padding:3px 6px; font-size:9px; background:#ef4444; border-color:#ef4444;" onclick="cancelOrder(${o.id})">Cancel</button>`;
            }
            return `<tr><td>${o.order_no}</td><td>${subTime}</td><td>${o.empname}</td><td>${o.party_name}</td><td>${o.total_qty}</td><td>₹${Number(o.grand_total).toLocaleString('en-IN')}</td><td>${o.status}</td><td>${act}</td></tr>`;
        }).join('') || '<tr><td colspan="8" style="padding:20px; text-align:center;">No orders found.</td></tr>';
    });
}

function editOrder(id) {
    if (!CAN_WRITE) { alert("View only access."); return; }
    const fd = new FormData(); fd.append('id', id);
    fetchWithCsrf('load_order', fd).then(d => {
        if(d.status!=='success') { alert(d.message); return; }
        const o = d.order;
        document.getElementById('oid').value = o.id;
        document.getElementById('orderNumber').value = o.order_no;
        document.getElementById('orderDate').value = o.order_date;
        document.getElementById('stateFilter').value = o.state || ''; filterDropdowns();
        
        document.getElementById('partyName').value = o.party_name;
        document.getElementById('partyAddress').value = o.party_address || '';
        document.getElementById('transporterName').value = o.transporter_name || '';
        document.getElementById('transporterAddress').value = o.transporter_address || '';
        
        document.getElementById('mrName').value = o.empcode || '';
        autoFillMR();
        
        document.getElementById('itemBody').innerHTML = '';
        (d.items||[]).forEach(it => addSingleRow(it));
        calcTotals();
        sw('new');
    });
}

function cancelOrder(id) {
    if (!CAN_WRITE) { alert("View only access. Cannot cancel."); return; }
    if(!confirm("Are you sure you want to cancel this order?")) return;
    const fd = new FormData(); fd.append('id', id);
    fetchWithCsrf('cancel_order', fd).then(d => {
        alert(d.message);
        if(d.status === 'success') loadList();
    });
}

function viewOrderDetails(id) {
    const fd = new FormData(); fd.append('id', id);
    fetchWithCsrf('load_order', fd).then(d => {
        if(d.status!=='success') return alert("Error loading order.");
        const o = d.order;
        const items = d.items || [];
        
        let itemRows = items.map(item => `
            <tr style="border-bottom: 1px solid #e2e8f0;">
                <td style="padding: 4px; text-align: left;">${item.product_name}</td>
                <td style="padding: 4px; text-align: center;">${item.mrp}</td>
                <td style="padding: 4px; text-align: center;">${item.rate_type}</td>
                <td style="padding: 4px; text-align: center;">${(parseFloat(item.actual_qty)+parseFloat(item.bonus_qty))} (A:${item.actual_qty})</td>
                <td style="padding: 4px; text-align: right;">₹${item.final_rate}</td>
                <td style="padding: 4px; text-align: right;">₹${item.taxable}</td>
            </tr>
        `).join('');

        document.getElementById('modalTitle').innerText = "Order Details: " + o.order_no;
        document.getElementById('previewDetails').innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 10px; background:#f8fafc; padding:8px; border:1px solid #cbd5e1;">
                <div><span>Date:</span> <span>${o.order_date}</span></div>
                <div><span>Status:</span> <span>${o.status}</span></div>
                <div><span>State:</span> <span>${o.state}</span></div>
                <div><span>Employee:</span> <span>${o.empname}</span></div>
                <div style="grid-column: span 2;"><span>Party:</span> <span>${o.party_name} (${o.party_address})</span></div>
                <div style="grid-column: span 2;"><span>Transporter:</span> <span>${o.transporter_name} (${o.transporter_address})</span></div>
            </div>
            
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #cbd5e1; margin-bottom: 10px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                    <thead style="background: var(--navy); color:white; position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 4px; text-align: left;">Product</th>
                            <th style="padding: 4px;">MRP</th>
                            <th style="padding: 4px;">Rate Type</th>
                            <th style="padding: 4px;">Qty</th>
                            <th style="padding: 4px; text-align: right;">Rate</th>
                            <th style="padding: 4px; text-align: right;">Taxable</th>
                        </tr>
                    </thead>
                    <tbody>${itemRows}</tbody>
                </table>
            </div>
            
            <div style="display:flex; justify-content:space-between; align-items:center; background:#e0f2fe; padding:8px; border:1px solid #38bdf8;">
                <span>Grand Total (Inc. GST):</span> 
                <span style="font-size:15px; color:var(--navy);">₹${Number(o.grand_total).toLocaleString('en-IN')}</span>
            </div>
        `;
        document.getElementById('modalConfirmBtn').style.display = 'none';
        document.getElementById('previewModal').style.display = 'flex';
    });
}

window.onload = initData;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>