<?php
require_once __DIR__ . '/../includes/core.php';
$ctx = require_page_access($pdo);
$isAdmin = $ctx['is_admin'];
if (!$isAdmin) { header('Location: ../dashboard/dashboard.php?alert=unauthorized'); exit; }

// Auto-create Tables for SRC and Route Mapping
$pdo->exec("CREATE TABLE IF NOT EXISTS src_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    route_name VARCHAR(100) NOT NULL,
    town_name VARCHAR(150) NOT NULL,
    category ENUM('HQ','EX-HQ','OS') DEFAULT 'HQ',
    distance_km DECIMAL(8,2) DEFAULT 0,
    fare_allowance DECIMAL(10,2) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS route_party_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    route_name VARCHAR(100) NOT NULL,
    party_id INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_route_party (empcode, route_name, party_id)
)");

// AJAX Request Handler
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status'=>'error','message'=>'Invalid request'];
    try {
        // ---- TAB 1: SRC MASTER ACTIONS ----
        if ($action === 'save_src') {
            $id = intval($_POST['id'] ?? 0);
            $code = trim($_POST['empcode'] ?? '');
            if (!$code) { echo json_encode(['status'=>'error','message'=>'Employee Code required']); exit; }
            
            $fields = [
                $code, trim($_POST['route_name'] ?? ''), trim($_POST['town_name'] ?? ''), 
                $_POST['category'] ?? 'HQ', floatval($_POST['distance_km'] ?? 0), 
                floatval($_POST['fare_allowance'] ?? 0)
            ];
            
            if ($id > 0) {$pdo->prepare("UPDATE src_master SET empcode=?, route_name=?, town_name=?, category=?, distance_km=?, fare_allowance=? WHERE id=?")
                    ->execute(array_merge($fields, [$id]));$response = ['status'=>'success','message'=>'SRC updated successfully'];
            } else {
                $pdo->prepare("INSERT INTO src_master (empcode, route_name, town_name, category, distance_km, fare_allowance) VALUES (?,?,?,?,?,?)")
                    ->execute($fields);$response = ['status'=>'success','message'=>'SRC Route added successfully'];
            }
        } elseif ($action === 'fetch_src') {$sql = "SELECT s.*, e.empname, e.designation, e.division, e.state 
                    FROM src_master s 
                    LEFT JOIN employees e ON s.empcode = e.empcode 
                    ORDER BY s.empcode, s.route_name";
            $response = ['status'=>'success','data'=>$pdo->query($sql)->fetchAll()];
            
        } elseif ($action === 'delete_src') {$pdo->prepare("DELETE FROM src_master WHERE id=?")->execute([intval($_POST['id'])]);$response = ['status'=>'success','message'=>'Route deleted'];
            
        } elseif ($action === 'get_emps_src') {
            // Fetch Employees
            $emps =$pdo->query("SELECT empcode, empname, designation, division, state, hq FROM employees ORDER BY empname")->fetchAll();
            $response = ['status'=>'success', 'emps'=>$emps];
        } elseif ($action === 'get_towns') {
            // Fetch Mapped Doctor/Stockist Cities (area/hq) strictly based on Employee HQ
            $hq = trim($_POST['hq'] ?? '');
            $stmt =$pdo->prepare("SELECT DISTINCT area FROM parties WHERE hq = ? AND area IS NOT NULL AND area != '' UNION SELECT DISTINCT hq FROM parties WHERE hq = ?");
            $stmt->execute([$hq,$hq]);
            $response = ['status'=>'success', 'towns'=>$stmt->fetchAll(PDO::FETCH_COLUMN)];
        }
        
        // ---- TAB 2: ROUTE MAPPING ACTIONS ----
        elseif ($action === 'save_map') {
            $code = trim($_POST['empcode'] ?? '');
            $route = trim($_POST['route_name'] ?? '');
            $party_id = intval($_POST['party_id'] ?? 0);
            
            if (!$code || !$route || !$party_id) { echo json_encode(['status'=>'error','message'=>'Employee, Route, and Party are required']); exit; }
            
            $stmt =$pdo->prepare("INSERT INTO route_party_map (empcode, route_name, party_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE updated_at=NOW()");
            $stmt->execute([$code,$route, $party_id]);$response = ['status'=>'success','message'=>'Party mapped to route successfully'];
            
        } elseif ($action === 'fetch_map') {$sql = "SELECT r.id, r.empcode, r.route_name, p.partyname, p.partycode, p.hq as party_hq, p.state as party_state, e.empname 
                    FROM route_party_map r 
                    JOIN parties p ON r.party_id = p.id 
                    LEFT JOIN employees e ON r.empcode = e.empcode 
                    ORDER BY r.empcode, r.route_name, p.partyname";
            $response = ['status'=>'success','data'=>$pdo->query($sql)->fetchAll()];
            
        } elseif ($action === 'delete_map') {$pdo->prepare("DELETE FROM route_party_map WHERE id=?")->execute([intval($_POST['id'])]);$response = ['status'=>'success','message'=>'Mapping deleted'];
            
        } elseif ($action === 'get_routes') {
            $code = trim($_POST['empcode'] ?? '');
            $stmt =$pdo->prepare("SELECT DISTINCT route_name FROM src_master WHERE empcode=?");
            $stmt->execute([$code]);
            $response = ['status'=>'success','routes'=>$stmt->fetchAll(PDO::FETCH_COLUMN)];
            
        } elseif ($action === 'get_deps_map') {
            $parties =$pdo->query("SELECT id, partyname, partycode, hq FROM parties ORDER BY partyname")->fetchAll();
            $response = ['status'=>'success', 'parties'=>$parties];
        }
    } catch(Exception $e) { $response['message'] =$e->getMessage(); }
    echo json_encode($response); exit;
}

