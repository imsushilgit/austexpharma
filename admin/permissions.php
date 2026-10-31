<?php
require_once __DIR__ . '/../includes/core.php';

// Strict Admin Validation
$ctx = require_page_access($pdo);
if (!$ctx['is_admin']) { 
    header('Location: ../dashboard/dashboard.php?access=denied'); 
    exit; 
}

// Create Table
$pdo->exec("CREATE TABLE IF NOT EXISTS page_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    role_name VARCHAR(50) NOT NULL, 
    page_name VARCHAR(100) NOT NULL,
    can_view TINYINT(1) DEFAULT 1, 
    can_write TINYINT(1) DEFAULT 0,
    UNIQUE KEY uniq_role_page (role_name, page_name)
)");

// Fetch active departments strictly from the official 'departments' table
$departments = [];
try {
    $deptQuery = $pdo->query("SELECT deptname FROM departments ORDER BY deptname ASC");
    $rawDepts = $deptQuery->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rawDepts as $d) {
        $cleanName = trim($d);
        if (!empty($cleanName) && !in_array($cleanName, $departments)) {
            $departments[] = $cleanName;
        }
    }
} catch (Exception $e) {}

// Fallback if empty
if (empty($departments)) { $departments = ['Sales', 'Dispatch', 'Accounts', 'HR', 'Admin']; }

