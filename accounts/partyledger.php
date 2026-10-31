<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin']; 
$isManager = $ctx['is_manager']; 
$isMR = $ctx['is_mr'];
$teamCodes = $ctx['team_codes']; 
$empCode = $ctx['empcode'];

$pdo->exec("CREATE TABLE IF NOT EXISTS party_ledger (
 id INT AUTO_INCREMENT PRIMARY KEY, party_name VARCHAR(200) NOT NULL, entry_date DATE NOT NULL,
 entry_type ENUM('Invoice','Collection','Credit Note','Debit Note','Opening','Adjustment') NOT NULL,
 ref_no VARCHAR(50) DEFAULT NULL, debit DECIMAL(14,2) DEFAULT 0, credit DECIMAL(14,2) DEFAULT 0,
 balance DECIMAL(14,2) DEFAULT 0, empcode VARCHAR(50) DEFAULT NULL, remarks VARCHAR(255) DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if (isset($_POST['ajax_action'])) {
 header('Content-Type: application/json');
 $a = $_POST['ajax_action']; 
 $r = ['status'=>'error','message'=>'Invalid'];
 try {
  if ($a === 'fetch' || $a === 'summary') {
   $party = trim($_POST['party_name'] ?? ''); 
   $emp = trim($_POST['empcode'] ?? '');
   $from = $_POST['from'] ?? '';
   $to = $_POST['to'] ?? '';

   // Merge party_ledger, invoices, and collections dynamically
   $baseSubquery = "(
       SELECT party_name, entry_date, entry_type, ref_no, debit, credit, empcode, remarks FROM party_ledger
       UNION ALL
       SELECT partyname as party_name, invoice_date as entry_date, 'Invoice' as entry_type, invoice_no as ref_no, total_value as debit, 0 as credit, NULL as empcode, product_name as remarks FROM invoices
       UNION ALL
       SELECT partyname as party_name, collection_date as entry_date, 'Collection' as entry_type, reference_no as ref_no, 0 as debit, amount as credit, empcode, remarks FROM collections
   )";

   $sqlBase = ($a === 'fetch') 
       ? "SELECT * FROM $baseSubquery AS combined_ledger WHERE 1=1"
       : "SELECT party_name, SUM(debit) d, SUM(credit) c, SUM(debit)-SUM(credit) bal FROM $baseSubquery AS combined_ledger WHERE 1=1";
   
   $sp = [];

   // 1. Party Filter & Scope Access
   if ($party !== '') { 
       $sqlBase .= " AND party_name = ?"; 
       $sp[] = $party; 
   } elseif (!is_ops_user($ctx,['ACCOUNTS','FINANCE'])) {
       $sqlBase .= scope_party_in($ctx,'party_name',$sp);
   }

   // 2. Date Filters
   if (!empty($from)) { $sqlBase .= " AND entry_date >= ?"; $sp[] = $from; }
   if (!empty($to)) { $sqlBase .= " AND entry_date <= ?"; $sp[] = $to; }

   // 3. Employee Filter & Team Restraints
   if ($emp !== '') {
       $sqlBase .= " AND empcode = ?";
       $sp[] = $emp;
   } elseif (!$isAdmin && !is_ops_user($ctx, ['ACCOUNTS', 'FINANCE'])) {
       // Avoid strictly hiding invoices missing an empcode if they passed party scope check
       $tCodes = !empty($teamCodes) ? $teamCodes : [];
       if (!in_array($empCode, $tCodes)) { $tCodes[] = $empCode; } 
       $placeholders = implode(',', array_fill(0, count($tCodes), '?'));
       // We only filter by empcode OR allow records with no empcode (like system invoices) to appear for valid parties
       $sqlBase .= " AND (empcode IN ($placeholders) OR empcode IS NULL OR empcode = '')";
       $sp = array_merge($sp, $tCodes);
   }

   if ($a === 'fetch') {
       $sqlBase .= " ORDER BY entry_date ASC";
       $st = $pdo->prepare($sqlBase);
       $st->execute($sp);
       $rows = $st->fetchAll(PDO::FETCH_ASSOC);
       $bal = 0; 
       foreach($rows as &$row){ 
           $bal += floatval($row['debit']) - floatval($row['credit']); 
           $row['balance'] = $bal; 
       }
       $r = ['status'=>'success','data'=>$rows,'closing'=>$bal];
   } else {
       $sqlBase .= " GROUP BY party_name HAVING bal<>0 ORDER BY bal DESC LIMIT 200";
       $st = $pdo->prepare($sqlBase);
       $st->execute($sp);
       $r = ['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
   }
   
  } elseif ($a === 'add' && $isAdmin) {
   $party = trim($_POST['party_name'] ?? ''); 
   $type = $_POST['entry_type'] ?? 'Adjustment';
   $debit = floatval($_POST['debit'] ?? 0); 
   $credit = floatval($_POST['credit'] ?? 0);
   if(!$party || ($debit <= 0 && $credit <= 0)){ echo json_encode(['status'=>'error','message'=>'Party & amount required']); exit; }
   if(!is_ops_user($ctx,['ACCOUNTS','FINANCE']) && !can_access_party($pdo,$ctx,$party)){ echo json_encode(['status'=>'error','message'=>'Access denied']); exit; }
   $pdo->prepare("INSERT INTO party_ledger (party_name,entry_date,entry_type,ref_no,debit,credit,empcode,remarks) VALUES (?,?,?,?,?,?,?,?)")
    ->execute([$party, $_POST['entry_date'] ?: date('Y-m-d'), $type, trim($_POST['ref_no'] ?? ''), $debit, $credit, trim($_POST['empcode'] ?? ''), trim($_POST['remarks'] ?? '')]);
   $r = ['status'=>'success','message'=>'Entry added'];
  } elseif ($a === 'parties') {
   $pp = [];
   $ps = $pdo->prepare("SELECT DISTINCT partyname FROM parties WHERE 1=1" . (is_ops_user($ctx,['ACCOUNTS','FINANCE']) ? '' : scope_party_in($ctx,'partyname',$pp)) . " ORDER BY partyname");
   $ps->execute($pp);
   $rows = $ps->fetchAll(PDO::FETCH_COLUMN);
   $r = ['status'=>'success','parties'=>$rows];
  } elseif ($a === 'employees') {
   $empSql = "SELECT empcode, empname FROM employees WHERE (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00')";
   $empParams = [];
   if (!$isAdmin && !is_ops_user($ctx, ['ACCOUNTS', 'FINANCE'])) {
       $tCodes = !empty($teamCodes) ? $teamCodes : [];
       if (!in_array($empCode, $tCodes)) { $tCodes[] = $empCode; } 
       $placeholders = implode(',', array_fill(0, count($tCodes), '?'));
       $empSql .= " AND empcode IN ($placeholders)";
       $empParams = $tCodes;
   }
   $empSql .= " ORDER BY empname ASC";
   $st = $pdo->prepare($empSql);
   $st->execute($empParams);
   $r = ['status'=>'success', 'employees'=>$st->fetchAll(PDO::FETCH_ASSOC)];
  }
 } catch(Exception $e){ $r['message'] = $e->getMessage(); }
 echo json_encode($r); exit;
}
$pageTitle = "Party Ledger & Outstanding"; 
include __DIR__ . '/../includes/header.php';
?>

<div id="msgBox" class="msg-box"></div>

<!-- UNIFIED GLOBAL FILTERS -->
<div class="panel-box" style="background:#f8fafc; border-color:#3b82f6;">
    <div class="panel-title" style="border-color:#94a3b8; color:#3b82f6;">Global Filters (Applies to both Ledger & Summary)</div>
    <div class="form-grid" style="grid-template-columns: 1fr 1fr 0.8fr 1fr 1fr auto;">
        <div class="form-group">
            <label>Employee</label>
            <select id="glob_empcode"><option value="">-- All Accessible --</option></select>
        </div>
        <div class="form-group">
            <label>Party</label>
            <select id="glob_party"><option value="">-- All Parties --</option></select>
        </div>
        <div class="form-group">
            <label>Select Month</label>
            <input type="month" id="glob_month" onchange="setDateFromMonth()">
        </div>
        <div class="form-group">
            <label>Date From</label>
            <input type="date" id="glob_from">
        </div>
        <div class="form-group">
            <label>Date To</label>
            <input type="date" id="glob_to">
        </div>
        <div style="display: flex; gap: 4px;">
            <button class="btn btn-primary" onclick="loadActiveData()"><i class="fa-solid fa-filter"></i> Apply</button>
            <button class="btn btn-secondary" onclick="clearFilters()"><i class="fa-solid fa-eraser"></i> Clear</button>
        </div>
    </div>
</div>

<div class="tabs">
    <div class="tab-left">
        <button class="tab-btn active" onclick="sw('led')">Party Statement</button>
        <button class="tab-btn" onclick="sw('sum')">Outstanding Summary</button>
    </div>
    <button class="btn btn-secondary" onclick="exportCSV()"><i class="fa-solid fa-download"></i> Export CSV</button>
</div>

<div id="tab-led" class="tab-content active">
    <?php if($isAdmin): ?>
    <div class="panel-box">
        <div class="panel-title">Manual Entry (Opening / Adjustment / Collection)</div>
        <div class="form-grid" style="grid-template-columns:1.2fr 1fr 1.2fr 1fr 1fr 1fr 1.5fr auto">
            <div class="form-group">
                <label>Type</label>
                <select id="etype">
                    <option>Collection</option><option>Opening</option>
                    <option>Adjustment</option><option>Credit Note</option><option>Debit Note</option>
                </select>
            </div>
            <div class="form-group"><label>Date</label><input type="date" id="edate" value="<?=date('Y-m-d')?>"></div>
            <div class="form-group"><label>Employee</label><select id="eemp"><option value="">-- None --</option></select></div>
            <div class="form-group"><label>Ref No</label><input id="eref"></div>
            <div class="form-group"><label>Debit (Dr)</label><input type="number" id="edebit" step="0.01" value="0"></div>
            <div class="form-group"><label>Credit (Cr)</label><input type="number" id="ecredit" step="0.01" value="0"></div>
            <div class="form-group"><label>Remarks</label><input id="erem"></div>
            <button class="btn btn-primary" onclick="addEntry()">Add</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel-box">
        <div class="panel-title" style="margin-bottom:0; border-bottom:none;">
            <span>Ledger Details</span>
            <span id="closeBal" style="color:#059669; font-size:12px; border:1px dotted #059669; padding:2px 8px; border-radius:4px;"></span>
        </div>
        <div class="table-container">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Date</th><th>Type</th><th>Ref</th><th>Debit</th><th>Credit</th><th>Balance</th><th>Remarks</th>
                    </tr>
                </thead>
                <tbody id="lbody"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="tab-sum" class="tab-content">
    <div class="panel-box">
        <div class="panel-title" style="margin-bottom:0; border-bottom:none;">Parties with Outstanding (Debit − Credit)</div>
        <div class="table-container" style="max-height: calc(100vh - 250px);">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Party</th><th>Total Debit</th><th>Total Credit</th><th>Balance</th>
                    </tr>
                </thead>
                <tbody id="sbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
let currentTab = 'led';

function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000)}