$pageTitle = "SRC & Route Mapping";
include __DIR__ . '/../includes/header.php';
?>

<div class="erp-workspace">
    <div id="msgBox" class="msg-box"></div>
    
    <!-- Datalist for Dynamic Town Suggestions Based on HQ -->
    <datalist id="townList"></datalist>

    <div class="tabs">
        <button class="tab-btn active" onclick="sw('src')">1. SRC Master (Create Routes)</button>
        <button class="tab-btn" onclick="sw('map')">2. Map Customers to Routes</button>
    </div>

    <!-- ============================================== -->
    <!-- TAB 1: SRC MASTER -->
    <!-- ============================================== -->
    <div id="tab-src" class="tab-content active">
        <div class="panel-box">
            <div class="panel-title">Setup Standard Route Card (SRC) & Fare Calculator</div>
            <form id="srcForm" onsubmit="saveSrc(event)">
                <input type="hidden" id="fid">
                
                <!-- Row 1: Employee and Auto-Fetched Details -->
                <div class="form-grid" style="grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr;">
                    <div class="form-group">
                        <label>Select Employee *</label>
                        <select id="femp" required onchange="onEmpChange()"></select>
                    </div>
                    <div class="form-group"><label>Emp HQ</label><input id="fhq" readonly placeholder="Auto-filled"></div>
                    <div class="form-group"><label>Division</label><input id="fdiv" readonly placeholder="Auto-filled"></div>
                    <div class="form-group"><label>State</label><input id="fstate" readonly placeholder="Auto-filled"></div>
                    <div class="form-group"><label>Designation</label><input id="fdesig" readonly placeholder="Auto-filled"></div>
                </div>
                
                <!-- Row 2: Route Config -->
                <div class="form-grid" style="grid-template-columns: 1.5fr 1.5fr 1fr;">
                    <div class="form-group"><label>Route Name / ID (Auto) *</label><input id="froute" required placeholder="Select Employee First"></div>
                    <div class="form-group">
                        <label>Route Path / Stops Count</label>
                        <select id="froute_type" onchange="generateStops()">
                            <option value="1">Direct (1 City / Stop)</option>
                            <option value="2">2 Cities / Stops</option>
                            <option value="3">3 Cities / Stops</option>
                            <option value="4">4 Cities / Stops</option>
                            <option value="5">5 Cities / Stops</option>
                            <option value="6">6 Cities / Stops</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Category *</label><select id="fcat"><option>HQ</option><option>EX-HQ</option><option>OS</option></select></div>
                </div>

                <!-- Dynamic Stops Container -->
                <div id="stopsContainer" class="stops-container">
                    <div class="stops-header"><i class="fa-solid fa-route"></i> Route Path Configuration</div>
                    <div id="stopsFields"></div>
                </div>

                <!-- Row 3: Fare Calculation -->
                <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;">
                    <div class="form-group"><label>Combined Towns Path</label><input id="ftown" readonly style="font-weight:bold; color:var(--navy-900);"></div>
                    <div class="form-group"><label>Total Distance (KM)</label><input type="number" id="fdist" step="0.1" value="0" readonly style="font-weight:bold; color:var(--danger);"></div>
                    <div class="form-group"><label>TA Rate per KM (₹)</label><input type="number" id="frate" step="0.1" value="2.50" oninput="calcFare()"></div>
                    <div class="form-group">
                        <label>Journey Mode</label>
                        <select id="f2way" onchange="calcFare()">
                            <option value="2">2-Way (Round Trip)</option>
                            <option value="1">1-Way (Single Trip)</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Final Calculated Fare (₹)</label><input type="number" id="ffare" step="0.01" value="0" style="font-weight:bold; color:var(--green); background:#d1fae5; border-color:#059669;"></div>
                    <div style="display:flex; gap:4px;">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save SRC</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="panel-box">
            <div class="panel-title">Approved SRC Registry</div>
            <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow: hidden; overflow-x: auto;">
                <table class="compact-table">
                    <thead><tr><th>Division</th><th>State</th><th>Designation</th><th>Employee Name</th><th>Route Name</th><th>Town Path</th><th>Category</th><th>Distance (KM)</th><th>Fare (₹)</th><th>Action</th></tr></thead>
                    <tbody id="tBodySrc"><tr><td colspan="10" style="text-align:center; padding: 20px;">Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============================================== -->
    <!-- TAB 2: ROUTE MAPPING -->
    <!-- ============================================== -->
    <div id="tab-map" class="tab-content">
        <div class="panel-box">
            <div class="panel-title">Map Party to Route</div>
            <form id="mapForm" onsubmit="saveMap(event)">
                <div class="form-grid" style="grid-template-columns: 1fr 1fr 2fr auto;">
                    <div class="form-group">
                        <label>Employee *</label>
                        <select id="memp" required onchange="loadRoutesForMap()"></select>
                    </div>
                    <div class="form-group">
                        <label>Select Route *</label>
                        <select id="mroute" required><option value="">- Select Employee First -</option></select>
                    </div>
                    <div class="form-group">
                        <label>Select Party / Customer *</label>
                        <select id="mparty" required></select>
                    </div>
                    <div style="display:flex; gap:4px;">
                        <button type="submit" class="btn btn-primary">Map Party</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="panel-box">
            <div class="panel-title">Route to Party Mapping Registry</div>
            <div style="border: 1.5px dotted #cbd5e1; border-radius: 4px; overflow: hidden; overflow-x: auto;">
                <table class="compact-table">
                    <thead><tr><th>Employee Name</th><th>Route Name</th><th>Party Code</th><th>Party Name</th><th>HQ</th><th>State</th><th>Action</th></tr></thead>
                    <tbody id="tBodyMap"><tr><td colspan="7" style="text-align:center; padding: 20px;">Loading...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// --- Global Utilities ---
