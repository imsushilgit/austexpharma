<?php
require_once __DIR__ . '/../includes/core.php';

// Role-based access – Admin only for structure
$ctx = require_page_access($pdo);
$canWrite  = user_can_write($ctx);
$isAdmin   = $ctx['is_admin'];

if (!$isAdmin) {
    header('Location: ../dashboard/dashboard.php?access=denied');
    exit;
}

// ==========================================
// AUTO-CREATE TABLES
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS salary_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    component_code VARCHAR(30) NOT NULL UNIQUE,
    component_name VARCHAR(100) NOT NULL,
    component_type ENUM('Earning','Deduction') NOT NULL DEFAULT 'Earning',
    is_percentage TINYINT(1) DEFAULT 0,
    default_value DECIMAL(12,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Seed default components if empty
$count = $pdo->query("SELECT COUNT(*) FROM salary_components")->fetchColumn();
if ($count == 0) {
    $pdo->exec("INSERT INTO salary_components (component_code, component_name, component_type, is_percentage, default_value, sort_order) VALUES
        ('BASIC', 'Basic Salary', 'Earning', 0, 0, 1),
        ('HRA', 'House Rent Allowance', 'Earning', 1, 40, 2),
        ('CONV', 'Conveyance Allowance', 'Earning', 0, 1600, 3),
        ('MED', 'Medical Allowance', 'Earning', 0, 1250, 4),
        ('SPECIAL', 'Special Allowance', 'Earning', 0, 0, 5),
        ('PF', 'Provident Fund', 'Deduction', 1, 12, 10),
        ('ESI', 'ESI', 'Deduction', 1, 0.75, 11),
        ('PT', 'Professional Tax', 'Deduction', 0, 200, 12),
        ('TDS', 'TDS', 'Deduction', 0, 0, 13),
        ('OTHER_DED', 'Other Deduction', 'Deduction', 0, 0, 14)
    ");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS employee_salary_structure (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empcode VARCHAR(50) NOT NULL,
    component_code VARCHAR(30) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    effective_from DATE DEFAULT NULL,
    UNIQUE KEY uniq_emp_comp (empcode, component_code)
)");

// ==========================================
// AJAX HANDLER
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'save_component') {
            $id   = intval($_POST['id'] ?? 0);
            $code = strtoupper(trim($_POST['component_code'] ?? ''));
            $name = trim($_POST['component_name'] ?? '');
            $type = $_POST['component_type'] ?? 'Earning';
            $isPct = intval($_POST['is_percentage'] ?? 0);
            $defVal = floatval($_POST['default_value'] ?? 0);
            $sort = intval($_POST['sort_order'] ?? 0);
            $active = intval($_POST['is_active'] ?? 1);

            if (empty($code) || empty($name)) {
                echo json_encode(['status' => 'error', 'message' => 'Code and Name are required.']);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE salary_components SET component_code=?, component_name=?, component_type=?, is_percentage=?, default_value=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$code, $name, $type, $isPct, $defVal, $sort, $active, $id]);
                $response = ['status' => 'success', 'message' => 'Component updated.'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO salary_components (component_code, component_name, component_type, is_percentage, default_value, sort_order, is_active) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$code, $name, $type, $isPct, $defVal, $sort, $active]);
                $response = ['status' => 'success', 'message' => 'Component added.'];
            }
        }

        elseif ($action === 'delete_component') {
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM salary_components WHERE id = ?")->execute([$id]);
            $response = ['status' => 'success', 'message' => 'Component deleted.'];
        }

        elseif ($action === 'fetch_components') {
            $data = $pdo->query("SELECT * FROM salary_components ORDER BY sort_order, component_type, component_name")->fetchAll(PDO::FETCH_ASSOC);
            $response = ['status' => 'success', 'data' => $data];
        }

        elseif ($action === 'save_emp_structure') {
            $empcode = trim($_POST['empcode'] ?? '');
            $items = json_decode($_POST['items'] ?? '[]', true);

            if (empty($empcode) || !is_array($items)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid data.']);
                exit;
            }

            $pdo->prepare("DELETE FROM employee_salary_structure WHERE empcode = ?")->execute([$empcode]);
            $stmt = $pdo->prepare("INSERT INTO employee_salary_structure (empcode, component_code, amount, effective_from) VALUES (?,?,?, CURDATE())");
            foreach ($items as $it) {
                if (floatval($it['amount']) > 0 || true) {
                    $stmt->execute([$empcode, $it['component_code'], floatval($it['amount'])]);
                }
            }
            $response = ['status' => 'success', 'message' => 'Salary structure saved for employee.'];
        }

        elseif ($action === 'fetch_emp_structure') {
            $empcode = trim($_POST['empcode'] ?? '');
            $comps = $pdo->query("SELECT * FROM salary_components WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("SELECT component_code, amount FROM employee_salary_structure WHERE empcode = ?");
            $stmt->execute([$empcode]);
            $saved = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $saved[$r['component_code']] = $r['amount'];
            }
            $response = ['status' => 'success', 'components' => $comps, 'saved' => $saved];
        }

    } catch (PDOException $e) {
        $response['message'] = 'DB Error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

$employees = $pdo->query("SELECT empcode, empname, designation, hq FROM employees ORDER BY empname")->fetchAll(PDO::FETCH_ASSOC);
$components = $pdo->query("SELECT * FROM salary_components ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Salary Structure";
include __DIR__ . '/../includes/header.php';
?>


<div class="tabs">
    <button class="tab-btn active" onclick="switchTab('components')">Salary Components</button>
    <button class="tab-btn" onclick="switchTab('empstruct')">Employee Structure</button>
</div>
<div id="msgBox" class="msg-box"></div>

<!-- TAB 1: Components -->
<div id="tab-components" class="tab-content active">
    <div class="panel-box">
        <div class="panel-title">Add / Edit Component</div>
        <form id="compForm" onsubmit="saveComponent(event)">
            <input type="hidden" id="compId" value="">
            <div class="form-grid" style="grid-template-columns: 1fr 1.5fr 1fr 0.8fr 1fr 0.7fr 0.7fr auto;">
                <div class="form-group"><label>Code</label><input type="text" id="compCode" required placeholder="BASIC"></div>
                <div class="form-group"><label>Name</label><input type="text" id="compName" required placeholder="Basic Salary"></div>
                <div class="form-group"><label>Type</label>
                    <select id="compType"><option value="Earning">Earning</option><option value="Deduction">Deduction</option></select>
                </div>
                <div class="form-group"><label>% based?</label>
                    <select id="compPct"><option value="0">No (Fixed)</option><option value="1">Yes (%)</option></select>
                </div>
                <div class="form-group"><label>Default Value</label><input type="number" id="compDef" step="0.01" value="0"></div>
                <div class="form-group"><label>Sort</label><input type="number" id="compSort" value="0"></div>
                <div class="form-group"><label>Active</label>
                    <select id="compActive"><option value="1">Yes</option><option value="0">No</option></select>
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
    <div class="panel-box">
        <div class="panel-title">All Components</div>
        <table class="compact-table">
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>% ?</th><th>Default</th><th>Sort</th><th>Active</th><th>Action</th></tr></thead>
            <tbody id="compTableBody"></tbody>
        </table>
    </div>
</div>

<!-- TAB 2: Employee Structure -->
<div id="tab-empstruct" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">Assign Salary Structure to Employee</div>
        <div class="form-grid" style="grid-template-columns: 2fr auto;">
            <div class="form-group">
                <label>Select Employee</label>
                <select id="empSelect" onchange="loadEmpStructure()">
                    <option value="">-- Select Employee --</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= htmlspecialchars($e['empcode']) ?>"><?= htmlspecialchars($e['empname']) ?> (<?= htmlspecialchars($e['empcode']) ?>) - <?= htmlspecialchars($e['designation'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-success" onclick="saveEmpStructure()">Save Structure</button>
        </div>
        <div id="empStructArea" style="display:none; margin-top:12px;">
            <table class="compact-table">
                <thead><tr><th>Component</th><th>Type</th><th>Amount / %</th></tr></thead>
                <tbody id="empStructBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
function showMsg(t, type='success') {
    const b = document.getElementById('msgBox');
    b.className = 'msg-box msg-' + type; b.innerText = t; b.style.display = 'block';
    setTimeout(() => b.style.display = 'none', 3500);
}
function switchTab(t) {
    document.querySelectorAll('.tab-content').forEach(e => e.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(e => e.classList.remove('active'));
    document.getElementById('tab-' + t).classList.add('active');
    event.target.classList.add('active');
    if (t === 'components') loadComponents();
}
function loadComponents() {
    const fd = new FormData(); fd.append('ajax_action', 'fetch_components');
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        const tb = document.getElementById('compTableBody');
        if (!d.data || !d.data.length) { tb.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#94a3b8;">No components</td></tr>'; return; }
        tb.innerHTML = d.data.map(c => `
            <tr>
                <td><strong>${c.component_code}</strong></td>
                <td>${c.component_name}</td>
                <td><span class="badge-${c.component_type==='Earning'?'earn':'ded'}">${c.component_type}</span></td>
                <td>${c.is_percentage==1?'Yes':'No'}</td>
                <td>${c.default_value}</td>
                <td>${c.sort_order}</td>
                <td>${c.is_active==1?'Yes':'No'}</td>
                <td>
                    <button class="btn btn-secondary btn-sm" onclick='editComp(${JSON.stringify(c)})'>Edit</button>
                    <button class="btn btn-danger btn-sm" onclick="delComp(${c.id})">Del</button>
                </td>
            </tr>`).join('');
    });
}
function editComp(c) {
    document.getElementById('compId').value = c.id;
    document.getElementById('compCode').value = c.component_code;
    document.getElementById('compName').value = c.component_name;
    document.getElementById('compType').value = c.component_type;
    document.getElementById('compPct').value = c.is_percentage;
    document.getElementById('compDef').value = c.default_value;
    document.getElementById('compSort').value = c.sort_order;
    document.getElementById('compActive').value = c.is_active;
}
function saveComponent(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('ajax_action', 'save_component');
    fd.append('id', document.getElementById('compId').value);
    fd.append('component_code', document.getElementById('compCode').value);
    fd.append('component_name', document.getElementById('compName').value);
    fd.append('component_type', document.getElementById('compType').value);
    fd.append('is_percentage', document.getElementById('compPct').value);
    fd.append('default_value', document.getElementById('compDef').value);
    fd.append('sort_order', document.getElementById('compSort').value);
    fd.append('is_active', document.getElementById('compActive').value);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        showMsg(d.message, d.status==='success'?'success':'error');
        if (d.status==='success') { document.getElementById('compForm').reset(); document.getElementById('compId').value=''; loadComponents(); }
    });
}
function delComp(id) {
    if (!confirm('Delete this component?')) return;
    const fd = new FormData(); fd.append('ajax_action','delete_component'); fd.append('id', id);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => { showMsg(d.message, d.status==='success'?'success':'error'); loadComponents(); });
}
function loadEmpStructure() {
    const emp = document.getElementById('empSelect').value;
    if (!emp) { document.getElementById('empStructArea').style.display='none'; return; }
    const fd = new FormData(); fd.append('ajax_action','fetch_emp_structure'); fd.append('empcode', emp);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if (d.status !== 'success') return;
        const tb = document.getElementById('empStructBody');
        tb.innerHTML = d.components.map(c => {
            const val = d.saved[c.component_code] !== undefined ? d.saved[c.component_code] : c.default_value;
            return `<tr data-code="${c.component_code}">
                <td>${c.component_name} (${c.component_code})</td>
                <td><span class="badge-${c.component_type==='Earning'?'earn':'ded'}">${c.component_type}</span></td>
                <td><input type="number" class="struct-amt" step="0.01" value="${val}" style="width:120px;height:26px;"></td>
            </tr>`;
        }).join('');
        document.getElementById('empStructArea').style.display = 'block';
    });
}
function saveEmpStructure() {
    const emp = document.getElementById('empSelect').value;
    if (!emp) { alert('Select employee'); return; }
    const items = [];
    document.querySelectorAll('#empStructBody tr').forEach(tr => {
        items.push({ component_code: tr.dataset.code, amount: tr.querySelector('.struct-amt').value });
    });
    const fd = new FormData();
    fd.append('ajax_action', 'save_emp_structure');
    fd.append('empcode', emp);
    fd.append('items', JSON.stringify(items));
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => showMsg(d.message, d.status==='success'?'success':'error'));
}
window.onload = loadComponents;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
