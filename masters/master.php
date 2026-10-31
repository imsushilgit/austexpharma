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

// ==========================================
// BACKEND AJAX HANDLER
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        // --- DIVISIONS ---
        if ($action === 'savediv') {
            $id =$_POST['divid'] ?? '';
            $divname = trim($_POST['divname'] ?? '');
            if (!empty($divname)) {
                if (!empty($id)) {
                    $stmt =$pdo->prepare("UPDATE divisions SET divname = ? WHERE id = ?");
                    $stmt->execute([$divname, $id]);$response = ["status" => "success", "message" => "Division updated successfully!"];
                } else {
                    $stmt =$pdo->prepare("INSERT INTO divisions (divname) VALUES (?)");
                    $stmt->execute([$divname]);$response = ["status" => "success", "message" => "Division added successfully!"];
                }
            }
        }
        
        // --- DEPARTMENTS ---
        elseif ($action === 'savedept') {
            $id =$_POST['deptid'] ?? '';
            $deptname = trim($_POST['deptname'] ?? '');
            if (!empty($deptname)) {
                if (!empty($id)) {
                    $stmt =$pdo->prepare("UPDATE departments SET deptname = ? WHERE deptid = ?");
                    $stmt->execute([$deptname, $id]);$response = ["status" => "success", "message" => "Department updated successfully!"];
                } else {
                    $stmt =$pdo->prepare("INSERT INTO departments (deptname) VALUES (?)");
                    $stmt->execute([$deptname]);$response = ["status" => "success", "message" => "Department added successfully!"];
                }
            }
        }
        
        // --- DESIGNATIONS ---
        elseif ($action === 'savedesig') {
            $id =$_POST['desigid'] ?? '';
            $deptid =$_POST['deptid'] ?? '';
            $designame = trim($_POST['designame'] ?? '');
            if (!empty($deptid) && !empty($designame)) {
                if (!empty($id)) {
                    $stmt =$pdo->prepare("UPDATE designations SET deptid = ?, designame = ? WHERE desigid = ?");
                    $stmt->execute([$deptid,$designame, $id]);$response = ["status" => "success", "message" => "Designation updated successfully!"];
                } else {
                    $stmt =$pdo->prepare("INSERT INTO designations (deptid, designame) VALUES (?, ?)");
                    $stmt->execute([$deptid, $designame]);$response = ["status" => "success", "message" => "Designation added successfully!"];
                }
            }
        }

        // Fetch fresh data for UI
        if ($response['status'] === 'success') {$response['data'] = [
                'divisions' => $pdo->query("SELECT * FROM divisions ORDER BY divname ASC")->fetchAll(PDO::FETCH_ASSOC),
                'departments' => $pdo->query("SELECT * FROM departments ORDER BY deptname ASC")->fetchAll(PDO::FETCH_ASSOC),
                'designations' => $pdo->query("SELECT d.desigid, d.designame, d.deptid, dp.deptname FROM designations d JOIN departments dp ON d.deptid = dp.deptid ORDER BY dp.deptname ASC, d.designame ASC")->fetchAll(PDO::FETCH_ASSOC)
            ];
        }
    } catch (PDOException $e) {$response['message'] = "Database error. Check for duplicate entries.";
    }

    echo json_encode($response);
    exit();
}

