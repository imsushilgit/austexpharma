<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin =$ctx['is_admin'];
if (!$isAdmin) { header('Location: ../dashboard/dashboard.php?alert=unauthorized'); exit; }

// Auto-create table
$pdo->exec("CREATE TABLE IF NOT EXISTS tada_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    designation VARCHAR(100) NOT NULL UNIQUE,
    da_hq DECIMAL(10,2) DEFAULT 0,
    da_exhq DECIMAL(10,2) DEFAULT 0,
    da_os DECIMAL(10,2) DEFAULT 0,
    ta_local DECIMAL(10,2) DEFAULT 0,
    ta_os DECIMAL(10,2) DEFAULT 0,
    remarks VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status'=>'error','message'=>'Invalid'];
    try {
        if ($action === 'save') {
            $id = intval($_POST['id'] ?? 0);
            $desig = trim($_POST['designation'] ?? '');
            if (!$desig) { echo json_encode(['status'=>'error','message'=>'Designation is required']); exit; }
            
            $fields = [$desig, floatval($_POST['da_hq'] ?? 0), floatval($_POST['da_exhq'] ?? 0), 
                floatval($_POST['da_os'] ?? 0), floatval($_POST['ta_local'] ?? 0), 
                floatval($_POST['ta_os'] ?? 0), trim($_POST['remarks'] ?? '')
            ];
            
            if ($id > 0) {$pdo->prepare("UPDATE tada_master SET designation=?, da_hq=?, da_exhq=?, da_os=?, ta_local=?, ta_os=?, remarks=? WHERE id=?")
                    ->execute(array_merge($fields, [$id]));$response = ['status'=>'success','message'=>'TA/DA rules updated successfully'];
            } else {
                $pdo->prepare("INSERT INTO tada_master (designation, da_hq, da_exhq, da_os, ta_local, ta_os, remarks) VALUES (?,?,?,?,?,?,?)")
                    ->execute($fields);$response = ['status'=>'success','message'=>'TA/DA rules created successfully'];
            }
        } elseif ($action === 'fetch') {
            $response = ['status'=>'success','data'=>$pdo->query("SELECT * FROM tada_master ORDER BY designation")->fetchAll()];
        } elseif ($action === 'delete') {$pdo->prepare("DELETE FROM tada_master WHERE id=?")->execute([intval($_POST['id'])]);$response = ['status'=>'success','message'=>'Record deleted'];
        } elseif ($action === 'get_designations') {
            // Fetch distinct designations dynamically from the employees table
            $response = ['status'=>'success','data'=>$pdo->query("SELECT DISTINCT designation FROM employees WHERE designation IS NOT NULL AND designation != '' ORDER BY designation")->fetchAll(PDO::FETCH_COLUMN)];
        }
    } catch(Exception $e) { $response['message'] =$e->getMessage(); }
    echo json_encode($response); exit;
}

$pageTitle = "TA / DA Master";
include __DIR__ . '/../includes/header.php';
?>

<div class="erp-workspace">
    <div id="msgBox" class="msg-box"></div>
    
    <div class="panel-box">
        <div class="panel-title">1. Setup TA / DA by Hierarchy</div>
        <form id="tadaForm" onsubmit="saveData(event)">
            <input type="hidden" id="fid">
            <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 2fr auto;">
                <div class="form-group"><label>Designation / Role</label><select id="fdesig" required></select></div>
                <div class="form-group"><label>DA (HQ) ₹</label><input type="number" id="fdahq" step="0.01" value="0"></div>
                <div class="form-group"><label>DA (Ex-HQ) ₹</label><input type="number" id="fdaex" step="0.01" value="0"></div>
                <div class="form-group"><label>DA (OS) ₹</label><input type="number" id="fdaos" step="0.01" value="0"></div>
                <div class="form-group"><label>TA (Local) ₹</label><input type="number" id="ftal" step="0.01" value="0"></div>
                <div class="form-group"><label>TA (OS) ₹</label><input type="number" id="ftaos" step="0.01" value="0"></div>
                <div class="form-group"><label>Remarks</label><input id="frem" placeholder="Optional notes"></div>
                <div style="display:flex; gap:4px;">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="resetF()">Clear</button>
                </div>
            </div>
        </form>
    </div>

    <div class="panel-box">
        <div class="panel-title">2. Hierarchy Allowances Registry</div>
        <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow: hidden;">
            <table class="compact-table">
                <thead><tr><th>Designation</th><th>DA (HQ)</th><th>DA (Ex-HQ)</th><th>DA (Outstation)</th><th>TA (Local)</th><th>TA (Outstation)</th><th>Action</th></tr></thead>
                <tbody id="tBody"><tr><td colspan="7" style="text-align:center;">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script>
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000)}

