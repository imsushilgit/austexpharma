<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin']; $isManager = $ctx['is_manager']; $isMR = $ctx['is_mr'];
$teamCodes = $ctx['team_codes']; $empCode = $ctx['empcode'];
if ($isMR) { header('Location: ../index.php?access=denied'); exit; }

// Auto-create/update table with template_style
$pdo->exec("CREATE TABLE IF NOT EXISTS hr_letters (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    letter_type ENUM('Offer','Appointment','Increment','Promotion','Demotion','Transfer') NOT NULL,
    template_style VARCHAR(50) DEFAULT 'Modern',
    letter_ref VARCHAR(50) DEFAULT NULL, empcode VARCHAR(50) NOT NULL, empname VARCHAR(150) NOT NULL,
    designation VARCHAR(100) DEFAULT NULL, department VARCHAR(100) DEFAULT NULL, hq VARCHAR(100) DEFAULT NULL,
    joining_date DATE DEFAULT NULL, effective_date DATE DEFAULT NULL, 
    current_designation VARCHAR(100) DEFAULT NULL, new_designation VARCHAR(100) DEFAULT NULL, 
    current_hq VARCHAR(100) DEFAULT NULL, new_hq VARCHAR(100) DEFAULT NULL,
    current_salary DECIMAL(12,2) DEFAULT 0, new_salary DECIMAL(12,2) DEFAULT 0, increment_pct DECIMAL(5,2) DEFAULT 0, 
    reporting_to VARCHAR(150) DEFAULT NULL, work_location VARCHAR(150) DEFAULT NULL,
    probation_months INT DEFAULT 6, notice_days INT DEFAULT 30, body_extra TEXT,
    status ENUM('Draft','Issued','Acknowledged','Cancelled') DEFAULT 'Draft',
    prepared_by VARCHAR(100) DEFAULT NULL, issued_on DATE DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Safely add columns if updating from older version
try {
    $pdo->exec("ALTER TABLE hr_letters ADD COLUMN current_hq VARCHAR(100) DEFAULT NULL AFTER new_designation");
    $pdo->exec("ALTER TABLE hr_letters ADD COLUMN new_hq VARCHAR(100) DEFAULT NULL AFTER current_hq");
    $pdo->exec("ALTER TABLE hr_letters MODIFY COLUMN letter_type ENUM('Offer','Appointment','Increment','Promotion','Demotion','Transfer') NOT NULL");
    $pdo->exec("ALTER TABLE hr_letters ADD COLUMN template_style VARCHAR(50) DEFAULT 'Modern' AFTER letter_type");
} catch(Exception $e) {}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status'=>'error','message'=>'Invalid'];
    try {
        if ($action === 'save') {
            $id = intval($_POST['id'] ?? 0);
            $type = $_POST['letter_type'] ?? 'Offer';
            $template = $_POST['template_style'] ?? 'Modern';
            $code = trim($_POST['empcode'] ?? '');
            $name = trim($_POST['empname'] ?? '');
            if (!$code || !$name) { echo json_encode(['status'=>'error','message'=>'Employee required']); exit; }
            $fields = [
                $type, $template, trim($_POST['letter_ref'] ?? ''), $code, $name,
                trim($_POST['designation'] ?? ''), trim($_POST['department'] ?? ''), trim($_POST['hq'] ?? ''),
                $_POST['joining_date'] ?: null, $_POST['effective_date'] ?: null,
                trim($_POST['current_designation'] ?? ''), trim($_POST['new_designation'] ?? ''),
                trim($_POST['current_hq'] ?? ''), trim($_POST['new_hq'] ?? ''),
                floatval($_POST['current_salary'] ?? 0), floatval($_POST['new_salary'] ?? 0),
                floatval($_POST['increment_pct'] ?? 0),
                trim($_POST['reporting_to'] ?? ''), trim($_POST['work_location'] ?? ''),
                intval($_POST['probation_months'] ?? 6), intval($_POST['notice_days'] ?? 30),
                trim($_POST['body_extra'] ?? ''),$_POST['status'] ?? 'Draft',
                $ctx['fullname'] ?? $ctx['username']
            ];
            if ($id > 0) {$pdo->prepare("UPDATE hr_letters SET letter_type=?,template_style=?,letter_ref=?,empcode=?,empname=?,designation=?,department=?,hq=?,
                    joining_date=?,effective_date=?,current_designation=?,new_designation=?,current_hq=?,new_hq=?,current_salary=?,new_salary=?,increment_pct=?,
                    reporting_to=?,work_location=?,probation_months=?,notice_days=?,body_extra=?,status=?,prepared_by=? WHERE id=?")
                    ->execute(array_merge($fields, [$id]));
                $response = ['status'=>'success','message'=>'Letter updated','id'=>$id];
            } else {
                if (empty($_POST['letter_ref'])) $fields[2] = strtoupper(substr($type,0,3)).'-'.date('Y').'-'.rand(1000,9999);
                $pdo->prepare("INSERT INTO hr_letters (letter_type,template_style,letter_ref,empcode,empname,designation,department,hq,
                    joining_date,effective_date,current_designation,new_designation,current_hq,new_hq,current_salary,new_salary,increment_pct,
                    reporting_to,work_location,probation_months,notice_days,body_extra,status,prepared_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($fields);
                $response = ['status'=>'success','message'=>'Letter saved','id'=>$pdo->lastInsertId()];
            }
        } elseif ($action === 'fetch') {$sql = "SELECT * FROM hr_letters WHERE 1=1"; $p = [];
            if ($isManager && !$isAdmin && $teamCodes) {$ph = implode(',', array_fill(0, count($teamCodes), '?'));$sql .= " AND empcode IN ($ph)"; $p =$teamCodes;
            }
            if (!empty($_POST['letter_type'])) {$sql .= " AND letter_type=?"; $p[] = $_POST['letter_type']; }$sql .= " ORDER BY created_at DESC LIMIT 150";
            $st = $pdo->prepare($sql); $st->execute($p);
            $response = ['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($action === 'load') {$st = $pdo->prepare("SELECT * FROM hr_letters WHERE id=?"); $st->execute([intval($_POST['id']??0)]);$row = $st->fetch(PDO::FETCH_ASSOC);$response = $row ? ['status'=>'success','data'=>$row] : ['status'=>'error','message'=>'Not found'];
        } elseif ($action === 'issue' && $isAdmin) {$pdo->prepare("UPDATE hr_letters SET status='Issued', issued_on=CURDATE() WHERE id=?")->execute([intval($_POST['id']??0)]);$response = ['status'=>'success','message'=>'Letter issued'];
        } elseif ($action === 'get_emps') {$sql = "SELECT empcode,empname,designation,department,hq FROM employees WHERE 1=1"; $p=[];
            if ($isManager && !$isAdmin && $teamCodes) {$ph = implode(',', array_fill(0, count($teamCodes), '?'));$sql .= " AND empcode IN ($ph)"; $p =$teamCodes;
            }
            $sql .= " ORDER BY empname";
            $st = $pdo->prepare($sql); $st->execute($p);
            $response = ['status'=>'success','employees'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        }
    } catch (Exception $e) { $response['message'] =$e->getMessage(); }
    echo json_encode($response); exit;
}

if (isset($_GET['print']) && intval($_GET['print']) > 0) {
    $st =$pdo->prepare("SELECT * FROM hr_letters WHERE id=?");
    $st->execute([intval($_GET['print'])]);
    $L =$st->fetch(PDO::FETCH_ASSOC);
    if (!$L) die('Letter not found');$type = $L['letter_type'];$ref = $L['letter_ref'] ?: ('LTR-'.$L['id']);
    $date =$L['issued_on'] ?: date('d/m/Y');
    include __DIR__ . '/hrletters_print.php';
    exit;
}

$pageTitle = "HR Letters & Documentation";
include __DIR__ . '/../includes/header.php';
?>

<div id="msgBox" class="msg-box"></div>
<div class="panel-box">
    <div class="panel-title">1. Master Document Configuration</div>
    <form id="lf" onsubmit="saveL(event)">
        <input type="hidden" id="lid">
        <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr 1fr 1fr">
            <div class="form-group">
                <label>Letter Type</label>
                <select id="ltype" onchange="toggleTypeFields()">
                    <option>Offer</option><option>Appointment</option><option>Increment</option><option>Promotion</option><option>Demotion</option><option>Transfer</option>
                </select>
            </div>
            <div class="form-group"><label>Employee</label><select id="lemp" onchange="fillEmp()" required></select></div>
            <div class="form-group">
                <label>Template Layout</label>
                <select id="ltemplate">
                    <option value="Modern">Modern Corporate</option>
                    <option value="Classic">Classic Formal</option>
                    <option value="Minimal">Compact Minimal</option>
                </select>
            </div>
            <div class="form-group"><label>Ref No (Auto if blank)</label><input id="lref" placeholder="Auto"></div>
            <div class="form-group"><label>Effective Date</label><input type="date" id="leff"></div>
            <div class="form-group">
                <label>Status</label>
                <select id="lstatus"><option>Draft</option><option>Issued</option><option>Acknowledged</option><option>Cancelled</option></select>
            </div>
        </div>
        
        <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr">
            <div class="form-group"><label>Primary Designation</label><input id="ldesig"></div>
            <div class="form-group"><label>Department</label><input id="ldept"></div>
            <div class="form-group"><label>Primary HQ / Base</label><input id="lhq"></div>
        </div>

        <!-- CONDITIONAL BLOCKS -->
        <!-- OFFER / APPOINTMENT -->
        <div id="fields-offer" class="type-fields show">
            <strong style="font-size: 10px; color: var(--navy-900); text-transform: uppercase;">Offer / Appointment Details</strong>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr; margin-top: 5px;">
                <div class="form-group"><label>Joining Date</label><input type="date" id="ljoin"></div>
                <div class="form-group"><label>Reporting Manager</label><input id="lreport"></div>
                <div class="form-group"><label>Probation (months)</label><input type="number" id="lprob" value="6"></div>
                <div class="form-group"><label>Notice (days)</label><input type="number" id="lnotice" value="30"></div>
            </div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 2fr">
                <div class="form-group"><label>Offered Salary</label><input type="number" id="lnewsal" step="0.01" value="0"></div>
                <div class="form-group"><label>Posting Location</label><input id="lloc"></div>
            </div>
        </div>

        <!-- INCREMENT / PROMOTION / DEMOTION -->
        <div id="fields-inc" class="type-fields">
            <strong style="font-size: 10px; color: var(--navy-900); text-transform: uppercase;">Compensation / Role Adjustment</strong>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr; margin-top: 5px;">
                <div class="form-group"><label>Current Designation</label><input id="lcurdes"></div>
                <div class="form-group"><label>New Designation</label><input id="lnewdes"></div>
                <div class="form-group"><label>Current Salary</label><input type="number" id="lcursal" step="0.01" value="0" onchange="calcPct()"></div>
                <div class="form-group"><label>Revised Salary</label><input type="number" id="lnewsal2" step="0.01" value="0" onchange="calcPct()"></div>
            </div>
            <div class="form-group" style="max-width:120px"><label>Increment/Change %</label><input id="lpct" readonly value="0" style="background:#e2e8f0; font-weight:bold;"></div>
        </div>

        <!-- TRANSFER -->
        <div id="fields-transfer" class="type-fields">
            <strong style="font-size: 10px; color: var(--navy-900); text-transform: uppercase;">Relocation / Transfer Details</strong>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr; margin-top: 5px;">
                <div class="form-group"><label>Current HQ</label><input id="lcurhq"></div>
                <div class="form-group"><label>New HQ</label><input id="lnewhq"></div>
                <div class="form-group"><label>Reporting To (New)</label><input id="lreport2"></div>
                <div class="form-group"><label>Notice / Transit Days</label><input type="number" id="lnotice2" value="7"></div>
            </div>
        </div>

        <div class="form-group" style="margin-top:8px">
            <label>Additional Custom Clauses / Notes (Will appear at bottom of letter)</label>
            <textarea id="lextra" placeholder="Add specific confidentiality, relocation, or performance clauses here..."></textarea>
        </div>
        <div style="margin-top:10px; display:flex; gap:8px">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Letter</button>
            <button type="button" class="btn btn-secondary" onclick="resetL()"><i class="fa-solid fa-eraser"></i> Clear</button>
        </div>
    </form>
</div>

<div class="panel-box">
    <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
        <span>2. Document Registry</span>
        <select id="filterType" onchange="loadList()" style="height:26px; font-size:10px; border-radius:4px; border:1.5px dotted #cbd5e1; outline:none;">
            <option value="">All Letter Types</option>
            <option>Offer</option><option>Appointment</option><option>Increment</option><option>Promotion</option><option>Demotion</option><option>Transfer</option>
        </select>
    </div>
    <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow: hidden;">
        <table class="compact-table">
            <thead><tr><th>Ref Number</th><th>Type</th><th>Employee</th><th>Designation</th><th>Template</th><th>Status</th><th>Action</th></tr></thead>
            <tbody id="lbody"><tr><td colspan="7" style="text-align:center;color:#94a3b8; font-weight:600; padding: 20px;">Loading...</td></tr></tbody>
        </table>
    </div>
</div>

<script>
const IS_ADMIN=<?=$isAdmin?'true':'false'?>;
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3500)}
function toggleTypeFields(){
    const t=document.getElementById('ltype').value;
    document.getElementById('fields-offer').classList.toggle('show', t==='Offer'||t==='Appointment');
    document.getElementById('fields-inc').classList.toggle('show', ['Increment','Promotion','Demotion'].includes(t));
    document.getElementById('fields-transfer').classList.toggle('show', t==='Transfer');
}
function calcPct(){
    const c=parseFloat(document.getElementById('lcursal').value)||0;
    const n=parseFloat(document.getElementById('lnewsal2').value)||0;
    document.getElementById('lpct').value=c>0?(((n-c)/c)*100).toFixed(2):0;
}
function loadEmps(){
    const fd=new FormData();fd.append('ajax_action','get_emps');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        const s=document.getElementById('lemp');s.innerHTML='<option value="">-- Select Employee --</option>';
        (d.employees||[]).forEach(e=>{s.innerHTML+=`<option value="${e.empcode}" data-name="${e.empname||''}" data-desig="${e.designation||''}" data-dept="${e.department||''}" data-hq="${e.hq||''}">${e.empname} (${e.empcode})</option>`});
    });
}
function fillEmp(){
    const o=document.getElementById('lemp').selectedOptions[0]; if(!o||!o.value)return;
    document.getElementById('ldesig').value=o.dataset.desig||'';
    document.getElementById('ldept').value=o.dataset.dept||'';
    document.getElementById('lhq').value=o.dataset.hq||'';
    document.getElementById('lcurdes').value=o.dataset.desig||'';
    document.getElementById('lcurhq').value=o.dataset.hq||'';
}
function saveL(e){
    e.preventDefault();
    const o=document.getElementById('lemp').selectedOptions[0];
    const t=document.getElementById('ltype').value;
    const fd=new FormData();
    fd.append('ajax_action','save');
    fd.append('id',document.getElementById('lid').value);
    fd.append('letter_type',t);
    fd.append('template_style',document.getElementById('ltemplate').value);
    fd.append('letter_ref',document.getElementById('lref').value);
    fd.append('empcode',document.getElementById('lemp').value);
    fd.append('empname',o?o.dataset.name:'');
    fd.append('designation',document.getElementById('ldesig').value);
    fd.append('department',document.getElementById('ldept').value);
    fd.append('hq',document.getElementById('lhq').value);
    fd.append('joining_date',document.getElementById('ljoin').value);
    fd.append('effective_date',document.getElementById('leff').value);
    fd.append('current_designation',document.getElementById('lcurdes').value);
    fd.append('new_designation',document.getElementById('lnewdes').value);
    fd.append('current_hq',document.getElementById('lcurhq').value);
    fd.append('new_hq',document.getElementById('lnewhq').value);
    fd.append('current_salary',document.getElementById('lcursal').value);
    fd.append('new_salary',(t==='Offer'||t==='Appointment')?document.getElementById('lnewsal').value:document.getElementById('lnewsal2').value);
    fd.append('increment_pct',document.getElementById('lpct').value);
    fd.append('reporting_to',(t==='Transfer')?document.getElementById('lreport2').value:document.getElementById('lreport').value);
    fd.append('work_location',document.getElementById('lloc').value);
    fd.append('probation_months',document.getElementById('lprob').value);
    fd.append('notice_days',(t==='Transfer')?document.getElementById('lnotice2').value:document.getElementById('lnotice').value);
    fd.append('body_extra',document.getElementById('lextra').value);
    fd.append('status',document.getElementById('lstatus').value);
    
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        showMsg(d.message,d.status==='success'?'success':'error');
        if(d.status==='success'){document.getElementById('lid').value=d.id||'';loadList()}
    });
}
function loadList(){
    const fd=new FormData();fd.append('ajax_action','fetch');fd.append('letter_type',document.getElementById('filterType').value);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        const bm={Draft:'badge-draft',Issued:'badge-issued',Acknowledged:'badge-ack',Cancelled:'badge-cancel'};
        document.getElementById('lbody').innerHTML=(d.data||[]).map(r=>{
            let a=`<button class="btn btn-secondary btn-sm" onclick="editL(${r.id})">Edit</button>
                   <a class="btn btn-primary btn-sm" href="hrletters.php?print=${r.id}" target="_blank">Print</a>`;
            if(IS_ADMIN&&r.status==='Draft')a+=` <button class="btn btn-success btn-sm" onclick="issueL(${r.id})">Issue</button>`;
            return`<tr>
                <td style="color:var(--sky); font-weight:bold;">${r.letter_ref||r.id}</td>
                <td><strong style="color:var(--navy-900);">${r.letter_type}</strong></td>
                <td><strong>${r.empname}</strong><br><small style="font-family:monospace; color:#64748b;">${r.empcode}</small></td>
                <td>${r.new_designation||r.designation||'-'}</td>
                <td>${r.template_style||'Modern'}</td>
                <td><span class="badge ${bm[r.status]||''}">${r.status}</span></td>
                <td><div style="display:flex; gap:4px;">${a}</div></td>
            </tr>`;
        }).join('')||'<tr><td colspan="7" style="text-align:center;color:#94a3b8; font-weight:600; padding:20px;">No documents found</td></tr>';
    });
}
function editL(id){
    const fd=new FormData();fd.append('ajax_action','load');fd.append('id',id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.status!=='success')return; const L=d.data;
        document.getElementById('lid').value=L.id;
        document.getElementById('ltype').value=L.letter_type; toggleTypeFields();
        document.getElementById('ltemplate').value=L.template_style||'Modern';
        document.getElementById('lemp').value=L.empcode;
        document.getElementById('lref').value=L.letter_ref||'';
        document.getElementById('leff').value=L.effective_date||'';
        document.getElementById('lstatus').value=L.status;
        document.getElementById('ldesig').value=L.designation||'';
        document.getElementById('ldept').value=L.department||'';
        document.getElementById('lhq').value=L.hq||'';
        document.getElementById('lloc').value=L.work_location||'';
        document.getElementById('ljoin').value=L.joining_date||'';
        
        if (L.letter_type === 'Transfer') {
            document.getElementById('lreport2').value=L.reporting_to||'';
            document.getElementById('lnotice2').value=L.notice_days||7;
        } else {
            document.getElementById('lreport').value=L.reporting_to||'';
            document.getElementById('lnotice').value=L.notice_days||30;
        }
        
        document.getElementById('lprob').value=L.probation_months||6;
        document.getElementById('lnewsal').value=L.new_salary||0;
        document.getElementById('lcurdes').value=L.current_designation||'';
        document.getElementById('lnewdes').value=L.new_designation||'';
        document.getElementById('lcurhq').value=L.current_hq||'';
        document.getElementById('lnewhq').value=L.new_hq||'';
        document.getElementById('lcursal').value=L.current_salary||0;
        document.getElementById('lnewsal2').value=L.new_salary||0;
        document.getElementById('lpct').value=L.increment_pct||0;
        document.getElementById('lextra').value=L.body_extra||'';
        window.scrollTo(0,0);
    });
}
function issueL(id){if(!confirm('Mark as Issued?'))return;const fd=new FormData();fd.append('ajax_action','issue');fd.append('id',id);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message);loadList()})}
function resetL(){document.getElementById('lf').reset();document.getElementById('lid').value='';toggleTypeFields()}
window.onload=()=>{loadEmps();loadList();toggleTypeFields()};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>