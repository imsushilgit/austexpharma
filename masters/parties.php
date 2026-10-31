<?php
ob_start();
require_once __DIR__ . '/../includes/core.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
        exit();
    }
    header("Location: ../auth/login.php");
    exit();
}

function getStatePrefix($stateName) {
    $map = [
        'ANDAMAN AND NICOBAR ISLANDS' => 'AN', 'ANDHRA PRADESH' => 'AP', 'ARUNACHAL PRADESH' => 'AR',
        'ASSAM' => 'AS', 'BIHAR' => 'BR', 'CHANDIGARH' => 'CH', 'CHHATTISGARH' => 'CG',
        'DADRA AND NAGAR HAVELI' => 'DN', 'DAMAN AND DIU' => 'DD', 'DELHI' => 'DL',
        'GOA' => 'GA', 'GUJARAT' => 'GJ', 'HARYANA' => 'HR', 'HIMACHAL PRADESH' => 'HP',
        'JAMMU AND KASHMIR' => 'JK', 'JHARKHAND' => 'JH', 'KARNATAKA' => 'KA', 'KERALA' => 'KL',
        'LADAKH' => 'LA', 'LAKSHADWEEP' => 'LD', 'MADHYA PRADESH' => 'MP', 'MAHARASHTRA' => 'MH',
        'MANIPUR' => 'MN', 'MEGHALAYA' => 'ML', 'MIZORAM' => 'MZ', 'NAGALAND' => 'NL',
        'ODISHA' => 'OR', 'PUDUCHERRY' => 'PY', 'PUNJAB' => 'PB', 'RAJASTHAN' => 'RJ',
        'SIKKIM' => 'SK', 'TAMIL NADU' => 'TN', 'TELANGANA' => 'TG', 'TRIPURA' => 'TR',
        'UTTAR PRADESH' => 'UP', 'UTTARAKHAND' => 'UK', 'WEST BENGAL' => 'WB'
    ];
    $upper = strtoupper(trim($stateName));
    if (isset($map[$upper])) return $map[$upper];
    return strtoupper(substr($upper, 0, 2));
}

$masterPartyQuery = "
    SELECT 
        p.*, 
        COALESCE(
            (SELECT empname FROM assignparties ap JOIN employees e2 ON ap.empcode = e2.empcode WHERE TRIM(LOWER(ap.partyname)) = TRIM(LOWER(p.partyname)) LIMIT 1),
            (SELECT e.empname FROM employees e WHERE TRIM(UPPER(e.designation)) = 'MR' AND (TRIM(LOWER(p.area)) = TRIM(LOWER(e.hq)) OR TRIM(LOWER(p.area)) = TRIM(LOWER(e.state))) LIMIT 1),
            'Unassigned'
        ) AS assigned_mr
    FROM parties p 
    ORDER BY p.partyname ASC
