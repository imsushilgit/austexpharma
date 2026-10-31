<?php
ob_start();
require_once __DIR__ . '/../includes/core.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]); exit();
    }
    header("Location: ../auth/login.php"); exit();
}

// AUTO-SCHEMA FIXER: Add new columns if they do not exist
try {
    $chk1 =$pdo->query("SHOW COLUMNS FROM transporters LIKE 'branch_name'");
    if ($chk1->rowCount() == 0) {$pdo->exec("ALTER TABLE transporters ADD COLUMN branch_name VARCHAR(150) NULL"); }
    
    $chk2 =$pdo->query("SHOW COLUMNS FROM transporters LIKE 'auth_person'");
    if ($chk2->rowCount() == 0) {$pdo->exec("ALTER TABLE transporters ADD COLUMN auth_person VARCHAR(100) NULL"); }
} catch (Exception $e) {}

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'save') {
            $id =$_POST['id'] ?? '';
            $transportername = trim($_POST['transportername'] ?? '');
            $branch_name = trim($_POST['branch_name'] ?? '');
            $auth_person = trim($_POST['auth_person'] ?? '');
            $servicearea = trim($_POST['servicearea'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (!empty($transportername) && !empty($servicearea)) {
                if (!empty($id)) {
                    $stmt =$pdo->prepare("UPDATE transporters SET transportername = ?, branch_name = ?, auth_person = ?, servicearea = ?, phone = ? WHERE id = ?");
                    $stmt->execute([$transportername, $branch_name,$auth_person, $servicearea,$phone, $id]);$response = ["status" => "success", "message" => "Transporter updated successfully!"];
                } else {
                    $stmt =$pdo->prepare("INSERT INTO transporters (transportername, branch_name, auth_person, servicearea, phone) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$transportername,$branch_name, $auth_person,$servicearea, $phone]);$response = ["status" => "success", "message" => "Transporter added successfully!"];
                }
            } else {
                $response['message'] = "Transporter Name and Service Area are required.";
            }
        } elseif ($action === 'delete') {
            $id =$_POST['id'] ?? '';
            if (!empty($id)) {
                $stmt =$pdo->prepare("DELETE FROM transporters WHERE id = ?");
                $stmt->execute([$id]);$response = ["status" => "success", "message" => "Transporter deleted successfully!"];
            }
        }

        if ($response['status'] === 'success') {
            $response['data'] =$pdo->query("SELECT * FROM transporters ORDER BY transportername ASC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {$response['message'] = "Database error. Please try again.";
    }

    echo json_encode($response);
    exit();
}

$transporters =$pdo->query("SELECT * FROM transporters ORDER BY transportername ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Transporters Master";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title" id="formHeader">1. Register / Update Shipping Carrier</div>
    <form id="transporterForm" onsubmit="submitForm(event)" class="compact-form">
        <input type="hidden" name="id" id="recordId" value="">
        
        <div class="form-grid" style="grid-template-columns: 1.5fr 1fr 1fr;">
            <div class="form-group">
                <label>Transporter Name</label>
                <input type="text" name="transportername" id="tName" required placeholder="e.g. Jagdamba Transport">
            </div>
            <div class="form-group">
                <label>Branch Name</label>
                <input type="text" name="branch_name" id="tBranch" placeholder="e.g. Patna Hub">
            </div>
            <div class="form-group">
                <label>Authorized Person</label>
                <input type="text" name="auth_person" id="tPerson" placeholder="e.g. Rahul Kumar">
            </div>
        </div>

        <div class="form-grid" style="grid-template-columns: 2.5fr 1fr auto; margin-top: 2px;">
            <div class="form-group">
                <label>Serviced Route / Areas</label>
                <input type="text" name="servicearea" id="tArea" required placeholder="e.g. Arrah, Buxar, Sasaram">
            </div>
            <div class="form-group">
                <label>Contact No.</label>
                <input type="text" name="phone" id="tPhone" placeholder="Enter Phone No">
            </div>
            <div class="btn-action-row">
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Transporter</button>
                <button type="button" class="btn btn-secondary" id="btnCancel" onclick="cancelEdit()" style="display:none;">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div class="panel-box">
    <div class="panel-title">2. Transporters Directory Ledger (Click row to edit)</div>
    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 30px; text-align: center;">Sl.No</th>
                    <th>Transporter Name</th>
                    <th>Branch Name</th>
                    <th>Authorized Person</th>
                    <th>Serviced Areas / Routes</th>
                    <th>Contact No</th>
                </tr>
            </thead>
            <tbody id="transporterTableBody">
                <?php if (empty($transporters)): ?>
                    <tr><td colspan="6" style="text-align: center; color: #64748b; padding: 20px;">No transporters registered yet.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($transporters as$t): ?>
                    <tr class="clickable-row" 
                        data-name="<?= htmlspecialchars($t['transportername']) ?>" 
                        data-branch="<?= htmlspecialchars($t['branch_name'] ?? '') ?>" 
                        data-person="<?= htmlspecialchars($t['auth_person'] ?? '') ?>" 
                        data-area="<?= htmlspecialchars($t['servicearea']) ?>" 
                        data-phone="<?= htmlspecialchars($t['phone'] ?? '') ?>" 
                        onclick="editRecord(<?= $t['id'] ?>, this)">
                        <td style="text-align: center; color: #64748b;"><?= $i++; ?></td>
                        <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($t['transportername']); ?></td>
                        <td style="color:#475569;"><?= htmlspecialchars($t['branch_name'] ?: '-'); ?></td>
                        <td style="color:#059669; font-weight:bold;"><?= htmlspecialchars($t['auth_person'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($t['servicearea']); ?></td>
                        <td style="font-family:monospace; font-weight:bold; color:#1e2a78;"><?= htmlspecialchars($t['phone'] ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function escapeHtml(text) {
        if (!text && text !== 0) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function updateUI(data) {
        const tbody = document.getElementById('transporterTableBody');
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 20px;">No transporters registered yet.</td></tr>';
            return;
        }

        let html = '';
        data.forEach((t, i) => {
            let escName = escapeHtml(t.transportername);
            let escBranch = escapeHtml(t.branch_name);
            let escPerson = escapeHtml(t.auth_person);
            let escArea = escapeHtml(t.servicearea);
            let escPhone = escapeHtml(t.phone);
            
            html += `<tr class="clickable-row" 
                        data-name="${escName}" 
                        data-branch="${escBranch}" 
                        data-person="${escPerson}" 
                        data-area="${escArea}" 
                        data-phone="${escPhone}" 
                        onclick="editRecord(${t.id}, this)">
                <td style="text-align: center; color:#64748b;">${i + 1}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                <td style="color:#475569;">${escBranch || '-'}</td>
                <td style="color:#059669; font-weight:bold;">${escPerson || '-'}</td>
                <td>${escArea}</td>
                <td style="font-family:monospace; font-weight:bold; color:#1e2a78;">${escPhone || '-'}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function editRecord(id, row) {
        document.getElementById('recordId').value = id;
        document.getElementById('tName').value = row.getAttribute('data-name') || '';
        document.getElementById('tBranch').value = row.getAttribute('data-branch') || '';
        document.getElementById('tPerson').value = row.getAttribute('data-person') || '';
        document.getElementById('tArea').value = row.getAttribute('data-area') || '';
        document.getElementById('tPhone').value = row.getAttribute('data-phone') || '';
        
        document.getElementById('formHeader').innerText = '1. Update Transporter Information';
        document.getElementById('btnSubmit').innerText = 'Update Transporter';
        document.getElementById('btnSubmit').style.background = '#059669';
        document.getElementById('btnCancel').style.display = 'inline-flex';
        
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch(e) {}
    }

    function cancelEdit() {
        document.getElementById('transporterForm').reset();
        document.getElementById('recordId').value = '';
        document.getElementById('formHeader').innerText = '1. Register / Update Shipping Carrier';
        document.getElementById('btnSubmit').innerText = 'Save Transporter';
        document.getElementById('btnSubmit').style.background = '';
        document.getElementById('btnCancel').style.display = 'none';
    }

    function submitForm(e) {
        e.preventDefault();
        const form = document.getElementById('transporterForm');
        const formData = new FormData(form);
        formData.append('ajax_action', 'save');

        document.getElementById('btnSubmit').innerText = '...';

        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') {
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                cancelEdit(); updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(err => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error occurred.');
        }).finally(() => {
            document.getElementById('btnSubmit').innerText = document.getElementById('recordId').value ? 'Update Transporter' : 'Save Transporter';
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>