function sw(t){
    document.querySelectorAll('.tab-content').forEach(e=>e.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));
    document.getElementById('tab-'+t).classList.add('active');
    event.target.classList.add('active');
    currentTab = t;
    loadActiveData();
}

function setDateFromMonth() {
    const val = document.getElementById('glob_month').value;
    if(val) {
        const [y, m] = val.split('-');
        const firstDay = `${y}-${m}-01`;
        const lastDay = new Date(y, m, 0).toLocaleDateString('en-CA');
        document.getElementById('glob_from').value = firstDay;
        document.getElementById('glob_to').value = lastDay;
    }
}

function clearFilters() {
    document.getElementById('glob_empcode').value = '';
    document.getElementById('glob_party').value = '';
    document.getElementById('glob_month').value = '';
    document.getElementById('glob_from').value = '';
    document.getElementById('glob_to').value = '';
    loadActiveData();
}

function loadEmployees(){
    const fd=new FormData(); fd.append('ajax_action','employees');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        const list = d.employees || [];
        const globOpts = '<option value="">-- All Accessible --</option>' + list.map(e => `<option value="${e.empcode}">${e.empname} (${e.empcode})</option>`).join('');
        const entryOpts = '<option value="">-- None --</option>' + list.map(e => `<option value="${e.empcode}">${e.empname} (${e.empcode})</option>`).join('');
        
        if(document.getElementById('glob_empcode')) document.getElementById('glob_empcode').innerHTML = globOpts;
        if(document.getElementById('eemp')) document.getElementById('eemp').innerHTML = entryOpts;
    });
}

