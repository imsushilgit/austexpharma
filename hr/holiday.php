<?php
require_once __DIR__ . '/../includes/core.php';

$ctx = require_page_access($pdo);
$canWrite = user_can_write($ctx);
$isAdmin  = $ctx['is_admin'];

// Admin manages holidays; others can view
$pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(150) NOT NULL,
    holiday_type ENUM('National','Regional','Company','Optional') DEFAULT 'Company',
    is_optional TINYINT(1) DEFAULT 0,
    year INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_date (holiday_date)
)");

if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'save_holiday') {
            if (!$isAdmin) {
                echo json_encode(['status'=>'error','message'=>'Only Admin can manage holidays']);
                exit;
            }
            $id = intval($_POST['id'] ?? 0);
            $date = trim($_POST['holiday_date'] ?? '');
            $name = trim($_POST['holiday_name'] ?? '');
            $type = $_POST['holiday_type'] ?? 'Company';
            $optional = intval($_POST['is_optional'] ?? 0);

            if (empty($date) || empty($name)) {
                echo json_encode(['status'=>'error','message'=>'Date and name required']);
                exit;
            }
            $year = (int)date('Y', strtotime($date));

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE holidays SET holiday_date=?, holiday_name=?, holiday_type=?, is_optional=?, year=? WHERE id=?");
                $stmt->execute([$date, $name, $type, $optional, $year, $id]);
                $response = ['status'=>'success','message'=>'Holiday updated'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO holidays (holiday_date, holiday_name, holiday_type, is_optional, year) VALUES (?,?,?,?,?)");
                $stmt->execute([$date, $name, $type, $optional, $year]);
                $response = ['status'=>'success','message'=>'Holiday added'];
            }
        }

        elseif ($action === 'delete_holiday') {
            if (!$isAdmin) {
                echo json_encode(['status'=>'error','message'=>'Only Admin can delete']);
                exit;
            }
            $id = intval($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM holidays WHERE id=?")->execute([$id]);
            $response = ['status'=>'success','message'=>'Holiday deleted'];
        }

        elseif ($action === 'fetch_holidays') {
            $year = intval($_POST['year'] ?? date('Y'));
            $stmt = $pdo->prepare("SELECT * FROM holidays WHERE year=? ORDER BY holiday_date");
            $stmt->execute([$year]);
            $response = ['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
        }

        elseif ($action === 'seed_national') {
            if (!$isAdmin) {
                echo json_encode(['status'=>'error','message'=>'Admin only']);
                exit;
            }
            $year = intval($_POST['year'] ?? date('Y'));
            // Common Indian national holidays (fixed dates; adjust yearly if needed)
            $list = [
                ["$year-01-26", 'Republic Day', 'National'],
                ["$year-08-15", 'Independence Day', 'National'],
                ["$year-10-02", 'Gandhi Jayanti', 'National'],
                ["$year-12-25", 'Christmas', 'National'],
            ];
            $stmt = $pdo->prepare("INSERT IGNORE INTO holidays (holiday_date, holiday_name, holiday_type, is_optional, year) VALUES (?,?,?,0,?)");
            $added = 0;
            foreach ($list as $h) {
                $stmt->execute([$h[0], $h[1], $h[2], $year]);
                $added += $stmt->rowCount();
            }
            $response = ['status'=>'success','message'=>"$added national holiday(s) added for $year"];
        }

    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $response['message'] = 'Holiday already exists for this date';
        } else {
            $response['message'] = $e->getMessage();
        }
    }
    echo json_encode($response);
    exit;
}

$pageTitle = "Holiday Calendar";
include __DIR__ . '/../includes/header.php';
?>


<div id="msgBox" class="msg-box"></div>

<?php if ($isAdmin): ?>
<div class="panel-box">
    <div class="panel-title">Add / Edit Holiday</div>
    <form id="holForm" onsubmit="saveHoliday(event)">
        <input type="hidden" id="holId" value="">
        <div class="form-grid" style="grid-template-columns: 1fr 2fr 1fr 0.8fr auto auto;">
            <div class="form-group">
                <label>Date</label>
                <input type="date" id="holDate" required>
            </div>
            <div class="form-group">
                <label>Holiday Name</label>
                <input type="text" id="holName" required placeholder="e.g. Diwali">
            </div>
            <div class="form-group">
                <label>Type</label>
                <select id="holType">
                    <option value="National">National</option>
                    <option value="Regional">Regional</option>
                    <option value="Company">Company</option>
                    <option value="Optional">Optional</option>
                </select>
            </div>
            <div class="form-group">
                <label>Optional?</label>
                <select id="holOptional">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
            <button type="button" class="btn btn-secondary" onclick="resetHolForm()">Clear</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel-box">
    <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
        <span>Holiday Calendar</span>
        <div style="display:flex; gap:8px; align-items:center;">
            <select id="holYear" onchange="loadHolidays()" style="height:26px; font-size:11px;">
                <?php for ($y = date('Y') + 1; $y >= date('Y') - 2; $y--): ?>
                    <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <?php if ($isAdmin): ?>
            <button class="btn btn-success btn-sm" onclick="seedNational()">Seed National Holidays</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="cal-grid" id="calGrid">
        <div style="grid-column:1/-1; text-align:center; color:#94a3b8; padding:20px;">Loading...</div>
    </div>

    <table class="compact-table" style="margin-top:16px;">
        <thead>
            <tr>
                <th>Date</th><th>Day</th><th>Holiday Name</th><th>Type</th><th>Optional</th>
                <?php if ($isAdmin): ?><th>Action</th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="holTableBody">
            <tr><td colspan="6" style="text-align:center;color:#94a3b8;">Loading...</td></tr>
        </tbody>
    </table>
