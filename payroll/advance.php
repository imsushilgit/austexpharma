<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$canWrite = user_can_write($ctx);
$isAdmin = $ctx['is_admin']; $isMR = $ctx['is_mr']; $isManager = $ctx['is_manager'];
$teamCodes = $ctx['team_codes']; $empCode = $ctx['empcode']; $empName = $ctx['empname'];

$pdo->exec("CREATE TABLE IF NOT EXISTS salary_advances (
    id INT AUTO_INCREMENT PRIMARY KEY, empcode VARCHAR(50) NOT NULL, empname VARCHAR(150) NOT NULL,
    advance_type ENUM('Advance','Loan') DEFAULT 'Advance', amount DECIMAL(12,2) NOT NULL,
    emi_amount DECIMAL(12,2) DEFAULT 0, total_emis INT DEFAULT 1, emis_paid INT DEFAULT 0,
    balance DECIMAL(12,2) DEFAULT 0, reason TEXT, status ENUM('Pending','Approved','Rejected','Closed') DEFAULT 'Pending',
    applied_on DATE DEFAULT (CURRENT_DATE), approved_by VARCHAR(100) DEFAULT NULL, approved_on DATETIME DEFAULT NULL,
    start_recovery_month INT DEFAULT NULL, start_recovery_year INT DEFAULT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS advance_recoveries (
    id INT AUTO_INCREMENT PRIMARY KEY, advance_id INT NOT NULL, empcode VARCHAR(50) NOT NULL,
    recovery_month INT NOT NULL, recovery_year INT NOT NULL, amount DECIMAL(12,2) NOT NULL,
    salary_id INT DEFAULT NULL, recovered_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action']; $response = ['status'=>'error','message'=>'Invalid'];
    try {
        if ($action === 'apply') {
            $code = trim($_POST['empcode'] ?? $empCode);
            if ($isMR && $code !== $empCode) { echo json_encode(['status'=>'error','message'=>'Self only']); exit; }
            if ($isManager && !in_array($code, $teamCodes) && $code !== $empCode) { echo json_encode(['status'=>'error','message'=>'Team only']); exit; }
            $name = trim($_POST['empname'] ?? $empName);
            $type = $_POST['advance_type'] ?? 'Advance';
            $amount = floatval($_POST['amount'] ?? 0);
            $emis = max(1, intval($_POST['total_emis'] ?? 1));
            $reason = trim($_POST['reason'] ?? '');
            if ($amount <= 0) { echo json_encode(['status'=>'error','message'=>'Amount required']); exit; }
            $emiAmt = round($amount / $emis, 2);
            $pdo->prepare("INSERT INTO salary_advances (empcode,empname,advance_type,amount,emi_amount,total_emis,balance,reason,status) VALUES (?,?,?,?,?,?,?,?, 'Pending')")
                ->execute([$code,$name,$type,$amount,$emiAmt,$emis,$amount,$reason]);
            $response = ['status'=>'success','message'=>'Advance/Loan applied'];
        } elseif ($action === 'update_status') {
            if (!$isAdmin && !$isManager) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $id = intval($_POST['id']??0); $st = $_POST['status']??'';
            if (!in_array($st,['Approved','Rejected','Closed'])) { echo json_encode(['status'=>'error','message'=>'Invalid status']); exit; }
            if ($isManager) {
                $c = $pdo->prepare("SELECT empcode FROM salary_advances WHERE id=?"); $c->execute([$id]);
                $ec = $c->fetchColumn();
                if (!$ec || !in_array($ec,$teamCodes)) { echo json_encode(['status'=>'error','message'=>'Not your team']); exit; }
            }
            $extra = $st==='Approved' ? ', approved_by=?, approved_on=NOW(), start_recovery_month=MONTH(CURDATE()), start_recovery_year=YEAR(CURDATE())' : '';
            $params = $st==='Approved' ? [$st, $ctx['fullname']??$ctx['username'], $id] : [$st, $id];
            $pdo->prepare("UPDATE salary_advances SET status=? $extra WHERE id=?")->execute($params);
            $response = ['status'=>'success','message'=>"Marked $st"];
        } elseif ($action === 'fetch') {
            $sql = "SELECT * FROM salary_advances WHERE 1=1"; $params=[];
            if ($isAdmin) {}
            elseif ($isManager && $teamCodes) { $ph=implode(',',array_fill(0,count($teamCodes),'?')); $sql.=" AND empcode IN ($ph)"; $params=$teamCodes; }
            else { $sql.=" AND empcode=?"; $params[]=$empCode; }
            $sql.=" ORDER BY applied_on DESC LIMIT 200";
            $st=$pdo->prepare($sql); $st->execute($params);
            $response=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($action === 'get_emps') {
            $sql="SELECT empcode,empname FROM employees WHERE 1=1"; $params=[];
            if ($isMR) { $sql.=" AND empcode=?"; $params[]=$empCode; }
            elseif ($isManager && $teamCodes) { $ph=implode(',',array_fill(0,count($teamCodes),'?')); $sql.=" AND empcode IN ($ph)"; $params=$teamCodes; }
            $sql.=" ORDER BY empname"; $st=$pdo->prepare($sql); $st->execute($params);
            $response=['status'=>'success','employees'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        }
    } catch(Exception $e) { $response['message']=$e->getMessage(); }
    echo json_encode($response); exit;
}
$pageTitle="Salary Advance / Loan"; include __DIR__ . '/../includes/header.php';
?>
<div id="msgBox" class="msg-box"></div>
<div class="panel-box">
<div class="panel-title">Apply Advance / Loan</div>
<form id="advForm" onsubmit="applyAdv(event)">
<div class="form-grid" style="grid-template-columns:1.2fr 1fr 1fr 1fr 1fr">
<?php if($isAdmin||$isManager): ?>
<div class="form-group"><label>Employee</label><select name="empcode" id="advEmp" required></select></div>
<?php else: ?><input type="hidden" name="empcode" value="<?=htmlspecialchars($empCode)?>"><?php endif; ?>
<div class="form-group"><label>Type</label><select name="advance_type"><option>Advance</option><option>Loan</option></select></div>
<div class="form-group"><label>Amount</label><input type="number" name="amount" step="0.01" required min="1"></div>
<div class="form-group"><label>Total EMIs</label><input type="number" name="total_emis" value="1" min="1"></div>
<div class="form-group"><label>Reason</label><input type="text" name="reason" placeholder="Reason"></div>
</div>
<button type="submit" class="btn btn-primary">Submit Application</button>
</form>
</div>
<div class="panel-box">
<div class="panel-title">Advance / Loan Register</div>
<table class="compact-table"><thead><tr>
<th>Emp</th><th>Type</th><th>Amount</th><th>EMI</th><th>Paid/Total</th><th>Balance</th><th>Status</th><th>Applied</th><th>Action</th>
</tr></thead><tbody id="advBody"><tr><td colspan="9" style="text-align:center;color:#94a3b8">Loading...</td></tr></tbody></table>
</div>
<script>
const CAN_APPROVE=<?=($isAdmin||$isManager)?'true':'false'?>;
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3500)}
function loadEmps(){
const fd=new FormData();fd.append('ajax_action','get_emps');
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
const s=document.getElementById('advEmp');if(!s)return;s.innerHTML='';
(d.employees||[]).forEach(e=>{s.innerHTML+=`<option value="${e.empcode}" data-name="${e.empname}">${e.empname} (${e.empcode})</option>`});
});}
function applyAdv(e){e.preventDefault();const fd=new FormData(document.getElementById('advForm'));fd.append('ajax_action','apply');
const sel=document.getElementById('advEmp');if(sel)fd.append('empname',sel.options[sel.selectedIndex]?.dataset.name||'');
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success'){document.getElementById('advForm').reset();loadList()}})}
function loadList(){const fd=new FormData();fd.append('ajax_action','fetch');
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
const tb=document.getElementById('advBody');
if(!d.data?.length){tb.innerHTML='<tr><td colspan="9" style="text-align:center;color:#94a3b8">No records</td></tr>';return}
const bm={Pending:'badge-pending',Approved:'badge-approved',Rejected:'badge-rejected',Closed:'badge-closed'};
tb.innerHTML=d.data.map(r=>{let act='-';
if(CAN_APPROVE&&r.status==='Pending')act=`<button class="btn btn-success btn-sm" onclick="setSt(${r.id},'Approved')">Approve</button> <button class="btn btn-danger btn-sm" onclick="setSt(${r.id},'Rejected')">Reject</button>`;
if(CAN_APPROVE&&r.status==='Approved')act=`<button class="btn btn-secondary btn-sm" onclick="setSt(${r.id},'Closed')">Close</button>`;
return`<tr><td><strong>${r.empname}</strong><br><small>${r.empcode}</small></td><td>${r.advance_type}</td><td>₹${Number(r.amount).toLocaleString('en-IN')}</td><td>₹${Number(r.emi_amount).toLocaleString('en-IN')}</td><td>${r.emis_paid}/${r.total_emis}</td><td>₹${Number(r.balance).toLocaleString('en-IN')}</td><td><span class="badge ${bm[r.status]||''}">${r.status}</span></td><td>${r.applied_on||'-'}</td><td>${act}</td></tr>`}).join('')})}
function setSt(id,st){if(!confirm(st+'?'))return;const fd=new FormData();fd.append('ajax_action','update_status');fd.append('id',id);fd.append('status',st);
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadList()})}
window.onload=()=>{loadEmps();loadList()};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