function loadDesignations(){
    const fd = new FormData(); fd.append('ajax_action', 'get_designations');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('fdesig').innerHTML = '<option value="">- Select Designation -</option>' + (d.data||[]).map(x=>`<option value="${x}">${x}</option>`).join('');
    });
}

function loadData(){
    const fd = new FormData(); fd.append('ajax_action', 'fetch');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('tBody').innerHTML = (d.data||[]).map(r=>`<tr>
            <td><strong style="color:var(--navy-900);">${r.designation}</strong></td>
            <td>₹ ${r.da_hq}</td><td>₹ ${r.da_exhq}</td><td>₹ ${r.da_os}</td>
            <td>₹ ${r.ta_local}</td><td>₹ ${r.ta_os}</td>
            <td>
                <button class="btn btn-secondary" style="padding:0 6px; height:20px;" onclick='editR(${JSON.stringify(r)})'>Edit</button>
                <button class="btn btn-danger" style="padding:0 6px; height:20px;" onclick="delR(${r.id})">Del</button>
            </td>
        </tr>`).join('') || '<tr><td colspan="7" style="text-align:center; color:#94a3b8;">No records found</td></tr>';
    });
}

function saveData(e){
    e.preventDefault();
    const fd = new FormData(); fd.append('ajax_action', 'save');
    fd.append('id', document.getElementById('fid').value);
    fd.append('designation', document.getElementById('fdesig').value);
    fd.append('da_hq', document.getElementById('fdahq').value);
    fd.append('da_exhq', document.getElementById('fdaex').value);
    fd.append('da_os', document.getElementById('fdaos').value);
    fd.append('ta_local', document.getElementById('ftal').value);
    fd.append('ta_os', document.getElementById('ftaos').value);
    fd.append('remarks', document.getElementById('frem').value);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ showMsg(d.message, d.status==='success'?'success':'error'); if(d.status==='success'){ resetF(); loadData(); } });
}

function editR(r){
    document.getElementById('fid').value = r.id; 
    
    // Check if designation exists in dropdown, if not append it temporarily
    let sel = document.getElementById('fdesig');
    let exists = Array.from(sel.options).some(opt => opt.value === r.designation);
    if (!exists) {
        let newOpt = new Option(r.designation, r.designation);
        sel.add(newOpt);
    }
    
    sel.value = r.designation;
    document.getElementById('fdahq').value = r.da_hq; document.getElementById('fdaex').value = r.da_exhq;
    document.getElementById('fdaos').value = r.da_os; document.getElementById('ftal').value = r.ta_local;
    document.getElementById('ftaos').value = r.ta_os; document.getElementById('frem').value = r.remarks;
}

function delR(id){
    if(!confirm('Delete this record?')) return;
    const fd = new FormData(); fd.append('ajax_action', 'delete'); fd.append('id', id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ showMsg(d.message); loadData(); });
}
function resetF(){ document.getElementById('tadaForm').reset(); document.getElementById('fid').value=''; }

window.onload = () => { loadDesignations(); loadData(); };
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>