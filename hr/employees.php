<?php
ob_start();
require_once __DIR__ . '/../includes/core.php';

// Unauthenticated AJAX Requests handling
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
        exit();
    }
    header("Location: ../auth/login.php");
    exit();
}

// Fetch Active Employees Only
// Fetch Active Employees Only (Duplicates Fixed)
$empFetchQuery = "
    SELECT e.*, 
           COALESCE(
               (SELECT m.empname FROM employees m 
                WHERE m.empcode = e.reporting_manager OR m.empname = e.reporting_manager 
                LIMIT 1), 
           e.reporting_manager) as display_manager 
    FROM employees e 
    WHERE e.resignation_date IS NULL OR e.resignation_date = '0000-00-00' OR e.resignation_date = ''
    ORDER BY e.empname ASC
";

// ==========================================
// 1. BACKEND AJAX HANDLER (SAFE CRUD ENGINE)
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'generate_empcode') {
            $stmt = $pdo->query("SELECT empcode FROM employees WHERE empcode LIKE 'E%' ORDER BY LENGTH(empcode) DESC, empcode DESC LIMIT 1");
            $lastCode = $stmt->fetchColumn();

            if ($lastCode && preg_match('/^E(\d+)$/', $lastCode, $matches)) {
                $nextCode = 'E' . str_pad(intval($matches[1]) + 1, strlen($matches[1]), '0', STR_PAD_LEFT);
            } else {
                $nextCode = 'E00001'; 
            }
            echo json_encode(["status" => "success", "empcode" => $nextCode]);
            exit();
            
        } elseif ($action === 'save') {
            // ROLE SCOPE: only admin, or a user with write permission editing someone inside their own team
            if (!$ctx['is_admin'] && (empty($ctx['can_write']) || !can_access_emp($ctx, $_POST['empcode'] ?? ''))) {
                echo json_encode(['status' => 'error', 'message' => 'Access Denied: you can only edit employees in your own team.']); exit();
            }
            $id = $_POST['id'] ?? '';
            $division = trim($_POST['division'] ?? '');
            $empcode = trim($_POST['empcode'] ?? '');
            $empname = trim($_POST['empname'] ?? '');
            $hq = trim($_POST['hq'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $designation = trim($_POST['designation'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $reporting_manager = trim($_POST['reporting_manager'] ?? ''); 
            $state = trim($_POST['state'] ?? '');
            
            $gender = trim($_POST['gender'] ?? '');
            $marital_status = trim($_POST['marital_status'] ?? '');
            
            $joining_date = empty($_POST['joining_date']) ? null : $_POST['joining_date'];
            $resignation_date = empty($_POST['resignation_date']) ? null : $_POST['resignation_date'];
            $dob = empty($_POST['dob']) ? null : $_POST['dob'];
            $anniversary_date = empty($_POST['anniversary_date']) ? null : $_POST['anniversary_date'];
            
            // Logic: Clear anniversary date if not married
            if ($marital_status !== 'Married') {
                $anniversary_date = null;
            }
            
            $bank_name = trim($_POST['bank_name'] ?? '');
            $bank_account = trim($_POST['bank_account'] ?? '');
            $ifsc_code = trim($_POST['ifsc_code'] ?? '');
            $pan_no = trim($_POST['pan_no'] ?? '');
            $aadhar_no = trim($_POST['aadhar_no'] ?? '');
            $dl_no = trim($_POST['dl_no'] ?? '');
            $education = trim($_POST['education'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if (empty($division) || empty($empcode) || empty($empname) || empty($department) || empty($designation) || empty($state) || empty($hq)) {
                echo json_encode(["status" => "error", "message" => "All mandatory fields must be filled completely."]);
                exit();
            }

            // RESIGNATION LOGIC (CONVERT TO VAC CODE)
            $old_empcode = $empcode;
            if ($resignation_date !== null && strpos($empcode, 'VAC') !== 0) {
                $vacStmt = $pdo->query("SELECT empcode FROM employees WHERE empcode LIKE 'VAC%' ORDER BY LENGTH(empcode) DESC, empcode DESC LIMIT 1");
                $lastVacCode = $vacStmt->fetchColumn();
                if ($lastVacCode && preg_match('/^VAC(\d+)$/', $lastVacCode, $m)) {
                    $empcode = 'VAC' . str_pad(intval($m[1]) + 1, 3, '0', STR_PAD_LEFT);
                } else {
                    $empcode = 'VAC001';
                }
            }

            $sql = "SELECT id FROM employees WHERE empcode = ?";
            $params = [$empcode];
            if (!empty($id)) { $sql .= " AND id != ?"; $params[] = $id; }
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            if ($stmt->rowCount() > 0) { echo json_encode(["status" => "error", "message" => "Generated Employee Code already exists!"]); exit(); }

            if (!empty($id)) {
                $stmt = $pdo->prepare("UPDATE employees SET division=?, empcode=?, empname=?, department=?, designation=?, mobile=?, email=?, reporting_manager=?, hq=?, state=?, joining_date=?, resignation_date=?, dob=?, bank_account=?, ifsc_code=?, pan_no=?, aadhar_no=?, dl_no=?, education=?, address=?, gender=?, marital_status=?, anniversary_date=?, bank_name=? WHERE id=?");
                $stmt->execute([$division, $empcode, $empname, $department, $designation, $mobile, $email, $reporting_manager, $hq, $state, $joining_date, $resignation_date, $dob, $bank_account, $ifsc_code, $pan_no, $aadhar_no, $dl_no, $education, $address, $gender, $marital_status, $anniversary_date, $bank_name, $id]);
                
                // Cascade VAC code to history tables
                if ($old_empcode !== $empcode) {
                    $tables = ['targets', 'collections', 'assignparties', 'src_master', 'route_party_map', 'hr_letters', 'primarysales', 'secondarysales', 'sales', 'achievements'];
                    foreach ($tables as $tbl) {
                        try { $pdo->prepare("UPDATE $tbl SET empcode = ? WHERE empcode = ?")->execute([$empcode, $old_empcode]); } catch(Exception $e) {}
                    }
                    try { $pdo->prepare("UPDATE employees SET reporting_manager = ? WHERE reporting_manager = ?")->execute([$empcode, $old_empcode]); } catch(Exception $e) {}
                }
                $response = ["status" => "success", "message" => "Employee updated successfully!" . ($old_empcode !== $empcode ? " (Code changed to $empcode)" : "")];
            } else {
                $stmt = $pdo->prepare("INSERT INTO employees (division, empcode, empname, department, designation, mobile, email, reporting_manager, hq, state, joining_date, resignation_date, dob, bank_account, ifsc_code, pan_no, aadhar_no, dl_no, education, address, gender, marital_status, anniversary_date, bank_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$division, $empcode, $empname, $department, $designation, $mobile, $email, $reporting_manager, $hq, $state, $joining_date, $resignation_date, $dob, $bank_account, $ifsc_code, $pan_no, $aadhar_no, $dl_no, $education, $address, $gender, $marital_status, $anniversary_date, $bank_name]);
                $response = ["status" => "success", "message" => "Employee added successfully!"];
            }
        }

        if ($response['status'] === 'success') {
            $response['data'] = scope_filter_rows($ctx, $pdo->query($empFetchQuery)->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (PDOException $e) { $response['message'] = "Database Error. Try refreshing."; }
    echo json_encode($response); exit();
}

$divisions = $pdo->query("SELECT divname FROM divisions ORDER BY divname ASC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT deptname FROM departments ORDER BY deptname ASC")->fetchAll(PDO::FETCH_ASSOC);
$designations = $pdo->query("SELECT d.designame, dp.deptname FROM designations d JOIN departments dp ON d.deptid = dp.deptid")->fetchAll(PDO::FETCH_ASSOC);
$states = $pdo->query("SELECT statename FROM states ORDER BY statename ASC")->fetchAll(PDO::FETCH_ASSOC);
try { $hqs = $pdo->query("SELECT h.hqname, s.statename FROM hqs h JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $hqs = $pdo->query("SELECT hqname, '' as statename FROM hqs ORDER BY hqname ASC")->fetchAll(PDO::FETCH_ASSOC); }

$employees = scope_filter_rows($ctx, $pdo->query($empFetchQuery)->fetchAll(PDO::FETCH_ASSOC));
$allEmployees = scope_filter_rows($ctx, $pdo->query("SELECT * FROM employees ORDER BY empname ASC")->fetchAll(PDO::FETCH_ASSOC));

$pageTitle = "Employees Master";
include __DIR__ . '/../includes/header.php';
?>


<!-- Compact Employee Form -->
<div class="panel-box">
    <div class="panel-title" id="formHeader">1. Add / Update Employee</div>
    <form id="employeeForm" onsubmit="submitForm(event)" class="compact-form">
        <input type="hidden" name="id" id="recordId" value="">
        
        <!-- Row 1: Basic Job Info -->
        <div class="form-grid" style="grid-template-columns: 1fr 1fr 2fr 1fr 1.5fr;">
            <div class="form-group"><label>Division *</label><select name="division" id="divSelect" onchange="generateNewEmpCode()" required><option value="">Select Division</option><?php foreach($divisions as $div): ?><option value="<?= htmlspecialchars($div['divname']) ?>"><?= htmlspecialchars($div['divname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Emp Code *</label><input type="text" name="empcode" id="eCode" required placeholder="Auto" readonly style="color:#0ea5e9; font-weight:bold; background:#f1f5f9;"></div>
            <div class="form-group"><label>Employee Name *</label><input type="text" name="empname" id="eName" required placeholder="Full Legal Name"></div>
            <div class="form-group"><label>State *</label><select name="state" id="stateSelect" onchange="filterHQs()" required><option value="">Select State</option><?php foreach($states as $s): ?><option value="<?= htmlspecialchars($s['statename']) ?>"><?= htmlspecialchars($s['statename']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>HQ *</label><select name="hq" id="hqSelect" required><option value="">Select HQ</option></select></div>
        </div>

        <!-- Row 2: Role & Contact -->
        <div class="form-grid" style="grid-template-columns: 1fr 1.5fr 1.5fr 1fr 1.5fr;">
            <div class="form-group"><label>Department *</label><select name="department" id="deptSelect" onchange="filterDesignations()" required><option value="">Select Dept</option><?php foreach($departments as $d): ?><option value="<?= htmlspecialchars($d['deptname']) ?>"><?= htmlspecialchars($d['deptname']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Designation *</label><select name="designation" id="desigSelect" onchange="filterManagers(this.value)" required><option value="">Select Designation</option></select></div>
            <div class="form-group"><label>Reporting Manager</label><select name="reporting_manager" id="eRepMgr"><option value="">Select Manager</option></select></div>
            <div class="form-group"><label>Mobile No.</label><input type="text" name="mobile" id="eMobile" placeholder="10-digit mobile"></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" id="eEmail" placeholder="email@domain.com"></div>
        </div>

        <!-- Row 3: Personal & Status Dates -->
        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr 1fr 1fr;">
            <div class="form-group"><label>Gender</label><select name="gender" id="eGen"><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option></select></div>
            <div class="form-group"><label>Marital Status</label><select name="marital_status" id="eMarital" onchange="toggleAnniversary()"><option value="">Select</option><option>Single</option><option value="Married">Married</option></select></div>
            <div class="form-group"><label>Birth Date</label><input type="date" name="dob" id="eDob"></div>
            <div class="form-group"><label style="color:#0ea5e9;">Anniversary</label><input type="date" name="anniversary_date" id="eAnniv" disabled></div>
            <div class="form-group"><label>Joining Date</label><input type="date" name="joining_date" id="eJoiningDate"></div>
            <div class="form-group"><label style="color:#dc2626;">Resign Date</label><input type="date" name="resignation_date" id="eResignationDate" style="border-color:#fca5a5;"></div>
        </div>

        <!-- Row 4: KYC, Bank & Education -->
        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr 1fr 1fr 1fr;">
            <div class="form-group"><label>Bank Name</label><input type="text" name="bank_name" id="eBankName" placeholder="Bank Name"></div>
            <div class="form-group"><label>Bank A/C No.</label><input type="text" name="bank_account" id="eBank" placeholder="A/C Number"></div>
            <div class="form-group"><label>Bank IFSC</label><input type="text" name="ifsc_code" id="eIfsc" placeholder="IFSC Code"></div>
            <div class="form-group"><label>PAN No.</label><input type="text" name="pan_no" id="ePan" placeholder="ABCDE1234F"></div>
            <div class="form-group"><label>Aadhar No.</label><input type="text" name="aadhar_no" id="eAadhar" placeholder="12-digit Aadhar"></div>
            <div class="form-group"><label>DL No.</label><input type="text" name="dl_no" id="eDl" placeholder="Driving License"></div>
            <div class="form-group"><label>Education</label><input type="text" name="education" id="eEdu" placeholder="Highest Degree"></div>
        </div>

        <!-- Row 5: Address & Actions -->
        <div class="form-grid" style="grid-template-columns: 4fr 1.2fr;">
            <div class="form-group">
                <label>Full Residential Address</label>
                <textarea name="address" id="eAddr" placeholder="House No, Street, City, Landmark, Pincode..." rows="2"></textarea>
            </div>
            <div class="btn-action-row" style="margin-top: 15px; margin-bottom: 5px;">
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Record</button>
                <button type="button" class="btn btn-secondary" id="btnCancel" onclick="cancelEdit()" style="display:none;">Cancel</button>
            </div>
        </div>
    </form>
</div>

<!-- Employee Table -->
<div class="panel-box">
    <div class="panel-title">
        <span>2. Active Employee Registry</span>
        <button type="button" class="btn-export" onclick="exportToExcel()">Export Excel</button>
    </div>
    <div class="table-container">
        <table class="compact-table" id="employeeTable">
            <thead>
                <tr>
                    <th style="text-align: center;">Sl.</th>
                    <th>Division</th><th>Empl Code</th><th>Employee Name</th><th>Department</th>
                    <th>Designation</th><th>Mobile. No</th><th>Email</th><th>Gender</th>
                    <th>Marital Status</th><th>Joining Date</th><th>Birth Date</th><th>Anniversary</th>
                    <th>Resign Date</th>
                    <th>HQ</th><th>Reporting Manager</th><th>State</th><th>Bank Name</th>
                    <th>Bank A/C No.</th><th>IFSC Code</th>
                    <th>Aadhar No.</th><th>PAN No</th><th>DL No.</th>
                    <th>Education</th><th>Address</th>
                </tr>
            </thead>
            <tbody id="employeeTableBody">
                <?php if (empty($employees)): ?>
                    <tr><td colspan="25" style="text-align: center; color: #64748b; padding: 20px;">No active employees found.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($employees as $e): ?>
                    <tr class="clickable-row" 
                        data-id="<?= htmlspecialchars($e['id']) ?>"
                        data-div="<?= htmlspecialchars($e['division'] ?? '') ?>" 
                        data-empcode="<?= htmlspecialchars($e['empcode']) ?>" 
                        data-empname="<?= htmlspecialchars($e['empname']) ?>" 
                        data-hq="<?= htmlspecialchars($e['hq']) ?>" 
                        data-dept="<?= htmlspecialchars($e['department'] ?? '') ?>" 
                        data-desig="<?= htmlspecialchars($e['designation'] ?? '') ?>" 
                        data-mobile="<?= htmlspecialchars($e['mobile'] ?? '') ?>"
                        data-email="<?= htmlspecialchars($e['email'] ?? '') ?>"
                        data-gender="<?= htmlspecialchars($e['gender'] ?? '') ?>"
                        data-marital="<?= htmlspecialchars($e['marital_status'] ?? '') ?>"
                        data-repmgr="<?= htmlspecialchars($e['reporting_manager'] ?? '') ?>"
                        data-state="<?= htmlspecialchars($e['state']) ?>"
                        data-joining="<?= htmlspecialchars($e['joining_date'] ?? '') ?>"
                        data-resignation="<?= htmlspecialchars($e['resignation_date'] ?? '') ?>"
                        data-dob="<?= htmlspecialchars($e['dob'] ?? '') ?>"
                        data-anniv="<?= htmlspecialchars($e['anniversary_date'] ?? '') ?>"
                        data-bankname="<?= htmlspecialchars($e['bank_name'] ?? '') ?>"
                        data-bank="<?= htmlspecialchars($e['bank_account'] ?? '') ?>"
                        data-ifsc="<?= htmlspecialchars($e['ifsc_code'] ?? '') ?>"
                        data-pan="<?= htmlspecialchars($e['pan_no'] ?? '') ?>"
                        data-aadhar="<?= htmlspecialchars($e['aadhar_no'] ?? '') ?>"
                        data-dl="<?= htmlspecialchars($e['dl_no'] ?? '') ?>"
                        data-edu="<?= htmlspecialchars($e['education'] ?? '') ?>"
                        data-addr="<?= htmlspecialchars($e['address'] ?? '') ?>"
                        onclick="editRecord(this)">
                        <td style="text-align: center; color: #64748b;"><?= $i++; ?></td>
                        <td><?= htmlspecialchars($e['division'] ?? '-'); ?></td>
                        <td style="color:#1d4ed8; font-family:monospace; font-weight:bold;"><?= htmlspecialchars($e['empcode']); ?></td>
                        <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($e['empname']); ?></td>
                        <td><?= htmlspecialchars($e['department'] ?? '-'); ?></td>
                        <td style="color: #475569;"><?= htmlspecialchars($e['designation'] ?? '-'); ?></td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($e['mobile'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['email'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['gender'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['marital_status'] ?: '-'); ?></td>
                        <td><?= $e['joining_date'] ? date('d-M-Y', strtotime($e['joining_date'])) : '-'; ?></td>
                        <td><?= $e['dob'] ? date('d-M-Y', strtotime($e['dob'])) : '-'; ?></td>
                        <td><?= $e['anniversary_date'] ? date('d-M-Y', strtotime($e['anniversary_date'])) : '-'; ?></td>
                        <td><?= $e['resignation_date'] ? date('d-M-Y', strtotime($e['resignation_date'])) : '-'; ?></td>
                        <td style="font-weight:bold;"><?= htmlspecialchars($e['hq']); ?></td>
                        <td><?= htmlspecialchars($e['display_manager'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['state']); ?></td>
                        <td><?= htmlspecialchars($e['bank_name'] ?: '-'); ?></td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($e['bank_account'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['ifsc_code'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['aadhar_no'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['pan_no'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['dl_no'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['education'] ?: '-'); ?></td>
                        <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($e['address'] ?: '') ?>"><?= htmlspecialchars($e['address'] ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const designationData = <?= json_encode($designations) ?>;
    const hqsData = <?= json_encode($hqs) ?>;
    const statesData = <?= json_encode($states) ?>; 
    let currentEmployees = <?= json_encode($employees) ?>;
    let allEmployeesReference = <?= json_encode($allEmployees) ?>; 

    // UI Logic for Anniversary toggle
    function toggleAnniversary() {
        const status = document.getElementById('eMarital').value;
        const annivField = document.getElementById('eAnniv');
        if (status === 'Married') {
            annivField.disabled = false;
        } else {
            annivField.value = '';
            annivField.disabled = true;
        }
    }

    function generateNewEmpCode() {
        const division = document.getElementById('divSelect').value;
        if (!division || document.getElementById('recordId').value !== '') return;
        const formData = new FormData();
        formData.append('ajax_action', 'generate_empcode');
        formData.append('division', division);
        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json()).then(data => { if (data.status === 'success') document.getElementById('eCode').value = data.empcode; });
    }

    function filterDesignations(preselect = '') {
        const dept = document.getElementById('deptSelect').value;
        const desigSelect = document.getElementById('desigSelect');
        desigSelect.innerHTML = '<option value="">Select Designation</option>';
        const filtered = designationData.filter(d => !dept || (d.deptname && d.deptname.trim().toLowerCase() === dept.trim().toLowerCase()));
        filtered.forEach(d => {
            const opt = new Option(d.designame, d.designame);
            if (d.designame === preselect) opt.selected = true;
            desigSelect.appendChild(opt);
        });
        if (preselect && ![...desigSelect.options].some(o => o.value === preselect)) {
            desigSelect.appendChild(new Option(preselect, preselect, true, true));
        }
    }

    function filterHQs(preselect = '') {
        const stateSelect = document.getElementById('stateSelect');
        const stateVal = stateSelect.value;
        const hqSelect = document.getElementById('hqSelect');
        hqSelect.innerHTML = '<option value="">Select HQ</option>';
        const filtered = hqsData.filter(h => !stateVal || (h.statename && h.statename.trim().toLowerCase() === stateVal.trim().toLowerCase()));
        filtered.forEach(h => {
            const opt = new Option(h.hqname, h.hqname);
            if (h.hqname === preselect) opt.selected = true;
            hqSelect.appendChild(opt);
        });
        if (preselect && ![...hqSelect.options].some(o => o.value === preselect)) {
            hqSelect.appendChild(new Option(preselect, preselect, true, true));
        }
    }

    function filterManagers(selectedDesig = '', preselect = '', selfCode = '') {
        const repMgrSelect = document.getElementById('eRepMgr');
        repMgrSelect.innerHTML = '<option value="">Select Manager</option>';
        
        // Fully Updated Hierarchy Mapping
        const allowedManagersMap = {
            'MR': ['ASM', 'ARM'],
            'ASM': ['RSM', 'Sr. RSM', 'SR.RSM'],
            'ARM': ['RSM', 'Sr. RSM', 'SR.RSM'],
            'RSM': ['DSM'],
            'Sr. RSM': ['DSM'],
            'SR.RSM': ['DSM'],
            'DSM': ['ZSM'],
            'ZSM': ['NSM'],
            'NSM': ['SM'],
            'SM': ['AGM'],
            'AGM': ['DGM'],
            'DGM': ['GM'],
            'GM': ['VP'],
            'VP': ['C.E.O.', 'CEO'],
            'C.E.O.': ['M.D.', 'MD'],
            'CEO': ['M.D.', 'MD'],
            'M.D.': ['C.M.D.', 'CMD'],
            'MD': ['C.M.D.', 'CMD'],
            'C.M.D.': [], // Top level
            'CMD': []
        };
        
        const levels = {
            'C.M.D.': 15, 'CMD': 15,
            'M.D.': 14, 'MD': 14,
            'C.E.O.': 13, 'CEO': 13,
            'VP': 12, 'GM': 11, 'DGM': 10, 'AGM': 9, 'SM': 8, 'NSM': 7, 
            'ZSM': 6, 'DSM': 5, 'RSM': 4, 'Sr. RSM': 4, 'SR.RSM': 4, 
            'ASM': 3, 'ARM': 3, 'MR': 1 
        };

        const currentLevel = levels[selectedDesig] || 0;
        const allowedDesigs = allowedManagersMap[selectedDesig];
        
        const filtered = allEmployeesReference.filter(e => {
            if (selfCode && e.empcode === selfCode) return false; 
            
            // 1. Strict mapping takes priority
            if (allowedDesigs && allowedDesigs.length > 0) {
                return allowedDesigs.includes(e.designation);
            }
            
            // 2. If top level (C.M.D.), no managers shown
            if (allowedDesigs && allowedDesigs.length === 0) {
                return false;
            }
            
            // 3. Fallback for unmapped designations using Levels
            const eLevel = levels[e.designation] || 0;
            if (currentLevel > 0) return eLevel > currentLevel;
            
            // 4. Default if completely unknown
            return true;
        });

        filtered.forEach(e => {
            const opt = new Option(e.empname + " (" + (e.designation || 'MR') + " - " + e.hq + ")", e.empcode); 
            if (e.empcode === preselect || e.empname === preselect) opt.selected = true;
            repMgrSelect.appendChild(opt);
        });

        if (preselect && ![...repMgrSelect.options].some(o => o.value === preselect)) {
            repMgrSelect.appendChild(new Option(preselect, preselect, true, true));
        }
    }

    function populateAllStates(preselect = '') {
        const stateSelect = document.getElementById('stateSelect');
        stateSelect.innerHTML = '<option value="">Select State</option>';
        statesData.forEach(s => {
            const opt = new Option(s.statename, s.statename);
            if (s.statename === preselect) opt.selected = true;
            stateSelect.appendChild(opt);
        });
        if (preselect && ![...stateSelect.options].some(o => o.value === preselect)) {
            stateSelect.appendChild(new Option(preselect, preselect, true, true));
        }
    }

    function escapeHtml(text) {
        if (!text && text !== 0) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function updateUI(data) {
        currentEmployees = data;
        const tbody = document.getElementById('employeeTableBody');
        if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="25" style="text-align: center; color: #64748b; padding: 20px;">No active employees found.</td></tr>'; return; }
        
        let html = '';
        data.forEach((e, i) => {
            html += `<tr class="clickable-row" 
                        data-id="${e.id}" data-div="${escapeHtml(e.division)}" data-empcode="${escapeHtml(e.empcode)}" data-empname="${escapeHtml(e.empname)}" 
                        data-hq="${escapeHtml(e.hq)}" data-dept="${escapeHtml(e.department)}" data-desig="${escapeHtml(e.designation)}" 
                        data-mobile="${escapeHtml(e.mobile)}" data-email="${escapeHtml(e.email)}" data-repmgr="${escapeHtml(e.reporting_manager)}" 
                        data-state="${escapeHtml(e.state)}" data-joining="${e.joining_date||''}" data-resignation="${e.resignation_date||''}"
                        data-dob="${e.dob||''}" data-anniv="${e.anniversary_date||''}" data-bankname="${escapeHtml(e.bank_name)}" 
                        data-bank="${escapeHtml(e.bank_account)}" data-ifsc="${escapeHtml(e.ifsc_code)}" data-pan="${escapeHtml(e.pan_no)}" 
                        data-aadhar="${escapeHtml(e.aadhar_no)}" data-dl="${escapeHtml(e.dl_no)}" data-edu="${escapeHtml(e.education)}" 
                        data-addr="${escapeHtml(e.address)}" data-gender="${escapeHtml(e.gender)}" data-marital="${escapeHtml(e.marital_status)}"
                        onclick="editRecord(this)">
                <td style="text-align: center; color: #64748b;">${i + 1}</td>
                <td>${escapeHtml(e.division || '-')}</td>
                <td style="color:#1d4ed8; font-family:monospace; font-weight:bold;">${escapeHtml(e.empcode)}</td>
                <td style="color:#0f172a; font-weight:bold;">${escapeHtml(e.empname)}</td>
                <td>${escapeHtml(e.department || '-')}</td>
                <td style="color: #475569;">${escapeHtml(e.designation || '-')}</td>
                <td style="font-family:monospace;">${escapeHtml(e.mobile || '-')}</td>
                <td>${escapeHtml(e.email || '-')}</td>
                <td>${escapeHtml(e.gender || '-')}</td>
                <td>${escapeHtml(e.marital_status || '-')}</td>
                <td>${e.joining_date ? e.joining_date : '-'}</td>
                <td>${e.dob ? e.dob : '-'}</td>
                <td>${e.anniversary_date ? e.anniversary_date : '-'}</td>
                <td>${e.resignation_date ? e.resignation_date : '-'}</td>
                <td style="font-weight:bold;">${escapeHtml(e.hq)}</td>
                <td>${escapeHtml(e.display_manager || '-')}</td>
                <td>${escapeHtml(e.state)}</td>
                <td>${escapeHtml(e.bank_name || '-')}</td>
                <td style="font-family:monospace;">${escapeHtml(e.bank_account || '-')}</td>
                <td>${escapeHtml(e.ifsc_code || '-')}</td>
                <td>${escapeHtml(e.aadhar_no || '-')}</td>
                <td>${escapeHtml(e.pan_no || '-')}</td>
                <td>${escapeHtml(e.dl_no || '-')}</td>
                <td>${escapeHtml(e.education || '-')}</td>
                <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(e.address)}">${escapeHtml(e.address || '-')}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function editRecord(row) {
        document.getElementById('recordId').value = row.getAttribute('data-id') || '';
        document.getElementById('divSelect').value = row.getAttribute('data-div') || '';
        document.getElementById('eCode').value = row.getAttribute('data-empcode') || '';
        document.getElementById('eName').value = row.getAttribute('data-empname') || '';
        document.getElementById('eMobile').value = row.getAttribute('data-mobile') || '';
        document.getElementById('eEmail').value = row.getAttribute('data-email') || '';
        document.getElementById('eGen').value = row.getAttribute('data-gender') || '';
        
        const maritalVal = row.getAttribute('data-marital') || '';
        document.getElementById('eMarital').value = maritalVal;
        
        document.getElementById('eJoiningDate').value = row.getAttribute('data-joining') || '';
        document.getElementById('eResignationDate').value = row.getAttribute('data-resignation') || '';
        document.getElementById('eDob').value = row.getAttribute('data-dob') || '';
        document.getElementById('eAnniv').value = row.getAttribute('data-anniv') || '';
        
        document.getElementById('eBankName').value = row.getAttribute('data-bankname') || '';
        document.getElementById('eBank').value = row.getAttribute('data-bank') || '';
        document.getElementById('eIfsc').value = row.getAttribute('data-ifsc') || '';
        document.getElementById('ePan').value = row.getAttribute('data-pan') || '';
        document.getElementById('eAadhar').value = row.getAttribute('data-aadhar') || '';
        document.getElementById('eDl').value = row.getAttribute('data-dl') || '';
        document.getElementById('eEdu').value = row.getAttribute('data-edu') || '';
        document.getElementById('eAddr').value = row.getAttribute('data-addr') || '';

        populateAllStates(row.getAttribute('data-state') || '');
        filterHQs(row.getAttribute('data-hq') || '');
        document.getElementById('deptSelect').value = row.getAttribute('data-dept') || '';
        filterDesignations(row.getAttribute('data-desig') || '');
        filterManagers(row.getAttribute('data-desig') || '', row.getAttribute('data-repmgr') || '', row.getAttribute('data-empcode') || '');

        toggleAnniversary();
        
        document.getElementById('formHeader').innerText = "1. Update Employee Record";
        const submitBtn = document.getElementById('btnSubmit');
        submitBtn.innerText = "Update Employee";
        submitBtn.style.background = "#059669"; 
        document.getElementById('btnCancel').style.display = 'inline-flex';
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch(e) {}
    }

    function cancelEdit() {
        document.getElementById('employeeForm').reset();
        document.getElementById('recordId').value = '';
        populateAllStates(); filterDesignations(); filterHQs(); filterManagers();
        toggleAnniversary();
        document.getElementById('formHeader').innerText = '1. Add New Employee';
        const submitBtn = document.getElementById('btnSubmit');
        submitBtn.innerText = 'Save Record'; submitBtn.style.background = ""; 
        document.getElementById('btnCancel').style.display = 'none';
    }

    function submitForm(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('employeeForm'));
        formData.append('ajax_action', 'save');
        document.getElementById('btnSubmit').innerText = '...';

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json()).then(data => {
            if (data.status === 'success') { 
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message); else alert(data.message);
                cancelEdit(); updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message); else alert(data.message);
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Database connection failure.');
        }).finally(() => {
            document.getElementById('btnSubmit').innerText = document.getElementById('recordId').value ? 'Update Employee' : 'Save Record';
        });
    }

    function exportToExcel() {
        if (!currentEmployees || currentEmployees.length === 0) {
            if (typeof AppAlert !== 'undefined') AppAlert.warning('No data to export.'); return;
        }
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;">
            <th>Division</th><th>Empl code</th><th>Employee Name</th><th>Department</th><th>Designation</th><th>Mobile. No</th><th>Email</th><th>Gender</th><th>Marital Status</th><th>Joining Date</th><th>Birth Date</th><th>Anniversary</th><th>Resign Date</th><th>HQ</th><th>Reporting Manager</th><th>State</th><th>Bank Name</th><th>Bank Account No.</th><th>Bank IFSC Code</th><th>Aadhar Card No.</th><th>Pancard No</th><th>DL No.</th><th>Education</th><th>Address</th></tr>`;
        currentEmployees.forEach((e) => {
            html += `<tr>
                <td>${escapeHtml(e.division || '-')}</td>
                <td>${escapeHtml(e.empcode)}</td>
                <td>${escapeHtml(e.empname)}</td>
                <td>${escapeHtml(e.department || '-')}</td>
                <td>${escapeHtml(e.designation || '-')}</td>
                <td>${escapeHtml(e.mobile || '-')}</td>
                <td>${escapeHtml(e.email || '-')}</td>
                <td>${escapeHtml(e.gender || '-')}</td>
                <td>${escapeHtml(e.marital_status || '-')}</td>
                <td>${e.joining_date ? e.joining_date : '-'}</td>
                <td>${e.dob ? e.dob : '-'}</td>
                <td>${e.anniversary_date ? e.anniversary_date : '-'}</td>
                <td>${e.resignation_date ? e.resignation_date : '-'}</td>
                <td>${escapeHtml(e.hq)}</td>
                <td>${escapeHtml(e.display_manager || '-')}</td>
                <td>${escapeHtml(e.state)}</td>
                <td>${escapeHtml(e.bank_name || '-')}</td>
                <td style="mso-number-format:'\@';">${escapeHtml(e.bank_account || '-')}</td>
                <td>${escapeHtml(e.ifsc_code || '-')}</td>
                <td style="mso-number-format:'\@';">${escapeHtml(e.aadhar_no || '-')}</td>
                <td>${escapeHtml(e.pan_no || '-')}</td>
                <td>${escapeHtml(e.dl_no || '-')}</td>
                <td>${escapeHtml(e.education || '-')}</td>
                <td>${escapeHtml(e.address || '-')}</td>
            </tr>`;
        });
        html += `</table>`;
        const a = document.createElement("a");
        a.href = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' }));
        a.download = `Active_Employees_Detailed_${new Date().toISOString().slice(0,10)}.xls`;
        a.click();
    }

    document.addEventListener("DOMContentLoaded", function() {
        populateAllStates(); filterDesignations(); filterManagers();
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>