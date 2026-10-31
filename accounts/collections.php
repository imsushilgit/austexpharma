<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin']; 
$empCode = $ctx['empcode'];

// Restrict entry to Admin or Accounts/Finance
$canEdit = $isAdmin || is_ops_user($ctx, ['ACCOUNTS', 'FINANCE']);
if (!$canEdit) {
    header("Location: collectionsreport.php");
    exit;
}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $a = $_POST['ajax_action']; 
    
    try {
        if ($a === 'add') {
            $party = trim($_POST['partyname'] ?? '');
            $date = trim($_POST['collection_date'] ?? date('Y-m-d'));
            $amt = floatval($_POST['amount'] ?? 0);
            $mode = trim($_POST['payment_mode'] ?? '');
            $ref = trim($_POST['reference_no'] ?? '');
            $rem = trim($_POST['remarks'] ?? '');
            $targetEmp = trim($_POST['empcode'] ?? '');
            if(empty($targetEmp)) $targetEmp = $empCode;

            if (!$party || $amt <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Party and Valid Amount are required.']); exit;
            }

            $st = $pdo->prepare("INSERT INTO collections (partyname, empcode, collection_date, amount, payment_mode, reference_no, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->execute([$party, $targetEmp, $date, $amt, $mode, $ref, $rem]);
            echo json_encode(['status' => 'success', 'message' => 'Collection recorded successfully!']);
            exit;

        } elseif ($a === 'init_data') {
            $pp = [];
            $ps = $pdo->prepare("SELECT DISTINCT partyname FROM parties ORDER BY partyname");
            $ps->execute();
            $parties = $ps->fetchAll(PDO::FETCH_COLUMN);

            $es = $pdo->prepare("SELECT empcode, empname FROM employees WHERE (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00') ORDER BY empname ASC");
            $es->execute();
            $employees = $es->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'parties' => $parties, 'employees' => $employees]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

$pageTitle = "New Collection Entry"; 
include __DIR__ . '/../includes/header.php';
?>


<div class="entry-wrapper">
    <div id="msgBox" class="msg-box"></div>
    
    <div class="panel-box">
        <div class="panel-header">
            <h2 class="panel-title">Record New Collection</h2>
            <a href="collectionsreport.php" class="btn btn-secondary"><i class="fa-solid fa-table-list"></i> View Reports</a>
        </div>
        
        <div class="form-grid">
            <div class="form-group full-width">
                <label>Party Name <span style="color:#dc2626">*</span></label>
                <select id="a_party"><option value="">-- Loading Parties --</option></select>
            </div>
            
            <div class="form-group">
                <label>Collection Date <span style="color:#dc2626">*</span></label>
                <input type="date" id="a_date" value="<?=date('Y-m-d')?>">
            </div>
            
            <div class="form-group">
                <label>Credit To (MR)</label>
                <select id="a_emp"><option value="">-- Self / Default --</option></select>
            </div>
            
            <div class="form-group">
                <label>Amount (₹) <span style="color:#dc2626">*</span></label>
                <input type="number" id="a_amount" step="0.01" min="0" placeholder="0.00">
            </div>
            
            <div class="form-group">
                <label>Payment Mode</label>
                <select id="a_mode">
                    <option value="Cheque">Cheque</option>
                    <option value="NEFT/RTGS">NEFT/RTGS</option>
                    <option value="UPI">UPI</option>
                    <option value="Cash">Cash</option>
                    <option value="Draft">Demand Draft</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Reference No. / Trx ID</label>
                <input type="text" id="a_ref" placeholder="Enter Ref number">
            </div>
            
            <div class="form-group">
                <label>Remarks</label>
                <input type="text" id="a_rem" placeholder="Optional notes...">
            </div>
        </div>
        
        <div class="action-bar">
            <button class="btn btn-secondary" onclick="window.location.reload()">Reset</button>
            <button class="btn btn-primary" id="saveBtn" onclick="addCollection()"><i class="fa-solid fa-check"></i> Submit Entry</button>
        </div>
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

window.onload = function() {
    const fd = new FormData(); fd.append('ajax_action', 'init_data');
    fetch('', {method: 'POST', body: fd}).then(r=>r.json()).then(d => {
        if(d.status === 'success') {
            document.getElementById('a_party').innerHTML = '<option value="">-- Select Party --</option>' + (d.parties||[]).map(p => `<option value="${p}">${p}</option>`).join('');
            document.getElementById('a_emp').innerHTML = '<option value="">-- Self / Default --</option>' + (d.employees||[]).map(e => `<option value="${e.empcode}">${e.empname} (${e.empcode})</option>`).join('');
        }
    });
};

function addCollection() {
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    const fd = new FormData();
    fd.append('ajax_action', 'add');
    fd.append('partyname', document.getElementById('a_party').value);
    fd.append('collection_date', document.getElementById('a_date').value);
    fd.append('empcode', document.getElementById('a_emp').value);
    fd.append('amount', document.getElementById('a_amount').value);
    fd.append('payment_mode', document.getElementById('a_mode').value);
    fd.append('reference_no', document.getElementById('a_ref').value);
    fd.append('remarks', document.getElementById('a_rem').value);

    fetch('', {method: 'POST', body: fd}).then(r=>r.json()).then(d => {
        if(d.status === 'success') {
            showMsg(d.message, 'success');
            document.getElementById('a_amount').value = '';
            document.getElementById('a_ref').value = '';
            document.getElementById('a_rem').value = '';
        } else {
            showMsg(d.message, 'error');
        }
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Submit Entry';
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>