function loadParties(){
    const fd=new FormData();fd.append('ajax_action','parties');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('glob_party').innerHTML = '<option value="">-- All Parties --</option>' + (d.parties||[]).map(p=>`<option>${p}</option>`).join('');
    });
}

function loadActiveData() {
    if (currentTab === 'led') loadLed();
    else loadSum();
}

function loadLed(){
    const party = document.getElementById('glob_party').value;
    if(!party && currentTab === 'led') { 
        document.getElementById('lbody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px;">Please select a Party to view Ledger</td></tr>';
        document.getElementById('closeBal').innerText = '';
        return; 
    }

    const fd=new FormData();
    fd.append('ajax_action','fetch');
    fd.append('party_name', party);
    fd.append('empcode', document.getElementById('glob_empcode').value);
    fd.append('from', document.getElementById('glob_from').value);
    fd.append('to', document.getElementById('glob_to').value);
    
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.status!=='success'){showMsg(d.message,'error');return}
        document.getElementById('closeBal').innerText='Closing: ₹ '+Number(d.closing||0).toLocaleString('en-IN',{minimumFractionDigits:2});
        document.getElementById('lbody').innerHTML=(d.data||[]).map(x=>`<tr>
        <td style="color:#64748b;">${x.entry_date}</td>
        <td style="font-weight:bold;">${x.entry_type}</td>
        <td style="font-family:monospace; color:#475569;">${x.ref_no||'-'}</td>
        <td style="color:#dc2626; font-weight:bold;">${Number(x.debit).toLocaleString('en-IN',{minimumFractionDigits:2})}</td>
        <td style="color:#059669; font-weight:bold;">${Number(x.credit).toLocaleString('en-IN',{minimumFractionDigits:2})}</td>
        <td style="color:#1e2a78; font-weight:bold;">${Number(x.balance).toLocaleString('en-IN',{minimumFractionDigits:2})}</td>
        <td style="color:#475569;">${x.remarks||''}</td></tr>`).join('')||'<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:20px;">No entries found</td></tr>';
    });
}

