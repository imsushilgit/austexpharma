<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin'];
if (!$isAdmin) { header('Location: ../dashboard/dashboard.php?access=denied'); exit; }

$pdo->exec("CREATE TABLE IF NOT EXISTS shifts (
 id INT AUTO_INCREMENT PRIMARY KEY, shift_code VARCHAR(20) NOT NULL UNIQUE, shift_name VARCHAR(100) NOT NULL,
 start_time TIME NOT NULL, end_time TIME NOT NULL, grace_minutes INT DEFAULT 15,
 is_night_shift TINYINT(1) DEFAULT 0, is_active TINYINT(1) DEFAULT 1)");
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_shifts (
 id INT AUTO_INCREMENT PRIMARY KEY, empcode VARCHAR(50) NOT NULL, shift_id INT NOT NULL,
 effective_from DATE NOT NULL, effective_to DATE DEFAULT NULL)");

if (isset($_POST['ajax_action'])) {
 header('Content-Type: application/json');
 $a=$_POST['ajax_action']; $r=['status'=>'error','message'=>'Invalid'];
 try {
  if ($a==='save_shift') {
   $id=intval($_POST['id']??0); $code=strtoupper(trim($_POST['shift_code']??'')); $name=trim($_POST['shift_name']??'');
   $st=$_POST['start_time']??''; $et=$_POST['end_time']??''; $grace=intval($_POST['grace_minutes']??15);
   $night=intval($_POST['is_night_shift']??0); $active=intval($_POST['is_active']??1);
   if(!$code||!$name||!$st||!$et){echo json_encode(['status'=>'error','message'=>'Required fields missing']);exit;}
   if($id>0){ $pdo->prepare("UPDATE shifts SET shift_code=?,shift_name=?,start_time=?,end_time=?,grace_minutes=?,is_night_shift=?,is_active=? WHERE id=?")->execute([$code,$name,$st,$et,$grace,$night,$active,$id]); $r=['status'=>'success','message'=>'Updated']; }
   else { $pdo->prepare("INSERT INTO shifts (shift_code,shift_name,start_time,end_time,grace_minutes,is_night_shift,is_active) VALUES (?,?,?,?,?,?,?)")->execute([$code,$name,$st,$et,$grace,$night,$active]); $r=['status'=>'success','message'=>'Added']; }
  } elseif ($a==='delete_shift') {
   $pdo->prepare("DELETE FROM shifts WHERE id=?")->execute([intval($_POST['id']??0)]); $r=['status'=>'success','message'=>'Deleted'];
  } elseif ($a==='fetch_shifts') {
   $r=['status'=>'success','data'=>$pdo->query("SELECT * FROM shifts ORDER BY shift_code")->fetchAll(PDO::FETCH_ASSOC)];
  } elseif ($a==='assign') {
   $code=trim($_POST['empcode']??''); $sid=intval($_POST['shift_id']??0); $from=$_POST['effective_from']??date('Y-m-d');
   if(!$code||!$sid){echo json_encode(['status'=>'error','message'=>'Employee and shift required']);exit;}
   $pdo->prepare("UPDATE employee_shifts SET effective_to=DATE_SUB(?, INTERVAL 1 DAY) WHERE empcode=? AND effective_to IS NULL")->execute([$from,$code]);
   $pdo->prepare("INSERT INTO employee_shifts (empcode,shift_id,effective_from) VALUES (?,?,?)")->execute([$code,$sid,$from]);
   $r=['status'=>'success','message'=>'Shift assigned'];
  } elseif ($a==='fetch_assignments') {
   $r=['status'=>'success','data'=>$pdo->query("SELECT es.*, s.shift_name, s.shift_code, e.empname FROM employee_shifts es JOIN shifts s ON es.shift_id=s.id LEFT JOIN employees e ON es.empcode=e.empcode ORDER BY es.effective_from DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC)];
  }
 } catch(Exception $e){ $r['message']=$e->getMessage(); }
 echo json_encode($r); exit;
}
$emps=$pdo->query("SELECT empcode,empname FROM employees ORDER BY empname")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle="Shift Management"; include __DIR__ . '/../includes/header.php';
?>

<div class="tabs">
    <button class="tab-btn active" onclick="sw('shifts')">Shifts Setup</button>
    <button class="tab-btn" onclick="sw('assign')">Assign to Employee</button>
</div>
<div id="msgBox" class="msg-box"></div>

<div id="tab-shifts" class="tab-content active">
    <div class="panel-box">
        <div class="panel-title">Add / Edit Shift</div>
        <form id="sf" onsubmit="saveShift(event)">
            <input type="hidden" id="sid">
            <div class="form-grid" style="grid-template-columns:1fr 1.5fr 1fr 1fr .7fr .7fr .7fr auto">
                <div class="form-group"><label>Code</label><input id="scode" required placeholder="GEN"></div>
                <div class="form-group"><label>Name</label><input id="sname" required placeholder="General Shift"></div>
                <div class="form-group"><label>Start</label><input type="time" id="sst" required value="09:30"></div>
                <div class="form-group"><label>End</label><input type="time" id="set" required value="18:00"></div>
                <div class="form-group"><label>Grace (min)</label><input type="number" id="sgrace" value="15"></div>
                <div class="form-group"><label>Night?</label><select id="snight"><option value="0">No</option><option value="1">Yes</option></select></div>
                <div class="form-group"><label>Active</label><select id="sactive"><option value="1">Yes</option><option value="0">No</option></select></div>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </div>

    <div class="panel-box">
        <div class="panel-title">All Shifts</div>
        <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow: hidden;">
            <table class="compact-table">
                <thead><tr><th>Code</th><th>Name</th><th>Start</th><th>End</th><th>Grace</th><th>Night</th><th>Active</th><th>Action</th></tr></thead>
                <tbody id="sbody"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="tab-assign" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">Assign Shift</div>
        <div class="form-grid" style="grid-template-columns:2fr 1.5fr 1fr auto">
            <div class="form-group"><label>Employee</label><select id="aemp"><?php foreach($emps as $e):?><option value="<?=htmlspecialchars($e['empcode'])?>"><?=htmlspecialchars($e['empname'])?> (<?=htmlspecialchars($e['empcode'])?>)</option><?php endforeach;?></select></div>
            <div class="form-group"><label>Shift</label><select id="ashift"></select></div>
            <div class="form-group"><label>From Date</label><input type="date" id="afrom" value="<?=date('Y-m-d')?>"></div>
            <button class="btn btn-primary" onclick="doAssign()">Assign</button>
        </div>
    </div>

    <div class="panel-box">
        <div class="panel-title">Assignments Ledger</div>
        <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow: hidden;">
            <table class="compact-table">
                <thead><tr><th>Employee Name</th><th>Emp Code</th><th>Shift Code</th><th>Effective From</th><th>Effective To</th></tr></thead>
                <tbody id="abody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000)}
function sw(t){document.querySelectorAll('.tab-content').forEach(e=>e.classList.remove('active'));document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));document.getElementById('tab-'+t).classList.add('active');event.target.classList.add('active');if(t==='assign'){loadShiftsSelect();loadAssign()}}
function loadShifts(){const fd=new FormData();fd.append('ajax_action','fetch_shifts');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
document.getElementById('sbody').innerHTML=(d.data||[]).map(s=>`<tr>
    <td style="color:var(--sky); font-family:monospace; font-weight:bold;">${s.shift_code}</td>
    <td><strong style="color:var(--navy-900);">${s.shift_name}</strong></td>
    <td style="font-weight:bold;">${s.start_time}</td><td style="font-weight:bold;">${s.end_time}</td>
    <td>${s.grace_minutes} min</td>
    <td><span style="font-weight:bold; color:${s.is_night_shift==1?'#0ea5e9':'#64748b'};">${s.is_night_shift==1?'Yes':'No'}</span></td>
    <td><span style="font-weight:bold; color:${s.is_active==1?'#059669':'#dc2626'};">${s.is_active==1?'Yes':'No'}</span></td>
    <td><button class="btn btn-secondary btn-sm" onclick='editS(${JSON.stringify(s)})'>Edit</button> <button class="btn btn-danger btn-sm" onclick="delS(${s.id})">Del</button></td>
</tr>`).join('')||'<tr><td colspan="8" style="text-align:center;color:#94a3b8; font-weight:bold; padding:20px;">No shifts configured</td></tr>'})}
function saveShift(e){e.preventDefault();const fd=new FormData();fd.append('ajax_action','save_shift');fd.append('id',document.getElementById('sid').value);
['scode','sname','sst','set','sgrace','snight','sactive'].forEach((id,i)=>{const keys=['shift_code','shift_name','start_time','end_time','grace_minutes','is_night_shift','is_active'];fd.append(keys[i],document.getElementById(id).value)});
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success'){document.getElementById('sf').reset();document.getElementById('sid').value='';loadShifts()}})}
function editS(s){document.getElementById('sid').value=s.id;document.getElementById('scode').value=s.shift_code;document.getElementById('sname').value=s.shift_name;document.getElementById('sst').value=s.start_time?.substring(0,5);document.getElementById('set').value=s.end_time?.substring(0,5);document.getElementById('sgrace').value=s.grace_minutes;document.getElementById('snight').value=s.is_night_shift;document.getElementById('sactive').value=s.is_active; window.scrollTo(0,0);}
function delS(id){if(!confirm('Delete?'))return;const fd=new FormData();fd.append('ajax_action','delete_shift');fd.append('id',id);fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message);loadShifts()})}
function loadShiftsSelect(){const fd=new FormData();fd.append('ajax_action','fetch_shifts');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{const s=document.getElementById('ashift');s.innerHTML=(d.data||[]).filter(x=>x.is_active==1).map(x=>`<option value="${x.id}">${x.shift_name} (${x.shift_code})</option>`).join('')})}
function doAssign(){const fd=new FormData();fd.append('ajax_action','assign');fd.append('empcode',document.getElementById('aemp').value);fd.append('shift_id',document.getElementById('ashift').value);fd.append('effective_from',document.getElementById('afrom').value);
fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadAssign()})}
function loadAssign(){const fd=new FormData();fd.append('ajax_action','fetch_assignments');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
document.getElementById('abody').innerHTML=(d.data||[]).map(r=>`<tr>
    <td><strong style="color:var(--navy-900);">${r.empname||r.empcode}</strong></td>
    <td style="font-family:monospace; font-weight:bold; color:var(--sky);">${r.empcode}</td>
    <td><strong>${r.shift_name}</strong> (${r.shift_code})</td>
    <td>${r.effective_from}</td>
    <td style="font-weight:bold; color:${r.effective_to?'#475569':'#059669'};">${r.effective_to||'Current'}</td>
</tr>`).join('')||'<tr><td colspan="5" style="text-align:center;color:#94a3b8; font-weight:bold; padding:20px;">No assignments found</td></tr>'})}
window.onload=loadShifts;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>