function showMsg(t,ty='success'){const b=document.getElementById('msgBox');b.className='msg-box msg-'+ty;b.innerText=t;b.style.display='block';setTimeout(()=>b.style.display='none',3000); window.scrollTo(0,0);}
function sw(t){
    document.querySelectorAll('.tab-content').forEach(e=>e.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));
    document.getElementById('tab-'+t).classList.add('active');
    event.currentTarget.classList.add('active');
    if(t === 'map') loadMapTab();
    if(t === 'src') loadSrc();
}

// --- Init: Load Employees Globally ---
function initData(){
    const fd = new FormData(); fd.append('ajax_action', 'get_emps_src');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        // Build employee options with data attributes
        const empOpts = '<option value="">- Select Employee -</option>' + (d.emps||[]).map(e=>`<option value="${e.empcode}" data-div="${e.division||'-'}" data-state="${e.state||'-'}" data-desig="${e.designation||'-'}" data-hq="${e.hq||'-'}">${e.empname} (${e.empcode})</option>`).join('');
        document.getElementById('femp').innerHTML = empOpts;
        document.getElementById('memp').innerHTML = empOpts;
        
        loadSrc(); // Load initial table
        generateStops(); // Initialize the first dynamic stop
    });
}

// ==============================================
// TAB 1: SRC LOGIC
// ==============================================
function onEmpChange() {
    const sel = document.getElementById('femp');
    const opt = sel.options[sel.selectedIndex];
    if(opt && opt.value) {
        const empHq = opt.getAttribute('data-hq');
        const empDiv = opt.getAttribute('data-div');
        
        document.getElementById('fhq').value = empHq;
        document.getElementById('fdiv').value = empDiv;
        document.getElementById('fstate').value = opt.getAttribute('data-state');
        document.getElementById('fdesig').value = opt.getAttribute('data-desig');
        
        // Auto-Generate Route Name/ID Based on Division
        if(empDiv && empDiv !== '-') {
            const prefix = empDiv.substring(0, 3).toUpperCase();
            const rand = Math.floor(1000 + Math.random() * 9000);
            document.getElementById('froute').value = prefix + '-RT-' + rand;
        } else {
            document.getElementById('froute').value = 'RT-' + Math.floor(1000 + Math.random() * 9000);
        }
        
        // Fetch Towns mapped to this specific Employee's HQ
        fetchTownsForHQ(empHq);
    } else {
        document.getElementById('fhq').value = '';
        document.getElementById('fdiv').value = '';
        document.getElementById('fstate').value = '';
        document.getElementById('fdesig').value = '';
        document.getElementById('froute').value = '';
        document.getElementById('townList').innerHTML = ''; // Clear datalist
    }
    generateStops(); // Refresh labels
}

