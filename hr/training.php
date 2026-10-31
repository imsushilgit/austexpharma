<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin']; $isManager = $ctx['is_manager']; $isMR = $ctx['is_mr'];
$teamCodes = $ctx['team_codes']; $empCode = $ctx['empcode']; $empName = $ctx['empname'];

$pdo->exec("CREATE TABLE IF NOT EXISTS trainings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_code VARCHAR(40) DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    training_type ENUM('Induction','Product','Soft Skill','Compliance','Sales','Other') DEFAULT 'Other',
    trainer_name VARCHAR(150) DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    description TEXT,
    status ENUM('Planned','Ongoing','Completed','Cancelled') DEFAULT 'Planned',
    created_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS training_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_id INT NOT NULL,
    empcode VARCHAR(50) NOT NULL,
    empname VARCHAR(150) NOT NULL,
    attendance ENUM('Registered','Present','Absent','Completed') DEFAULT 'Registered',
    score DECIMAL(5,2) DEFAULT NULL,
    feedback TEXT,
    certificate_issued TINYINT(1) DEFAULT 0,
    UNIQUE KEY uniq_train_emp (training_id, empcode)
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $a = $_POST['ajax_action']; $r = ['status'=>'error','message'=>'Invalid'];
    try {
        if ($a === 'save_training') {
            if (!$isAdmin && !$isManager) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $id = intval($_POST['id']??0);
            $title = trim($_POST['title']??'');
            if (!$title) { echo json_encode(['status'=>'error','message'=>'Title required']); exit; }
            $code = trim($_POST['training_code']??'') ?: ('TRN-'.date('Y').'-'.rand(100,999));
            $data = [$code,$title,$_POST['training_type']??'Other',trim($_POST['trainer_name']??''),
                trim($_POST['location']??''),$_POST['start_date']?:date('Y-m-d'),$_POST['end_date']?:null,
                trim($_POST['description']??''),$_POST['status']??'Planned',$ctx['fullname']??$ctx['username']];
            if ($id>0) {
                $pdo->prepare("UPDATE trainings SET training_code=?,title=?,training_type=?,trainer_name=?,location=?,start_date=?,end_date=?,description=?,status=?,created_by=? WHERE id=?")
                    ->execute(array_merge($data,[$id]));
                $r=['status'=>'success','message'=>'Training updated','id'=>$id];
            } else {
                $pdo->prepare("INSERT INTO trainings (training_code,title,training_type,trainer_name,location,start_date,end_date,description,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute($data);
                $r=['status'=>'success','message'=>'Training created','id'=>$pdo->lastInsertId()];
            }
        } elseif ($a === 'enroll') {
            if (!$isAdmin && !$isManager) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $tid=intval($_POST['training_id']??0); $code=trim($_POST['empcode']??''); $name=trim($_POST['empname']??'');
            if (!$tid||!$code) { echo json_encode(['status'=>'error','message'=>'Training & employee required']); exit; }
            $pdo->prepare("INSERT IGNORE INTO training_attendance (training_id,empcode,empname,attendance) VALUES (?,?,?,'Registered')")
                ->execute([$tid,$code,$name]);
            $r=['status'=>'success','message'=>'Enrolled'];
        } elseif ($a === 'mark_attendance') {
            if (!$isAdmin && !$isManager) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $id=intval($_POST['id']??0); $att=$_POST['attendance']??'Present';
            $score = $_POST['score']!=='' ? floatval($_POST['score']) : null;
            $pdo->prepare("UPDATE training_attendance SET attendance=?, score=? WHERE id=?")->execute([$att,$score,$id]);
            $r=['status'=>'success','message'=>'Updated'];
        } elseif ($a === 'fetch_trainings') {
            $r=['status'=>'success','data'=>$pdo->query("SELECT t.*, (SELECT COUNT(*) FROM training_attendance WHERE training_id=t.id) as enrolled FROM trainings t ORDER BY start_date DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($a === 'fetch_attendance') {
            $tid=intval($_POST['training_id']??0);
            $sql="SELECT * FROM training_attendance WHERE training_id=?"; $p=[$tid];
            if ($isMR) { $sql.=" AND empcode=?"; $p[]=$empCode; }
            elseif ($isManager && !$isAdmin && $teamCodes) {
                $ph=implode(',',array_fill(0,count($teamCodes),'?')); $sql.=" AND empcode IN ($ph)"; $p=array_merge($p,$teamCodes);
            }
            $sql.=" ORDER BY empname";
            $st=$pdo->prepare($sql); $st->execute($p);
            $r=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($a === 'my_trainings') {
            $st=$pdo->prepare("SELECT t.*, ta.attendance, ta.score FROM trainings t JOIN training_attendance ta ON t.id=ta.training_id WHERE ta.empcode=? ORDER BY t.start_date DESC");
            $st->execute([$empCode]);
            $r=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($a === 'get_emps') {
            $sql="SELECT empcode,empname FROM employees WHERE 1=1"; $p=[];
            if ($isManager && !$isAdmin && $teamCodes) { $ph=implode(',',array_fill(0,count($teamCodes),'?')); $sql.=" AND empcode IN ($ph)"; $p=$teamCodes; }
            $sql.=" ORDER BY empname"; $st=$pdo->prepare($sql); $st->execute($p);
            $r=['status'=>'success','employees'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        }
    } catch(Exception $e){ $r['message']=$e->getMessage(); }
    echo json_encode($r); exit;
}
$pageTitle = "Training Management";
include __DIR__ . '/../includes/header.php';
?>
<div class="tabs">
<button class="tab-btn active" onclick="sw('list')">Trainings</button>
<?php if($isAdmin||$isManager): ?><button class="tab-btn" onclick="sw('enroll')">Enroll / Attendance</button><?php endif; ?>
<button class="tab-btn" onclick="sw('my')">My Trainings</button>
</div>
<div id="msgBox" class="msg-box"></div>

<div id="tab-list" class="tab-content active">
<?php if($isAdmin||$isManager): ?>
<div class="panel-box"><div class="panel-title">Create / Edit Training</div>
<form id="tf" onsubmit="saveT(event)"><input type="hidden" id="tid">
<div class="form-grid" style="grid-template-columns:1fr 2fr 1fr 1fr">
<div class="form-group"><label>Code</label><input id="tcode" placeholder="Auto"></div>
<div class="form-group"><label>Title</label><input id="ttitle" required></div>
<div class="form-group"><label>Type</label>
<select id="ttype"><option>Induction</option><option>Product</option><option>Soft Skill</option><option>Compliance</option><option>Sales</option><option>Other</option></select>
</div>
<div class="form-group"><label>Status</label>
<select id="tstatus"><option>Planned</option><option>Ongoing</option><option>Completed</option><option>Cancelled</option></select>
</div>
</div>
<div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr auto;margin-top:8px">
<div class="form-group"><label>Trainer</label><input id="ttrainer"></div>
<div class="form-group"><label>Location</label><input id="tloc"></div>
<div class="form-group"><label>Start Date</label><input type="date" id="tstart" required value="<?=date('Y-m-d')?>"></div>
<div class="form-group"><label>End Date</label><input type="date" id="tend"></div>
<button class="btn btn-primary" type="submit">Save</button>
</div>
<div class="form-group" style="margin-top:8px"><label>Description</label><input id="tdesc" placeholder="Topics / agenda"></div>
</form></div>
<?php endif; ?>
<div class="panel-box"><div class="panel-title">Training Calendar</div>
<table class="compact-table"><thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Trainer</th><th>Dates</th><th>Enrolled</th><th>Status</th><?php if($isAdmin||$isManager):?><th>Action</th><?php endif;?></tr></thead>
<tbody id="tbody"></tbody></table></div>
</div>

<div id="tab-enroll" class="tab-content">
<div class="panel-box"><div class="panel-title">Enroll Employee</div>
<div class="form-grid" style="grid-template-columns:1.5fr 1.5fr auto">
<div class="form-group"><label>Training</label><select id="eTrain" onchange="loadAtt()"></select></div>
<div class="form-group"><label>Employee</label><select id="eEmp"></select></div>
<button class="btn btn-success" onclick="doEnroll()">Enroll</button>
</div></div>
<div class="panel-box"><div class="panel-title">Attendance / Scores</div>
<table class="compact-table"><thead><tr><th>Employee</th><th>Status</th><th>Score</th><th>Update</th></tr></thead>
<tbody id="attBody"></tbody></table></div>
</div>

<div id="tab-my" class="tab-content">
<div class="panel-box"><div class="panel-title">My Training History</div>
<table class="compact-table"><thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Dates</th><th>Attendance</th><th>Score</th></tr></thead>
<tbody id="myBody"></tbody></table></div>
</div>

<script>
const CAN=<?=($isAdmin||$isManager)?'true':'false'?>;
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3500)}
function sw(t){document.querySelectorAll('.tab-content').forEach(e=>e.classList.remove('active'));document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));document.getElementById('tab-'+t).classList.add('active');event.target.classList.add('active');
 if(t==='list')loadT(); if(t==='enroll'){loadTSelect();loadEmps();} if(t==='my')loadMy();}
function saveT(e){e.preventDefault();const fd=new FormData();fd.append('ajax_action','save_training');fd.append('id',document.getElementById('tid').value);
 ['tcode','ttitle','ttype','ttrainer','tloc','tstart','tend','tdesc','tstatus'].forEach((id,i)=>{const k=['training_code','title','training_type','trainer_name','location','start_date','end_date','description','status'][i];fd.append(k,document.getElementById(id).value)});
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success'){document.getElementById('tf').reset();document.getElementById('tid').value='';loadT()}})}
function loadT(){const fd=new FormData();fd.append('ajax_action','fetch_trainings');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  const bm={Planned:'badge-planned',Ongoing:'badge-ongoing',Completed:'badge-done',Cancelled:'badge-cancel'};
  document.getElementById('tbody').innerHTML=(d.data||[]).map(t=>{
   let act=''; if(CAN)act=`<button class="btn btn-secondary btn-sm" onclick='editT(${JSON.stringify(t)})'>Edit</button>`;
   return`<tr><td>${t.training_code||'-'}</td><td><strong>${t.title}</strong></td><td>${t.training_type}</td><td>${t.trainer_name||'-'}</td>
   <td>${t.start_date}${t.end_date?' → '+t.end_date:''}</td><td>${t.enrolled||0}</td>
   <td><span class="badge ${bm[t.status]||''}">${t.status}</span></td>${CAN?`<td>${act}</td>`:''}</tr>`;
  }).join('')||'<tr><td colspan="8" style="text-align:center;color:#94a3b8">No trainings</td></tr>';
 })}
function editT(t){document.getElementById('tid').value=t.id;document.getElementById('tcode').value=t.training_code||'';document.getElementById('ttitle').value=t.title;
 document.getElementById('ttype').value=t.training_type;document.getElementById('ttrainer').value=t.trainer_name||'';document.getElementById('tloc').value=t.location||'';
 document.getElementById('tstart').value=t.start_date;document.getElementById('tend').value=t.end_date||'';document.getElementById('tdesc').value=t.description||'';document.getElementById('tstatus').value=t.status;window.scrollTo(0,0)}
function loadTSelect(){const fd=new FormData();fd.append('ajax_action','fetch_trainings');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{const s=document.getElementById('eTrain');
  s.innerHTML=(d.data||[]).map(t=>`<option value="${t.id}">${t.training_code||''} — ${t.title}</option>`).join(''); loadAtt();})}