// Initial Data Load
$divisions =$pdo->query("SELECT * FROM divisions ORDER BY divname ASC")->fetchAll(PDO::FETCH_ASSOC);
$departments =$pdo->query("SELECT * FROM departments ORDER BY deptname ASC")->fetchAll(PDO::FETCH_ASSOC);
$designations =$pdo->query("SELECT d.desigid, d.designame, d.deptid, dp.deptname FROM designations d JOIN departments dp ON d.deptid = dp.deptid ORDER BY dp.deptname ASC, d.designame ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "HR Configuration Master";
include __DIR__ . '/../includes/header.php';
?>


<div class="grid-container">

    <!-- Division Section -->
    <div class="panel-box">
        <div class="panel-title" id="divHeader">Add Division</div>
        <form id="divForm" onsubmit="submitDiv(event)">
            <input type="hidden" name="divid" id="divid" value="">
            <div class="form-grid" style="grid-template-columns: 1fr auto;">
                <div class="form-group">
                    <label>Division Name</label>
                    <input type="text" name="divname" id="divName" required placeholder="e.g. Pharma">
                </div>
                <div style="display:flex; gap: 4px;">
                    <button type="submit" class="btn btn-primary" id="btnDivSubmit">Save</button>
                    <button type="button" class="btn btn-secondary" id="btnDivCancel" onclick="cancelDiv()" style="display:none; padding: 0 8px;">✕</button>
                </div>
            </div>
        </form>

        <div class="table-container">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;">Sl.No</th>
                        <th>Division Name</th>
                    </tr>
                </thead>
                <tbody id="divTableBody">
                    <?php if (empty($divisions)): ?>
                        <tr><td colspan="2" style="text-align: center; color: #64748b; padding: 15px;">No divisions found.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($divisions as$div): ?>
                        <tr class="clickable-row" onclick="editDiv(<?= $div['id'] ?>, '<?= htmlspecialchars($div['divname'], ENT_QUOTES); ?>')">
                            <td style="text-align: center; color: #64748b;"><?= $i++; ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($div['divname']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Department Section -->
    <div class="panel-box">
        <div class="panel-title" id="deptHeader">Add Department</div>
        <form id="deptForm" onsubmit="submitDept(event)">
            <input type="hidden" name="deptid" id="deptid" value="">
            <div class="form-grid" style="grid-template-columns: 1fr auto;">
                <div class="form-group">
                    <label>Department Name</label>
                    <input type="text" name="deptname" id="dName" required placeholder="e.g. Dispatch">
                </div>
                <div style="display:flex; gap: 4px;">
                    <button type="submit" class="btn btn-primary" id="btnDeptSubmit">Save</button>
                    <button type="button" class="btn btn-secondary" id="btnDeptCancel" onclick="cancelDept()" style="display:none; padding: 0 8px;">✕</button>
                </div>
            </div>
        </form>

        <div class="table-container">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;">Sl.No</th>
                        <th>Department Name</th>
                    </tr>
                </thead>
                <tbody id="deptTableBody">
                    <?php if (empty($departments)): ?>
                        <tr><td colspan="2" style="text-align: center; color: #64748b; padding: 15px;">No departments found.</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($departments as$d): ?>
                        <tr class="clickable-row" onclick="editDept(<?= $d['deptid'] ?>, '<?= htmlspecialchars($d['deptname'], ENT_QUOTES); ?>')">
                            <td style="text-align: center; color: #64748b;"><?= $i++; ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($d['deptname']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Designation Section -->
    <div class="panel-box">
        <div class="panel-title" id="desigHeader">Add Designation</div>
        <form id="desigForm" onsubmit="submitDesig(event)">
            <input type="hidden" name="desigid" id="desigid" value="">
            <div class="form-grid" style="grid-template-columns: 1fr;">
                <div class="form-group">
                    <label>Department</label>
                    <select name="deptid" id="desigDept" required>
                        <option value="">Select Dept</option>
                        <?php foreach ($departments as$d): ?>
                            <option value="<?= $d['deptid'] ?>"><?= htmlspecialchars($d['deptname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-grid" style="grid-template-columns: 1fr auto;">
                    <div class="form-group">
                        <label>Designation Name</label>
                        <input type="text" name="designame" id="desigName" required placeholder="e.g. Area Manager">
                    </div>
                    <div style="display:flex; gap: 4px;">
                        <button type="submit" class="btn btn-primary" id="btnDesigSubmit">Save</button>
                        <button type="button" class="btn btn-secondary" id="btnDesigCancel" onclick="cancelDesig()" style="display:none; padding: 0 8px;">✕</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="table-container">
            <table class="compact-table">
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;">Sl.No</th>
                        <th>Department</th>
                        <th>Designation</th>
                    </tr>
                </thead>
                <tbody id="desigTableBody">
                    <?php if (empty($designations)): ?>
                        <tr><td colspan="3" style="text-align: center; color: #64748b; padding: 15px;">No designations found.</td></tr>
                    <?php else: ?>
                        <?php $j = 1; foreach ($designations as$des): ?>
                        <tr class="clickable-row" onclick="editDesig(<?= $des['desigid'] ?>, <?= $des['deptid'] ?>, '<?= htmlspecialchars($des['designame'], ENT_QUOTES); ?>')">
                            <td style="text-align: center; color: #64748b;"><?= $j++; ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($des['deptname']); ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($des['designame']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
    function escapeHtml(text) {
        if (!text) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function updateUI(data) {
        const divTbody = document.getElementById('divTableBody');
        let divHtml = '';
        if (data.divisions.length === 0) {
            divTbody.innerHTML = '<tr><td colspan="2" style="text-align: center; color: #64748b; padding: 15px;">No divisions found.</td></tr>';
        } else {
            data.divisions.forEach((div, i) => {
                let escName = escapeHtml(div.divname);
                divHtml += `<tr class="clickable-row" onclick="editDiv(${div.id}, '${escName.replace(/'/g, "\\'")}')">
                    <td style="text-align: center; color: #64748b;">${i + 1}</td>
                    <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                </tr>`;
            });
            divTbody.innerHTML = divHtml;
        }

        const deptTbody = document.getElementById('deptTableBody');
        let dHtml = ''; let optHtml = '<option value="">Select Dept</option>';
        if (data.departments.length === 0) {
            deptTbody.innerHTML = '<tr><td colspan="2" style="text-align: center; color: #64748b; padding: 15px;">No departments found.</td></tr>';
        } else {
            data.departments.forEach((d, i) => {
                let escName = escapeHtml(d.deptname);
                dHtml += `<tr class="clickable-row" onclick="editDept(${d.deptid}, '${escName.replace(/'/g, "\\'")}')">
                    <td style="text-align: center; color: #64748b;">${i + 1}</td>
                    <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                </tr>`;
                optHtml += `<option value="${d.deptid}">${escName}</option>`;
            });
            deptTbody.innerHTML = dHtml;
        }
        document.getElementById('desigDept').innerHTML = optHtml;

        const desigTbody = document.getElementById('desigTableBody');
        if (data.designations.length === 0) {
            desigTbody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: #64748b; padding: 15px;">No designations found.</td></tr>';
        } else {
            let desHtml = '';
            data.designations.forEach((des, i) => {
                let escDesName = escapeHtml(des.designame);
                let escDeptName = escapeHtml(des.deptname);
                desHtml += `<tr class="clickable-row" onclick="editDesig(${des.desigid}, ${des.deptid}, '${escDesName.replace(/'/g, "\\'")}')">
                    <td style="text-align: center; color: #64748b;">${i + 1}</td>
                    <td style="color: #64748b;">${escDeptName}</td>
                    <td style="color:#0f172a; font-weight:bold;">${escDesName}</td>
                </tr>`;
            });
            desigTbody.innerHTML = desHtml;
        }
    }

    // DIV LOGIC
    function editDiv(id, name) {
        document.getElementById('divid').value = id;
        document.getElementById('divName').value = name;
        document.getElementById('divHeader').innerText = 'Update Division';
        document.getElementById('btnDivSubmit').innerText = 'Update';
        document.getElementById('btnDivSubmit').style.background = '#059669';
        document.getElementById('btnDivCancel').style.display = 'inline-flex';
    }
    
    function cancelDiv() {
        document.getElementById('divForm').reset();
        document.getElementById('divid').value = '';
        document.getElementById('divHeader').innerText = 'Add Division';
        document.getElementById('btnDivSubmit').innerText = 'Save';
        document.getElementById('btnDivSubmit').style.background = '';
        document.getElementById('btnDivCancel').style.display = 'none';
    }
    
    function submitDiv(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('divForm'));
        formData.append('ajax_action', 'savediv');
        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') { 
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                cancelDiv(); updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error.');
        });
    }

    // DEPT LOGIC
    function editDept(id, name) {
        document.getElementById('deptid').value = id;
        document.getElementById('dName').value = name;
        document.getElementById('deptHeader').innerText = 'Update Department';
        document.getElementById('btnDeptSubmit').innerText = 'Update';
        document.getElementById('btnDeptSubmit').style.background = '#059669';
        document.getElementById('btnDeptCancel').style.display = 'inline-flex';
    }
    
    function cancelDept() {
        document.getElementById('deptForm').reset();
        document.getElementById('deptid').value = '';
        document.getElementById('deptHeader').innerText = 'Add Department';
        document.getElementById('btnDeptSubmit').innerText = 'Save';
        document.getElementById('btnDeptSubmit').style.background = '';
        document.getElementById('btnDeptCancel').style.display = 'none';
    }
    
    function submitDept(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('deptForm'));
        formData.append('ajax_action', 'savedept');
        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') { 
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                cancelDept(); updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error.');
        });
    }

    // DESIG LOGIC
    function editDesig(id, deptId, name) {
        document.getElementById('desigid').value = id;
        document.getElementById('desigDept').value = deptId;
        document.getElementById('desigName').value = name;
        document.getElementById('desigHeader').innerText = 'Update Designation';
        document.getElementById('btnDesigSubmit').innerText = 'Update';
        document.getElementById('btnDesigSubmit').style.background = '#059669';
        document.getElementById('btnDesigCancel').style.display = 'inline-flex';
    }
    
    function cancelDesig() {
        document.getElementById('desigForm').reset();
        document.getElementById('desigid').value = '';
        document.getElementById('desigHeader').innerText = 'Add Designation';
        document.getElementById('btnDesigSubmit').innerText = 'Save';
        document.getElementById('btnDesigSubmit').style.background = '';
        document.getElementById('btnDesigCancel').style.display = 'none';
    }
    
    function submitDesig(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('desigForm'));
        formData.append('ajax_action', 'savedesig');
        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') { 
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                cancelDesig(); updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error.');
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>