// Fetch active employees from table with cleaned departments and designations
$empData = [];
try {
    $empStmt = $pdo->query("
        SELECT empcode, empname, 
               COALESCE(NULLIF(TRIM(department), ''), 'General') AS dept, 
               COALESCE(NULLIF(TRIM(designation), ''), 'Staff') AS desig 
        FROM employees 
        WHERE resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00'
        ORDER BY empname ASC
    ");
    $empData = $empStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$empData[] = ['empcode' => 'ADMIN_ALL', 'empname' => 'All Administrators', 'dept' => 'Administration', 'desig' => 'Admin'];

// Complete Grouped Segment Matrix (Synced with sidebar.php)
$segments = [
    'Dashboard & Reports' => [
        'dashboard.php', 'salesdata.php', 'primary.php', 'primarysales.php', 
        'secondary.php', 'secondarysales.php', 'achievements.php', 'targets.php', 'salestrend.php',
        'notifications.php'
    ],
    'Sales, Orders & Billing' => [
        'orders.php', 'billing.php', 'partyrates.php', 'partyledger.php', 
        'collections.php', 'outstanding.php', 'salessupport.php'
    ],
    'Field Force Operations' => [
        'doctors.php', 'doctorrequestapproval.php', 'chemistrequest.php',
        'tourplan.php', 'tourplanapproval.php', 'tourplancalendar.php',
        'dailyvisit.php', 'secondaryentry.php', 'stockstatement.php', 'routemap.php', 
        'expense.php', 'expenseapproval.php', 'mystock.php'
    ],
    'HR & Payroll' => [
        'profile.php', 'attendance.php', 'attendancereport.php', 'holiday.php', 
        'leave.php', 'leaveapproval.php', 
        'salary.php', 'salarystructure.php', 'salaryslip.php', 'form16.php',
        'shift.php', 'fullfinal.php', 
        'advance.php', 'reimbursement.php', 'appraisal.php', 'hrletters.php', 'assets.php', 
        'training.php', 'resignedemployee.php', 'tada.php'
    ],
    'Master Data' => [
        'employees.php', 'master.php', 'geography.php', 'parties.php', 'assignparty.php', 
        'transporters.php', 'senders.php', 'products.php', 'brand.php', 'src.php'
    ],
    'Logistics & Config' => [
        'dispatch.php', 'stock.php', 'masterupload.php', 'cop.php', 'users.php', 
        'permissions.php', 'settings.php', 'systemlogs.php', 'backup.php'
    ]
];

// Handle AJAX
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // FETCH LOGIC
    if ($_POST['ajax_action'] === 'fetch') {
        $target = trim($_POST['target'] ?? '');
        $fallback = trim($_POST['fallback_role'] ?? ''); 
        
        $stmt = $pdo->prepare("SELECT page_name, can_view, can_write FROM page_permissions WHERE role_name = ?");
        $stmt->execute([$target]);
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fallback to role defaults if specific employee has no custom rules
        if (empty($perms) && !empty($fallback) && $target !== $fallback) {
            $stmt->execute([$fallback]);
            $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $map = []; 
        foreach($perms as $r) { 
            $map[$r['page_name']] = $r; 
        }
        echo json_encode(['status' => 'success', 'map' => $map]); 
        exit;
    }
    
    // SAVE LOGIC
    if ($_POST['ajax_action'] === 'save_role') {
        $targets = json_decode($_POST['target_names'] ?? '[]', true) ?: [];
        $data = json_decode($_POST['data_json'] ?? '[]', true) ?: [];
        
        if (empty($targets)) {
            echo json_encode(['status' => 'error', 'message' => 'No target selected for saving.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $del = $pdo->prepare("DELETE FROM page_permissions WHERE role_name = ?");
            $ins = $pdo->prepare("INSERT INTO page_permissions (role_name, page_name, can_view, can_write) VALUES (?, ?, ?, ?)");
            
            foreach ($targets as $target) {
                $del->execute([$target]);
                foreach($data as $d) {
                    if ($d['view'] == 1 || $d['write'] == 1) {
                        $ins->execute([$target, $d['page'], (int)$d['view'], (int)$d['write']]);
                    }
                }
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "Rights updated successfully!"]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // COPY LOGIC
    if ($_POST['ajax_action'] === 'copy_role') {
        $source = trim($_POST['source_role'] ?? '');
        $target = trim($_POST['target_role'] ?? '');
        
        if (empty($source) || empty($target)) {
            echo json_encode(['status' => 'error', 'message' => 'Source and target required.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            // Delete existing target permissions
            $del = $pdo->prepare("DELETE FROM page_permissions WHERE role_name = ?");
            $del->execute([$target]);
            
            // Fetch source permissions and insert into target
            $stmt = $pdo->prepare("SELECT page_name, can_view, can_write FROM page_permissions WHERE role_name = ?");
            $stmt->execute([$source]);
            $sourcePerms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($sourcePerms)) {
                $ins = $pdo->prepare("INSERT INTO page_permissions (role_name, page_name, can_view, can_write) VALUES (?, ?, ?, ?)");
                foreach ($sourcePerms as $p) {
                    $ins->execute([$target, $p['page_name'], (int)$p['can_view'], (int)$p['can_write']]);
                }
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "Rights copied successfully from $source!"]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid request']); 
    exit;
}

$pageTitle = "Permissions Matrix"; 
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <!-- Compact Cascading Filters with Database Integration -->
    <div class="filter-bar">
        <div class="filter-group">
            <label class="filter-label">Department</label>
            <select id="deptFilter" class="filter-select" onchange="populateDesignations()">
                <option value="">-- Select Department --</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Designation / Role</label>
            <select id="desigFilter" class="filter-select" disabled onchange="populateEmployees()">
                <option value="">-- Select Designation --</option>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Target Employee</label>
            <select id="empFilter" class="filter-select" disabled onchange="evaluateSelectionAndFetch()">
                <option value="">-- All (Designation Default) --</option>
            </select>
        </div>
    </div>

    <!-- Target Identifier Display -->
    <div style="margin-bottom: 12px; font-size: 9px; color: #1e2a78; font-weight: 600; text-transform: uppercase;">
        Configuring Matrix For: <span id="targetLabel" style="font-weight:800; border-bottom:1px solid #1e2a78;">No Selection</span>
    </div>

    <!-- Segments Grid -->
    <div class="segments-grid">
        <?php 
        $segIndex = 0;
        foreach ($segments as $segTitle => $pagesList): 
            $segId = 'seg_' . $segIndex++;
        ?>
        <div class="segment-card" id="<?= $segId ?>">
            <div class="segment-header">
                <div class="segment-title"><?= htmlspecialchars($segTitle) ?></div>
                <div class="segment-master-actions">
                    <button type="button" class="master-btn" onclick="setSegment('<?= $segId ?>', 0)">N</button>
                    <button type="button" class="master-btn" onclick="setSegment('<?= $segId ?>', 1)">V</button>
                    <button type="button" class="master-btn" onclick="setSegment('<?= $segId ?>', 2)">W</button>
                </div>
            </div>
            
            <div class="page-list">
                <?php foreach ($pagesList as $p): $pageId = str_replace('.', '_', $p); ?>
                <div class="page-item">
                    <span class="page-name"><?= htmlspecialchars($p) ?></span>
                    <div class="compact-radios" data-page="<?= htmlspecialchars($p) ?>">
                        <input type="radio" name="perm_<?= $pageId ?>" id="<?= $segId ?>_<?= $pageId ?>_0" value="0">
                        <label for="<?= $segId ?>_<?= $pageId ?>_0" title="None">N</label>
                        
                        <input type="radio" name="perm_<?= $pageId ?>" id="<?= $segId ?>_<?= $pageId ?>_1" value="1">
                        <label for="<?= $segId ?>_<?= $pageId ?>_1" title="View">V</label>
                        
                        <input type="radio" name="perm_<?= $pageId ?>" id="<?= $segId ?>_<?= $pageId ?>_2" value="2">
                        <label for="<?= $segId ?>_<?= $pageId ?>_2" title="Write">W</label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <button class="btn-save" id="saveBtn" onclick="savePermissions()" disabled><i class="fa-solid fa-check"></i> Apply Rights</button>
        
        <!-- NEW COPY FEATURE -->
        <div style="display: flex; align-items: center; gap: 6px; margin-top: 14px; margin-left: 20px; border-left: 1px solid #cbd5e1; padding-left: 20px;">
            <select id="copySource" class="filter-select" disabled>
                <option value="">-- Copy Rights From --</option>
            </select>
            <button class="btn-save" id="copyBtn" onclick="copyPermissions()" style="background: #059669; margin-top: 0;" disabled><i class="fa-solid fa-copy"></i> Copy To Target</button>
        </div>

        <span id="msg" style="font-weight: 700; font-size: 10px; color: #1e2a78; margin-top: 14px; margin-left: 15px;"></span>
    </div>
</div>

<script>
    const dbEmployees = <?= json_encode($empData) ?>;
    
    const deptEl = document.getElementById('deptFilter');
    const desigEl = document.getElementById('desigFilter');
    const empEl = document.getElementById('empFilter');
    
    let activeTargetForFetch = ''; 
    let activeFallback = ''; 

    // Populate Copy Dropdown on Load
    window.addEventListener('DOMContentLoaded', () => {
        const copySource = document.getElementById('copySource');
        const allDesigs = [...new Set(dbEmployees.map(e => e.desig))].sort();
        allDesigs.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d; opt.text = d;
            copySource.add(opt);
        });
    });

    function populateDesignations() {
        desigEl.innerHTML = '<option value="">-- Select Designation --</option>';
        empEl.innerHTML = '<option value="">-- All (Designation Default) --</option>';
        empEl.disabled = true;
        empEl.value = '';
        resetRadios();
        document.getElementById('saveBtn').disabled = true;
        document.getElementById('copySource').disabled = true;
        document.getElementById('copyBtn').disabled = true;

        const selDept = deptEl.value;
        if (!selDept) { desigEl.disabled = true; return; }

        desigEl.disabled = false;
        const desigs = [...new Set(dbEmployees.filter(e => e.dept.toLowerCase() === selDept.toLowerCase()).map(e => e.desig))].sort();
        
        desigs.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d; opt.text = d;
            desigEl.add(opt);
        });
    }

    function populateEmployees() {
        const selDept = deptEl.value;
        const selDesig = desigEl.value;
        
        empEl.innerHTML = '<option value="">-- All (Designation Default) --</option>';
        
        if (!selDesig) { 
            empEl.disabled = true; 
            document.getElementById('saveBtn').disabled = true;
            document.getElementById('copySource').disabled = true;
            document.getElementById('copyBtn').disabled = true;
            resetRadios();
            return; 
        }

        empEl.disabled = false;
        document.getElementById('saveBtn').disabled = false;
        
        const emps = dbEmployees.filter(e => e.dept.toLowerCase() === selDept.toLowerCase() && e.desig.toLowerCase() === selDesig.toLowerCase());
        emps.forEach(e => {
            if(e.empcode !== 'ADMIN_ALL') {
                const opt = document.createElement('option');
                opt.value = e.empcode; 
                opt.text = `${e.empname} (${e.empcode})`;
                empEl.add(opt);
            }
        });
        
        evaluateSelectionAndFetch();
    }

    function evaluateSelectionAndFetch() {
        const selDesig = desigEl.value;
        const selEmp = empEl.value; 

        if (selEmp) {
            activeTargetForFetch = selEmp;
            activeFallback = selDesig; 
            document.getElementById('targetLabel').innerText = empEl.options[empEl.selectedIndex].text;
            document.getElementById('copySource').disabled = false;
            document.getElementById('copyBtn').disabled = false;
        } else if (selDesig) {
            activeTargetForFetch = selDesig;
            activeFallback = '';
            document.getElementById('targetLabel').innerText = `All ${selDesig}s (Role Default)`;
            document.getElementById('copySource').disabled = false;
            document.getElementById('copyBtn').disabled = false;
        } else {
            document.getElementById('copySource').disabled = true;
            document.getElementById('copyBtn').disabled = true;
            resetRadios();
            return;
        }

        fetchPermissions();
    }

    function fetchPermissions() {
        const fd = new FormData();
        fd.append('ajax_action', 'fetch');
        fd.append('target', activeTargetForFetch);
        fd.append('fallback_role', activeFallback);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            applyPermissionsToUI(d.map || {}, activeTargetForFetch.toLowerCase() === 'admin');
        });
    }

    function applyPermissionsToUI(map, isAdmin) {
        document.querySelectorAll('.compact-radios').forEach(group => {
            const page = group.getAttribute('data-page');
            const rowData = map[page];
            
            const rad0 = group.querySelector('input[value="0"]');
            const rad1 = group.querySelector('input[value="1"]');
            const rad2 = group.querySelector('input[value="2"]');
            
            if (!rowData && isAdmin) {
                rad2.checked = true; 
            } else if (!rowData) {
                rad0.checked = true; 
            } else {
                if (rowData.can_write == 1) rad2.checked = true;
                else if (rowData.can_view == 1) rad1.checked = true;
                else rad0.checked = true;
            }
        });
    }

    function resetRadios() {
        document.getElementById('targetLabel').innerText = 'No Selection';
        document.querySelectorAll('.compact-radios').forEach(g => {
            g.querySelector('input[value="0"]').checked = true;
        });
    }

    function setSegment(segmentId, levelValue) {
        if (!activeTargetForFetch) return;
        const segmentBox = document.getElementById(segmentId);
        if (segmentBox) {
            segmentBox.querySelectorAll(`input[value="${levelValue}"]`).forEach(r => r.checked = true);
        }
    }

    function savePermissions() {
        if (!activeTargetForFetch) return;
        
        const btn = document.getElementById('saveBtn');
        const msg = document.getElementById('msg');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
        btn.disabled = true;
        
        let targetsToSave = [activeTargetForFetch];
        
        const payload = [];
        document.querySelectorAll('.compact-radios').forEach(group => {
            const page = group.getAttribute('data-page');
            const checkedRad = group.querySelector('input:checked');
            if (checkedRad) {
                const val = parseInt(checkedRad.value);
                if (val > 0) { 
                    payload.push({ page: page, view: 1, write: (val === 2) ? 1 : 0 });
                }
            }
        });

        const fd = new FormData();
        fd.append('ajax_action', 'save_role');
        fd.append('target_names', JSON.stringify(targetsToSave));
        fd.append('data_json', JSON.stringify(payload));
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                msg.style.color = '#059669';
                msg.innerHTML = '<i class="fa-solid fa-check"></i> ' + d.message;
            } else {
                msg.style.color = '#dc2626';
                msg.innerText = d.message;
            }
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Apply Rights';
            btn.disabled = false;
            setTimeout(() => { msg.innerHTML = ''; }, 3000);
        }).catch(err => {
            msg.style.color = '#dc2626';
            msg.innerText = 'Network connection error.';
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Apply Rights';
            btn.disabled = false;
        });
    }

    function copyPermissions() {
        if (!activeTargetForFetch) return;
        
        const sourceRole = document.getElementById('copySource').value;
        if (!sourceRole) {
            alert("Please select a designation to copy rights from.");
            return;
        }
        if (sourceRole === activeTargetForFetch) {
            alert("Source and target cannot be the same.");
            return;
        }

        if (!confirm(`Overwrite all rights for ${activeTargetForFetch} with the matrix from ${sourceRole}?`)) {
            return;
        }

        const btn = document.getElementById('copyBtn');
        const msg = document.getElementById('msg');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Copying...';
        btn.disabled = true;

        const fd = new FormData();
        fd.append('ajax_action', 'copy_role');
        fd.append('source_role', sourceRole);
        fd.append('target_role', activeTargetForFetch);

        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                msg.style.color = '#059669';
                msg.innerHTML = '<i class="fa-solid fa-check"></i> ' + d.message;
                fetchPermissions(); // Reload UI matrix visually
            } else {
                msg.style.color = '#dc2626';
                msg.innerText = d.message;
            }
            btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy To Target';
            btn.disabled = false;
            setTimeout(() => { msg.innerHTML = ''; }, 3000);
        }).catch(err => {
            msg.style.color = '#dc2626';
            msg.innerText = 'Network error.';
            btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy To Target';
            btn.disabled = false;
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>