</div>

<script>
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;

function showMsg(t, type='success') {
    const b = document.getElementById('msgBox');
    b.className = 'msg-box msg-' + type; b.innerText = t; b.style.display = 'block';
    setTimeout(() => b.style.display = 'none', 3500);
}

function typeBadge(t) {
    const m = {National:'badge-national', Regional:'badge-regional', Company:'badge-company', Optional:'badge-optional'};
    return `<span class="badge ${m[t]||'badge-company'}">${t}</span>`;
}

function loadHolidays() {
    const year = document.getElementById('holYear').value;
    const fd = new FormData();
    fd.append('ajax_action', 'fetch_holidays');
    fd.append('year', year);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        const grid = document.getElementById('calGrid');
        const tb = document.getElementById('holTableBody');
        if (!d.data || !d.data.length) {
            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#94a3b8;padding:20px;">No holidays for this year</div>';
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#94a3b8;">No holidays</td></tr>';
            return;
        }
        grid.innerHTML = d.data.map(h => `
            <div class="cal-card">
                <div class="date">${new Date(h.holiday_date+'T00:00:00').toLocaleDateString('en-IN', {day:'numeric', month:'short'})}</div>
                <div class="name">${h.holiday_name}</div>
                <div class="type">${typeBadge(h.holiday_type)}</div>
            </div>
        `).join('');

        tb.innerHTML = d.data.map(h => {
            const day = new Date(h.holiday_date+'T00:00:00').toLocaleDateString('en-IN', {weekday:'short'});
            let actions = '';
            if (IS_ADMIN) {
                actions = `<td>
                    <button class="btn btn-secondary btn-sm" onclick='editHol(${JSON.stringify(h)})'>Edit</button>
                    <button class="btn btn-danger btn-sm" onclick="delHol(${h.id})">Del</button>
                </td>`;
            }
            return `<tr>
                <td>${h.holiday_date}</td>
                <td>${day}</td>
                <td><strong>${h.holiday_name}</strong></td>
                <td>${typeBadge(h.holiday_type)}</td>
                <td>${h.is_optional==1?'Yes':'No'}</td>
                ${actions}
            </tr>`;
        }).join('');
    });
}

function saveHoliday(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('ajax_action', 'save_holiday');
    fd.append('id', document.getElementById('holId').value);
    fd.append('holiday_date', document.getElementById('holDate').value);
    fd.append('holiday_name', document.getElementById('holName').value);
    fd.append('holiday_type', document.getElementById('holType').value);
    fd.append('is_optional', document.getElementById('holOptional').value);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        showMsg(d.message, d.status==='success'?'success':'error');
        if (d.status==='success') { resetHolForm(); loadHolidays(); }
    });
}

function editHol(h) {
    document.getElementById('holId').value = h.id;
    document.getElementById('holDate').value = h.holiday_date;
    document.getElementById('holName').value = h.holiday_name;
    document.getElementById('holType').value = h.holiday_type;
    document.getElementById('holOptional').value = h.is_optional;
    window.scrollTo({top:0, behavior:'smooth'});
}

function delHol(id) {
    if (!confirm('Delete this holiday?')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_holiday');
    fd.append('id', id);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        showMsg(d.message, d.status==='success'?'success':'error');
        if (d.status==='success') loadHolidays();
    });
}

function resetHolForm() {
    document.getElementById('holId').value = '';
    document.getElementById('holForm').reset();
}

function seedNational() {
    const year = document.getElementById('holYear').value;
    if (!confirm('Add standard national holidays for ' + year + '?')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'seed_national');
    fd.append('year', year);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        showMsg(d.message, d.status==='success'?'success':'error');
        if (d.status==='success') loadHolidays();
    });
}

window.onload = loadHolidays;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