function loadSum(){
    const fd=new FormData(); 
    fd.append('ajax_action','summary');
    fd.append('party_name', document.getElementById('glob_party').value);
    fd.append('empcode', document.getElementById('glob_empcode').value);
    fd.append('from', document.getElementById('glob_from').value);
    fd.append('to', document.getElementById('glob_to').value);
    
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('sbody').innerHTML=(d.data||[]).map(x=>`<tr>
        <td style="font-weight:bold; color:#1e2a78;">${x.party_name}</td>
        <td style="color:#dc2626; font-weight:bold;">₹${Number(x.d).toLocaleString('en-IN')}</td>
        <td style="color:#059669; font-weight:bold;">₹${Number(x.c).toLocaleString('en-IN')}</td>
        <td style="color:#1e2a78; font-weight:bold;">₹${Number(x.bal).toLocaleString('en-IN')}</td></tr>`).join('')||'<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;">No data found</td></tr>';
    });
}

function addEntry(){
    const party = document.getElementById('glob_party').value;
    if(!party) { showMsg("Please select a Party from global filter to add entry", "error"); return; }
    
    const fd=new FormData();fd.append('ajax_action','add');
    fd.append('party_name', party);
    fd.append('entry_type', document.getElementById('etype').value);
    fd.append('entry_date', document.getElementById('edate').value);
    fd.append('ref_no', document.getElementById('eref').value);
    fd.append('empcode', document.getElementById('eemp') ? document.getElementById('eemp').value : '');
    fd.append('debit', document.getElementById('edebit').value);
    fd.append('credit', document.getElementById('ecredit').value);
    fd.append('remarks', document.getElementById('erem').value);
    
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        showMsg(d.message,d.status==='success'?'success':'error');
        if(d.status==='success' && currentTab==='led') loadLed();
    });
}

function exportCSV() {
    const activeTab = document.querySelector('.tab-content.active');
    if(!activeTab) return;
    const rows = activeTab.querySelectorAll("tr");
    let csv = [];
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(","));
    }
    const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    const link = document.createElement("a");
    link.download = "Ledger_Export_" + new Date().toISOString().slice(0, 10) + ".csv";
    link.href = window.URL.createObjectURL(csvFile);
    link.click();
}

window.onload = function() {
    loadParties();
    loadEmployees();
    loadActiveData();
};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>