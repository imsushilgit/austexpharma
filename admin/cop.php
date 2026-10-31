<?php
// HARD CACHE BUSTER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../includes/core.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]); exit();
    }
    header("Location: ../auth/login.php"); exit();
}

// AUTO-SCHEMA FIXER: Creates the COP Settings table automatically if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cop_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parameter_name VARCHAR(150) NOT NULL UNIQUE,
        parameter_value VARCHAR(255) NOT NULL,
        description TEXT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// ==========================================
// BACKEND AJAX HANDLER
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'save') {
            $id =$_POST['id'] ?? '';
            $param_name = trim($_POST['parameter_name'] ?? '');
            $param_val = trim($_POST['parameter_value'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $status = trim($_POST['status'] ?? 'active');

            if (!empty($param_name) && !empty($param_val)) {
                
                // Duplicate check for parameter name
                $checkSql = "SELECT id FROM cop_settings WHERE parameter_name = ?";
                $checkParams = [$param_name];
                if (!empty($id)) {$checkSql .= " AND id != ?";
                    $checkParams[] =$id;
                }
                $stmtChk = $pdo->prepare($checkSql);
                $stmtChk->execute($checkParams);
                
                if ($stmtChk->rowCount() > 0) {
                    echo json_encode(["status" => "error", "message" => "This Parameter Name already exists!"]);
                    exit();
                }

                if (!empty($id)) {
                    $stmt =$pdo->prepare("UPDATE cop_settings SET parameter_name=?, parameter_value=?, description=?, status=? WHERE id=?");
                    $stmt->execute([$param_name,$param_val, $desc,$status, $id]);$response = ["status" => "success", "message" => "COP Setting updated successfully!"];
                } else {
                    $stmt =$pdo->prepare("INSERT INTO cop_settings (parameter_name, parameter_value, description, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$param_name, $param_val,$desc, $status]);$response = ["status" => "success", "message" => "COP Setting added successfully!"];
                }
            } else {
                $response['message'] = "Parameter Name and Value are required fields.";
            }
        }

        if ($response['status'] === 'success' || $action === 'fetch') {
            $response['data'] =$pdo->query("SELECT * FROM cop_settings ORDER BY parameter_name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $response['status'] = 'success';
        }
    } catch (PDOException $e) {$response['message'] = "Database error occurred.";
    }

    echo json_encode($response);
    exit();
}

$cop_records =$pdo->query("SELECT * FROM cop_settings ORDER BY parameter_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "COP Settings Master";
include __DIR__ . '/../includes/header.php';
?>


<!-- Add/Update Form -->
<div class="panel-box">
    <div class="panel-title" id="formHeader">1. Configure COP Parameter</div>
    <form id="copForm" onsubmit="submitForm(event)" class="compact-form">
        <input type="hidden" name="id" id="recordId" value="">
        
        <div class="form-grid" style="grid-template-columns: 1.5fr 1.5fr 2fr 1fr auto;">
            <div class="form-group">
                <label>Parameter / Rule Name</label>
                <input type="text" name="parameter_name" id="cName" required placeholder="e.g. MIN_ORDER_QTY">
            </div>
            <div class="form-group">
                <label>Parameter Value</label>
                <input type="text" name="parameter_value" id="cValue" required placeholder="e.g. 5000">
            </div>
            <div class="form-group">
                <label>Short Description / Note</label>
                <input type="text" name="description" id="cDesc" placeholder="Explanation of this rule">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="cStatus">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="btn-action-row">
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Rule</button>
                <button type="button" class="btn btn-secondary" id="btnCancel" onclick="cancelEdit()" style="display:none;">Cancel</button>
            </div>
        </div>
    </form>
</div>

<!-- Ledger Panel -->
<div class="panel-box">
    <div class="panel-title">
        <span>2. COP Parameters Directory (Click row to edit)</span>
        <button class="btn-export" onclick="exportToExcel()">Export Excel</button>
    </div>
    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 30px; text-align: center;">Sl.No</th>
                    <th>Parameter / Rule Name</th>
                    <th>Configured Value</th>
                    <th>Description</th>
                    <th style="text-align: center;">Status</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody id="copTableBody">
                <?php if (empty($cop_records)): ?>
                    <tr><td colspan="6" style="text-align: center; color: #64748b; padding: 20px;">No parameters configured yet.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($cop_records as$c): ?>
                    <tr class="clickable-row" 
                        data-name="<?= htmlspecialchars($c['parameter_name']) ?>" 
                        data-val="<?= htmlspecialchars($c['parameter_value']) ?>" 
                        data-desc="<?= htmlspecialchars($c['description'] ?? '') ?>" 
                        data-status="<?= htmlspecialchars($c['status']) ?>" 
                        onclick="editRecord(<?= $c['id'] ?>, this)">
                        <td style="text-align: center; color:#64748b;"><?= $i++; ?></td>
                        <td style="color:#1d4ed8; font-weight:bold; font-family:monospace;"><?= htmlspecialchars($c['parameter_name']); ?></td>
                        <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($c['parameter_value']); ?></td>
                        <td style="color:#475569;"><?= htmlspecialchars($c['description'] ?: '-'); ?></td>
                        <td style="text-align: center;">
                            <span class="<?= $c['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                <?= strtoupper($c['status']); ?>
                            </span>
                        </td>
                        <td style="color:#64748b; font-size:9px;"><?= date('d-M-Y H:i', strtotime($c['updated_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    let copRecords = <?= json_encode($cop_records); ?>;

    function escapeHtml(text) {
        if (!text && text !== 0) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function updateUI(data) {
        const tbody = document.getElementById('copTableBody');
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 20px;">No parameters configured yet.</td></tr>';
            return;
        }

        let html = '';
        data.forEach((c, i) => {
            let escName = escapeHtml(c.parameter_name);
            let escVal = escapeHtml(c.parameter_value);
            let escDesc = escapeHtml(c.description);
            let escStatus = escapeHtml(c.status);
            
            let statusBadge = c.status === 'active' 
                ? '<span class="status-active">ACTIVE</span>' 
                : '<span class="status-inactive">INACTIVE</span>';
                
            let dateStr = c.updated_at ? c.updated_at.substring(0, 16).replace('T', ' ') : '-';
            
            html += `<tr class="clickable-row" 
                        data-name="${escName}" data-val="${escVal}" 
                        data-desc="${escDesc}" data-status="${escStatus}" 
                        onclick="editRecord(${c.id}, this)">
                <td style="text-align: center; color:#64748b;">${i + 1}</td>
                <td style="color:#1d4ed8; font-weight:bold; font-family:monospace;">${escName}</td>
                <td style="color:#0f172a; font-weight:bold;">${escVal}</td>
                <td style="color:#475569;">${escDesc || '-'}</td>
                <td style="text-align: center;">${statusBadge}</td>
                <td style="color:#64748b; font-size:9px;">${dateStr}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function editRecord(id, row) {
        document.getElementById('recordId').value = id;
        document.getElementById('cName').value = row.getAttribute('data-name') || '';
        document.getElementById('cValue').value = row.getAttribute('data-val') || '';
        document.getElementById('cDesc').value = row.getAttribute('data-desc') || '';
        document.getElementById('cStatus').value = row.getAttribute('data-status') || 'active';
        
        document.getElementById('formHeader').innerText = '1. Update COP Parameter';
        document.getElementById('btnSubmit').innerText = 'Update Rule';
        document.getElementById('btnSubmit').style.background = '#059669';
        document.getElementById('btnCancel').style.display = 'inline-flex';
        
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch(e) {}
    }

    function cancelEdit() {
        document.getElementById('copForm').reset();
        document.getElementById('recordId').value = '';
        document.getElementById('formHeader').innerText = '1. Configure COP Parameter';
        document.getElementById('btnSubmit').innerText = 'Save Rule';
        document.getElementById('btnSubmit').style.background = '';
        document.getElementById('btnCancel').style.display = 'none';
    }

    function submitForm(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('copForm'));
        formData.append('ajax_action', 'save');
        
        document.getElementById('btnSubmit').innerText = '...';

        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') { 
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                copRecords = data.data; 
                cancelEdit(); 
                updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error.');
        }).finally(() => {
            document.getElementById('btnSubmit').innerText = document.getElementById('recordId').value ? 'Update Rule' : 'Save Rule';
        });
    }

    function exportToExcel() {
        if(copRecords.length === 0) {
            if (typeof AppAlert !== 'undefined') AppAlert.warning("Database is empty.");
            return;
        }
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;">
            <th>Parameter / Rule Name</th><th>Configured Value</th><th>Description</th><th>Status</th><th>Last Updated</th></tr>`;
            
        copRecords.forEach(c => {
            html += `<tr>
                <td>${escapeHtml(c.parameter_name)}</td>
                <td>${escapeHtml(c.parameter_value)}</td>
                <td>${escapeHtml(c.description)}</td>
                <td>${escapeHtml(c.status).toUpperCase()}</td>
                <td>${escapeHtml(c.updated_at)}</td>
            </tr>`;
        });
        
        const a = document.createElement("a"); 
        a.href = URL.createObjectURL(new Blob([html+"</table>"], { type: 'application/vnd.ms-excel' })); 
        a.download = `Company_COP_Settings_${new Date().toISOString().slice(0,10)}.xls`; 
        a.click();
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>