function fetchTownsForHQ(hq) {
    if (!hq || hq === '-') return;
    const fd = new FormData(); 
    fd.append('ajax_action', 'get_towns'); 
    fd.append('hq', hq);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('townList').innerHTML = (d.towns||[]).map(t=>`<option value="${t}">`).join('');
    });
}

function generateStops() {
    const count = parseInt(document.getElementById('froute_type').value) || 1;
    const empHq = document.getElementById('fhq').value || 'HQ';
    
    let html = '';
    for(let i=1; i<=count; i++) {
        let origin = i === 1 ? empHq : `City ${i-1}`;
        html += `
        <div class="form-grid" style="grid-template-columns: 2fr 1fr; margin-bottom: 6px;">
            <div class="form-group">
                <label>Destination City ${i} *</label>
                <input type="text" class="stop-city" list="townList" required placeholder="Type or select town" onchange="updateTownsAndFare()" onkeyup="updateTownsAndFare()">
            </div>
            <div class="form-group">
                <label>Distance from ${origin} to City ${i} (KM) *</label>
                <input type="number" class="stop-dist" step="0.1" value="0" required oninput="calcFare()">
            </div>
        </div>`;
    }
    document.getElementById('stopsFields').innerHTML = html;
    updateTownsAndFare();
}

function updateTownsAndFare() {
    let cities = [];
    document.querySelectorAll('.stop-city').forEach(c => {
        if(c.value.trim() !== '') cities.push(c.value.trim());
    });
    document.getElementById('ftown').value = cities.join(' - ');
    calcFare();
}

