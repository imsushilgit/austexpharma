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

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'save') {
            $id =$_POST['id'] ?? '';
            $sendername = trim($_POST['sendername'] ?? '');
            $mobile = trim($_POST['mobile'] ?? '');

            if (!empty($sendername)) {
                if (!empty($id)) {
                    $stmt =$pdo->prepare("UPDATE senders SET sendername = ?, mobile = ? WHERE senderid = ?");
                    $stmt->execute([$sendername,$mobile, $id]);$response = ["status" => "success", "message" => "Sender updated successfully!"];
                } else {
                    $stmt =$pdo->prepare("INSERT INTO senders (sendername, mobile) VALUES (?, ?)");
                    $stmt->execute([$sendername, $mobile]);$response = ["status" => "success", "message" => "Sender added successfully!"];
                }
            } else {
                $response['message'] = "Sender Name is required.";
            }
        } elseif ($action === 'delete') {
            $id =$_POST['id'] ?? '';
            if (!empty($id)) {
                $stmt =$pdo->prepare("DELETE FROM senders WHERE senderid = ?");
                $stmt->execute([$id]);$response = ["status" => "success", "message" => "Sender deleted successfully!"];
            }
        }

        if ($response['status'] === 'success') {
            $response['data'] =$pdo->query("SELECT * FROM senders ORDER BY sendername ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (PDOException $e) {$response['message'] = "Database error. Please try again.";
    }

    echo json_encode($response);
    exit();
}

$senders =$pdo->query("SELECT * FROM senders ORDER BY sendername ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Dispatcher";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title" id="formHeader">1. Add New Authorized Sender</div>
    <form id="senderForm" onsubmit="submitForm(event)" class="compact-form">
        <input type="hidden" name="id" id="recordId" value="">
        <div class="form-grid" style="grid-template-columns: 1fr 1fr auto;">
            <div class="form-group">
                <label>Sender Name / Executive Name</label>
                <input type="text" name="sendername" id="sName" required placeholder="e.g. John Doe">
            </div>
            <div class="form-group">
                <label>Mobile Number (For Courier Tracking)</label>
                <input type="text" name="mobile" id="sMobile" placeholder="e.g. 9876543210">
            </div>
            <div class="btn-action-row">
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Sender</button>
                <button type="button" class="btn btn-secondary" id="btnCancel" onclick="cancelEdit()" style="display:none;">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div class="panel-box">
    <div class="panel-title">2. Authorized Senders Directory (Click row to edit)</div>
    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;">Sl.No</th>
                    <th>Sender Name</th>
                    <th>Mobile Number</th>
                </tr>
            </thead>
            <tbody id="senderTableBody">
                <?php if (empty($senders)): ?>
                    <tr><td colspan="3" style="text-align: center; color: #64748b; padding: 20px;">No senders registered yet.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($senders as$s): ?>
                    <tr class="clickable-row" 
                        data-name="<?= htmlspecialchars($s['sendername']) ?>" 
                        data-mobile="<?= htmlspecialchars($s['mobile'] ?? '') ?>" 
                        onclick="editRecord(<?= $s['senderid'] ?>, this)">
                        <td style="text-align: center; color:#64748b;"><?= $i++; ?></td>
                        <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($s['sendername']); ?></td>
                        <td style="font-family:monospace; font-weight:bold; color:#1e2a78;"><?= htmlspecialchars($s['mobile'] ?: '-'); ?></td>
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
        const tbody = document.getElementById('senderTableBody');
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: #64748b; padding: 20px;">No senders registered yet.</td></tr>';
            return;
        }

        let html = '';
        data.forEach((s, i) => {
            let escName = escapeHtml(s.sendername);
            let escMobile = escapeHtml(s.mobile);
            
            html += `<tr class="clickable-row" 
                        data-name="${escName}" 
                        data-mobile="${escMobile}" 
                        onclick="editRecord(${s.senderid}, this)">
                <td style="text-align: center; color:#64748b;">${i + 1}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                <td style="font-family:monospace; font-weight:bold; color:#1e2a78;">${escMobile ? escMobile : '-'}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function editRecord(id, row) {
        document.getElementById('recordId').value = id;
        document.getElementById('sName').value = row.getAttribute('data-name') || '';
        document.getElementById('sMobile').value = row.getAttribute('data-mobile') || '';
        
        document.getElementById('formHeader').innerText = '1. Update Sender Information';
        document.getElementById('btnSubmit').innerText = 'Update Sender';
        document.getElementById('btnSubmit').style.background = '#059669';
        document.getElementById('btnCancel').style.display = 'inline-flex';
        
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch(e) {}
    }

    function cancelEdit() {
        document.getElementById('senderForm').reset();
        document.getElementById('recordId').value = '';
        document.getElementById('formHeader').innerText = '1. Add New Authorized Sender';
        document.getElementById('btnSubmit').innerText = 'Save Sender';
        document.getElementById('btnSubmit').style.background = '';
        document.getElementById('btnCancel').style.display = 'none';
    }

    function submitForm(e) {
        e.preventDefault();
        const form = document.getElementById('senderForm');
        const formData = new FormData(form);
        formData.append('ajax_action', 'save');
        
        document.getElementById('btnSubmit').innerText = '...';

        fetch('senders.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') {
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                cancelEdit(); 
                updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(err => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error occurred.');
        }).finally(() => {
            document.getElementById('btnSubmit').innerText = document.getElementById('recordId').value ? 'Update Sender' : 'Save Sender';
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>