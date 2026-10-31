<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin =$ctx['is_admin'];
if (!$isAdmin) { header('Location: ../dashboard/dashboard.php?alert=unauthorized'); exit; }

$pdo->exec("CREATE TABLE IF NOT EXISTS route_party_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    route_name VARCHAR(100) NOT NULL,
    party_id INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_route_party (empcode, route_name, party_id)
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status'=>'error','message'=>'Invalid'];
    try {
        if ($action === 'save') {
            $code = trim($_POST['empcode'] ?? '');
            $route = trim($_POST['route_name'] ?? '');
            $party_id = intval($_POST['party_id'] ?? 0);
            
            if (!$code || !$route  || !$party_id) { echo json_encode(['status'=>'error','message'=>'Employee, Route, and Party are required']); exit; }
            
            $stmt =$pdo->prepare("INSERT INTO route_party_map (empcode, route_name, party_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE updated_at=NOW()");
            $stmt->execute([$code,$route, $party_id]);$response = ['status'=>'success','message'=>'Party mapped to route successfully'];
            
        } elseif ($action === 'fetch') {$sql = "SELECT r.id, r.empcode, r.route_name, p.partyname, p.partycode, p.hq, p.state, e.empname 
                    FROM route_party_map r 
                    JOIN parties p ON r.party_id = p.id 
                    LEFT JOIN employees e ON r.empcode = e.empcode 
                    ORDER BY r.empcode, r.route_name, p.partyname";
            $response = ['status'=>'success','data'=>$pdo->query($sql)->fetchAll()];
            
        } elseif ($action === 'delete') {$pdo->prepare("DELETE FROM route_party_map WHERE id=?")->execute([intval($_POST['id'])]);$response = ['status'=>'success','message'=>'Mapping deleted'];
            
        } elseif ($action === 'get_routes') {
            $code = trim($_POST['empcode'] ?? '');
            $stmt =$pdo->prepare("SELECT DISTINCT route_name FROM src_master WHERE empcode=?");
            $stmt->execute([$code]);
            $response = ['status'=>'success','routes'=>$stmt->fetchAll(PDO::FETCH_COLUMN)];
            
        } elseif ($action === 'get_deps') {
            $emps =$pdo->query("SELECT empcode, empname FROM employees ORDER BY empname")->fetchAll();
            $parties =$pdo->query("SELECT id, partyname, partycode, hq FROM parties ORDER BY partyname")->fetchAll();
            $response = ['status'=>'success','emps'=>$emps, 'parties'=>$parties];
        }
    } catch(Exception $e) { $response['message'] =$e->getMessage(); }
    echo json_encode($response); exit;
}

$pageTitle = "Route Mapping (Customer to Route)";
include __DIR__ . '/../includes/header.php';
?>

<div class="erp-workspace">
    <div id="msgBox" class="msg-box"></div>
    
    <div class="panel-box">
        <div class="panel-title">1. Map Party to Route</div>
        <form id="mapForm" onsubmit="saveData(event)">
            <div class="form-grid" style="grid-template-columns: 1fr 1fr 2fr auto;">
                <div class="form-group">
                    <label>Employee</label>
                    <select id="femp" required onchange="loadRoutes()"></select>
                </div>
                <div class="form-group">
                    <label>Select Route</label>
                    <select id="froute" required><option value="">- Select Employee First -</option></select>
                </div>
                <div class="form-group">
                    <label>Select Party / Customer</label>
                    <select id="fparty" required></select>
                </div>
                <div style="display:flex; gap:4px;">
                    <button type="submit" class="btn btn-primary">Map Party</button>
                </div>
            </div>
        </form>
    </div>

    <div class="panel-box">
        <div class="panel-title">2. Route to Party Mapping Registry</div>
        <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow: hidden; overflow-x: auto;">
            <table class="compact-table">
                <thead><tr><th>Employee Name</th><th>Route Name</th><th>Party Code</th><th>Party Name</th><th>HQ</th><th>State</th><th>Action</th></tr></thead>
                <tbody id="tBody"><tr><td colspan="7" style="text-align:center;">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script>
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000)}

function initData(){
    const fd = new FormData(); fd.append('ajax_action', 'get_deps');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('femp').innerHTML = '<option value="">- Select Employee -</option>' + (d.emps||[]).map(e=>`<option value="${e.empcode}">${e.empname} (${e.empcode})</option>`).join('');
        document.getElementById('fparty').innerHTML = '<option value="">- Select Party -</option>' + (d.parties||[]).map(p=>`<option value="${p.id}">${p.partyname} (${p.partycode}) - ${p.hq}</option>`).join('');
        loadData();
    });
}

function loadRoutes(){
    const emp = document.getElementById('femp').value;
    const rSel = document.getElementById('froute');
    if(!emp) { rSel.innerHTML = '<option value="">- Select Employee First -</option>'; return; }
    
    const fd = new FormData(); fd.append('ajax_action', 'get_routes'); fd.append('empcode', emp);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        rSel.innerHTML = '<option value="">- Select Route -</option>' + (d.routes||[]).map(rt=>`<option value="${rt}">${rt}</option>`).join('');
    });
}

function loadData(){
    const fd = new FormData(); fd.append('ajax_action', 'fetch');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('tBody').innerHTML = (d.data||[]).map(r=>`<tr>
            <td><strong style="color:var(--navy-900);">${r.empname||'-'} (${r.empcode})</strong></td>
            <td><strong style="color:var(--sky);">${r.route_name}</strong></td>
            <td style="font-family:monospace;">${r.partycode}</td>
            <td>${r.partyname}</td>
            <td>${r.hq||'-'}</td>
            <td>${r.state||'-'}</td>
            <td>
                <button class="btn btn-danger" style="padding:0 6px; height:20px;" onclick="delR(${r.id})">Unmap</button>
            </td>
        </tr>`).join('') || '<tr><td colspan="7" style="text-align:center; color:#94a3b8;">No mappings found</td></tr>';
    });
}

function saveData(e){
    e.preventDefault();
    const fd = new FormData(); fd.append('ajax_action', 'save');
    fd.append('empcode', document.getElementById('femp').value);
    fd.append('route_name', document.getElementById('froute').value);
    fd.append('party_id', document.getElementById('fparty').value);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ 
        showMsg(d.message, d.status==='success'?'success':'error'); 
        if(d.status==='success'){ loadData(); } 
    });
}

function delR(id){
    if(!confirm('Remove this party from the route?')) return;
    const fd = new FormData(); fd.append('ajax_action', 'delete'); fd.append('id', id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ showMsg(d.message); loadData(); });
}

window.onload = initData;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>