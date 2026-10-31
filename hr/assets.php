<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin']; $isManager = $ctx['is_manager']; $isMR = $ctx['is_mr'];
$teamCodes = $ctx['team_codes']; $empCode = $ctx['empcode'];

$pdo->exec("CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(50) NOT NULL UNIQUE,
    asset_type ENUM('Mobile','TAB','Bag','Laptop','Computer','AC','Table','Chair','Sample','Gift','Other') NOT NULL,
    asset_name VARCHAR(150) NOT NULL,
    brand VARCHAR(100) DEFAULT NULL,
    serial_no VARCHAR(100) DEFAULT NULL,
    purchase_date DATE DEFAULT NULL,
    purchase_value DECIMAL(12,2) DEFAULT 0,
    condition_status ENUM('New','Good','Fair','Damaged','Scrap') DEFAULT 'Good',
    status ENUM('Available','Assigned','Under Repair','Returned','Lost','Scrapped') DEFAULT 'Available',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS asset_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    empcode VARCHAR(50) NOT NULL,
    empname VARCHAR(150) NOT NULL,
    assigned_on DATE NOT NULL,
    returned_on DATE DEFAULT NULL,
    condition_on_assign VARCHAR(50) DEFAULT NULL,
    condition_on_return VARCHAR(50) DEFAULT NULL,
    remarks TEXT,
    assigned_by VARCHAR(100) DEFAULT NULL,
    status ENUM('Active','Returned') DEFAULT 'Active'
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $a = $_POST['ajax_action']; $r = ['status'=>'error','message'=>'Invalid'];
    try {
        if ($a === 'save_asset') {
            if (!$isAdmin && !$isManager) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $id = intval($_POST['id']??0);
            $code = trim($_POST['asset_code']??'');
            $type = $_POST['asset_type']??'Other';
            $name = trim($_POST['asset_name']??'');
            if (!$code || !$name) { echo json_encode(['status'=>'error','message'=>'Code & name required']); exit; }
            $data = [$code,$type,$name,trim($_POST['brand']??''),trim($_POST['serial_no']??''),
                $_POST['purchase_date']?:null,floatval($_POST['purchase_value']??0),
                $_POST['condition_status']??'Good',$_POST['status']??'Available',trim($_POST['remarks']??'')];
            if ($id>0) {
                $pdo->prepare("UPDATE assets SET asset_code=?,asset_type=?,asset_name=?,brand=?,serial_no=?,purchase_date=?,purchase_value=?,condition_status=?,status=?,remarks=? WHERE id=?")
                    ->execute(array_merge($data,[$id]));
                $r=['status'=>'success','message'=>'Asset updated'];
            } else {
                $pdo->prepare("INSERT INTO assets (asset_code,asset_type,asset_name,brand,serial_no,purchase_date,purchase_value,condition_status,status,remarks) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute($data);
                $r=['status'=>'success','message'=>'Asset added'];
            }
        } elseif ($a === 'assign') {
            if (!$isAdmin && !$isManager) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $aid=intval($_POST['asset_id']??0); $code=trim($_POST['empcode']??''); $name=trim($_POST['empname']??'');
            $on=$_POST['assigned_on']??date('Y-m-d');
            if (!$aid||!$code) { echo json_encode(['status'=>'error','message'=>'Asset & employee required']); exit; }
            $chk=$pdo->prepare("SELECT status FROM assets WHERE id=?"); $chk->execute([$aid]);
            if ($chk->fetchColumn()!=='Available') { echo json_encode(['status'=>'error','message'=>'Asset not available']); exit; }
            $pdo->prepare("INSERT INTO asset_assignments (asset_id,empcode,empname,assigned_on,condition_on_assign,assigned_by,status) VALUES (?,?,?,?,?,?, 'Active')")
                ->execute([$aid,$code,$name,$on,$_POST['condition_on_assign']??'Good',$ctx['fullname']??$ctx['username']]);
            $pdo->prepare("UPDATE assets SET status='Assigned' WHERE id=?")->execute([$aid]);
            $r=['status'=>'success','message'=>'Asset assigned'];
        } elseif ($a === 'return') {
            if (!$isAdmin && !$isManager) { echo json_encode(['status'=>'error','message'=>'No permission']); exit; }
            $id=intval($_POST['id']??0); $cond=$_POST['condition_on_return']??'Good';
            $st=$pdo->prepare("SELECT asset_id FROM asset_assignments WHERE id=? AND status='Active'"); $st->execute([$id]);
            $aid=$st->fetchColumn();
            if (!$aid) { echo json_encode(['status'=>'error','message'=>'Assignment not found']); exit; }
            $pdo->prepare("UPDATE asset_assignments SET status='Returned', returned_on=CURDATE(), condition_on_return=? WHERE id=?")->execute([$cond,$id]);
            $pdo->prepare("UPDATE assets SET status='Available', condition_status=? WHERE id=?")->execute([$cond,$aid]);
            $r=['status'=>'success','message'=>'Asset returned'];
        } elseif ($a === 'fetch_assets') {
            $type=trim($_POST['asset_type']??''); $status=trim($_POST['status']??'');
            $sql="SELECT a.*, (SELECT empname FROM asset_assignments WHERE asset_id=a.id AND status='Active' LIMIT 1) as assigned_to
                  FROM assets a WHERE 1=1"; $p=[];
            if ($type) { $sql.=" AND a.asset_type=?"; $p[]=$type; }
            if ($status) { $sql.=" AND a.status=?"; $p[]=$status; }
            $sql.=" ORDER BY a.asset_type, a.asset_code";
            $st=$pdo->prepare($sql); $st->execute($p);
            $r=['status'=>'success','data'=>$st->fetchAll(PDO::FETCH_ASSOC)];
        } elseif ($a === 'fetch_assignments') {
            $sql="SELECT aa.*, a.asset_code, a.asset_type, a.asset_name FROM asset_assignments aa JOIN assets a ON aa.asset_id=a.id WHERE 1=1"; $p=[];
            if ($isMR) { $sql.=" AND aa.empcode=?"; $p[]=$empCode; }
            elseif ($isManager && !$isAdmin && $teamCodes) {
                $ph=implode(',',array_fill(0,count($teamCodes),'?')); $sql.=" AND aa.empcode IN ($ph)"; $p=$teamCodes;
            }
            $sql.=" ORDER BY aa.assigned_on DESC LIMIT 200";
            $st=$pdo->prepare($sql); $st->execute($p);
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
$pageTitle = "Asset Management";
include __DIR__ . '/../includes/header.php';
?>
<div class="tabs">
<button class="tab-btn active" onclick="sw('assets')">Assets Master</button>
<button class="tab-btn" onclick="sw('assign')">Assign / Return</button>
<button class="tab-btn" onclick="sw('my')">Assignment History</button>
</div>
<div id="msgBox" class="msg-box"></div>

<div id="tab-assets" class="tab-content active">
<?php if ($isAdmin || $isManager): ?>
<div class="panel-box"><div class="panel-title">Add / Edit Asset</div>
<form id="af" onsubmit="saveA(event)"><input type="hidden" id="aid">
<div class="form-grid" style="grid-template-columns:1fr 1fr 1.5fr 1fr 1fr">
<div class="form-group"><label>Asset Code</label><input id="acode" required placeholder="MOB-001"></div>
<div class="form-group"><label>Type</label>
<select id="atype"><option>Mobile</option><option>TAB</option><option>Bag</option><option>Laptop</option><option>Computer</option><option>AC</option><option>Table</option><option>Chair</option><option>Sample</option><option>Gift</option><option>Other</option></select>
</div>
<div class="form-group"><label>Name / Model</label><input id="aname" required></div>
<div class="form-group"><label>Brand</label><input id="abrand"></div>
<div class="form-group"><label>Serial No</label><input id="aserial"></div>
</div>
<div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr auto;margin-top:8px">
<div class="form-group"><label>Purchase Date</label><input type="date" id="apdate"></div>
<div class="form-group"><label>Value (₹)</label><input type="number" id="aval" step="0.01" value="0"></div>
<div class="form-group"><label>Condition</label><select id="acond"><option>New</option><option selected>Good</option><option>Fair</option><option>Damaged</option><option>Scrap</option></select></div>
<div class="form-group"><label>Status</label><select id="astatus"><option>Available</option><option>Assigned</option><option>Under Repair</option><option>Returned</option><option>Lost</option><option>Scrapped</option></select></div>
<button class="btn btn-primary" type="submit">Save Asset</button>
</div></form></div>
<?php endif; ?>
<div class="panel-box"><div class="panel-title" style="display:flex;justify-content:space-between">
<span>Asset Register</span>
<span>
<select id="fType" onchange="loadAssets()" style="height:26px;font-size:11px"><option value="">All Types</option>
<option>Mobile</option><option>TAB</option><option>Bag</option><option>Laptop</option><option>Computer</option><option>AC</option><option>Table</option><option>Chair</option><option>Sample</option><option>Gift</option><option>Other</option>
</select>
<select id="fStatus" onchange="loadAssets()" style="height:26px;font-size:11px"><option value="">All Status</option>
<option>Available</option><option>Assigned</option><option>Under Repair</option><option>Lost</option><option>Scrapped</option>
</select>
</span></div>
<table class="compact-table"><thead><tr><th>Code</th><th>Type</th><th>Name</th><th>Brand</th><th>Serial</th><th>Value</th><th>Condition</th><th>Status</th><th>Assigned To</th><?php if($isAdmin||$isManager):?><th>Action</th><?php endif;?></tr></thead>
<tbody id="abody"></tbody></table></div>
</div>

<div id="tab-assign" class="tab-content">
<?php if ($isAdmin || $isManager): ?>
<div class="panel-box"><div class="panel-title">Assign Asset</div>
<div class="form-grid" style="grid-template-columns:1.2fr 1.5fr 1fr auto">
<div class="form-group"><label>Available Asset</label><select id="asAsset"></select></div>
<div class="form-group"><label>Employee</label><select id="asEmp"></select></div>
<div class="form-group"><label>Assign Date</label><input type="date" id="asDate" value="<?=date('Y-m-d')?>"></div>
<button class="btn btn-success" onclick="doAssign()">Assign</button>
</div></div>
<?php endif; ?>
<div class="panel-box"><div class="panel-title">Active Assignments</div>
<table class="compact-table"><thead><tr><th>Asset</th><th>Type</th><th>Employee</th><th>Assigned On</th><th>Action</th></tr></thead>
<tbody id="assignBody"></tbody></table></div>
</div>

<div id="tab-my" class="tab-content">
<div class="panel-box"><div class="panel-title">Assignment History</div>
<table class="compact-table"><thead><tr><th>Asset</th><th>Type</th><th>Employee</th><th>Assigned</th><th>Returned</th><th>Status</th></tr></thead>
<tbody id="histBody"></tbody></table></div>
</div>

<script>
const CAN=<?=($isAdmin||$isManager)?'true':'false'?>;
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3500)}
function sw(t){document.querySelectorAll('.tab-content').forEach(e=>e.classList.remove('active'));document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));document.getElementById('tab-'+t).classList.add('active');event.target.classList.add('active');
 if(t==='assets')loadAssets(); if(t==='assign'){loadAvail();loadEmps();loadActive();} if(t==='my')loadHist();}