function calcFare() {
    let dists = document.querySelectorAll('.stop-dist');
    let totalDist = 0;
    dists.forEach(d => totalDist += parseFloat(d.value) || 0);
    
    // Set total distance
    document.getElementById('fdist').value = totalDist.toFixed(2);
    
    // Calculate Fare: Total Dist * Rate * Way Multiplier
    let rate = parseFloat(document.getElementById('frate').value) || 0;
    let multiplier = parseInt(document.getElementById('f2way').value) || 2;
    
    let finalFare = totalDist * rate * multiplier;
    document.getElementById('ffare').value = finalFare.toFixed(2);
}

function loadSrc(){
    const fd = new FormData(); fd.append('ajax_action', 'fetch_src');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('tBodySrc').innerHTML = (d.data||[]).map(r=>`<tr>
            <td>${r.division||'-'}</td>
            <td>${r.state||'-'}</td>
            <td>${r.designation||'-'}</td>
            <td><strong style="color:var(--navy-900);">${r.empname||'-'} (${r.empcode})</strong></td>
            <td><strong>${r.route_name}</strong></td>
            <td><span style="color:var(--sky); font-weight:600;">${r.town_name}</span></td>
            <td><span style="font-weight:bold; color:${r.category==='OS'?'#dc2626':(r.category==='EX-HQ'?'#d97706':'#059669')}">${r.category}</span></td>
            <td>${r.distance_km} KM</td>
            <td><strong style="color:#059669;">₹ ${r.fare_allowance}</strong></td>
            <td>
                <div style="display:flex; gap:4px;">
                    <button class="btn btn-secondary btn-sm" onclick='editSrc(${JSON.stringify(r)})'>Edit</button>
                    <button class="btn btn-danger btn-sm" onclick="delSrc(${r.id})">Del</button>
                </div>
            </td>
        </tr>`).join('') || '<tr><td colspan="10" style="text-align:center; color:#94a3b8; padding:20px;">No routes found</td></tr>';
    });
}

function saveSrc(e){
    e.preventDefault();
    const fd = new FormData(); fd.append('ajax_action', 'save_src');
    fd.append('id', document.getElementById('fid').value);
    fd.append('empcode', document.getElementById('femp').value);
    fd.append('route_name', document.getElementById('froute').value);
    fd.append('town_name', document.getElementById('ftown').value);
    fd.append('category', document.getElementById('fcat').value);
    fd.append('distance_km', document.getElementById('fdist').value);
    fd.append('fare_allowance', document.getElementById('ffare').value);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ 
        showMsg(d.message, d.status==='success'?'success':'error'); 
        if(d.status==='success'){ resetSrc(); loadSrc(); } 
    });
}

function editSrc(r){
    // Reset layout for flat edit (we inject the combined town directly for simplicity during edit mode)
    document.getElementById('fid').value = r.id; 
    document.getElementById('femp').value = r.empcode;
    
    // Auto fill readonly fields without overriding route name
    const sel = document.getElementById('femp');
    for(let i=0; i<sel.options.length; i++) {
        if(sel.options[i].value === r.empcode) {
            sel.selectedIndex = i;
            document.getElementById('fhq').value = sel.options[i].getAttribute('data-hq');
            document.getElementById('fdiv').value = sel.options[i].getAttribute('data-div');
            document.getElementById('fstate').value = sel.options[i].getAttribute('data-state');
            document.getElementById('fdesig').value = sel.options[i].getAttribute('data-desig');
            break;
        }
    }
    
    document.getElementById('froute').value = r.route_name; 
    document.getElementById('fcat').value = r.category; 
    
    // Override stops container to just show one flat text input for town during edit
    document.getElementById('froute_type').value = "1";
    document.getElementById('stopsFields').innerHTML = `
        <div class="form-grid" style="grid-template-columns: 2fr 1fr;">
            <div class="form-group"><label>Flat Route Town String</label><input type="text" class="stop-city" value="${r.town_name}" oninput="updateTownsAndFare()" required></div>
            <div class="form-group"><label>Total Distance (KM)</label><input type="number" class="stop-dist" step="0.1" value="${r.distance_km}" oninput="calcFare()" required></div>
        </div>`;
    
    document.getElementById('ftown').value = r.town_name;
    document.getElementById('fdist').value = r.distance_km;
    
    // Back-calculate the rate (Assuming 2-way)
    let dist = parseFloat(r.distance_km);
    let fare = parseFloat(r.fare_allowance);
    if(dist > 0 && fare > 0) {
        let calcRate = fare / (dist * 2);
        document.getElementById('frate').value = calcRate.toFixed(2);
    }
    
    document.getElementById('ffare').value = r.fare_allowance;
    window.scrollTo(0,0);
}