";

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'generate_partycode') {
            $state = trim($_POST['state'] ?? '');
            if (empty($state)) { echo json_encode(["status" => "error"]); exit(); }
            
            $prefix = getStatePrefix($state);
            $stmt = $pdo->prepare("SELECT partycode FROM parties WHERE partycode LIKE ? ORDER BY LENGTH(partycode) DESC, partycode DESC LIMIT 1");
            $stmt->execute([$prefix . '%']);
            $lastCode = $stmt->fetchColumn();

            if ($lastCode && preg_match('/^' . $prefix . '(\d+)$/', $lastCode, $matches)) {
                $number = intval($matches[1]) + 1;
                $nextCode = $prefix . str_pad($number, 4, '0', STR_PAD_LEFT);
            } else {
                $nextCode = $prefix . '0001';
            }
            echo json_encode(["status" => "success", "partycode" => $nextCode]);
            exit();

        } elseif ($action === 'save') {
            // ROLE SCOPE: non-admins need write permission; only admin / back-office may create or edit masters
            if (!is_ops_user($ctx, ['ACCOUNTS','FINANCE','BILLING']) && empty($ctx['can_write'])) {
                echo json_encode(['status' => 'error', 'message' => 'Access Denied: you do not have permission to modify party master.']); exit();
            }
            $id = $_POST['id'] ?? '';
            $division = trim($_POST['division'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $partycode = trim($_POST['partycode'] ?? '');
            $partyname = trim($_POST['partyname'] ?? '');
            $hq = trim($_POST['hq'] ?? '');
            $area = trim($_POST['area'] ?? '');
            $contact = trim($_POST['contact'] ?? '');

            if (!empty($partyname) && !empty($hq) && !empty($state) && !empty($partycode)) {
                $checkSql = "SELECT id FROM parties WHERE partycode = ?";
                $params = [$partycode];
                if (!empty($id)) {
                    $checkSql .= " AND id != ?";
                    $params[] = $id;
                }
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute($params);
                if ($checkStmt->rowCount() > 0) {
                    echo json_encode(["status" => "error", "message" => "Party Code $partycode already exists!"]);
                    exit();
                }

                if (!empty($id)) {
                    $stmt = $pdo->prepare("UPDATE parties SET division=?, state=?, partycode=?, partyname=?, hq=?, area=?, contact=? WHERE id=?");
                    $stmt->execute([$division, $state, $partycode, $partyname, $hq, $area, $contact, $id]);
                    $response = ["status" => "success", "message" => "Party updated successfully!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO parties (division, state, partycode, partyname, hq, area, contact) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$division, $state, $partycode, $partyname, $hq, $area, $contact]);
                    $response = ["status" => "success", "message" => "Party added successfully!"];
                }
            } else {
                $response['message'] = "Division, State, Party Code, Name, and HQ are required fields.";
            }
        }

        if ($response['status'] === 'success') {
            $response['data'] = scope_filter_parties($pdo, $ctx, $pdo->query($masterPartyQuery)->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (PDOException $e) {
        $response['message'] = "Database error. (Ensure data is unique if restricted).";
    }
    echo json_encode($response);
    exit();
}

$parties = scope_filter_parties($pdo, $ctx, $pdo->query($masterPartyQuery)->fetchAll(PDO::FETCH_ASSOC));
$divisions = $pdo->query("SELECT divname FROM divisions ORDER BY divname ASC")->fetchAll(PDO::FETCH_ASSOC);
$hqs = $pdo->query("SELECT hqname FROM hqs ORDER BY hqname ASC")->fetchAll(PDO::FETCH_ASSOC);
$states = $pdo->query("SELECT statename FROM states ORDER BY statename ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Parties Master";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title" id="formHeader">1. Party / Client Entry</div>
    <form id="partyForm" onsubmit="submitForm(event)" class="compact-form">
        <input type="hidden" name="id" id="recordId" value="">
        
        <div class="form-grid" style="grid-template-columns: repeat(5, 1fr);">
            <div class="form-group">
                <label>Division</label>
                <select name="division" id="pDiv" required>
                    <option value="">Select Division</option>
                    <?php foreach($divisions as $div): ?>
                        <option value="<?= htmlspecialchars($div['divname']) ?>"><?= htmlspecialchars($div['divname']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>State</label>
                <select name="state" id="pState" onchange="generatePartyCode()" required>
                    <option value="">Select State</option>
                    <?php foreach($states as $s): ?>
                        <option value="<?= htmlspecialchars($s['statename']) ?>"><?= htmlspecialchars($s['statename']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Party Code</label>
                <input type="text" name="partycode" id="pCode" required placeholder="Auto" readonly style="color:#0ea5e9; font-weight:bold; background:#f1f5f9;">
            </div>
            <div class="form-group" style="grid-column: span 2;">
                <label>Party Name</label>
                <input type="text" name="partyname" id="pName" required placeholder="e.g. Soni Medico">
            </div>
        </div>

        <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr auto; align-items: end;">
            <div class="form-group">
                <label>Select HQ</label>
                <select name="hq" id="pHQ" required>
                    <option value="">Select HQ</option>
                    <?php foreach($hqs as $h): ?>
                        <option value="<?= htmlspecialchars($h['hqname']) ?>"><?= htmlspecialchars($h['hqname']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Delivery Area / City</label>
                <input type="text" name="area" id="pArea" required placeholder="Area">
            </div>
            <div class="form-group">
                <label>Contact</label>
                <input type="text" name="contact" id="pContact" placeholder="Phone">
            </div>
            <div class="btn-action-row">
                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Party</button>
                <button type="button" class="btn btn-secondary" id="btnCancel" onclick="cancelEdit()" style="display:none;">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div class="panel-box">
    <div class="panel-title">
        <span>2. Registered Parties Directory (Click row to edit)</span>
        <button class="btn-export" onclick="exportToExcel()">Export Excel</button>
    </div>
    <div class="table-container">
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 30px; text-align: center;">Sl.No</th>
                    <th>Code</th>
                    <th>Party Name</th>
                    <th>HQ / Area</th>
                    <th>State</th>
                    <th>Mapped MR</th>
                    <th>Contact</th>
                </tr>
            </thead>
            <tbody id="partyTableBody">
                <?php if (empty($parties)): ?>
                    <tr><td colspan="7" style="text-align: center; color: #64748b; padding: 20px;">No records.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($parties as $p): ?>
                    <tr class="clickable-row" 
                        data-div="<?= htmlspecialchars($p['division'] ?? '') ?>"
                        data-state="<?= htmlspecialchars($p['state'] ?? '') ?>"
                        data-code="<?= htmlspecialchars($p['partycode'] ?? '') ?>"
                        data-name="<?= htmlspecialchars($p['partyname'] ?? '') ?>" 
                        data-hq="<?= htmlspecialchars($p['hq'] ?? '') ?>" 
                        data-area="<?= htmlspecialchars($p['area'] ?? '') ?>" 
                        data-contact="<?= htmlspecialchars($p['contact'] ?? '') ?>" 
                        onclick="editRecord(<?= $p['id'] ?? 0 ?>, this)">
                        <td style="text-align: center; color:#64748b;"><?= $i++; ?></td>
                        <td style="color:#1d4ed8; font-family:monospace; font-weight:bold;"><?= htmlspecialchars($p['partycode'] ?: '-'); ?></td>
                        <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($p['partyname'] ?? ''); ?></td>
                        <td><?= htmlspecialchars($p['hq'] ?? ''); ?> <span style="color:#64748b; font-size:8px;">(<?= htmlspecialchars($p['area'] ?? ''); ?>)</span></td>
                        <td><?= htmlspecialchars($p['state'] ?: '-'); ?></td>
                        <td>
                            <?php if ($p['assigned_mr'] === 'Unassigned'): ?>
                                <span style="color:#dc2626; font-style:italic; font-size: 9px;">Unassigned</span>
                            <?php else: ?>
                                <strong style="color:#059669; font-size: 9px; text-transform:uppercase;"><?= htmlspecialchars($p['assigned_mr']); ?></strong>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($p['contact'] ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    let partyRecords = <?= json_encode($parties); ?>;

    function escapeHtml(text) {
        if (!text && text !== 0) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function generatePartyCode() {
        const state = document.getElementById('pState').value;
        if (!state || document.getElementById('recordId').value !== '') return;

        const formData = new FormData();
        formData.append('ajax_action', 'generate_partycode');
        formData.append('state', state);

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('pCode').value = data.partycode;
            }
        });
    }

    function updateUI(data) {
        const tbody = document.getElementById('partyTableBody');
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: #64748b; padding: 20px;">No records.</td></tr>';
            return;
        }

        let html = '';
        data.forEach((p, i) => {
            let escDiv = escapeHtml(p.division); let escState = escapeHtml(p.state);
            let escCode = escapeHtml(p.partycode); let escName = escapeHtml(p.partyname); 
            let escHQ = escapeHtml(p.hq); let escArea = escapeHtml(p.area); 
            let escContact = escapeHtml(p.contact);
            let mrDisplay = p.assigned_mr === 'Unassigned' 
                ? '<span style="color:#dc2626; font-style:italic; font-size: 9px;">Unassigned</span>' 
                : `<strong style="color:#059669; font-size: 9px; text-transform:uppercase;">${escapeHtml(p.assigned_mr)}</strong>`;
            
            html += `<tr class="clickable-row" 
                        data-div="${escDiv}" data-state="${escState}" data-code="${escCode}" 
                        data-name="${escName}" data-hq="${escHQ}" data-area="${escArea}" 
                        data-contact="${escContact}" onclick="editRecord(${p.id}, this)">
                <td style="text-align: center; color:#64748b;">${i + 1}</td>
                <td style="color:#1d4ed8; font-family:monospace; font-weight:bold;">${escCode || '-'}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                <td>${escHQ} <span style="color:#64748b; font-size:8px;">(${escArea})</span></td>
                <td>${escState || '-'}</td>
                <td>${mrDisplay}</td>
                <td style="font-family:monospace;">${escContact || '-'}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function editRecord(id, row) {
        document.getElementById('recordId').value = id;
        document.getElementById('pDiv').value = row.getAttribute('data-div') || '';
        document.getElementById('pState').value = row.getAttribute('data-state') || '';
        document.getElementById('pCode').value = row.getAttribute('data-code') || '';
        document.getElementById('pName').value = row.getAttribute('data-name') || '';
        document.getElementById('pHQ').value = row.getAttribute('data-hq') || '';
        document.getElementById('pArea').value = row.getAttribute('data-area') || '';
        document.getElementById('pContact').value = row.getAttribute('data-contact') || '';
        
        document.getElementById('formHeader').innerText = '1. Update Party Information';
        document.getElementById('btnSubmit').innerText = 'Update Party';
        document.getElementById('btnSubmit').style.background = '#059669';
        document.getElementById('btnCancel').style.display = 'inline-flex';
        
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch(e) {}
    }

    function cancelEdit() {
        document.getElementById('partyForm').reset();
        document.getElementById('recordId').value = '';
        document.getElementById('formHeader').innerText = '1. Party / Client Entry';
        document.getElementById('btnSubmit').innerText = 'Save Party';
        document.getElementById('btnSubmit').style.background = '';
        document.getElementById('btnCancel').style.display = 'none';
    }

    function submitForm(e) {
        e.preventDefault();
        const formData = new FormData(document.getElementById('partyForm'));
        formData.append('ajax_action', 'save');
        
        document.getElementById('btnSubmit').innerText = '...';

        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') { 
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                partyRecords = data.data; 
                cancelEdit(); updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error.');
        }).finally(() => {
            document.getElementById('btnSubmit').innerText = document.getElementById('recordId').value ? 'Update Party' : 'Save Party';
        });
    }

    function exportToExcel() {
        if(partyRecords.length === 0) {
            if (typeof AppAlert !== 'undefined') AppAlert.warning("Database is empty.");
            return;
        }
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;">
            <th>Division</th><th>State</th><th>Party Code</th><th>Party Name</th><th>HQ</th><th>Area</th><th>Mapped MR</th><th>Contact</th></tr>`;
            
        partyRecords.forEach(p => {
            html += `<tr><td>${escapeHtml(p.division)}</td><td>${escapeHtml(p.state)}</td><td>${escapeHtml(p.partycode)}</td><td>${escapeHtml(p.partyname)}</td><td>${escapeHtml(p.hq)}</td><td>${escapeHtml(p.area)}</td><td><b>${escapeHtml(p.assigned_mr)}</b></td><td>${escapeHtml(p.contact)}</td></tr>`;
        });
        
        const a = document.createElement("a"); 
        a.href = URL.createObjectURL(new Blob([html+"</table>"], { type: 'application/vnd.ms-excel' })); 
        a.download = `Company Name_Parties_Export_${new Date().toISOString().slice(0,10)}.xls`; 
        a.click();
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>