<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context & Permissions ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.designation, e.department, e.empcode FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$isAdmin = ($userMeta['role'] ?? '') === 'Admin';
$empCode = $userMeta['empcode'] ?? $userMeta['username'] ?? '';
$designation = $userMeta['designation'] ?? 'MR';
$department = strtoupper(trim($userMeta['department'] ?? 'SALES'));

$isSales = ($department === 'SALES' || $department === 'MARKETING' || empty($department));
$isManager = ($isSales && !$isAdmin && strtoupper(trim($designation)) !== 'MR');
$isMR = ($isSales && !$isAdmin && strtoupper(trim($designation)) === 'MR');

$canEntry = ($isAdmin || $isManager || $isMR);

// --- Fetch Team Codes for Managers ---
$teamCodes = [$empCode];
if ($isManager) {
    // ROLE SCOPE: full reporting line (code or name based), from core.php
    $teamCodes = scope_codes($ctx);
    if (empty($teamCodes)) $teamCodes = [$empCode];
}
$teamPh = implode(',', array_fill(0, count($teamCodes), '?'));

// --- Handle Form Submissions (Add, Update Request, Delete Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEntry) {
    $reqType = $_POST['request_type'] ?? ''; 
    $assignedEmp = trim($_POST['assigned_emp'] ?? $empCode);
    $remarks = trim($_POST['remarks'] ?? '');

    // Capture all form fields matching the exact schema
    $docName = trim($_POST['doc_name'] ?? '');
    $specialty = trim($_POST['specialty'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    
    $cAdd1 = trim($_POST['clinic_add1'] ?? '');
    $cAdd2 = trim($_POST['clinic_add2'] ?? '');
    $cAdd3 = trim($_POST['clinic_add3'] ?? '');
    $cCity = trim($_POST['clinic_city'] ?? '');
    $cState = trim($_POST['clinic_state'] ?? '');
    $cPin = trim($_POST['clinic_pin'] ?? '');
    $cPhone = trim($_POST['clinic_phone'] ?? '');
    
    $rAdd1 = trim($_POST['res_add1'] ?? '');
    $rAdd2 = trim($_POST['res_add2'] ?? '');
    $rAdd3 = trim($_POST['res_add3'] ?? '');
    $rCity = trim($_POST['res_city'] ?? '');
    $rState = trim($_POST['res_state'] ?? '');
    $rPin = trim($_POST['res_pin'] ?? '');
    $rPhone = trim($_POST['res_phone'] ?? '');
    
    $type = trim($_POST['doc_type'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $prefTime = trim($_POST['pref_day_time'] ?? '');
    $visitsExp = (int)($_POST['visits_expected'] ?? 0);
    $hospital = trim($_POST['hospital_name'] ?? '');
    $doa = empty($_POST['doa']) ? null : trim($_POST['doa']);
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $patientsCount = (int)($_POST['patients_count'] ?? 0);
    $regNo = trim($_POST['reg_no'] ?? '');
    $panNo = trim($_POST['pan_no'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $religion = trim($_POST['religion'] ?? '');
    $dob = empty($_POST['dob']) ? null : trim($_POST['dob']);
    $docDate = empty($_POST['doc'] ?? '') ? null : trim($_POST['doc']);

    if (!$isAdmin && !in_array($assignedEmp, $teamCodes)) {
        die("Security Violation: You cannot assign a doctor to an employee outside your scope.");
    }

    if ($reqType === 'Delete') {
        $id = (int)$_POST['selected_doc_id'];
        $stmt = $pdo->prepare("SELECT doc_name, specialty, clinic_city FROM doctors WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            if ($isMR) {
                try {
                    $pdo->prepare("INSERT INTO doctor_requests (empcode, doc_id, request_type, doc_name, specialty, hq, remarks, status) VALUES (?, ?, 'Delete', ?, ?, ?, ?, 'Pending')")
                        ->execute([$empCode, $id, $doc['doc_name'], $doc['specialty'], $doc['clinic_city'], $remarks]);
                    $_SESSION['msg'] = "Delete request submitted successfully for approval!";
                } catch(Exception $e) { $_SESSION['msg'] = "Error: " . $e->getMessage(); }
            } else {
                $pdo->prepare("DELETE FROM doctors WHERE id=?")->execute([$id]);
                $_SESSION['msg'] = "Doctor deleted successfully!";
            }
        }
    } elseif ($reqType === 'Edit') {
        $id = (int)$_POST['selected_doc_id'];
        if ($isMR) {
            try {
                $pdo->prepare("INSERT INTO doctor_requests (empcode, doc_id, request_type, doc_name, specialty, hq, remarks, status) VALUES (?, ?, 'Edit', ?, ?, ?, ?, 'Pending')")
                    ->execute([$assignedEmp, $id, $docName, $specialty, $cCity, $remarks]);
                $_SESSION['msg'] = "Update request submitted successfully for approval!";
            } catch(Exception $e) { $_SESSION['msg'] = "Error: " . $e->getMessage(); }
        } else {
            $sql = "UPDATE doctors SET doc_name=?, specialty=?, category=?, qualification=?, clinic_add1=?, clinic_add2=?, clinic_add3=?, clinic_city=?, clinic_state=?, clinic_pin=?, clinic_phone=?, res_add1=?, res_add2=?, res_add3=?, res_city=?, res_state=?, res_pin=?, res_phone=?, doc_type=?, mobile=?, email=?, pref_day_time=?, visits_expected=?, hospital_name=?, doa=?, contact_person=?, patients_count=?, reg_no=?, pan_no=?, gender=?, religion=?, dob=?, doc=?, empcode=? WHERE id=?";
            $pdo->prepare($sql)->execute([
                $docName, $specialty, $category, $qualification, $cAdd1, $cAdd2, $cAdd3, $cCity, $cState, $cPin, $cPhone, $rAdd1, $rAdd2, $rAdd3, $rCity, $rState, $rPin, $rPhone, $type, $mobile, $email, $prefTime, $visitsExp, $hospital, $doa, $contactPerson, $patientsCount, $regNo, $panNo, $gender, $religion, $dob, $docDate, $assignedEmp, $id
            ]);
            $_SESSION['msg'] = "Doctor updated successfully!";
        }
    } elseif ($reqType === 'Add') {
        if ($isMR) {
            try {
                $pdo->prepare("INSERT INTO doctor_requests (empcode, request_type, doc_name, specialty, hq, remarks, status) VALUES (?, 'Add', ?, ?, ?, ?, 'Pending')")
                    ->execute([$assignedEmp, $docName, $specialty, $cCity, $remarks]);
                $_SESSION['msg'] = "Add request submitted successfully for approval!";
            } catch(Exception $e) { $_SESSION['msg'] = "Error: " . $e->getMessage(); }
        } else {
            $sql = "INSERT INTO doctors (doc_name, specialty, category, qualification, clinic_add1, clinic_add2, clinic_add3, clinic_city, clinic_state, clinic_pin, clinic_phone, res_add1, res_add2, res_add3, res_city, res_state, res_pin, res_phone, doc_type, mobile, email, pref_day_time, visits_expected, hospital_name, doa, contact_person, patients_count, reg_no, pan_no, gender, religion, dob, doc, empcode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([
                $docName, $specialty, $category, $qualification, $cAdd1, $cAdd2, $cAdd3, $cCity, $cState, $cPin, $cPhone, $rAdd1, $rAdd2, $rAdd3, $rCity, $rState, $rPin, $rPhone, $type, $mobile, $email, $prefTime, $visitsExp, $hospital, $doa, $contactPerson, $patientsCount, $regNo, $panNo, $gender, $religion, $dob, $docDate, $assignedEmp
            ]);
            $_SESSION['msg'] = "Doctor added successfully!";
        }
    }
    header("Location: doctors.php"); exit();
}

$pageTitle = "Doctors Master & Requests";
include __DIR__ . '/../includes/header.php';
?>


<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 15px; align-items:center;">
        <h2 style="font-family:'Sora', sans-serif; color:#1e2a78; margin:0; text-transform: uppercase; font-size: 18px;">🩺 Doctors Request & Management</h2>
        <a href="../index.php" class="btn-white">← Dashboard</a>
    </div>

    <!-- EXACT 4 REQUIRED BUTTONS -->
    <?php if($canEntry): ?>
    <div class="action-bar">
        <button class="btn-navy" onclick="openAddModal()">Add Request</button>
        <button class="btn-navy" onclick="prepareRequest('Edit')">Update Request</button>
        <button class="btn-navy" onclick="prepareRequest('Delete')">Delete Request</button>
        <select class="btn-navy" id="reqFilter" onchange="switchView('requests', this.value)">
            <option value="" disabled selected>View Requests ▼</option>
            <option value="All">View All</option>
            <option value="Pending">Pending</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
        </select>
        <button class="btn-white" id="btnBackDir" onclick="switchView('directory')" style="display:none;">Back to Directory</button>
    </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['msg'])): ?>
        <div style="background:#fff; color:#1e2a78; padding:10px; border-radius:3px; margin-bottom:15px; border:1px dotted #1e2a78; font-weight:bold;"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
    <?php endif; ?>

    <!-- DIRECTORY VIEW -->
    <div id="view-directory" class="view-section" style="display:block;">
        <div style="background:#eff6ff; color:#1d4ed8; padding:8px 12px; border:1px dotted #1d4ed8; font-size:11px; margin-bottom:10px; border-radius:4px;">
            ℹ️ <b>Note:</b> For Update or Delete requests, select a doctor using the checkbox on the left, then click the respective request button above.
        </div>
        <table style="width:100%; border-collapse:collapse; background:#fff; border: 1px solid #1e2a78;">
            <thead style="background:#1e2a78; color:#fff; font-size:11px; text-transform: uppercase;">
                <tr>
                    <th style="padding:10px; width:40px; text-align:center; border: 1px solid #1e2a78;">Select</th>
                    <th style="padding:10px; text-align:left; border: 1px solid #1e2a78;">ID</th>
                    <th style="padding:10px; text-align:left; border: 1px solid #1e2a78;">Doctor Name</th>
                    <th style="padding:10px; text-align:left; border: 1px solid #1e2a78;">Specialty</th>
                    <th style="padding:10px; text-align:left; border: 1px solid #1e2a78;">City / Mobile</th>
                    <th style="padding:10px; text-align:left; border: 1px solid #1e2a78;">Assigned MR</th>
                </tr>
            </thead>
            <tbody style="font-size:12px; color:#000;">
                <?php
                if ($isAdmin) {
                    $docs = $pdo->query("SELECT d.*, e.empname FROM doctors d LEFT JOIN employees e ON d.empcode = e.empcode ORDER BY d.id DESC")->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $pdo->prepare("SELECT d.*, e.empname FROM doctors d LEFT JOIN employees e ON d.empcode = e.empcode WHERE d.empcode IN ($teamPh) ORDER BY d.id DESC");
                    $stmt->execute($teamCodes);
                    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                foreach($docs as $doc): ?>
                <tr style="border-bottom:1px dotted #cbd5e1;">
                    <td style="padding:10px; text-align:center; border-right: 1px dotted #cbd5e1;">
                        <input type="radio" name="selected_doc" value="<?= $doc['id'] ?>" data-doc='<?= htmlspecialchars(json_encode($doc), ENT_QUOTES, "UTF-8") ?>'>
                    </td>
                    <td style="padding:10px; border-right: 1px dotted #cbd5e1;">#<?= $doc['id'] ?></td>
                    <td style="padding:10px; font-weight:bold; color:#1e2a78; border-right: 1px dotted #cbd5e1;"><?= htmlspecialchars($doc['doc_name']) ?></td>
                    <td style="padding:10px; border-right: 1px dotted #cbd5e1;"><?= htmlspecialchars($doc['specialty'] ?? 'N/A') ?></td>
                    <td style="padding:10px; border-right: 1px dotted #cbd5e1;"><?= htmlspecialchars($doc['clinic_city'] ?? 'N/A') ?> (<?= htmlspecialchars($doc['mobile'] ?? '-') ?>)</td>
                    <td style="padding:10px; border-right: 1px dotted #cbd5e1;"><?= htmlspecialchars($doc['empname'] ?? $doc['empcode']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- VIEW ALL REQUESTS -->
    <div id="view-requests" class="view-section" style="display:none;">
        <table style="width:100%; border-collapse:collapse; background:#fff; border: 1px dotted #1e2a78;">
            <thead style="background:#f1f5f9; color:#1e2a78; font-size:11px; text-transform: uppercase;">
                <tr>
                    <th style="padding:10px; text-align:left; border: 1px dotted #1e2a78;">Date</th>
                    <th style="padding:10px; text-align:center; border: 1px dotted #1e2a78;">Type</th>
                    <th style="padding:10px; text-align:left; border: 1px dotted #1e2a78;">Doctor Name</th>
                    <th style="padding:10px; text-align:left; border: 1px dotted #1e2a78;">Remarks</th>
                    <th style="padding:10px; text-align:center; border: 1px dotted #1e2a78;">Status</th>
                </tr>
            </thead>
            <tbody style="font-size:12px; color:#000;">
                <?php
                try {
                    $stmtReq = $pdo->prepare("SELECT * FROM doctor_requests WHERE empcode IN ($teamPh) ORDER BY created_at DESC");
                    $stmtReq->execute($teamCodes);
                    $requestsList = $stmtReq->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($requestsList)): ?>
                        <tr class="req-row" data-status="All"><td colspan="5" style="padding:20px; text-align:center; color:#64748b; font-style:italic;">No requests found.</td></tr>
                    <?php else:
                        foreach($requestsList as $req):
                            $typeColor = ($req['request_type'] === 'Add') ? '#059669' : (($req['request_type'] === 'Delete') ? '#dc2626' : '#2563eb');
                            $statusColor = ($req['status'] === 'Approved') ? '#059669' : (($req['status'] === 'Rejected') ? '#dc2626' : '#d97706');
                    ?>
                        <tr class="req-row" data-status="<?= htmlspecialchars($req['status']) ?>" style="border-bottom:1px dotted #cbd5e1;">
                            <td style="padding:10px; border-right: 1px dotted #cbd5e1;"><?= date('d-M-Y H:i', strtotime($req['created_at'])) ?></td>
                            <td style="padding:10px; text-align:center; border-right: 1px dotted #cbd5e1;"><span style="color:<?= $typeColor ?>; font-weight:bold;"><?= $req['request_type'] ?></span></td>
                            <td style="padding:10px; font-weight:bold; border-right: 1px dotted #cbd5e1;"><?= htmlspecialchars($req['doc_name']) ?></td>
                            <td style="padding:10px; border-right: 1px dotted #cbd5e1;"><?= htmlspecialchars($req['remarks'] ?? '-') ?></td>
                            <td style="padding:10px; text-align:center;"><span style="color:<?= $statusColor ?>; font-weight:bold;"><?= $req['status'] ?></span></td>
                        </tr>
                    <?php endforeach; 
                    endif;
                } catch(Exception $e) { echo "<tr><td colspan='5'>Table missing or error.</td></tr>"; } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- FULL DETAILED FORM MODAL FOR ADD / UPDATE / DELETE -->
<div id="actionModal" class="modal-box">
    <div class="modal-content">
        <h3 id="modalTitle" style="margin-top:0; color:#1e2a78; font-size:14px; text-transform:uppercase; border-bottom:1px dotted #1e2a78; padding-bottom:6px;">Doctor Request Form</h3>
        <form method="POST" id="actionForm">
            <input type="hidden" name="request_type" id="req_type" value="Add">
            <input type="hidden" name="selected_doc_id" id="selected_doc_id" value="0">
            <?php if($isAdmin || $isManager): ?>
                <input type="hidden" name="assigned_emp" id="assigned_emp" value="<?= $empCode ?>">
            <?php else: ?>
                <input type="hidden" name="assigned_emp" value="<?= $empCode ?>">
            <?php endif; ?>

            <div id="allFormFields">
                <!-- Basic Info Section -->
                <div class="form-section">Basic Information</div>
                <div class="form-grid">
                    <div class="form-group-compact"><label>Doctor Name <span class="req">*</span></label><input type="text" name="doc_name" id="doc_name" required></div>
                    <?php if($isAdmin || $isManager): ?>
                        <div class="form-group-compact"><label>Employee Code <span class="req">*</span></label><input type="text" name="assigned_emp_input" id="assigned_emp_input" value="<?= $empCode ?>"></div>
                    <?php endif; ?>
                    <div class="form-group-compact">
                        <label>Speciality <span class="req">*</span></label>
                        <select name="specialty" id="specialty" required>
                            <option value="">Select</option>
                            <option value="Cardiologist">Cardiologist</option>
                            <option value="Dentist">Dentist</option>
                            <option value="Dermatologist">Dermatologist</option>
                            <option value="ENT">ENT</option>
                            <option value="General Physician">General Physician</option>
                            <option value="Neurologist">Neurologist</option>
                            <option value="Orthopedic">Orthopedic</option>
                            <option value="Pediatrician">Pediatrician</option>
                            <option value="Surgeon">Surgeon</option>
                        </select>
                    </div>
                    <div class="form-group-compact">
                        <label>Category <span class="req">*</span></label>
                        <select name="category" id="category" required>
                            <option value="">Select</option>
                            <option value="Core">Core</option>
                            <option value="Important">Important</option>
                            <option value="Super Core">Super Core</option>
                        </select>
                    </div>
                    <div class="form-group-compact"><label>Qualification</label><input type="text" name="qualification" id="qualification"></div>
                </div>

                <!-- Address Information Section -->
                <div class="form-section">Address Information</div>
                <div class="address-grid">
                    <div class="address-box">
                        <div class="box-title">Clinic Address</div>
                        <div class="form-group-compact"><label>Address 1</label><input type="text" name="clinic_add1" id="clinic_add1"></div>
                        <div class="form-group-compact"><label>Address 2</label><input type="text" name="clinic_add2" id="clinic_add2"></div>
                        <div class="form-group-compact"><label>Address 3</label><input type="text" name="clinic_add3" id="clinic_add3"></div>
                        <div class="form-group-compact"><label>City <span class="req">*</span></label><input type="text" name="clinic_city" id="clinic_city" required></div>
                        <div class="form-group-compact">
                            <label>State <span class="req">*</span></label>
                            <select name="clinic_state" id="clinic_state" required>
                                <option value="">Select</option>
                                <option value="Bihar">Bihar</option>
                                <option value="Delhi">Delhi</option>
                                <option value="Jharkhand">Jharkhand</option>
                                <option value="Maharashtra">Maharashtra</option>
                                <option value="Uttar Pradesh">Uttar Pradesh</option>
                                <option value="West Bengal">West Bengal</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group-compact"><label>Pincode</label><input type="text" name="clinic_pin" id="clinic_pin"></div>
                        <div class="form-group-compact"><label>Clinic Phone</label><input type="text" name="clinic_phone" id="clinic_phone"></div>
                    </div>
                    <div class="address-box">
                        <div class="box-title">Residential Address</div>
                        <div class="form-group-compact"><label>Address 1</label><input type="text" name="res_add1" id="res_add1"></div>
                        <div class="form-group-compact"><label>Address 2</label><input type="text" name="res_add2" id="res_add2"></div>
                        <div class="form-group-compact"><label>Address 3</label><input type="text" name="res_add3" id="res_add3"></div>
                        <div class="form-group-compact"><label>City</label><input type="text" name="res_city" id="res_city"></div>
                        <div class="form-group-compact">
                            <label>State</label>
                            <select name="res_state" id="res_state">
                                <option value="">Select</option>
                                <option value="Bihar">Bihar</option>
                                <option value="Delhi">Delhi</option>
                                <option value="Jharkhand">Jharkhand</option>
                                <option value="Maharashtra">Maharashtra</option>
                                <option value="Uttar Pradesh">Uttar Pradesh</option>
                                <option value="West Bengal">West Bengal</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group-compact"><label>Pincode</label><input type="text" name="res_pin" id="res_pin"></div>
                        <div class="form-group-compact"><label>Res. Phone</label><input type="text" name="res_phone" id="res_phone"></div>
                    </div>
                </div>

                <!-- Other Details Section -->
                <div class="form-section">Other Details</div>
                <div class="other-grid">
                    <div class="form-group-compact">
                        <label>Type</label>
                        <select name="doc_type" id="doc_type">
                            <option value="">Select</option>
                            <option value="Consultant">Consultant</option>
                            <option value="Practitioner">Practitioner</option>
                        </select>
                    </div>
                    <div class="form-group-compact"><label>Mobile</label><input type="text" name="mobile" id="mobile"></div>
                    <div class="form-group-compact"><label>Email</label><input type="email" name="email" id="email"></div>
                    <div class="form-group-compact"><label>Pref. Day/Time</label><input type="text" name="pref_day_time" id="pref_day_time"></div>
                    <div class="form-group-compact"><label># Visits Exp.</label><input type="number" name="visits_expected" id="visits_expected"></div>
                    <div class="form-group-compact"><label>Hospital Name</label><input type="text" name="hospital_name" id="hospital_name"></div>
                    <div class="form-group-compact"><label>DOA</label><input type="date" name="doa" id="doa"></div>
                    <div class="form-group-compact"><label>Contact Person</label><input type="text" name="contact_person" id="contact_person"></div>
                    <div class="form-group-compact"><label># Patients</label><input type="number" name="patients_count" id="patients_count"></div>
                    <div class="form-group-compact"><label>Reg. Number</label><input type="text" name="reg_no" id="reg_no"></div>
                    <div class="form-group-compact"><label>PAN No.</label><input type="text" name="pan_no" id="pan_no"></div>
                    <div class="form-group-compact">
                        <label>Gender</label>
                        <select name="gender" id="gender">
                            <option value="">Select</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="form-group-compact">
                        <label>Religion</label>
                        <select name="religion" id="religion">
                            <option value="">Select</option>
                            <option value="Hindu">Hindu</option>
                            <option value="Muslim">Muslim</option>
                            <option value="Christian">Christian</option>
                            <option value="Sikh">Sikh</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group-compact"><label>DOB</label><input type="date" name="dob" id="dob"></div>
                    <div class="form-group-compact"><label>DOC</label><input type="date" name="doc" id="doc"></div>
                </div>
            </div>

            <div class="form-group-compact" style="margin-top:12px;">
                <label>Remarks / Reason <span class="req">*</span></label>
                <textarea name="remarks" id="modal_remarks" rows="2" required style="flex:1; border:1px dotted #1e2a78; padding:5px; outline:none;" placeholder="Enter remarks..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:15px; border-top:1px dotted #1e2a78; padding-top:10px;">
                <button type="button" onclick="document.getElementById('actionModal').style.display='none'" class="btn-white">Cancel</button>
                <button type="submit" class="btn-navy" id="modalSubmitBtn">Save Doctor Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchView(viewName, filterStatus = 'All') {
    if (viewName === 'requests') {
        document.getElementById('view-directory').style.display = 'none';
        document.getElementById('view-requests').style.display = 'block';
        document.getElementById('btnBackDir').style.display = 'inline-block';
        
        var rows = document.getElementsByClassName('req-row');
        for (var i = 0; i < rows.length; i++) {
            var st = rows[i].getAttribute('data-status');
            if (filterStatus === 'All' || st === filterStatus) {
                rows[i].style.display = '';
            } else {
                rows[i].style.display = 'none';
            }
        }
    } else {
        document.getElementById('view-requests').style.display = 'none';
        document.getElementById('view-directory').style.display = 'block';
        document.getElementById('btnBackDir').style.display = 'none';
        document.getElementById('reqFilter').selectedIndex = 0;
    }
}

function openAddModal() {
    document.getElementById('modalTitle').innerText = "Add New Doctor";
    document.getElementById('req_type').value = "Add";
    document.getElementById('selected_doc_id').value = "0";
    document.getElementById('allFormFields').style.display = "block";
    document.getElementById('actionForm').reset();
    document.getElementById('actionModal').style.display = "flex";
}

function prepareRequest(type) {
    var selected = document.querySelector('input[name="selected_doc"]:checked');
    if (!selected) {
        alert("Please select a doctor using the checkbox on the left first!");
        return;
    }
    var docData = JSON.parse(selected.getAttribute('data-doc'));

    if (type === 'Delete') {
        document.getElementById('modalTitle').innerText = "Doctor Deletion Request: " + docData.doc_name;
        document.getElementById('req_type').value = "Delete";
        document.getElementById('selected_doc_id').value = docData.id;
        document.getElementById('allFormFields').style.display = "none"; // Hide form fields for delete
        document.getElementById('modal_remarks').value = "";
        document.getElementById('actionModal').style.display = "flex";
    } else if (type === 'Edit') {
        document.getElementById('modalTitle').innerText = "Update Doctor Request: " + docData.doc_name;
        document.getElementById('req_type').value = "Edit";
        document.getElementById('selected_doc_id').value = docData.id;
        document.getElementById('allFormFields').style.display = "block";
        
        // Populate all fields for updating
        document.getElementById('doc_name').value = docData.doc_name || '';
        document.getElementById('specialty').value = docData.specialty || '';
        document.getElementById('category').value = docData.category || '';
        document.getElementById('qualification').value = docData.qualification || '';
        document.getElementById('clinic_add1').value = docData.clinic_add1 || '';
        document.getElementById('clinic_add2').value = docData.clinic_add2 || '';
        document.getElementById('clinic_add3').value = docData.clinic_add3 || '';
        document.getElementById('clinic_city').value = docData.clinic_city || '';
        document.getElementById('clinic_state').value = docData.clinic_state || '';
        document.getElementById('clinic_pin').value = docData.clinic_pin || '';
        document.getElementById('clinic_phone').value = docData.clinic_phone || '';
        document.getElementById('res_add1').value = docData.res_add1 || '';
        document.getElementById('res_add2').value = docData.res_add2 || '';
        document.getElementById('res_add3').value = docData.res_add3 || '';
        document.getElementById('res_city').value = docData.res_city || '';
        document.getElementById('res_state').value = docData.res_state || '';
        document.getElementById('res_pin').value = docData.res_pin || '';
        document.getElementById('res_phone').value = docData.res_phone || '';
        document.getElementById('doc_type').value = docData.doc_type || '';
        document.getElementById('mobile').value = docData.mobile || '';
        document.getElementById('email').value = docData.email || '';
        document.getElementById('pref_day_time').value = docData.pref_day_time || '';
        document.getElementById('visits_expected').value = docData.visits_expected || '';
        document.getElementById('hospital_name').value = docData.hospital_name || '';
        document.getElementById('doa').value = docData.doa || '';
        document.getElementById('contact_person').value = docData.contact_person || '';
        document.getElementById('patients_count').value = docData.patients_count || '';
        document.getElementById('reg_no').value = docData.reg_no || '';
        document.getElementById('pan_no').value = docData.pan_no || '';
        document.getElementById('gender').value = docData.gender || '';
        document.getElementById('religion').value = docData.religion || '';
        document.getElementById('dob').value = docData.dob || '';
        document.getElementById('doc').value = docData.doc || '';
        document.getElementById('modal_remarks').value = "";
        
        document.getElementById('actionModal').style.display = 'flex';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>