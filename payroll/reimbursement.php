<?php
require_once __DIR__ . '/../includes/core.php';
$ctx=require_page_access($pdo);
$isAdmin=$ctx['is_admin'];$isMR=$ctx['is_mr'];$isManager=$ctx['is_manager'];
$teamCodes=$ctx['team_codes'];$empCode=$ctx['empcode'];$empName=$ctx['empname'];
$pdo->exec("CREATE TABLE IF NOT EXISTS reimbursement_types (id INT AUTO_INCREMENT PRIMARY KEY, type_code VARCHAR(20) NOT NULL UNIQUE, type_name VARCHAR(100) NOT NULL, max_limit DECIMAL(12,2) DEFAULT 0, is_active TINYINT(1) DEFAULT 1)");
$pdo->exec("CREATE TABLE IF NOT EXISTS reimbursements (id INT AUTO_INCREMENT PRIMARY KEY, empcode VARCHAR(50) NOT NULL, empname VARCHAR(150) NOT NULL, type_code VARCHAR(20) NOT NULL, claim_date DATE NOT NULL, amount DECIMAL(12,2) NOT NULL, description TEXT, bill_file VARCHAR(255) DEFAULT NULL, status ENUM('Pending','Approved','Rejected','Paid') DEFAULT 'Pending', approved_by VARCHAR(100) DEFAULT NULL, approved_on DATETIME DEFAULT NULL, paid_on DATE DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$cnt=$pdo->query("SELECT COUNT(*) FROM reimbursement_types")->fetchColumn();
if($cnt==0)$pdo->exec("INSERT INTO reimbursement_types (type_code,type_name,max_limit) VALUES ('TRAVEL','Travel',5000),('MEDICAL','Medical',2000),('MOBILE','Mobile Bill',500),('OTHER','Other',1000)");

if(isset($_POST['ajax_action'])){
 header('Content-Type: application/json'); $a=$_POST['ajax_action']; $r=['status'=>'error','message'=>'Invalid'];
 try{
  if($a==='claim'){
   $code=trim($_POST['empcode']??$empCode);
   if($isMR&&$code!==$empCode){echo json_encode(['status'=>'error','message'=>'Self only']);exit;}
   $type=trim($_POST['type_code']??''); $amt=floatval($_POST['amount']??0); $date=$_POST['claim_date']??date('Y-m-d'); $desc=trim($_POST['description']??'');
   if(!$type||$amt<=0){echo json_encode(['status'=>'error','message'=>'Type and amount required']);exit;}
   $nm=$pdo->prepare("SELECT empname FROM employees WHERE empcode=?"); $nm->execute([$code]); $name=$nm->fetchColumn()?:$empName;
   $pdo->prepare("INSERT INTO reimbursements (empcode,empname,type_code,claim_date,amount,description) VALUES (?,?,?,?,?,?)")->execute([$code,$name,$type,$date,$amt,$desc]);
   $r=['status'=>'success','message'=>'Claim submitted'];
  } elseif($a==='update_status'){
   if(!$isAdmin&&!$isManager){echo json_encode(['status'=>'error','message'=>'No permission']);exit;}
   $id=intval($_POST['id']??0); $st=$_POST['status']??'';
   if(!in_array($st,['Approved','Rejected','Paid'])){echo json_encode(['status'=>'error','message'=>'Invalid']);exit;}
   $extra=$st==='Approved'?', approved_by=?, approved_on=NOW()':($st==='Paid'?', paid_on=CURDATE()':'');
   $params=$st==='Approved'?[$st,$ctx['fullname']??$ctx['username'],$id]:[$st,$id];
   $pdo->prepare("UPDATE reimbursements SET status=? $extra WHERE id=?")->execute($params);
   $r=['status'=>'success','message'=>"Marked $st"];
  } elseif($a==='fetch'){
   $sql="SELECT * FROM reimbursements WHERE 1=1"; $p=[];
   if($isAdmin){} elseif($isManager&&$teamCodes){$ph=implode(',',array_fill(0,count($teamCodes),'?'));$sql.=" AND empcode IN ($ph)";$p=$teamCodes;}
   else{$sql.=" AND empcode=?";$p[]=$empCode;}
   $sql.=" ORDER BY created_at DESC LIMIT 200"; $st=$pdo->prepare($sql);$st->execute($p);
   $r=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
  } elseif($a==='save_type'&&$isAdmin){
   $pdo->prepare("INSERT INTO reimbursement_types (type_code,type_name,max_limit) VALUES (?,?,?) ON DUPLICATE KEY UPDATE type_name=VALUES(type_name),max_limit=VALUES(max_limit)")
    ->execute([strtoupper(trim($_POST['type_code']??'')),trim($_POST['type_name']??''),floatval($_POST['max_limit']??0)]);
   $r=['status'=>'success','message'=>'Type saved'];
  } elseif($a==='fetch_types'){
   $r=['status'=>'success','data'=>$pdo->query("SELECT * FROM reimbursement_types WHERE is_active=1 ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC)];
  }
 }catch(Exception $e){$r['message']=$e->getMessage();}
 echo json_encode($r);exit;
}
$types=$pdo->query("SELECT * FROM reimbursement_types WHERE is_active=1 ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle="Reimbursement"; include __DIR__ . '/../includes/header.php';
?>
<div id="msgBox" class="msg-box"></div>
<div class="panel-box"><div class="panel-title">Submit Claim</div>
<form id="cf" onsubmit="claim(event)">
<div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 2fr auto">
<div class="form-group"><label>Type</label><select name="type_code" required><?php foreach($types as $t):?><option value="<?=htmlspecialchars($t['type_code'])?>"><?=htmlspecialchars($t['type_name'])?> (Max ₹<?=number_format($t['max_limit'],0)?>)</option><?php endforeach;?></select></div>
<div class="form-group"><label>Date</label><input type="date" name="claim_date" value="<?=date('Y-m-d')?>" required></div>
<div class="form-group"><label>Amount</label><input type="number" name="amount" step="0.01" required min="1"></div>
<div class="form-group"><label>Description</label><input type="text" name="description" placeholder="Details"></div>
<button class="btn btn-primary" type="submit">Submit</button>
</div><input type="hidden" name="empcode" value="<?=htmlspecialchars($empCode)?>">
</form></div>
<div class="panel-box"><div class="panel-title">Claims</div>
<table class="compact-table"><thead><tr><th>Emp</th><th>Type</th><th>Date</th><th>Amount</th><th>Desc</th><th>Status</th><th>Action</th></tr></thead>
<tbody id="tbody"></tbody></table></div>
<script>
const CAN=<?=($isAdmin||$isManager)?'true':'false'?>;
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000)}
function claim(e){e.preventDefault();const fd=new FormData(document.getElementById('cf'));fd.append('ajax_action','claim');
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success'){document.getElementById('cf').reset();load()}})}
function load(){const fd=new FormData();fd.append('ajax_action','fetch');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
const bm={Pending:'badge-pending',Approved:'badge-approved',Rejected:'badge-rejected',Paid:'badge-paid'};
document.getElementById('tbody').innerHTML=(d.data||[]).map(r=>{let a='-';
if(CAN&&r.status==='Pending')a=`<button class="btn btn-success btn-sm" onclick="st(${r.id},'Approved')">Approve</button> <button class="btn btn-danger btn-sm" onclick="st(${r.id},'Rejected')">Reject</button>`;
if(CAN&&r.status==='Approved')a=`<button class="btn btn-primary btn-sm" onclick="st(${r.id},'Paid')">Mark Paid</button>`;
return`<tr><td><strong>${r.empname}</strong><br><small>${r.empcode}</small></td><td>${r.type_code}</td><td>${r.claim_date}</td><td>₹${Number(r.amount).toLocaleString('en-IN')}</td><td>${r.description||'-'}</td><td><span class="badge ${bm[r.status]||''}">${r.status}</span></td><td>${a}</td></tr>`}).join('')||'<tr><td colspan="7" style="text-align:center;color:#94a3b8">No claims</td></tr>'})}
function st(id,s){if(!confirm(s+'?'))return;const fd=new FormData();fd.append('ajax_action','update_status');fd.append('id',id);fd.append('status',s);
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success')load()})}
window.onload=load;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