function saveA(e){e.preventDefault();const fd=new FormData();fd.append('ajax_action','save_asset');fd.append('id',document.getElementById('aid').value);
 ['acode','atype','aname','abrand','aserial','apdate','aval','acond','astatus'].forEach((id,i)=>{const k=['asset_code','asset_type','asset_name','brand','serial_no','purchase_date','purchase_value','condition_status','status'][i];fd.append(k,document.getElementById(id).value)});
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success'){document.getElementById('af').reset();document.getElementById('aid').value='';loadAssets()}})}
function loadAssets(){const fd=new FormData();fd.append('ajax_action','fetch_assets');fd.append('asset_type',document.getElementById('fType').value);fd.append('status',document.getElementById('fStatus').value);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  const bm={Available:'badge-avail',Assigned:'badge-assigned','Under Repair':'badge-repair'};
  document.getElementById('abody').innerHTML=(d.data||[]).map(a=>{
   let act=''; if(CAN)act=`<button class="btn btn-secondary btn-sm" onclick='editA(${JSON.stringify(a)})'>Edit</button>`;
   return`<tr><td><strong>${a.asset_code}</strong></td><td>${a.asset_type}</td><td>${a.asset_name}</td><td>${a.brand||'-'}</td><td>${a.serial_no||'-'}</td>
   <td>₹${Number(a.purchase_value).toLocaleString('en-IN')}</td><td>${a.condition_status}</td>
   <td><span class="badge ${bm[a.status]||'badge-other'}">${a.status}</span></td><td>${a.assigned_to||'-'}</td>${CAN?`<td>${act}</td>`:''}</tr>`;
  }).join('')||'<tr><td colspan="10" style="text-align:center;color:#94a3b8">No assets</td></tr>';
 })}
