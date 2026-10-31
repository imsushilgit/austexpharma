<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin']; $isManager = $ctx['is_manager']; $isMR = $ctx['is_mr'];
$teamCodes = $ctx['team_codes']; $empCode = $ctx['empcode']; $empName = $ctx['empname'] ?? $ctx['fullname'] ?? '';

if (!$isAdmin && !$isManager) {
    echo "<div style='padding:40px; text-align:center; color:red; font-family:Inter,sans-serif;'><h3>Access Denied</h3><p>You do not have permission to access the Order Approval Panel.</p></div>";
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if (isset($_POST['ajax_action'])) {
    verify_csrf();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status'=>'error','message'=>'Invalid Action'];
    
    try {
        if ($action === 'fetch_pending_orders') {
            $sql = "SELECT * FROM sales_orders WHERE status = 'Pending_Manager' AND 1=1"; 
            $p = [];
            
            if (!$isAdmin && $isManager && !empty($teamCodes)) {
                $ph = implode(',', array_fill(0, count($teamCodes), '?'));
                $sql .= " AND empcode IN ($ph)";
                $p = $teamCodes;
            } elseif (!$isAdmin && $isManager && empty($teamCodes)) {
                echo json_encode(['status'=>'success', 'data'=>[]]);
                exit;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT 200";
            $st = $pdo->prepare($sql); 
            $st->execute($p);
            echo json_encode(['status'=>'success', 'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]); 
            exit;
        }
        
        elseif ($action === 'load_order_details') {
            $id = intval($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sales_orders WHERE id=?"); 
            $st->execute([$id]);
            $ord = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ord) { echo json_encode(['status'=>'error','message'=>'Order not found']); exit; }
            
            $it = $pdo->prepare("SELECT * FROM sales_order_items WHERE order_id=?"); 
            $it->execute([$id]);
            
            $prods = [];
            try { $prods = $pdo->query("SELECT * FROM products")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

            echo json_encode(['status'=>'success', 'order'=>$ord, 'items'=>$it->fetchAll(PDO::FETCH_ASSOC), 'products'=>$prods]); 
            exit;
        }
        
        elseif ($action === 'update_and_approve_order') {
            $id = intval($_POST['id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? 'Approved'; // 'Approved' or 'Rejected'
            $remarks = trim($_POST['remarks'] ?? '');
            $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
            
            if (!in_array($newStatus, ['Approved', 'Rejected'])) {
                echo json_encode(['status'=>'error','message'=>'Invalid status action']);
                exit;
            }

            if ($newStatus === 'Approved' && empty($items)) {
                echo json_encode(['status'=>'error','message'=>'An approved order must contain at least one product item.']);
                exit;
            }

            $currentDateTime = date('Y-m-d H:i:s');

            if ($newStatus === 'Approved') {
                $totQty=$tax=$gst=$disc=$grand=0;
                foreach ($items as $it) {
                    $totQty += floatval($it['actual_qty']??0) + floatval($it['bonus_qty']??0);
                    $tax += floatval($it['taxable']??0);
                    $g = floatval($it['taxable']??0) * floatval($it['gst_pct']??0) / 100;
                    $gst += $g;
                    $disc += floatval($it['taxable']??0) * floatval($it['disc_pct']??0) / 100;
                }
                $grand = $tax + $gst;

                // Update Order Totals
                $pdo->prepare("UPDATE sales_orders SET status=?, total_qty=?, taxable_value=?, total_gst=?, grand_total=?, total_discount=?, mgr_by=?, mgr_on=?, mgr_remarks=? WHERE id=?")
                    ->execute([$newStatus, $totQty, $tax, $gst, $grand, $disc, $empName ?: $empCode, $currentDateTime, $remarks, $id]);

                // Replace Items with Manager's Edited List
                $pdo->prepare("DELETE FROM sales_order_items WHERE order_id=?")->execute([$id]);
                $ins = $pdo->prepare("INSERT INTO sales_order_items (order_id,product_name,mrp,rate_type,scheme,actual_qty,bonus_qty,gst_pct,disc_pct,req_rate,final_rate,taxable) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($items as $it) {
                    $ins->execute([
                        $id, $it['product_name']??'', floatval($it['mrp']??0), $it['rate_type']??'', $it['scheme']??'',
                        floatval($it['actual_qty']??0), floatval($it['bonus_qty']??0), floatval($it['gst_pct']??0),
                        floatval($it['disc_pct']??0), floatval($it['req_rate']??0), floatval($it['final_rate']??0), floatval($it['taxable']??0)
                    ]);
                }
                echo json_encode(['status'=>'success', 'message'=>'Order successfully edited and approved!']);
            } else {
                // Rejection
                $pdo->prepare("UPDATE sales_orders SET status=?, mgr_by=?, mgr_on=?, mgr_remarks=? WHERE id=?")
                    ->execute([$newStatus, $empName ?: $empCode, $currentDateTime, $remarks, $id]);
                echo json_encode(['status'=>'success', 'message'=>'Order has been rejected.']);
            }
            exit;
        }

    } catch (Exception $e) { 
        $response['message'] = $e->getMessage(); 
        echo json_encode($response); 
        exit;
    }
}

$pageTitle = "Order Approval Panel";
include __DIR__ . '/../includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap" rel="stylesheet">

<div class="calc-wrapper">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid var(--navy); padding-bottom: 6px; margin-bottom: 10px;">
        <span style="font-size:14px; text-transform:uppercase; color:var(--navy);">Manager Order Approval & Edit Panel</span>
        <button class="btn" onclick="loadPendingOrders()">🔄 Refresh Queue</button>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Order No</th>
                    <th>Submission Date & Time</th>
                    <th>Employee Name</th>
                    <th>Party Name</th>
                    <th>Qty</th>
                    <th>Grand Total</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="approvalListBody">
                <tr><td colspan="8" style="padding:20px; text-align:center;">Loading pending orders...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ORDER REVIEW & EDIT MODAL -->
<div id="approvalModal" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <span class="modal-title" id="modalOrderTitle">Review & Edit Order</span>
        <div id="orderHeaderMeta"></div>
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin: 8px 0 4px 0;">
            <strong style="font-size:10.5px; color:var(--navy);">Order Items (Manager can Edit Qty, Scheme or Add/Remove rows):</strong>
            <button class="btn" style="padding:2px 8px;" onclick="addManagerRow()">➕ Add Product</button>
        </div>

        <div style="max-height: 200px; overflow-y: auto; border: 1px dotted var(--navy); margin-bottom: 8px;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background:var(--navy); color:white; position:sticky; top:0;">
                    <tr>
                        <th style="padding:4px;">Product Name</th>
                        <th style="padding:4px;">MRP</th>
                        <th style="padding:4px;">Rate Type</th>
                        <th style="padding:4px;">Scheme</th>
                        <th style="padding:4px;">Act Qty</th>
                        <th style="padding:4px;">Bns Qty</th>
                        <th style="padding:4px;">GST%</th>
                        <th style="padding:4px;">Taxable</th>
                        <th style="padding:4px;"></th>
                    </tr>
                </thead>
                <tbody id="managerItemsBody"></tbody>
            </table>
        </div>

        <div style="display:flex; justify-content:flex-end; background:#e0f2fe; padding:6px 10px; border:1px solid #38bdf8; margin-bottom:8px;">
            <span style="font-weight:bold; font-size:11px;">Recalculated Grand Total: &nbsp;<span id="modalGrandTotal" style="font-size:14px; color:var(--navy);">₹0.00</span></span>
        </div>
        
        <div>
            <label style="display:block; font-size:10px; text-transform:uppercase; margin-bottom:2px; color:var(--navy);">Manager Remarks / Reason for Rejection:</label>
            <textarea id="managerRemarks" placeholder="Enter remarks here..."></textarea>
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:10px; padding-top:6px; border-top:1px dotted var(--navy);">
            <button class="btn" style="background:#e2e8f0; color:var(--navy);" onclick="closeApprovalModal()">Close</button>
            <button class="btn" style="background:#ef4444; border-color:#ef4444;" onclick="processApproval('Rejected')">❌ Reject Order</button>
            <button class="btn" style="background:#22c55e; border-color:#22c55e;" onclick="processApproval('Approved')">✅ Save Changes & Approve</button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
let currentProcessingId = null;
let globalProductsList = [];
let schemeOptionsHTML = `<option value="0">None</option><option value="5_1">5+1</option>`;
for (let i = 1; i <= 30; i++) schemeOptionsHTML += `<option value="10_${i}">10+${i}</option>`;

function fetchWithCsrf(action, formData = new FormData()) {
    formData.append('ajax_action', action);
    formData.append('csrf_token', CSRF_TOKEN);
    return fetch('', { method: 'POST', body: formData }).then(r => r.json());
}

function loadPendingOrders() {
    fetchWithCsrf('fetch_pending_orders').then(d => {
        if(d.status !== 'success') return;
        const rows = (d.data || []).map(o => {
            let subTime = o.created_at ? o.created_at : '-';
            let act = `<button class="btn" style="padding:3px 8px; font-size:9px;" onclick="openReviewModal(${o.id})">Review & Edit</button>`;
            return `<tr><td>${o.order_no}</td><td>${subTime}</td><td>${o.empname} (${o.empcode})</td><td>${o.party_name}</td><td>${o.total_qty}</td><td>₹${Number(o.grand_total).toLocaleString('en-IN')}</td><td>${o.status}</td><td>${act}</td></tr>`;
        }).join('') || '<tr><td colspan="8" style="padding:25px; text-align:center; color:#64748b;">No pending orders found for review.</td></tr>';
        
        document.getElementById('approvalListBody').innerHTML = rows;
    });
}

function openReviewModal(id) {
    currentProcessingId = id;
    document.getElementById('managerRemarks').value = '';
    
    const fd = new FormData(); 
    fd.append('id', id);
    
    fetchWithCsrf('load_order_details', fd).then(d => {
        if(d.status !== 'success') return alert("Error loading order details.");
        const o = d.order;
        globalProductsList = d.products || [];
        const items = d.items || [];
        
        document.getElementById('modalOrderTitle').innerText = "Review & Edit Order: " + o.order_no;
        document.getElementById('orderHeaderMeta').innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; background:#f8fafc; padding:6px; border:1px solid #cbd5e1; font-size:10px;">
                <div><span>Submission:</span> <strong>${o.created_at || o.order_date}</strong></div>
                <div><span>Employee:</span> <strong>${o.empname} (${o.empcode})</strong></div>
                <div><span>HQ / State:</span> <strong>${o.hq || '-'} / ${o.state || '-'}</strong></div>
                <div><span>Transporter:</span> <strong>${o.transporter_name || '-'}</strong></div>
                <div style="grid-column: span 2;"><span>Party:</span> <strong>${o.party_name} (${o.party_address || ''})</strong></div>
            </div>
        `;

        const tbody = document.getElementById('managerItemsBody');
        tbody.innerHTML = '';
        items.forEach(it => addManagerRow(it));
        recalculateModalTotals();

        document.getElementById('approvalModal').style.display = 'flex';
    });
}

function productDropdownOptions(selectedName) {
    let opts = '<option value="">-- Select Product --</option>';
    globalProductsList.forEach(p => {
        let name = p.product_name || p.productname || p.name || '';
        let mrp = p.mrp || p.rate || p.price || 0;
        let gst = p.gst || p.gst_pct || 12;
        let sel = (name === selectedName) ? 'selected' : '';
        opts += `<option value="${name}" data-mrp="${mrp}" data-gst="${gst}" ${sel}>${name}</option>`;
    });
    return opts;
}

function addManagerRow(data={}) {
    const tbody = document.getElementById('managerItemsBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="padding:2px;"><select class="product-select" onchange="onManagerProdChange(this)">${productDropdownOptions(data.product_name||'')}</select></td>
        <td style="padding:2px;"><input type="number" class="mrp-input" value="${data.mrp||''}" readonly style="width:45px;" tabindex="-1"></td>
        <td style="padding:2px;">
          <select class="rate-type" onchange="recalculateModalRow(this)" style="width:90px;">
            <option value="PTR + Bonus" ${data.rate_type==='PTR + Bonus'?'selected':''}>PTR + Bonus</option>
            <option value="PTS + Bonus" ${data.rate_type==='PTS + Bonus'?'selected':''}>PTS + Bonus</option>
            <option value="PTR + Bonus + Disc" ${data.rate_type==='PTR + Bonus + Disc'?'selected':''}>PTR+Bns+Disc</option>
            <option value="PTS + Bonus + Disc" ${data.rate_type==='PTS + Bonus + Disc'?'selected':''}>PTS+Bns+Disc</option>
            <option value="Flat Rate" ${data.rate_type==='Flat Rate'?'selected':''}>Flat Rate</option>
            <option value="% Discount on MRP" ${data.rate_type==='% Discount on MRP'?'selected':''}>% Disc on MRP</option>
          </select>
        </td>
        <td style="padding:2px;"><select class="bonus-scheme" onchange="recalculateModalRow(this)" style="width:55px;">${schemeOptionsHTML}</select></td>
        <td style="padding:2px;"><input type="number" min="0" class="actual-qty" value="${data.actual_qty||''}" oninput="recalculateModalRow(this)" style="width:45px; background:#e0f2fe; border:1px solid #38bdf8;"></td>
        <td style="padding:2px;"><input type="number" min="0" class="bonus-qty" value="${data.bonus_qty||''}" readonly style="width:40px;" tabindex="-1"></td>
        <td style="padding:2px;"><input type="number" class="gst-rate" value="${data.gst_pct||12}" readonly style="width:35px;" tabindex="-1"></td>
        <td style="padding:2px;"><input type="number" class="total-value" value="${data.taxable||''}" readonly style="width:60px; font-weight:bold;" tabindex="-1"></td>
        <td style="padding:2px;"><button type="button" class="btn" style="padding:0 4px; background:#ef4444; border-color:#ef4444;" onclick="this.closest('tr').remove(); recalculateModalTotals();">×</button></td>
    `;
    if(data.scheme) tr.querySelector('.bonus-scheme').value = data.scheme;
    tbody.appendChild(tr);
}

function onManagerProdChange(sel) {
    const tr = sel.closest('tr');
    if(sel.value) {
        const opt = sel.options[sel.selectedIndex];
        tr.querySelector('.mrp-input').value = opt.getAttribute('data-mrp');
        tr.querySelector('.gst-rate').value = opt.getAttribute('data-gst') || 12;
    } else {
        tr.querySelector('.mrp-input').value = "";
    }
    recalculateModalRow(sel);
}

function recalculateModalRow(el) {
    const tr = el.closest('tr');
    const mrp = parseFloat(tr.querySelector('.mrp-input').value) || 0;
    const rt = tr.querySelector('.rate-type').value;
    const sch = tr.querySelector('.bonus-scheme').value;
    const actQty = Math.max(0, parseFloat(tr.querySelector('.actual-qty').value) || 0);
    const gst = parseFloat(tr.querySelector('.gst-rate').value) || 0;

    let bns = 0;
    if(sch !== "0" && actQty > 0) {
        const p = sch.split('_');
        if(actQty >= parseInt(p[0])) bns = Math.floor(actQty / parseInt(p[0])) * parseInt(p[1]);
    }
    tr.querySelector('.bonus-qty').value = bns > 0 ? bns : "";
    const totQty = actQty + bns;

    tr.dataset.rActualValue = 0; tr.dataset.rGst = 0;

    if(mrp > 0 && actQty > 0) {
        const ptr = mrp * 0.7651; const pts = ptr * 0.8299;
        let base = 0;
        if(rt.includes('PTR')) base = ptr;
        else if(rt.includes('PTS')) base = pts;
        else if(rt === '% Discount on MRP') base = mrp;
        else if(rt === 'Flat Rate') base = mrp * 0.75; 

        let eff = base;
        if(totQty > 0) eff = (base * actQty) / totQty;

        const tax = Math.round((actQty * eff) * 100) / 100;
        tr.querySelector('.total-value').value = tax.toFixed(2);
        tr.dataset.rActualValue = tax;
        tr.dataset.rGst = Math.round((tax * (gst/100)) * 100) / 100;
        tr.dataset.finalRate = eff.toFixed(2);
    } else {
        tr.querySelector('.total-value').value = "";
        tr.dataset.rActualValue = 0;
        tr.dataset.rGst = 0;
        tr.dataset.finalRate = 0;
    }
    recalculateModalTotals();
}

function recalculateModalTotals() {
    let grandTot = 0;
    document.querySelectorAll('#managerItemsBody tr').forEach(tr => {
        let tax = parseFloat(tr.dataset.rActualValue) || 0;
        let gst = parseFloat(tr.dataset.rGst) || 0;
        grandTot += (tax + gst);
    });
    document.getElementById('modalGrandTotal').innerText = '₹' + grandTot.toFixed(2);
}

function closeApprovalModal() {
    currentProcessingId = null;
    document.getElementById('approvalModal').style.display = 'none';
}

function processApproval(newStatus) {
    if (!currentProcessingId) return;
    
    let items = [];
    if (newStatus === 'Approved') {
        document.querySelectorAll('#managerItemsBody tr').forEach(tr => {
            const prod = tr.querySelector('.product-select').value;
            const act = parseFloat(tr.querySelector('.actual-qty').value) || 0;
            if (prod && act > 0) {
                items.push({
                    product_name: prod,
                    mrp: tr.querySelector('.mrp-input').value,
                    rate_type: tr.querySelector('.rate-type').value,
                    scheme: tr.querySelector('.bonus-scheme').value,
                    actual_qty: act,
                    bonus_qty: tr.querySelector('.bonus-qty').value || 0,
                    gst_pct: tr.querySelector('.gst-rate').value,
                    disc_pct: 0,
                    req_rate: 0,
                    final_rate: tr.dataset.finalRate || 0,
                    taxable: tr.querySelector('.total-value').value
                });
            }
        });

        if (items.length === 0) {
            return alert("Error: An approved order must contain at least one valid product line with actual quantity.");
        }
    }

    if (newStatus === 'Rejected' && !document.getElementById('managerRemarks').value.trim()) {
        if (!confirm("Are you sure you want to reject this order without entering remarks?")) return;
    }
    
    const remarks = document.getElementById('managerRemarks').value;
    const fd = new FormData();
    fd.append('id', currentProcessingId);
    fd.append('new_status', newStatus);
    fd.append('remarks', remarks);
    fd.append('items_json', JSON.stringify(items));
    
    fetchWithCsrf('update_and_approve_order', fd).then(d => {
        alert(d.message);
        if (d.status === 'success') {
            closeApprovalModal();
            loadPendingOrders();
        }
    });
}

window.onload = loadPendingOrders;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>