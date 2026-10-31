<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
        exit();
    }
    header("Location: ../auth/login.php");
    exit();
}


require_once __DIR__ . '/../includes/core.php';

// yahan se aapka baaki code shuru hoga...

// ==========================================
// PURE AJAX BACKEND HANDLER
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'fetch_initial') {
            $employees = scope_filter_rows($ctx, $pdo->query("SELECT empcode, empname, hq FROM employees WHERE department LIKE '%Sales%' OR designation = 'MR' ORDER BY empname ASC")->fetchAll(PDO::FETCH_ASSOC));
            if (empty($employees)) {
                $employees = scope_filter_rows($ctx, $pdo->query("SELECT empcode, empname, hq FROM employees ORDER BY empname ASC")->fetchAll(PDO::FETCH_ASSOC));
            }

            $parties =$pdo->query("
                SELECT p.partyname, 
                       (100 - COALESCE((SELECT SUM(percentage) FROM assignparties WHERE partyname = p.partyname), 0)) as available
                FROM parties p 
                ORDER BY p.partyname ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $assignments = scope_filter_rows($ctx, $pdo->query("
                SELECT a.id, a.empcode, COALESCE(e.empname, 'Unknown') as empname, COALESCE(e.hq, '-') as hq, a.partyname, a.percentage 
                FROM assignparties a 
                LEFT JOIN employees e ON a.empcode = e.empcode 
                ORDER BY a.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC));

            echo json_encode(["status" => "success", "employees" => $employees, "parties" => $parties, "assignments" => $assignments]);
            exit();
        }

        if ($action === 'save') {
            $id =$_POST['id'] ?? '';
            $empcode = trim($_POST['empcode'] ?? '');
            $partyname = trim($_POST['partyname'] ?? '');
            $percentage = intval($_POST['percentage'] ?? 100);

            // ROLE SCOPE: non-admins need write permission and may only assign to people inside their own team
            if (!$ctx['is_admin']) {
                $__denied = empty($ctx['can_write']) || !can_access_emp($ctx, $empcode);
                if (!$__denied && !empty($id)) {
                    $__o = $pdo->prepare("SELECT empcode FROM assignparties WHERE id = ?");
                    $__o->execute([$id]);
                    $__oc = $__o->fetchColumn();
                    if ($__oc !== false && !can_access_emp($ctx, $__oc)) $__denied = true;
                }
                if ($__denied) { echo json_encode(["status" => "error", "message" => "Access Denied: you can only assign parties to your own team."]); exit(); }
            }

            if (empty($empcode) || empty($partyname) || $percentage < 1 || $percentage > 100) {
                echo json_encode(["status" => "error", "message" => "Valid Employee, Party, and Percentage (1-100) are required."]);
                exit();
            }

            $sql = "SELECT COALESCE(SUM(percentage), 0) FROM assignparties WHERE partyname = ?";
            $params = [$partyname];
            if (!empty($id)) {$sql .= " AND id != ?";
                $params[] =$id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $used_pct = (int)$stmt->fetchColumn();

            if (($used_pct +$percentage) > 100) {
                $available = 100 -$used_pct;
                echo json_encode(["status" => "error", "message" => "Cannot assign {$percentage}%. Only {$available}\% is available for {$partyname}."]);
                exit();
            }

            if (!empty($id)) {
                $stmt =$pdo->prepare("UPDATE assignparties SET empcode = ?, partyname = ?, percentage = ? WHERE id = ?");
                $stmt->execute([$empcode, $partyname,$percentage, $id]);$response = ["status" => "success", "message" => "Assignment updated successfully!"];
            } else {
                $stmt =$pdo->prepare("INSERT INTO assignparties (empcode, partyname, percentage) VALUES (?, ?, ?)");
                $stmt->execute([$empcode,$partyname, $percentage]);$response = ["status" => "success", "message" => "Party assigned successfully!"];
            }

            $response['assignments'] = scope_filter_rows($ctx, $pdo->query("
                SELECT a.id, a.empcode, COALESCE(e.empname, 'Unknown') as empname, COALESCE(e.hq, '-') as hq, a.partyname, a.percentage 
                FROM assignparties a 
                LEFT JOIN employees e ON a.empcode = e.empcode 
                ORDER BY a.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC));

            $response['parties'] =$pdo->query("
                SELECT p.partyname, 
                       (100 - COALESCE((SELECT SUM(percentage) FROM assignparties WHERE partyname = p.partyname), 0)) as available
                FROM parties p 
                ORDER BY p.partyname ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {$response['message'] = "Database error. (Check for existing constraints).";
    }

    echo json_encode($response);
    exit();
}

$pageTitle = "Party Assignment Mapping";
include __DIR__ . '/../includes/header.php';
?>

<div id="ajaxAlert"></div>

<div class="panel">
    <div class="panel-header" id="formHeader" style="margin-bottom: 8px; padding-bottom: 4px; font-size: 12px; font-weight: bold; color: var(--navy); border-bottom: 1px solid var(--border-light);">1. Assign Party to Active Employee (MR)</div>
    <form id="assignForm" onsubmit="submitForm(event); return false;" class="compact-form">
        <input type="hidden" name="id" id="recordId" value="">
        <input type="hidden" id="currentEditPct" value="0">
        
        <div class="form-grid" style="grid-template-columns: 1.5fr 1.5fr 0.5fr auto; align-items: end;">
            <div class="form-group">
                <label>Select Active Employee (MR)</label>
                <select name="empcode" id="aEmp" required>
                    <option value="">Loading Employees...</option>
                </select>
            </div>
            <div class="form-group">
                <label>Select Party / Client</label>
                <select name="partyname" id="aParty" required>
                    <option value="">Loading Parties...</option>
                </select>
            </div>
            <div class="form-group">
                <label>Share (%)</label>
                <input type="number" name="percentage" id="aPct" min="1" max="100" value="100" required>
            </div>
            <div style="display:flex; gap: 4px; margin-bottom: 2px;">
                <button type="submit" class="btn-submit" id="btnSubmit" disabled>Loading...</button>
                <button type="button" class="btn-cancel" id="btnCancel" onclick="cancelEdit()" style="display:none;">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-header" style="margin-bottom: 8px; padding-bottom: 4px; font-size: 12px; font-weight: bold; color: var(--navy); border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between;">
        <span>2. Currently Assigned Mappings (Click row to edit)</span>
    </div>
    <div class="table-container" style="max-height: 500px; overflow-y: auto;">
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 30px; text-align: center;">#</th>
                    <th>Emp Code</th>
                    <th>Employee Name</th>
                    <th>Headquarters</th>
                    <th>Assigned Party Name</th>
                    <th style="text-align: center; width: 100px;">Allocation</th>
                </tr>
            </thead>
            <tbody id="assignTableBody">
                <tr><td colspan="6" style="text-align: center; color: var(--ink-soft); padding: 15px;">Initializing Fast AJAX Load...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
    let dbEmployees = [];
    let dbParties = [];
    let dbAssignments = [];

    function showAlert(type, msg) {
        const alertBox = document.getElementById('ajaxAlert');
        alertBox.className = type === 'success' ? 'alert alert-success' : 'alert alert-danger';
        alertBox.style.display = 'block'; alertBox.innerHTML = msg;
        setTimeout(() => alertBox.style.display = 'none', 3000);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function loadInitialData() {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch_initial');

        fetch('assignparty.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                dbEmployees = data.employees;
                dbParties = data.parties;
                dbAssignments = data.assignments;
                
                populateEmployeeDropdown();
                populatePartyDropdown();
                renderTable();

                document.getElementById('btnSubmit').disabled = false;
                document.getElementById('btnSubmit').innerText = 'Save Assignment';
            } else {
                showAlert('error', 'Failed to load initial data.');
            }
        });
    }

    // High Performance DOM Update (String Building instead of slow appendChild loops)
    function populateEmployeeDropdown(selectedEmp = '') {
        let html = '<option value="">Select Employee</option>';
        dbEmployees.forEach(e => {
            let sel = e.empcode === selectedEmp ? 'selected' : '';
            html += `<option value="${escapeHtml(e.empcode)}" ${sel}>${escapeHtml(e.empname)} (${escapeHtml(e.hq)})</option>`;
        });
        document.getElementById('aEmp').innerHTML = html;
    }

    // High Performance DOM Update
    function populatePartyDropdown(selectedParty = '') {
        const currentEditPct = parseInt(document.getElementById('currentEditPct').value) || 0;
        let html = '<option value="">Select Party</option>';
        
        dbParties.forEach(p => {
            let avail = parseInt(p.available);
            if (p.partyname === selectedParty) { avail += currentEditPct; }
            if (avail > 0 || p.partyname === selectedParty) {
                let sel = p.partyname === selectedParty ? 'selected' : '';
                html += `<option value="${escapeHtml(p.partyname)}" ${sel}>${escapeHtml(p.partyname)} (${avail}% Avail)</option>`;
            }
        });
        document.getElementById('aParty').innerHTML = html;
    }

    // High Performance DOM Update
    function renderTable() {
        const tbody = document.getElementById('assignTableBody');
        if (dbAssignments.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--ink-soft); padding: 15px;">No assignments created yet.</td></tr>';
            return;
        }

        let html = '';
        dbAssignments.forEach((a, i) => {
            let pct = parseInt(a.percentage);
            let tagClass = pct === 100 ? 'tag tag-100' : 'tag';
            let escPartyStr = (a.partyname || '').replace(/'/g, "\\'"); 
            
            html += `<tr class="clickable-row" onclick="editRecord(${a.id}, '${a.empcode}', '${escPartyStr}', ${pct})">
                <td style="text-align: center;"><span style="color:var(--ink-soft); font-size:9px;">${i + 1}</span></td>
                <td><strong style="color:var(--sky); font-family:monospace;">${escapeHtml(a.empcode)}</strong></td>
                <td><strong style="color:var(--navy);">${escapeHtml(a.empname)}</strong></td>
                <td>${escapeHtml(a.hq)}</td>
                <td><strong>${escapeHtml(a.partyname)}</strong></td>
                <td style="text-align: center;"><span class="${tagClass}">${pct}%</span></td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function editRecord(id, empcode, party, pct) {
        document.getElementById('recordId').value = id;
        document.getElementById('aEmp').value = empcode;
        document.getElementById('aPct').value = pct;
        document.getElementById('currentEditPct').value = pct; 
        
        populatePartyDropdown(party);
        
        document.getElementById('formHeader').innerText = '1. Update Assignment Mapping';
        document.getElementById('btnSubmit').innerText = 'Update Assignment';
        document.getElementById('btnCancel').style.display = 'inline-flex';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function cancelEdit() {
        document.getElementById('assignForm').reset();
        document.getElementById('recordId').value = '';
        document.getElementById('currentEditPct').value = 0;
        populatePartyDropdown(); 
        
        document.getElementById('formHeader').innerText = '1. Assign Party to Active Employee (MR)';
        document.getElementById('btnSubmit').innerText = 'Save Assignment';
        document.getElementById('btnCancel').style.display = 'none';
    }

    function submitForm(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('assignForm'));
        formData.append('ajax_action', 'save');
        document.getElementById('btnSubmit').innerText = 'Processing...';
        document.getElementById('btnSubmit').disabled = true;

        fetch('assignparty.php', { method: 'POST', body: formData })
        .then(res => res.json()).then(data => {
            showAlert(data.status, data.message);
            if (data.status === 'success') { 
                dbAssignments = data.assignments;
                dbParties = data.parties;
                // Instantly update everything without lag
                populatePartyDropdown(); 
                renderTable();
                cancelEdit(); 
            }
        }).finally(() => {
            document.getElementById('btnSubmit').disabled = false;
            document.getElementById('btnSubmit').innerText = document.getElementById('recordId').value ? 'Update Assignment' : 'Save Assignment';
        });
    }

    document.addEventListener('DOMContentLoaded', loadInitialData);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>