function editA(a){document.getElementById('aid').value=a.id;document.getElementById('acode').value=a.asset_code;document.getElementById('atype').value=a.asset_type;
 document.getElementById('aname').value=a.asset_name;document.getElementById('abrand').value=a.brand||'';document.getElementById('aserial').value=a.serial_no||'';
 document.getElementById('apdate').value=a.purchase_date||'';document.getElementById('aval').value=a.purchase_value;document.getElementById('acond').value=a.condition_status;document.getElementById('astatus').value=a.status;window.scrollTo(0,0)}
function loadAvail(){const fd=new FormData();fd.append('ajax_action','fetch_assets');fd.append('status','Available');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{const s=document.getElementById('asAsset');if(!s)return;
  s.innerHTML=(d.data||[]).map(a=>`<option value="${a.id}">${a.asset_code} — ${a.asset_type} / ${a.asset_name}</option>`).join('')||'<option value="">None available</option>'})}
function loadEmps(){const fd=new FormData();fd.append('ajax_action','get_emps');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{const s=document.getElementById('asEmp');if(!s)return;
  s.innerHTML=(d.employees||[]).map(e=>`<option value="${e.empcode}" data-name="${e.empname}">${e.empname} (${e.empcode})</option>`).join('')})}
function doAssign(){const s=document.getElementById('asEmp');const fd=new FormData();fd.append('ajax_action','assign');
 fd.append('asset_id',document.getElementById('asAsset').value);fd.append('empcode',s.value);fd.append('empname',s.selectedOptions[0]?.dataset.name||'');
 fd.append('assigned_on',document.getElementById('asDate').value);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success'){loadAvail();loadActive()}})}
function loadActive(){const fd=new FormData();fd.append('ajax_action','fetch_assignments');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  document.getElementById('assignBody').innerHTML=(d.data||[]).filter(x=>x.status==='Active').map(r=>{
   let act=CAN?`<button class="btn btn-secondary btn-sm" onclick="doReturn(${r.id})">Return</button>`:'-';
   return`<tr><td>${r.asset_code} — ${r.asset_name}</td><td>${r.asset_type}</td><td>${r.empname}</td><td>${r.assigned_on}</td><td>${act}</td></tr>`;
  }).join('')||'<tr><td colspan="5" style="text-align:center;color:#94a3b8">None</td></tr>';
 })}
function doReturn(id){const cond=prompt('Condition on return (Good/Fair/Damaged)','Good');if(cond===null)return;
 const fd=new FormData();fd.append('ajax_action','return');fd.append('id',id);fd.append('condition_on_return',cond);
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{showMsg(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadActive()})}
function loadHist(){const fd=new FormData();fd.append('ajax_action','fetch_assignments');
 fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
  document.getElementById('histBody').innerHTML=(d.data||[]).map(r=>`<tr><td>${r.asset_code} — ${r.asset_name}</td><td>${r.asset_type}</td><td>${r.empname}</td><td>${r.assigned_on}</td><td>${r.returned_on||'-'}</td><td>${r.status}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:#94a3b8">None</td></tr>';
 })}
window.onload=loadAssets;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