function delSrc(id){
    if(!confirm('Delete this route?')) return;
    const fd = new FormData(); fd.append('ajax_action', 'delete_src'); fd.append('id', id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ showMsg(d.message); loadSrc(); });
}
function resetSrc(){ 
    document.getElementById('srcForm').reset(); 
    document.getElementById('fid').value=''; 
    onEmpChange();
}


// ==============================================
// TAB 2: ROUTE MAPPING LOGIC
// ==============================================
let isMapTabLoaded = false;
function loadMapTab() {
    if(!isMapTabLoaded) {
        const fd = new FormData(); fd.append('ajax_action', 'get_deps_map');
        fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
            document.getElementById('mparty').innerHTML = '<option value="">- Select Party -</option>' + (d.parties||[]).map(p=>`<option value="${p.id}">${p.partyname} (${p.partycode}) - ${p.hq}</option>`).join('');
            isMapTabLoaded = true;
            loadMap();
        });
    } else {
        loadMap();
    }
}

function loadRoutesForMap(){
    const emp = document.getElementById('memp').value;
    const rSel = document.getElementById('mroute');
    if(!emp) { rSel.innerHTML = '<option value="">- Select Employee First -</option>'; return; }
    
    const fd = new FormData(); fd.append('ajax_action', 'get_routes'); fd.append('empcode', emp);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        rSel.innerHTML = '<option value="">- Select Route -</option>' + (d.routes||[]).map(rt=>`<option value="${rt}">${rt}</option>`).join('');
    });
}

function loadMap(){
    const fd = new FormData(); fd.append('ajax_action', 'fetch_map');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        document.getElementById('tBodyMap').innerHTML = (d.data||[]).map(r=>`<tr>
            <td><strong style="color:var(--navy-900);">${r.empname||'-'} (${r.empcode})</strong></td>
            <td><strong style="color:var(--sky);">${r.route_name}</strong></td>
            <td style="font-family:monospace;">${r.partycode}</td>
            <td>${r.partyname}</td>
            <td>${r.party_hq||'-'}</td>
            <td>${r.party_state||'-'}</td>
            <td>
                <button class="btn btn-danger btn-sm" onclick="delMap(${r.id})">Unmap</button>
            </td>
        </tr>`).join('') || '<tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:20px;">No mappings found</td></tr>';
    });
}

function saveMap(e){
    e.preventDefault();
    const fd = new FormData(); fd.append('ajax_action', 'save_map');
    fd.append('empcode', document.getElementById('memp').value);
    fd.append('route_name', document.getElementById('mroute').value);
    fd.append('party_id', document.getElementById('mparty').value);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ 
        showMsg(d.message, d.status==='success'?'success':'error'); 
        if(d.status==='success'){ loadMap(); } 
    });
}

function delMap(id){
    if(!confirm('Remove this party from the route?')) return;
    const fd = new FormData(); fd.append('ajax_action', 'delete_map'); fd.append('id', id);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ showMsg(d.message); loadMap(); });
}

window.onload = initData;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>