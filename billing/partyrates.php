<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin'];
$isManager = $ctx['is_manager'];
$isBilling = $isAdmin || str_contains(strtoupper($ctx['designation'] ?? ''), 'BILL')
    || str_contains(strtoupper($ctx['designation'] ?? ''), 'ACCOUNT')
    || str_contains(strtoupper($_SESSION['user_role'] ?? ''), 'BILL');

$canEdit = $isAdmin || $isBilling;

$pdo->exec("CREATE TABLE IF NOT EXISTS party_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    party_name VARCHAR(200) NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    rate_type VARCHAR(100) DEFAULT 'PTR',
    rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    disc_pct DECIMAL(5,2) DEFAULT 0,
    scheme VARCHAR(50) DEFAULT NULL,
    gst_pct DECIMAL(5,2) DEFAULT 12,
    effective_from DATE DEFAULT NULL,
    effective_to DATE DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    updated_by VARCHAR(100) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_party_product (party_name, product_name)
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid'];
    try {
        if ($action === 'save_rate') {
            if (!$canEdit) { echo json_encode(['status'=>'error','message'=>'Only Admin/Billing can edit rates']); exit; }
            $id = intval($_POST['id'] ?? 0);
            $party = trim($_POST['party_name'] ?? '');
            $product = trim($_POST['product_name'] ?? '');
            $rate = floatval($_POST['rate'] ?? 0);
            if (!$party || !$product) { echo json_encode(['status'=>'error','message'=>'Party and Product required']); exit; }
            if ($rate < 0) { echo json_encode(['status'=>'error','message'=>'Invalid rate']); exit; }
            $data = [
                $party, $product, trim($_POST['rate_type'] ?? 'PTR'), $rate,
                floatval($_POST['disc_pct'] ?? 0), trim($_POST['scheme'] ?? ''),
                floatval($_POST['gst_pct'] ?? 12),
                $_POST['effective_from'] ?: null, $_POST['effective_to'] ?: null,
                intval($_POST['is_active'] ?? 1),
                $ctx['fullname'] ?? $ctx['username']
            ];
            if ($id > 0) {
                $pdo->prepare("UPDATE party_rates SET party_name=?,product_name=?,rate_type=?,rate=?,disc_pct=?,scheme=?,gst_pct=?,effective_from=?,effective_to=?,is_active=?,updated_by=? WHERE id=?")
                    ->execute(array_merge($data, [$id]));
                $response = ['status'=>'success','message'=>'Rate updated'];
            } else {
                $pdo->prepare("INSERT INTO party_rates (party_name,product_name,rate_type,rate,disc_pct,scheme,gst_pct,effective_from,effective_to,is_active,updated_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE rate_type=VALUES(rate_type), rate=VALUES(rate), disc_pct=VALUES(disc_pct),
                    scheme=VALUES(scheme), gst_pct=VALUES(gst_pct), effective_from=VALUES(effective_from),
                    effective_to=VALUES(effective_to), is_active=VALUES(is_active), updated_by=VALUES(updated_by)")
                    ->execute($data);
                $response = ['status'=>'success','message'=>'Rate saved'];
            }
        } elseif ($action === 'delete_rate') {
            if (!$canEdit) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $pdo->prepare("DELETE FROM party_rates WHERE id=?")->execute([intval($_POST['id']??0)]);
            $response = ['status'=>'success','message'=>'Deleted'];
        } elseif ($action === 'fetch_rates') {
            $party = trim($_POST['party_name'] ?? '');
            $sql = "SELECT * FROM party_rates WHERE 1=1"; $p = [];
            if ($party !== '') { $sql .= " AND party_name=?"; $p[] = $party; }
            if (isset($_POST['active_only']) && $_POST['active_only']) $sql .= " AND is_active=1";
            $sql .= " ORDER BY party_name, product_name";
            $st = $pdo->prepare($sql); $st->execute($p);
            $response = ['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($action === 'get_parties') {
            $rows = $pdo->query("SELECT partyname FROM parties ORDER BY partyname")->fetchAll(PDO::FETCH_COLUMN);
            $response = ['status'=>'success','parties'=>$rows];
        } elseif ($action === 'get_products') {
            try {
                $rows = $pdo->query("SELECT product_name, mrp, gst FROM products ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $rows = $pdo->query("SELECT productname AS product_name, mrp, 12 AS gst FROM products ORDER BY productname")->fetchAll(PDO::FETCH_ASSOC);
            }
            $response = ['status'=>'success','products'=>$rows];
        }
    } catch (Exception $e) { $response['message'] = $e->getMessage(); }
    echo json_encode($response); exit;
}

$pageTitle = "Party Rates";
include __DIR__ . '/../includes/header.php';
?>

<div id="msgBox" class="msg-box"></div>

<div class="panel-box">
    <div class="panel-title">Party-wise Product Rates</div>
    <p class="hint">Assigned rate auto-applies on Order → Invoice. Billing Admin can overwrite rate while executing invoice.</p>
    
    <?php if ($canEdit): ?>
    <form id="rf" onsubmit="saveRate(event)">
        <input type="hidden" id="rid">
        <div class="form-grid" style="grid-template-columns:1.4fr 1.4fr 0.8fr 0.8fr 0.7fr 0.7fr 0.7fr auto">
            <div class="form-group"><label>Party</label><select id="rparty" required></select></div>
            <div class="form-group"><label>Product</label><select id="rproduct" required></select></div>
            <div class="form-group"><label>Rate Type</label><input id="rrtype" value="PTR"></div>
            <div class="form-group"><label>Rate (₹)</label><input type="number" id="rrate" step="0.01" required min="0"></div>
            <div class="form-group"><label>Disc %</label><input type="number" id="rdisc" step="0.01" value="0"></div>
            <div class="form-group"><label>Scheme</label><input id="rscheme" placeholder="10+1"></div>
            <div class="form-group"><label>GST %</label><input type="number" id="rgst" step="0.01" value="12"></div>
            <button class="btn btn-primary" type="submit" id="saveBtn">Save Rate</button>
        </div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr 0.8fr auto; margin-top:8px; max-width:500px">
            <div class="form-group"><label>Effective From</label><input type="date" id="rfrom"></div>
            <div class="form-group"><label>Effective To</label><input type="date" id="rto"></div>
            <div class="form-group"><label>Active</label><select id="ractive"><option value="1">Yes</option><option value="0">No</option></select></div>
            <button type="button" class="btn btn-secondary" onclick="resetForm()" style="display:none;" id="cancelBtn">Cancel</button>
        </div>
    </form>
    <?php else: ?>
    <p class="hint" style="color:#dc2626; font-weight:bold;">View only mode. Rate modification is restricted to Admin / Billing roles.</p>
    <?php endif; ?>
</div>

<div class="panel-box">
    <div class="panel-title" style="border-bottom:none; margin-bottom:0;">
        <span>Rate List Matrix</span>
        <select id="filterParty" onchange="loadRates()" style="height:22px; font-size:9px; max-width:220px; border:1px solid #cbd5e1; border-radius:4px; outline:none; font-weight:bold; color:#1e2a78;">
            <option value="">All Parties</option>
        </select>
    </div>
    
    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th>Party</th><th>Product</th><th>Type</th><th>Rate</th><th>Disc%</th><th>Scheme</th><th>GST%</th><th>Valid Term</th><th>Active</th>
                    <?php if ($canEdit): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="rbody"></tbody>
        </table>
    </div>
</div>

<script>
const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;

function showMsg(t,ty='success'){
    const b = document.getElementById('msgBox');
    b.className = 'msg-box msg-'+ty;
    b.innerText = t;
    b.style.display = 'block';
    setTimeout(()=> b.style.display = 'none', 3500);
}

function loadMeta(){
    Promise.all([
        fetch('',{method:'POST',body:(()=>{const f=new FormData();f.append('ajax_action','get_parties');return f;})()}).then(r=>r.json()),
        fetch('',{method:'POST',body:(()=>{const f=new FormData();f.append('ajax_action','get_products');return f;})()}).then(r=>r.json())
    ]).then(([p,pr]) => {
        const ps = document.getElementById('rparty'); 
        const fp = document.getElementById('filterParty');
        let opts = '<option value="">-- Party --</option>';
        (p.parties||[]).forEach(x=>{ opts += `<option value="${x}">${x}</option>`; });
        if(ps) ps.innerHTML = opts;
        
        fp.innerHTML = '<option value="">All Parties</option>' + (p.parties||[]).map(x=>`<option value="${x}">${x}</option>`).join('');
        
        const prod = document.getElementById('rproduct');
        if(prod) prod.innerHTML = '<option value="">-- Product --</option>' + (pr.products||[]).map(x=>`<option value="${x.product_name}">${x.product_name}</option>`).join('');
        
        loadRates();
    });
}

function saveRate(e){
    e.preventDefault();
    const fd = new FormData();
    fd.append('ajax_action','save_rate');
    fd.append('id', document.getElementById('rid').value);
    fd.append('party_name', document.getElementById('rparty').value);
    fd.append('product_name', document.getElementById('rproduct').value);
    fd.append('rate_type', document.getElementById('rrtype').value);
    fd.append('rate', document.getElementById('rrate').value);
    fd.append('disc_pct', document.getElementById('rdisc').value);
    fd.append('scheme', document.getElementById('rscheme').value);
    fd.append('gst_pct', document.getElementById('rgst').value);
    fd.append('effective_from', document.getElementById('rfrom').value);
    fd.append('effective_to', document.getElementById('rto').value);
    fd.append('is_active', document.getElementById('ractive').value);
    
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        showMsg(d.message, d.status==='success' ? 'success' : 'error');
        if(d.status === 'success'){
            resetForm();
            loadRates();
        }
    });
}

function resetForm() {
    document.getElementById('rf').reset();
    document.getElementById('rid').value = '';
    document.getElementById('saveBtn').innerText = 'Save Rate';
    document.getElementById('saveBtn').style.background = '#1e2a78';
    document.getElementById('cancelBtn').style.display = 'none';
}

function loadRates(){
    const fd = new FormData();
    fd.append('ajax_action','fetch_rates');
    fd.append('party_name', document.getElementById('filterParty').value);
    
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('rbody').innerHTML = (d.data||[]).map(r => {
            let act = ''; 
            if(CAN_EDIT) {
                act = `<div style="display:flex;gap:4px;">
                         <button type="button" class="btn btn-secondary" style="padding:0 6px;height:20px;" onclick='editR(${JSON.stringify(r).replace(/'/g, "&#39;")})'>Edit</button> 
                         <button type="button" class="btn btn-danger" style="padding:0 6px;height:20px;" onclick="delR(${r.id})">Del</button>
                       </div>`;
            }
            const valid = (r.effective_from || '—') + ' → ' + (r.effective_to || '∞');
            return `<tr>
                <td style="font-weight:bold; color:#0f172a;">${r.party_name}</td>
                <td style="color:#1e2a78; font-weight:bold;">${r.product_name}</td>
                <td style="color:#64748b;">${r.rate_type||'PTR'}</td>
                <td style="color:#059669; font-weight:bold;">₹${Number(r.rate).toLocaleString('en-IN',{minimumFractionDigits:2})}</td>
                <td>${r.disc_pct}</td>
                <td style="font-family:monospace; color:#ca8a04; font-weight:bold;">${r.scheme||'-'}</td>
                <td>${r.gst_pct}</td>
                <td style="color:#64748b;">${valid}</td>
                <td style="${r.is_active==1 ? 'color:#059669;' : 'color:#dc2626;'} font-weight:bold;">${r.is_active==1 ? 'Yes' : 'No'}</td>
                ${CAN_EDIT ? `<td>${act}</td>` : ''}
            </tr>`;
        }).join('') || '<tr><td colspan="10" style="text-align:center; color:#94a3b8; padding:20px;">No rates configured</td></tr>';
    });
}

function editR(r){
    document.getElementById('rid').value = r.id;
    document.getElementById('rparty').value = r.party_name;
    document.getElementById('rproduct').value = r.product_name;
    document.getElementById('rrtype').value = r.rate_type || 'PTR';
    document.getElementById('rrate').value = r.rate;
    document.getElementById('rdisc').value = r.disc_pct;
    document.getElementById('rscheme').value = r.scheme || '';
    document.getElementById('rgst').value = r.gst_pct;
    document.getElementById('rfrom').value = r.effective_from || '';
    document.getElementById('rto').value = r.effective_to || '';
    document.getElementById('ractive').value = r.is_active;
    
    document.getElementById('saveBtn').innerText = 'Update Rate';
    document.getElementById('saveBtn').style.background = '#059669';
    document.getElementById('cancelBtn').style.display = 'inline-block';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function delR(id){
    if(!confirm('Are you sure you want to delete this rate?')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_rate');
    fd.append('id', id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        showMsg(d.message, d.status==='success' ? 'success' : 'error');
        loadRates();
    });
}

window.onload = loadMeta;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>