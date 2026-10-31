<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access. Admins only."]);
        exit();
    }
    header("Location: ../dashboard/dashboard.php");
    exit();
}

require_once __DIR__ . '/../includes/core.php';

// Fetch dynamic departments
$departments = [];
try {
    $deptQuery = $pdo->query("SELECT deptname FROM departments ORDER BY deptname ASC");
    $rawDepts = $deptQuery->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rawDepts as $d) {
        $cleanName = ucfirst(strtolower(trim($d)));
        if (!in_array($cleanName, $departments)) {
            $departments[] = $cleanName;
        }
    }
    if (!in_array('Sales', $departments)) { $departments[] = 'Sales'; } 
} catch (Exception $e) {}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    verify_csrf(); 
    
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'save_access') {
            $empcode = trim($_POST['empcode'] ?? '');
            $status = trim($_POST['status'] ?? 'Unlocked');
            $custom_password = trim($_POST['custom_password'] ?? '');
            $max_attempts = (int)($_POST['max_attempts'] ?? 5);

            if (empty($empcode)) {
                echo json_encode(["status" => "error", "message" => "No employee selected."]);
                exit();
            }

            $empStmt = $pdo->prepare("SELECT empname, designation, email, mobile FROM employees WHERE empcode = ? LIMIT 1");
            $empStmt->execute([$empcode]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

            if (!$emp) {
                echo json_encode(["status" => "error", "message" => "Employee record not found."]);
                exit();
            }

            $finalRole = ($status === 'Locked') ? 'Locked' : ($emp['designation'] ?: 'Staff');

            // Fetch existing user to prevent username override
            $userStmt = $pdo->prepare("SELECT id, username, failed_attempts FROM users WHERE empcode = ? LIMIT 1");
            $userStmt->execute([$empcode]);
            $existingUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                // UPDATE EXISTING USER
                $failed_attempts = ($status === 'Unlocked') ? 0 : ($existingUser['failed_attempts'] ?? 0);
                $passwordHash = !empty($custom_password) ? password_hash($custom_password, PASSWORD_DEFAULT) : null;

                if ($passwordHash) {
                    $stmt = $pdo->prepare("UPDATE users SET fullname=?, email=?, mobile=?, role=?, max_attempts=?, failed_attempts=?, password=? WHERE id=?");
                    $stmt->execute([$emp['empname'], $emp['email'], $emp['mobile'], $finalRole, $max_attempts, $failed_attempts, $passwordHash, $existingUser['id']]);
                    $msg = "Access updated and password reset successfully!";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET fullname=?, email=?, mobile=?, role=?, max_attempts=?, failed_attempts=? WHERE id=?");
                    $stmt->execute([$emp['empname'], $emp['email'], $emp['mobile'], $finalRole, $max_attempts, $failed_attempts, $existingUser['id']]);
                    $msg = "User access status updated successfully!";
                }
                $response = ["status" => "success", "message" => $msg];

            } else {
                // INSERT NEW USER (Generate Unique Username)
                $firstNameStr = explode(' ', trim($emp['empname']))[0];
                $cleanFirstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstNameStr));
                $codeDigitsOnly = preg_replace('/\D/', '', $empcode); 
                $username = $cleanFirstName . $codeDigitsOnly; 

                // Uniqueness Check
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $checkStmt->execute([$username]);
                if ($checkStmt->rowCount() > 0) {
                    echo json_encode(["status" => "error", "message" => "Generated User ID '$username' is already taken!"]);
                    exit();
                }

                $passwordHash = !empty($custom_password) ? password_hash($custom_password, PASSWORD_DEFAULT) : password_hash(ucfirst($cleanFirstName) . '@' . $codeDigitsOnly . '#', PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users (empcode, username, fullname, email, mobile, password, role, max_attempts, failed_attempts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([$empcode, $username, $emp['empname'], $emp['email'], $emp['mobile'], $passwordHash, $finalRole, $max_attempts]);
                $response = ["status" => "success", "message" => "New user identity created successfully!"];
            }
        }

        if ($response['status'] === 'success') {
            $response['data'] = getCombinedUsersData($pdo);
        }
    } catch (PDOException $e) {
        $response['message'] = "Database error: " . $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

function getCombinedUsersData($pdo) {
    $employees = $pdo->query("
        SELECT empcode, empname, division, state, hq, department, designation, mobile, email 
        FROM employees 
        WHERE (hq IS NOT NULL AND hq != '') 
          AND (reporting_manager IS NOT NULL AND reporting_manager != '') 
          AND (resignation_date IS NULL OR resignation_date = '0000-00-00' OR resignation_date = '')
        ORDER BY empname ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    try {
        $users = $pdo->query("SELECT id, empcode, username, role, max_attempts, failed_attempts, createdat FROM users")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $users = $pdo->query("SELECT id, empcode, username, role, createdat FROM users")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $userMap = [];
    foreach ($users as $u) { $userMap[$u['empcode']] = $u; }

    $combined = [];
    foreach ($employees as $emp) {
        $matchedUser = $userMap[$emp['empcode']] ?? null;
        $isLocked = ($matchedUser && $matchedUser['role'] === 'Locked');
        $displayRole = $isLocked ? $emp['designation'] : ($matchedUser['role'] ?? 'Unregistered');
        
        $combined[] = [
            'empcode' => $emp['empcode'],
            'empname' => $emp['empname'],
            'division' => $emp['division'] ?: '-',
            'state' => $emp['state'] ?: '-',
            'hq' => $emp['hq'],
            'department' => $emp['department'] ?: 'Unknown',
            'designation' => $emp['designation'] ?: '-',
            'mobile' => $emp['mobile'] ?: '-',
            'email' => $emp['email'] ?: '-',
            'username' => $matchedUser['username'] ?? '-',
            
            // STRICTLY FETCHED FROM DATABASE (No hardcoded fallback here)
            'max_attempts' => $matchedUser['max_attempts'] ?? null,
            'failed_attempts' => $matchedUser['failed_attempts'] ?? null,
            
            'is_registered' => !empty($matchedUser),
            'is_locked' => $isLocked,
            'role_display' => $displayRole,
            'updated_by' => $matchedUser ? 'System Admin' : '-',
            'updated_on' => $matchedUser['createdat'] ?? '-'
        ];
    }
    return $combined;
}

$combinedUsers = getCombinedUsersData($pdo);
$pageTitle = "User Access & Identity Management";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title">1. Employee Directory Filter & Configuration</div>
    
    <div class="sky-bordered-row filter-row">
        <div class="radio-bar" style="flex-grow: 1;">
            <span style="font-size: 10px; font-weight: 800; color: #1e2a78; text-transform: uppercase; margin-right: 8px;">
                <i class="fa-solid fa-filter" style="color: #64748b;"></i> Department:
            </span>
            <label class="radio-label"><input type="radio" name="deptFilter" value="All" onchange="filterTable()"> All</label>
            <?php foreach ($departments as $dept): ?>
                <label class="radio-label">
                    <input type="radio" name="deptFilter" value="<?= htmlspecialchars($dept) ?>" <?= ($dept === 'Sales') ? 'checked' : '' ?> onchange="filterTable()"> 
                    <?= htmlspecialchars($dept) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="tableSearch" class="form-control" placeholder="Global search..." onkeyup="filterTable()">
        </div>
    </div>

    <form id="accessForm" onsubmit="submitForm(event)">
        <input type="hidden" name="empcode" id="actionEmpCode" value="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        
        <div class="sky-bordered-row action-row">
            <div class="action-info">
                <div class="emp-name-display" id="displayName">Select an employee below</div>
                <div class="emp-code-display" id="displayCode">Code: -</div>
            </div>

            <div class="status-toggles">
                <label class="radio-label" style="color: #166534;">
                    <input type="radio" name="status" id="statusUnlocked" value="Unlocked"> Unlocked / Active
                </label>
                <label class="radio-label" style="color: #991b1b;">
                    <input type="radio" name="status" id="statusLocked" value="Locked"> Locked
                </label>
                <div style="border-left: 1px dotted #cbd5e1; height: 16px; margin: 0 4px;"></div>
                <div style="display:flex; align-items:center; gap:5px;">
                    <span style="font-size: 9px; font-weight: 800; color: #1e2a78; text-transform: uppercase;">Max Attempts:</span>
                    <input type="number" name="max_attempts" id="maxAttempts" class="form-control" style="width: 50px; height: 22px; padding: 2px 4px; text-align: center; border-color: #93c5fd;" value="5" min="1" max="15">
                </div>
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="button" class="btn btn-secondary" onclick="applyDefaultPassword()"><i class="fa-solid fa-magic"></i> Default</button>
                <button type="button" class="btn btn-danger" onclick="applyRandomPassword()"><i class="fa-solid fa-shuffle"></i> Reset</button>
                <button type="button" class="btn btn-secondary" style="color:#b91c1c; border-color:#fca5a5;" onclick="cancelAction()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmit"><i class="fa-solid fa-check-double"></i> Save Updates</button>
            </div>
        </div>

        <div id="passwordDisplayBox" class="sky-bordered-row password-row" style="display: none;">
            <span style="font-size: 11px; font-weight: 800; text-transform: uppercase;" id="pwdLabel">New Password:</span>
            <input type="text" name="custom_password" id="customPasswordInput" class="form-control" style="width: 250px; font-family: monospace; font-size: 15px; font-weight: 800; letter-spacing: 0.5px; text-align: center; color:#0f172a; border-color: transparent; background: #ffffff;">
            <span style="font-size: 9px; color: #64748b;">(Displayed here before saving. Edit manually if needed.)</span>
        </div>
    </form>
</div>

<div class="panel-box">
    <div class="panel-title">2. Eligible Employee Access Ledger</div>
    <div class="table-container">
        <table class="compact-table" id="empTable">
            <thead>
                <tr>
                    <th style="width: 30px; text-align: center;">#</th>
                    <th>Division</th>
                    <th>Role</th>
                    <th>Emp Code</th>
                    <th>Emp Name</th>
                    <th>Username</th>
                    <th>State</th>
                    <th>HQ</th>
                    <th style="text-align: center;">Locked?</th>
                    <th>Mobile No.</th>
                    <th>Email Id</th>
                    <th style="text-align: center;">Failed Attempts</th>
                    <th>Latest Updated By</th>
                    <th>Latest Updated On</th>
                </tr>
                <tr class="col-search-row">
                    <th></th>
                    <th><input type="text" class="col-search-input" data-col="1" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="2" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="3" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="4" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="5" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="6" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="7" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="8" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="9" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="10" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="11" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="12" placeholder="Search..." onkeyup="filterTable()"></th>
                    <th><input type="text" class="col-search-input" data-col="13" placeholder="Search..." onkeyup="filterTable()"></th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php if (empty($combinedUsers)): ?>
                    <tr><td colspan="14" style="text-align: center; color: #64748b; padding: 20px;">No eligible active records found.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($combinedUsers as $u): ?>
                    <tr data-dept="<?= htmlspecialchars($u['department']) ?>" 
                        data-search="<?= strtolower(htmlspecialchars($u['empcode'] . ' ' . $u['empname'] . ' ' . $u['username'] . ' ' . $u['hq'] . ' ' . $u['state'] . ' ' . $u['mobile'])) ?>"
                        onclick="selectRecord(this, '<?= htmlspecialchars($u['empcode'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['empname'], ENT_QUOTES) ?>', <?= $u['is_locked'] ? 'true' : 'false' ?>, <?= $u['is_registered'] ? 'true' : 'false' ?>, <?= json_encode($u['max_attempts']) ?>)">
                        
                        <td style="text-align: center; color: #64748b;"><?= $i++; ?></td>
                        <td><?= htmlspecialchars($u['division']); ?></td>
                        <td style="font-weight: 600; color: #0ea5e9;"><?= htmlspecialchars($u['designation']); ?></td>
                        <td style="font-family: monospace; font-weight: bold; color: #0f172a;"><?= htmlspecialchars($u['empcode']); ?></td>
                        <td style="font-weight: bold; color: #1e3a8a;"><?= htmlspecialchars($u['empname']); ?></td>
                        <td style="font-family: monospace; font-weight: bold; color: <?= $u['is_registered'] ? '#16a34a' : '#94a3b8' ?>;"><?= htmlspecialchars($u['username']); ?></td>
                        <td><?= htmlspecialchars($u['state']); ?></td>
                        <td style="font-weight: 600;"><?= htmlspecialchars($u['hq']); ?></td>
                        <td style="text-align: center;">
                            <?php if (!$u['is_registered']): ?>
                                <span style="color:#94a3b8; font-size:10px;">-</span>
                            <?php else: ?>
                                <?= $u['is_locked'] ? '<span class="badge-locked">YES</span>' : '<span class="badge-active">NO</span>' ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($u['mobile']); ?></td>
                        <td><?= htmlspecialchars($u['email']); ?></td>
                        <td style="text-align: center;">
                            <?php if (!$u['is_registered'] || $u['max_attempts'] === null): ?>
                                <span style="color:#94a3b8; font-size:10px;">-</span>
                            <?php else: ?>
                                <?php 
                                    $attemptColor = ($u['failed_attempts'] > 0) ? '#991b1b' : '#166534';
                                    $attemptBg = ($u['failed_attempts'] > 0) ? '#fee2e2' : '#dcfce7';
                                    $attemptBorder = ($u['failed_attempts'] > 0) ? '#fca5a5' : '#bbf7d0';
                                ?>
                                <span class="badge-attempt" style="color: <?= $attemptColor ?>; background: <?= $attemptBg ?>; border-color: <?= $attemptBorder ?>;">
                                    <?= $u['failed_attempts'] ?> / <?= $u['max_attempts'] ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 10px; color: #64748b;"><?= htmlspecialchars($u['updated_by']); ?></td>
                        <td style="font-family: monospace; font-size: 9px; color: #64748b;"><?= htmlspecialchars($u['updated_on']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        filterTable(); 
    });

    function filterTable() {
        const globalQuery = document.getElementById('tableSearch').value.toLowerCase();
        const selectedDeptElement = document.querySelector('input[name="deptFilter"]:checked');
        const selectedDept = selectedDeptElement ? selectedDeptElement.value.toLowerCase() : 'all';
        
        const colInputs = document.querySelectorAll('.col-search-input');
        const rows = document.querySelectorAll('#userTableBody tr');

        rows.forEach(row => {
            if (!row.getAttribute('data-dept')) return;
            
            let isVisible = true;

            const rowDept = row.getAttribute('data-dept').toLowerCase();
            if (selectedDept !== 'all' && rowDept !== selectedDept) isVisible = false;

            const searchStr = row.getAttribute('data-search');
            if (globalQuery && !searchStr.includes(globalQuery)) isVisible = false;

            colInputs.forEach(input => {
                const val = input.value.toLowerCase();
                if (val) {
                    const colIndex = parseInt(input.getAttribute('data-col'));
                    const cell = row.cells[colIndex];
                    if (cell && !cell.innerText.toLowerCase().includes(val)) {
                        isVisible = false;
                    }
                }
            });

            row.style.display = isVisible ? '' : 'none';
        });
    }

    let currentComputedPassword = '';

    function selectRecord(row, empcode, empname, isLocked, isRegistered, maxAttempts) {
        document.querySelectorAll('#userTableBody tr').forEach(r => r.classList.remove('selected'));
        row.classList.add('selected');

        document.getElementById('accessForm').classList.add('active');
        document.getElementById('actionEmpCode').value = empcode;
        document.getElementById('displayName').innerText = empname;
        document.getElementById('displayCode').innerText = "System Code: " + empcode;
        
        // Uses the DB fetched maxAttempts, or falls back to 5 ONLY if empty/null for new users in form UI
        document.getElementById('maxAttempts').value = maxAttempts || 5; 
        
        const firstName = empname.split(' ')[0];
        const formattedName = firstName.charAt(0).toUpperCase() + firstName.slice(1).toLowerCase();
        const codeDigits = empcode.replace(/\D/g, ''); 
        currentComputedPassword = formattedName + '@' + codeDigits + '#';
        
        document.getElementById('passwordDisplayBox').style.display = 'none';
        document.getElementById('customPasswordInput').value = '';
        
        if (isRegistered) {
            if (isLocked) document.getElementById('statusLocked').checked = true;
            else document.getElementById('statusUnlocked').checked = true;
        } else {
            document.getElementById('statusUnlocked').checked = true;
        }
        
        document.getElementById('accessForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function cancelAction() {
        document.getElementById('accessForm').classList.remove('active');
        document.getElementById('accessForm').reset();
        document.getElementById('passwordDisplayBox').style.display = 'none';
        document.getElementById('customPasswordInput').value = '';
        document.getElementById('actionEmpCode').value = '';
        
        document.querySelectorAll('#userTableBody tr').forEach(r => r.classList.remove('selected'));
    }

    function applyDefaultPassword() {
        document.getElementById('customPasswordInput').value = currentComputedPassword;
        const box = document.getElementById('passwordDisplayBox');
        box.style.display = 'flex';
        box.style.background = '#f0fdf4';
        box.style.borderColor = '#22c55e';
        
        const label = document.getElementById('pwdLabel');
        label.innerText = "Default Logic Password:";
        label.style.color = '#166534';
    }

    function applyRandomPassword() {
        const chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789@#$*"; 
        let randomPass = "";
        for (let i = 0; i < 8; i++) {
            randomPass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('customPasswordInput').value = randomPass;
        
        const box = document.getElementById('passwordDisplayBox');
        box.style.display = 'flex';
        box.style.background = '#eff6ff';
        box.style.borderColor = '#3b82f6';
        
        const label = document.getElementById('pwdLabel');
        label.innerText = "System Generated Password:";
        label.style.color = '#1e40af';
    }

    function submitForm(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('accessForm'));
        formData.append('ajax_action', 'save_access');

        const submitBtn = document.getElementById('btnSubmit');
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json()).then(data => {
            if (data.status === 'success') {
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                setTimeout(() => location.reload(), 800);
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
                submitBtn.innerHTML = '<i class="fa-solid fa-check-double"></i> Save Updates';
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error("Network Error.");
            submitBtn.innerHTML = '<i class="fa-solid fa-check-double"></i> Save Updates';
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>