function loadEmps(){const fd=new FormData();fd.append('ajax_action','get_emps');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{const s=document.getElementById('eEmp');
  s.innerHTML=(d.employees||[]).map(e=>`<option value="${e.empcode}" data-name="${e.empname}">${e.empname} (${e.empcode})</option>`).join('')})}
function doEnroll(){const s=document.getElementById('eEmp');const fd=new FormData();fd.append('ajax_action','enroll');
 fd.append('training_id',document.getElementById('eTrain').value);fd.append('empcode',s.value);fd.append('empname',s.selectedOptions[0]?.dataset.name||'');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadAtt()})}
function loadAtt(){const tid=document.getElementById('eTrain').value;if(!tid)return;const fd=new FormData();fd.append('ajax_action','fetch_attendance');fd.append('training_id',tid);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  document.getElementById('attBody').innerHTML=(d.data||[]).map(r=>`<tr>
   <td>${r.empname} <small>(${r.empcode})</small></td>
   <td><select id="att${r.id}" style="height:24px;font-size:11px"><option ${r.attendance==='Registered'?'selected':''}>Registered</option><option ${r.attendance==='Present'?'selected':''}>Present</option><option ${r.attendance==='Absent'?'selected':''}>Absent</option><option ${r.attendance==='Completed'?'selected':''}>Completed</option></select></td>
   <td><input type="number" id="sc${r.id}" value="${r.score??''}" step="0.01" style="width:70px;height:24px"></td>
   <td><button class="btn btn-primary btn-sm" onclick="markAtt(${r.id})">Save</button></td></tr>`).join('')||'<tr><td colspan="4" style="text-align:center;color:#94a3b8">No enrollments</td></tr>';
 })}
function markAtt(id){const fd=new FormData();fd.append('ajax_action','mark_attendance');fd.append('id',id);
 fd.append('attendance',document.getElementById('att'+id).value);fd.append('score',document.getElementById('sc'+id).value);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>showMsg(d.message,d.status==='success'?'success':'error'))}
function loadMy(){const fd=new FormData();fd.append('ajax_action','my_trainings');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  document.getElementById('myBody').innerHTML=(d.data||[]).map(t=>`<tr><td>${t.training_code||'-'}</td><td>${t.title}</td><td>${t.training_type}</td>
  <td>${t.start_date}${t.end_date?' → '+t.end_date:''}</td><td>${t.attendance}</td><td>${t.score??'-'}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:#94a3b8">No trainings assigned</td></tr>';
 })}
window.onload=loadT;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
