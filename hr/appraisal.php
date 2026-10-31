<?php
require_once __DIR__ . '/../includes/core.php';
$ctx=require_page_access($pdo);
$isAdmin=$ctx['is_admin'];$isManager=$ctx['is_manager'];$isMR=$ctx['is_mr'];
$teamCodes=$ctx['team_codes'];$empCode=$ctx['empcode'];
if($isMR){header('Location:../index.php?access=denied');exit;}

$pdo->exec("CREATE TABLE IF NOT EXISTS appraisals (
 id INT AUTO_INCREMENT PRIMARY KEY, empcode VARCHAR(50) NOT NULL, empname VARCHAR(150) NOT NULL,
 appraisal_year INT NOT NULL, current_salary DECIMAL(12,2) DEFAULT 0, proposed_salary DECIMAL(12,2) DEFAULT 0,
 increment_pct DECIMAL(5,2) DEFAULT 0, rating ENUM('Outstanding','Excellent','Good','Average','Below Average') DEFAULT 'Good',
 remarks TEXT, status ENUM('Draft','Submitted','Approved','Rejected') DEFAULT 'Draft',
 reviewed_by VARCHAR(100) DEFAULT NULL, reviewed_on DATETIME DEFAULT NULL, effective_from DATE DEFAULT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

if(isset($_POST['ajax_action'])){
 header('Content-Type: application/json'); $a=$_POST['ajax_action']; $r=['status'=>'error','message'=>'Invalid'];
 try{
  if($a==='save'){
   $id=intval($_POST['id']??0); $code=trim($_POST['empcode']??''); $name=trim($_POST['empname']??'');
   $year=intval($_POST['appraisal_year']??date('Y')); $cur=floatval($_POST['current_salary']??0);
   $prop=floatval($_POST['proposed_salary']??0); $pct=$cur>0?round(($prop-$cur)/$cur*100,2):0;
   $rating=$_POST['rating']??'Good'; $remarks=trim($_POST['remarks']??''); $status=$_POST['status']??'Draft';
   $eff=$_POST['effective_from']?:null;
   if(!$code){echo json_encode(['status'=>'error','message'=>'Employee required']);exit;}
   if($id>0){
    $pdo->prepare("UPDATE appraisals SET empcode=?,empname=?,appraisal_year=?,current_salary=?,proposed_salary=?,increment_pct=?,rating=?,remarks=?,status=?,effective_from=? WHERE id=?")
     ->execute([$code,$name,$year,$cur,$prop,$pct,$rating,$remarks,$status,$eff,$id]);
   } else {
    $pdo->prepare("INSERT INTO appraisals (empcode,empname,appraisal_year,current_salary,proposed_salary,increment_pct,rating,remarks,status,effective_from) VALUES (?,?,?,?,?,?,?,?,?,?)")
     ->execute([$code,$name,$year,$cur,$prop,$pct,$rating,$remarks,$status,$eff]);
   }
   $r=['status'=>'success','message'=>'Saved'];
  } elseif($a==='update_status'&&$isAdmin){
   $id=intval($_POST['id']??0); $st=$_POST['status']??'';
   $pdo->prepare("UPDATE appraisals SET status=?, reviewed_by=?, reviewed_on=NOW() WHERE id=?")->execute([$st,$ctx['fullname']??$ctx['username'],$id]);
   $r=['status'=>'success','message'=>"Marked $st"];
  } elseif($a==='fetch'){
   $sql="SELECT * FROM appraisals WHERE 1=1"; $p=[];
   if(!$isAdmin&&$isManager&&$teamCodes){$ph=implode(',',array_fill(0,count($teamCodes),'?'));$sql.=" AND empcode IN ($ph)";$p=$teamCodes;}
   $sql.=" ORDER BY appraisal_year DESC, created_at DESC LIMIT 200";
   $st=$pdo->prepare($sql);$st->execute($p); $r=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
  }
 }catch(Exception $e){$r['message']=$e->getMessage();}
 echo json_encode($r);exit;
}
$emps=$pdo->query("SELECT empcode,empname FROM employees ORDER BY empname")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle="Appraisal / Increment"; include __DIR__ . '/../includes/header.php';
?>
<div id="msgBox" class="msg-box"></div>
<div class="panel-box"><div class="panel-title">New Appraisal</div>
<form id="af" onsubmit="saveA(event)"><input type="hidden" id="aid">
<div class="form-grid" style="grid-template-columns:1.5fr .7fr 1fr 1fr 1fr 1fr">
<div class="form-group"><label>Employee</label><select id="aemp" required><?php foreach($emps as $e):?><option value="<?=htmlspecialchars($e['empcode'])?>" data-name="<?=htmlspecialchars($e['empname'])?>"><?=htmlspecialchars($e['empname'])?> (<?=htmlspecialchars($e['empcode'])?>)</option><?php endforeach;?></select></div>
<div class="form-group"><label>Year</label><input type="number" id="ayear" value="<?=date('Y')?>" required></div>
<div class="form-group"><label>Current Salary</label><input type="number" id="acur" step="0.01" value="0"></div>
<div class="form-group"><label>Proposed Salary</label><input type="number" id="aprop" step="0.01" value="0"></div>
<div class="form-group"><label>Rating</label><select id="arate"><option>Outstanding</option><option>Excellent</option><option selected>Good</option><option>Average</option><option>Below Average</option></select></div>
<div class="form-group"><label>Effective From</label><input type="date" id="aeff"></div>
</div>
<div class="form-grid" style="grid-template-columns:2fr 1fr auto;margin-top:8px">
<div class="form-group"><label>Remarks</label><input type="text" id="aremarks"></div>
<div class="form-group"><label>Status</label><select id="astatus"><option>Draft</option><option>Submitted</option></select></div>
<button class="btn btn-primary" type="submit">Save</button>
</div></form></div>
<div class="panel-box"><div class="panel-title">Appraisals</div>
<table class="compact-table"><thead><tr><th>Emp</th><th>Year</th><th>Current</th><th>Proposed</th><th>Inc %</th><th>Rating</th><th>Status</th><th>Action</th></tr></thead>
<tbody id="tbody"></tbody></table></div>
<script>
const IS_ADMIN=<?=$isAdmin?'true':'false'?>;
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000)}
function saveA(e){e.preventDefault();const sel=document.getElementById('aemp');const fd=new FormData();
fd.append('ajax_action','save');fd.append('id',document.getElementById('aid').value);
fd.append('empcode',sel.value);fd.append('empname',sel.options[sel.selectedIndex].dataset.name);
fd.append('appraisal_year',document.getElementById('ayear').value);fd.append('current_salary',document.getElementById('acur').value);
fd.append('proposed_salary',document.getElementById('aprop').value);fd.append('rating',document.getElementById('arate').value);
fd.append('remarks',document.getElementById('aremarks').value);fd.append('status',document.getElementById('astatus').value);
fd.append('effective_from',document.getElementById('aeff').value);
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success'){document.getElementById('af').reset();document.getElementById('aid').value='';load()}})}
function load(){const fd=new FormData();fd.append('ajax_action','fetch');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
const bm={Draft:'badge-draft',Submitted:'badge-submitted',Approved:'badge-approved',Rejected:'badge-rejected'};
document.getElementById('tbody').innerHTML=(d.data||[]).map(r=>{let a=`<button class="btn btn-secondary btn-sm" onclick='editA(${JSON.stringify(r)})'>Edit</button>`;
if(IS_ADMIN&&r.status==='Submitted')a+=` <button class="btn btn-success btn-sm" onclick="st(${r.id},'Approved')">Approve</button> <button class="btn btn-danger btn-sm" onclick="st(${r.id},'Rejected')">Reject</button>`;
return`<tr><td><strong>${r.empname}</strong><br><small>${r.empcode}</small></td><td>${r.appraisal_year}</td><td>₹${Number(r.current_salary).toLocaleString('en-IN')}</td><td>₹${Number(r.proposed_salary).toLocaleString('en-IN')}</td><td>${r.increment_pct}%</td><td>${r.rating}</td><td><span class="badge ${bm[r.status]||''}">${r.status}</span></td><td>${a}</td></tr>`}).join('')||'<tr><td colspan="8" style="text-align:center;color:#94a3b8">None</td></tr>'})}
function editA(r){document.getElementById('aid').value=r.id;document.getElementById('aemp').value=r.empcode;document.getElementById('ayear').value=r.appraisal_year;document.getElementById('acur').value=r.current_salary;document.getElementById('aprop').value=r.proposed_salary;document.getElementById('arate').value=r.rating;document.getElementById('aremarks').value=r.remarks||'';document.getElementById('astatus').value=r.status;document.getElementById('aeff').value=r.effective_from||'';window.scrollTo(0,0)}
function st(id,s){if(!confirm(s+'?'))return;const fd=new FormData();fd.append('ajax_action','update_status');fd.append('id',id);fd.append('status',s);
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success')load()})}
window.onload=load;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
