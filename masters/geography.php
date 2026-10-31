<?php
// HARD CACHE BUSTER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
        exit();
    }
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/../includes/core.php';

// AUTO-SCHEMA FIXER FOR HIERARCHY CHAIN WITH COUNTRIES
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS countries (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        countryname VARCHAR(100) NOT NULL UNIQUE
    )");
    
    // Check if countryid exists in states, if not add it safely
    $chk = $pdo->query("SHOW COLUMNS FROM states LIKE 'countryid'");
    if ($chk->rowCount() == 0) {
        $pdo->exec("ALTER TABLE states ADD COLUMN countryid INT NOT NULL DEFAULT 1");
    }
    
    // Let's create standard compound keys/unique restraints for parent chains to block duplication
    try { $pdo->exec("ALTER TABLE states ADD CONSTRAINT unique_country_state UNIQUE (countryid, statename)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE districts ADD CONSTRAINT unique_state_dist UNIQUE (stateid, districtname)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE hqs ADD CONSTRAINT unique_dist_hq UNIQUE (districtid, hqname)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE cities ADD CONSTRAINT unique_hq_city (hqid, cityname)"); } catch (Exception $e) {}

    // Seed India as the default country if empty
    $chkCount = $pdo->query("SELECT COUNT(*) FROM countries");
    if ($chkCount->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO countries (countryname) VALUES ('India')");
    }
} catch (Exception $e) {}

// ==========================================
// 1. BACKEND AJAX HANDLER (ADD / UPDATE / FETCH)
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'savecountry') {
            $id = $_POST['id'] ?? '';
            $countryname = trim($_POST['countryname'] ?? '');
            if (!empty($countryname)) {
                if (!empty($id)) {
                    $stmt = $pdo->prepare("UPDATE countries SET countryname = ? WHERE id = ?");
                    $stmt->execute([$countryname, $id]);
                    $response = ["status" => "success", "message" => "Country updated successfully!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO countries (countryname) VALUES (?)");
                    $stmt->execute([$countryname]);
                    $response = ["status" => "success", "message" => "Country added successfully!"];
                }
            } else {
                $response['message'] = "Country Name is required.";
            }
        } 
        elseif ($action === 'savestate') {
            $id = $_POST['id'] ?? '';
            $countryid = $_POST['countryid'] ?? '';
            $statename = trim($_POST['statename'] ?? '');
            if (!empty($countryid) && !empty($statename)) {
                if (!empty($id)) {
                    $stmt = $pdo->prepare("UPDATE states SET countryid = ?, statename = ? WHERE id = ?");
                    $stmt->execute([$countryid, $statename, $id]);
                    $response = ["status" => "success", "message" => "State updated successfully!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO states (countryid, statename) VALUES (?, ?)");
                    $stmt->execute([$countryid, $statename]);
                    $response = ["status" => "success", "message" => "State added successfully!"];
                }
            } else {
                $response['message'] = "Please ensure both Country and State Name are filled.";
            }
        } 
        elseif ($action === 'savedistrict') {
            $id = $_POST['id'] ?? '';
            $stateid = $_POST['stateid'] ?? '';
            $districtname = trim($_POST['districtname'] ?? '');
            if (!empty($stateid) && !empty($districtname)) {
                if (!empty($id)) {
                    $stmt = $pdo->prepare("UPDATE districts SET stateid = ?, districtname = ? WHERE id = ?");
                    $stmt->execute([$stateid, $districtname, $id]);
                    $response = ["status" => "success", "message" => "District updated successfully!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO districts (stateid, districtname) VALUES (?, ?)");
                    $stmt->execute([$stateid, $districtname]);
                    $response = ["status" => "success", "message" => "District added successfully!"];
                }
            } else {
                $response['message'] = "Please ensure both State and District Name are filled.";
            }
        } 
        elseif ($action === 'savehq') {
            $id = $_POST['id'] ?? '';
            $districtid = $_POST['districtid'] ?? '';
            $hqname = trim($_POST['hqname'] ?? '');
            if (!empty($districtid) && !empty($hqname)) {
                if (!empty($id)) {
                    $stmt = $pdo->prepare("UPDATE hqs SET districtid = ?, hqname = ? WHERE id = ?");
                    $stmt->execute([$districtid, $hqname, $id]);
                    $response = ["status" => "success", "message" => "HQ updated successfully!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO hqs (districtid, hqname) VALUES (?, ?)");
                    $stmt->execute([$districtid, $hqname]);
                    $response = ["status" => "success", "message" => "HQ added successfully!"];
                }
            } else {
                $response['message'] = "Please ensure both District and HQ Name are filled.";
            }
        } 
        elseif ($action === 'savecity') {
            $id = $_POST['id'] ?? '';
            $hqid = $_POST['hqid'] ?? '';
            $cityname = trim($_POST['cityname'] ?? '');
            if (!empty($hqid) && !empty($cityname)) {
                if (!empty($id)) {
                    $stmt = $pdo->prepare("UPDATE cities SET hqid = ?, cityname = ? WHERE id = ?");
                    $stmt->execute([$hqid, $cityname, $id]);
                    $response = ["status" => "success", "message" => "City/Area updated successfully!"];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO cities (hqid, cityname) VALUES (?, ?)");
                    $stmt->execute([$hqid, $cityname]);
                    $response = ["status" => "success", "message" => "City/Area added successfully!"];
                }
            } else {
                $response['message'] = "Please ensure both HQ and City Name are filled.";
            }
        }

        if ($response['status'] === 'success') {
            $response['data'] = [
                'countries' => $pdo->query("SELECT * FROM countries ORDER BY countryname ASC")->fetchAll(PDO::FETCH_ASSOC),
                'states' => $pdo->query("SELECT s.id, s.statename, s.countryid, c.countryname FROM states s LEFT JOIN countries c ON s.countryid = c.id ORDER BY s.statename ASC")->fetchAll(PDO::FETCH_ASSOC),
                'districts' => $pdo->query("SELECT d.id, d.districtname, d.stateid, s.statename, s.countryid, c.countryname FROM districts d JOIN states s ON d.stateid = s.id LEFT JOIN countries c ON s.countryid = c.id ORDER BY s.statename ASC, d.districtname ASC")->fetchAll(PDO::FETCH_ASSOC),
                'hqs' => $pdo->query("SELECT h.id, h.hqname, h.districtid, d.districtname, d.stateid, s.statename, s.countryid, c.countryname FROM hqs h JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id LEFT JOIN countries c ON s.countryid = c.id ORDER BY d.districtname ASC, h.hqname ASC")->fetchAll(PDO::FETCH_ASSOC),
                'cities' => $pdo->query("SELECT ci.id, ci.cityname, ci.hqid, h.hqname, h.districtid, d.districtname, d.stateid, s.statename, s.countryid, c.countryname FROM cities ci JOIN hqs h ON ci.hqid = h.id JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id LEFT JOIN countries c ON s.countryid = c.id ORDER BY h.hqname ASC, ci.cityname ASC")->fetchAll(PDO::FETCH_ASSOC)
            ];
        }

    } catch (PDOException $e) {
        $response['message'] = "Database error. Entry may be a duplicate.";
    }

    echo json_encode($response);
    exit();
}

$countries = $pdo->query("SELECT * FROM countries ORDER BY countryname ASC")->fetchAll(PDO::FETCH_ASSOC);
$states    = $pdo->query("SELECT s.id, s.statename, s.countryid, c.countryname FROM states s LEFT JOIN countries c ON s.countryid = c.id ORDER BY s.statename ASC")->fetchAll(PDO::FETCH_ASSOC);
$districts = $pdo->query("SELECT d.id, d.districtname, d.stateid, s.statename, s.countryid, c.countryname FROM districts d JOIN states s ON d.stateid = s.id LEFT JOIN countries c ON s.countryid = c.id ORDER BY s.statename ASC, d.districtname ASC")->fetchAll(PDO::FETCH_ASSOC);
$hqs       = $pdo->query("SELECT h.id, h.hqname, h.districtid, d.districtname, d.stateid, s.statename, s.countryid, c.countryname FROM hqs h JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id LEFT JOIN countries c ON s.countryid = c.id ORDER BY d.districtname ASC, h.hqname ASC")->fetchAll(PDO::FETCH_ASSOC);
$cities    = $pdo->query("SELECT ci.id, ci.cityname, ci.hqid, h.hqname, h.districtid, d.districtname, d.stateid, s.statename, s.countryid, c.countryname FROM cities ci JOIN hqs h ON ci.hqid = h.id JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id LEFT JOIN countries c ON s.countryid = c.id ORDER BY h.hqname ASC, ci.cityname ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Geography Configuration";
include __DIR__ . '/../includes/header.php';
?>


<div class="tabs-nav">
    <button class="tab-btn active" onclick="switchTab('countryTab', this)">1. Countries</button>
    <button class="tab-btn" onclick="switchTab('stateTab', this)">2. States</button>
    <button class="tab-btn" onclick="switchTab('districtTab', this)">3. Districts</button>
    <button class="tab-btn" onclick="switchTab('hqTab', this)">4. Headquarters (HQ)</button>
    <button class="tab-btn" onclick="switchTab('cityTab', this)">5. Cities / Areas</button>
</div>

<!-- 1. COUNTRIES TAB -->
<div id="countryTab" class="tab-content active">
    <div class="panel-box">
        <div class="panel-title">Country Configuration</div>
        <form id="countryForm" onsubmit="submitForm(event, 'savecountry', 'countryForm', 'Add Country')" class="compact-form">
            <input type="hidden" name="id" value="">
            <div class="form-grid" style="grid-template-columns: 1fr auto; gap: 8px;">
                <div class="form-group">
                    <label>Country Name</label>
                    <input type="text" name="countryname" required placeholder="e.g. India">
                </div>
                <div class="btn-action-row">
                    <button type="submit" class="btn btn-primary" id="btnSubmit_countryForm">Add Country</button>
                    <button type="button" class="btn btn-secondary" onclick="cancelEdit('countryForm', 'Add Country')" style="display: none;">Cancel</button>
                </div>
            </div>
        </form>
        
        <div class="table-control-row">
            <div class="inline-filter-group">
                <label>Search Country Name</label>
                <input type="text" id="countrySearchInput" onkeyup="filterCountryTable()" placeholder="Type to search country...">
            </div>
            <button class="btn-export" onclick="exportCountryToExcel()">Export Countries</button>
        </div>

        <div class="table-container">
            <table class="compact-table" id="countryTable">
                <thead><tr><th style="width: 40px; text-align: center;">Sl.No</th><th>Country Name</th></tr></thead>
                <tbody id="countryTableBody">
                    <?php $i=1; foreach($countries as $c): ?>
                        <tr class="clickable-row" onclick="editRecord('country', <?= $c['id'] ?>, '<?= htmlspecialchars($c['countryname'], ENT_QUOTES) ?>')">
                            <td style="text-align: center; color: #64748b;"><?= $i++ ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($c['countryname']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 2. STATES TAB -->
<div id="stateTab" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">State Configuration</div>
        <form id="stateForm" onsubmit="submitForm(event, 'savestate', 'stateForm', 'Add State')" class="compact-form">
            <input type="hidden" name="id" value="">
            <div class="form-grid" style="grid-template-columns: 1fr 1fr auto; gap: 8px;">
                <div class="form-group">
                    <label>Select Country</label>
                    <select name="countryid" id="countrySelectForStateForm" onchange="syncFilterWithParent('stateTab', 'country', this.value)" required>
                        <option value="">Select Country</option>
                        <?php foreach($countries as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>State Name</label>
                    <input type="text" name="statename" required placeholder="e.g. Bihar">
                </div>
                <div class="btn-action-row">
                    <button type="submit" class="btn btn-primary" id="btnSubmit_stateForm">Add State</button>
                    <button type="button" class="btn btn-secondary" onclick="cancelEdit('stateForm', 'Add State')" style="display: none;">Cancel</button>
                </div>
            </div>
        </form>

        <div class="table-control-row">
            <div class="inline-filter-group">
                <label>Filter by Country</label>
                <select id="stateCountryFilter" onchange="filterStateTable()">
                    <option value="">All Countries</option>
                    <?php foreach($countries as $c): ?><option value="<?= htmlspecialchars($c['countryname']) ?>" <?= strtolower($c['countryname'])==='india' ? 'selected':'' ?>><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">Search State</label>
                <input type="text" id="stateSearchInput" onkeyup="filterStateTable()" placeholder="Search state name...">
            </div>
            <button class="btn-export" onclick="exportStateToExcel()">Export States</button>
        </div>

        <div class="table-container">
            <table class="compact-table" id="stateTable">
                <thead><tr><th style="width: 40px; text-align: center;">Sl.No</th><th>State Name</th><th>Country</th></tr></thead>
                <tbody id="stateTableBody">
                    <?php $i=1; foreach($states as $s): ?>
                        <tr class="clickable-row" data-country="<?= htmlspecialchars($s['countryname']) ?>" onclick="editRecord('state', <?= $s['id'] ?>, '<?= htmlspecialchars($s['statename'], ENT_QUOTES) ?>', <?= $s['countryid'] ?>)">
                            <td style="text-align: center; color: #64748b;"><?= $i++ ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($s['statename']) ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($s['countryname'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 3. DISTRICTS TAB -->
<div id="districtTab" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">District Configuration</div>
        <form id="districtForm" onsubmit="submitForm(event, 'savedistrict', 'districtForm', 'Add District')" class="compact-form">
            <input type="hidden" name="id" value="">
            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr auto; gap: 8px;">
                <div class="form-group">
                    <label>Select Country</label>
                    <select id="countrySelectForDistrictForm" onchange="cascadeStateOptions(this.value, 'stateSelectForDistrictForm'); syncFilterWithParent('districtTab', 'country', this.value)" required>
                        <option value="">Select Country</option>
                        <?php foreach($countries as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select State</label>
                    <select name="stateid" id="stateSelectForDistrictForm" onchange="syncFilterWithParent('districtTab', 'state', this.value)" required>
                        <option value="">Select State</option>
                        <?php foreach($states as $s): ?><option value="<?= $s['id'] ?>" data-country-id="<?= $s['countryid'] ?>"><?= htmlspecialchars($s['statename']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>District Name</label>
                    <input type="text" name="districtname" required placeholder="e.g. Patna">
                </div>
                <div class="btn-action-row">
                    <button type="submit" class="btn btn-primary" id="btnSubmit_districtForm">Add District</button>
                    <button type="button" class="btn btn-secondary" onclick="cancelEdit('districtForm', 'Add District')" style="display: none;">Cancel</button>
                </div>
            </div>
        </form>

        <div class="table-control-row">
            <div class="inline-filter-group">
                <label>Filter Country</label>
                <select id="districtCountryFilter" onchange="cascadeFilterStates('districtCountryFilter', 'districtStateFilter'); filterDistrictTable();">
                    <option value="">All Countries</option>
                    <?php foreach($countries as $c): ?><option value="<?= htmlspecialchars($c['countryname']) ?>" <?= strtolower($c['countryname'])==='india'?'selected':'' ?>><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">State</label>
                <select id="districtStateFilter" onchange="filterDistrictTable()">
                    <option value="">All States</option>
                    <?php foreach($states as $s): ?><option value="<?= htmlspecialchars($s['statename']) ?>" data-country="<?= htmlspecialchars($s['countryname']) ?>"><?= htmlspecialchars($s['statename']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">Search District</label>
                <input type="text" id="districtSearchInput" onkeyup="filterDistrictTable()" placeholder="District name...">
            </div>
            <button class="btn-export" onclick="exportDistrictToExcel()">Export Districts</button>
        </div>

        <div class="table-container">
            <table class="compact-table" id="districtTable">
                <thead><tr><th style="width: 40px; text-align: center;">Sl.No</th><th>District Name</th><th>State</th><th>Country</th></tr></thead>
                <tbody id="districtTableBody">
                    <?php $i=1; foreach($districts as $d): ?>
                        <tr class="clickable-row" data-country="<?= htmlspecialchars($d['countryname']) ?>" data-state="<?= htmlspecialchars($d['statename']) ?>" onclick="editRecord('district', <?= $d['id'] ?>, '<?= htmlspecialchars($d['districtname'], ENT_QUOTES) ?>', <?= $d['stateid'] ?>, <?= $d['countryid'] ?>)">
                            <td style="text-align: center; color: #64748b;"><?= $i++ ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($d['districtname']) ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($d['statename']) ?></td>
                            <td style="color: #64748b; font-weight: bold;"><?= htmlspecialchars($d['countryname'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 4. HEADQUARTERS TAB -->
<div id="hqTab" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">Headquarters (HQ) Configuration</div>
        <form id="hqForm" onsubmit="submitForm(event, 'savehq', 'hqForm', 'Add HQ')" class="compact-form">
            <input type="hidden" name="id" value="">
            <div class="form-grid" style="grid-template-columns: repeat(4, 1fr) auto; gap: 8px;">
                <div class="form-group">
                    <label>Select Country</label>
                    <select id="countrySelectForHQForm" onchange="cascadeStateOptions(this.value, 'stateSelectForHQForm'); cascadeDistrictOptions(0, 'districtSelectForHQForm'); syncFilterWithParent('hqTab', 'country', this.value)" required>
                        <option value="">Select Country</option>
                        <?php foreach($countries as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select State</label>
                    <select id="stateSelectForHQForm" onchange="cascadeDistrictOptions(this.value, 'districtSelectForHQForm'); syncFilterWithParent('hqTab', 'state', this.value)" required>
                        <option value="">Select State</option>
                        <?php foreach($states as $s): ?><option value="<?= $s['id'] ?>" data-country-id="<?= $s['countryid'] ?>"><?= htmlspecialchars($s['statename']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select District</label>
                    <select name="districtid" id="districtSelectForHQForm" onchange="syncFilterWithParent('hqTab', 'district', this.value)" required>
                        <option value="">Select District</option>
                        <?php foreach($districts as $d): ?><option value="<?= $d['id'] ?>" data-state-id="<?= $d['stateid'] ?>"><?= htmlspecialchars($d['districtname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>HQ Name</label>
                    <input type="text" name="hqname" required placeholder="e.g. Patna Pl">
                </div>
                <div class="btn-action-row">
                    <button type="submit" class="btn btn-primary" id="btnSubmit_hqForm">Add HQ</button>
                    <button type="button" class="btn btn-secondary" onclick="cancelEdit('hqForm', 'Add HQ')" style="display: none;">Cancel</button>
                </div>
            </div>
        </form>

        <div class="table-control-row">
            <div class="inline-filter-group">
                <label>Filter Country</label>
                <select id="hqCountryFilter" onchange="cascadeFilterStates('hqCountryFilter', 'hqStateFilter'); cascadeFilterDistricts('hqStateFilter', 'hqDistrictFilter'); filterHqTable();">
                    <option value="">All Countries</option>
                    <?php foreach($countries as $c): ?><option value="<?= htmlspecialchars($c['countryname']) ?>" <?= strtolower($c['countryname'])==='india'?'selected':'' ?>><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">State</label>
                <select id="hqStateFilter" onchange="cascadeFilterDistricts('hqStateFilter', 'hqDistrictFilter'); filterHqTable();">
                    <option value="">All States</option>
                    <?php foreach($states as $s): ?><option value="<?= htmlspecialchars($s['statename']) ?>" data-country="<?= htmlspecialchars($s['countryname']) ?>"><?= htmlspecialchars($s['statename']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">District</label>
                <select id="hqDistrictFilter" onchange="filterHqTable()">
                    <option value="">All Districts</option>
                    <?php foreach($districts as $d): ?><option value="<?= htmlspecialchars($d['districtname']) ?>" data-state="<?= htmlspecialchars($d['statename']) ?>"><?= htmlspecialchars($d['districtname']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">Search HQ</label>
                <input type="text" id="hqSearchInput" onkeyup="filterHqTable()" placeholder="HQ Name...">
            </div>
            <button class="btn-export" onclick="exportHqToExcel()">Export HQs</button>
        </div>

        <div class="table-container">
            <table class="compact-table" id="hqTable">
                <thead><tr><th style="width: 40px; text-align: center;">Sl.No</th><th>HQ Name</th><th>District</th><th>State</th><th>Country</th></tr></thead>
                <tbody id="hqTableBody">
                    <?php $i=1; foreach($hqs as $h): ?>
                        <tr class="clickable-row" data-country="<?= htmlspecialchars($h['countryname']) ?>" data-state="<?= htmlspecialchars($h['statename']) ?>" data-district="<?= htmlspecialchars($h['districtname']) ?>" onclick="editRecord('hq', <?= $h['id'] ?>, '<?= htmlspecialchars($h['hqname'], ENT_QUOTES) ?>', <?= $h['districtid'] ?>, <?= $h['countryid'] ?>, <?= $h['stateid'] ?>)">
                            <td style="text-align: center; color: #64748b;"><?= $i++ ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($h['hqname']) ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($h['districtname']) ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($h['statename']) ?></td>
                            <td style="color: #64748b; font-weight: bold;"><?= htmlspecialchars($h['countryname'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 5. CITIES TAB -->
<div id="cityTab" class="tab-content">
    <div class="panel-box">
        <div class="panel-title">City / Delivery Area Configuration</div>
        <form id="cityForm" onsubmit="submitForm(event, 'savecity', 'cityForm', 'Add City/Area')" class="compact-form">
            <input type="hidden" name="id" value="">
            <div class="form-grid" style="grid-template-columns: repeat(5, 1fr) auto; gap: 8px;">
                <div class="form-group">
                    <label>Select Country</label>
                    <select id="countrySelectForCityForm" onchange="cascadeStateOptions(this.value, 'stateSelectForCityForm'); cascadeDistrictOptions(0, 'districtSelectForCityForm'); cascadeHQOptions(0, 'hqSelectForCityForm'); syncFilterWithParent('cityTab', 'country', this.value)" required>
                        <option value="">Select Country</option>
                        <?php foreach($countries as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select State</label>
                    <select id="stateSelectForCityForm" onchange="cascadeDistrictOptions(this.value, 'districtSelectForCityForm'); cascadeHQOptions(0, 'hqSelectForCityForm'); syncFilterWithParent('cityTab', 'state', this.value)" required>
                        <option value="">Select State</option>
                        <?php foreach($states as $s): ?><option value="<?= $s['id'] ?>" data-country-id="<?= $s['countryid'] ?>"><?= htmlspecialchars($s['statename']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select District</label>
                    <select id="districtSelectForCityForm" onchange="cascadeHQOptions(this.value, 'hqSelectForCityForm'); syncFilterWithParent('cityTab', 'district', this.value)" required>
                        <option value="">Select District</option>
                        <?php foreach($districts as $d): ?><option value="<?= $d['id'] ?>" data-state-id="<?= $d['stateid'] ?>"><?= htmlspecialchars($d['districtname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Select HQ</label>
                    <select name="hqid" id="hqSelectForCityForm" onchange="syncFilterWithParent('cityTab', 'hq', this.value)" required>
                        <option value="">Select HQ</option>
                        <?php foreach($hqs as $h): ?><option value="<?= $h['id'] ?>" data-district-id="<?= $h['districtid'] ?>"><?= htmlspecialchars($h['hqname']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>City / Area Name</label>
                    <input type="text" name="cityname" required placeholder="e.g. Arwal, Maner">
                </div>
                <div class="btn-action-row">
                    <button type="submit" class="btn btn-primary" id="btnSubmit_cityForm">Add City</button>
                    <button type="button" class="btn btn-secondary" onclick="cancelEdit('cityForm', 'Add City/Area')" style="display: none;">Cancel</button>
                </div>
            </div>
        </form>

        <div class="table-control-row">
            <div class="inline-filter-group">
                <label>Filter Country</label>
                <select id="cityCountryFilter" onchange="cascadeFilterStates('cityCountryFilter', 'cityStateFilter'); cascadeFilterDistricts('cityStateFilter', 'cityDistrictFilter'); cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter'); filterCityTable();">
                    <option value="">All Countries</option>
                    <?php foreach($countries as $c): ?><option value="<?= htmlspecialchars($c['countryname']) ?>" <?= strtolower($c['countryname'])==='india'?'selected':'' ?>><?= htmlspecialchars($c['countryname']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">State</label>
                <select id="cityStateFilter" onchange="cascadeFilterDistricts('cityStateFilter', 'cityDistrictFilter'); cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter'); filterCityTable();">
                    <option value="">All States</option>
                    <?php foreach($states as $s): ?><option value="<?= htmlspecialchars($s['statename']) ?>" data-country="<?= htmlspecialchars($s['countryname']) ?>"><?= htmlspecialchars($s['statename']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">District</label>
                <select id="cityDistrictFilter" onchange="cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter'); filterCityTable();">
                    <option value="">All Districts</option>
                    <?php foreach($districts as $d): ?><option value="<?= htmlspecialchars($d['districtname']) ?>" data-state="<?= htmlspecialchars($d['statename']) ?>"><?= htmlspecialchars($d['districtname']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">HQ</label>
                <select id="cityHqFilter" onchange="filterCityTable()">
                    <option value="">All HQs</option>
                    <?php foreach($hqs as $h): ?><option value="<?= htmlspecialchars($h['hqname']) ?>" data-district="<?= htmlspecialchars($h['districtname']) ?>"><?= htmlspecialchars($h['hqname']) ?></option><?php endforeach; ?>
                </select>
                <label style="margin-left:8px;">Search City</label>
                <input type="text" id="citySearchInput" onkeyup="filterCityTable()" placeholder="City/Area...">
            </div>
            <button class="btn-export" onclick="exportCityToExcel()">Export Cities</button>
        </div>

        <div class="table-container">
            <table class="compact-table" id="cityTable">
                <thead><tr><th style="width: 40px; text-align: center;">Sl.No</th><th>City / Area</th><th>HQ Name</th><th>District</th><th>State</th><th>Country</th></tr></thead>
                <tbody id="cityTableBody">
                    <?php $i=1; foreach($cities as $c): ?>
                        <tr class="clickable-row" data-country="<?= htmlspecialchars($c['countryname']) ?>" data-state="<?= htmlspecialchars($c['statename']) ?>" data-district="<?= htmlspecialchars($c['districtname']) ?>" data-hq="<?= htmlspecialchars($c['hqname']) ?>" onclick="editRecord('city', <?= $c['id'] ?>, '<?= htmlspecialchars($c['cityname'], ENT_QUOTES) ?>', <?= $c['hqid'] ?>, <?= $c['countryid'] ?>, <?= $c['stateid'] ?>, <?= $c['districtid'] ?>)">
                            <td style="text-align: center; color: #64748b;"><?= $i++ ?></td>
                            <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($c['cityname']) ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($c['hqname']) ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($c['districtname']) ?></td>
                            <td style="color: #64748b;"><?= htmlspecialchars($c['statename']) ?></td>
                            <td style="color: #64748b; font-weight: bold;"><?= htmlspecialchars($c['countryname'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    let rawGeoData = {
        countries: <?= json_encode($countries) ?>,
        states: <?= json_encode($states) ?>,
        districts: <?= json_encode($districts) ?>,
        hqs: <?= json_encode($hqs) ?>,
        cities: <?= json_encode($cities) ?>
    };

    function switchTab(tabId, btn) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        btn.classList.add('active');
        
        if (tabId === 'stateTab') { filterStateTable(); }
        else if (tabId === 'districtTab') { cascadeFilterStates('districtCountryFilter', 'districtStateFilter'); filterDistrictTable(); }
        else if (tabId === 'hqTab') { cascadeFilterStates('hqCountryFilter', 'hqStateFilter'); cascadeFilterDistricts('hqStateFilter', 'hqDistrictFilter'); filterHqTable(); }
        else if (tabId === 'cityTab') { cascadeFilterStates('cityCountryFilter', 'cityStateFilter'); cascadeFilterDistricts('cityStateFilter', 'cityDistrictFilter'); cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter'); filterCityTable(); }
    }

    function cascadeFilterStates(countryFilterId, stateFilterId) {
        const countryVal = document.getElementById(countryFilterId).value.toLowerCase().trim();
        const stateFilter = document.getElementById(stateFilterId);
        if (!stateFilter) return;
        
        const prevValue = stateFilter.value;
        stateFilter.innerHTML = '<option value="">All States</option>';
        
        rawGeoData.states.forEach(s => {
            const countryName = (s.countryname || '').toLowerCase();
            if (countryVal && countryName !== countryVal) return;
            const opt = new Option(s.statename, s.statename);
            if (s.statename === prevValue) opt.selected = true;
            stateFilter.appendChild(opt);
        });
    }

    function cascadeFilterDistricts(stateFilterId, districtFilterId) {
        const stateVal = document.getElementById(stateFilterId).value.toLowerCase().trim();
        const districtFilter = document.getElementById(districtFilterId);
        if (!districtFilter) return;

        const prevValue = districtFilter.value;
        districtFilter.innerHTML = '<option value="">All Districts</option>';

        rawGeoData.districts.forEach(d => {
            const stateName = (d.statename || '').toLowerCase();
            if (stateVal && stateName !== stateVal) return;
            const opt = new Option(d.districtname, d.districtname);
            if (d.districtname === prevValue) opt.selected = true;
            districtFilter.appendChild(opt);
        });
    }

    function cascadeFilterHQs(districtFilterId, hqFilterId) {
        const districtVal = document.getElementById(districtFilterId).value.toLowerCase().trim();
        const hqFilter = document.getElementById(hqFilterId);
        if (!hqFilter) return;

        const prevValue = hqFilter.value;
        hqFilter.innerHTML = '<option value="">All HQs</option>';

        rawGeoData.hqs.forEach(h => {
            const districtName = (h.districtname || '').toLowerCase();
            if (districtVal && districtName !== districtVal) return;
            const opt = new Option(h.hqname, h.hqname);
            if (h.hqname === prevValue) opt.selected = true;
            hqFilter.appendChild(opt);
        });
    }

    function syncFilterWithParent(tabId, entity, value) {
        if (!value) return;

        if (entity === 'country') {
            const countryObj = rawGeoData.countries.find(c => parseInt(c.id) === parseInt(value));
            if (!countryObj) return;
            const name = countryObj.countryname;

            if (tabId === 'stateTab') {
                document.getElementById('stateCountryFilter').value = name;
                filterStateTable();
            } else if (tabId === 'districtTab') {
                document.getElementById('districtCountryFilter').value = name;
                cascadeFilterStates('districtCountryFilter', 'districtStateFilter');
                document.getElementById('districtStateFilter').value = '';
                filterDistrictTable();
            } else if (tabId === 'hqTab') {
                document.getElementById('hqCountryFilter').value = name;
                cascadeFilterStates('hqCountryFilter', 'hqStateFilter');
                document.getElementById('hqStateFilter').value = '';
                cascadeFilterDistricts('hqStateFilter', 'hqDistrictFilter');
                document.getElementById('hqDistrictFilter').value = '';
                filterHqTable();
            } else if (tabId === 'cityTab') {
                document.getElementById('cityCountryFilter').value = name;
                cascadeFilterStates('cityCountryFilter', 'cityStateFilter');
                document.getElementById('cityStateFilter').value = '';
                cascadeFilterDistricts('cityStateFilter', 'cityDistrictFilter');
                document.getElementById('cityDistrictFilter').value = '';
                cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter');
                document.getElementById('cityHqFilter').value = '';
                filterCityTable();
            }
        } 
        else if (entity === 'state') {
            const stateObj = rawGeoData.states.find(s => parseInt(s.id) === parseInt(value));
            if (!stateObj) return;
            const name = stateObj.statename;

            if (tabId === 'districtTab') {
                document.getElementById('districtStateFilter').value = name;
                filterDistrictTable();
            } else if (tabId === 'hqTab') {
                document.getElementById('hqStateFilter').value = name;
                cascadeFilterDistricts('hqStateFilter', 'hqDistrictFilter');
                document.getElementById('hqDistrictFilter').value = '';
                filterHqTable();
            } else if (tabId === 'cityTab') {
                document.getElementById('cityStateFilter').value = name;
                cascadeFilterDistricts('cityStateFilter', 'cityDistrictFilter');
                document.getElementById('cityDistrictFilter').value = '';
                cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter');
                document.getElementById('cityHqFilter').value = '';
                filterCityTable();
            }
        } 
        else if (entity === 'district') {
            const distObj = rawGeoData.districts.find(d => parseInt(d.id) === parseInt(value));
            if (!distObj) return;
            const name = distObj.districtname;

            if (tabId === 'hqTab') {
                document.getElementById('hqDistrictFilter').value = name;
                filterHqTable();
            } else if (tabId === 'cityTab') {
                document.getElementById('cityDistrictFilter').value = name;
                cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter');
                document.getElementById('cityHqFilter').value = '';
                filterCityTable();
            }
        } 
        else if (entity === 'hq') {
            const hqObj = rawGeoData.hqs.find(h => parseInt(h.id) === parseInt(value));
            if (!hqObj) return;
            const name = hqObj.hqname;

            if (tabId === 'cityTab') {
                document.getElementById('cityHqFilter').value = name;
                filterCityTable();
            }
        }
    }

    function cascadeStateOptions(countryId, targetSelectId, selectedValue = '') {
        const targetSelect = document.getElementById(targetSelectId);
        targetSelect.innerHTML = '<option value="">Select State</option>';
        if (!countryId) return;
        const filtered = rawGeoData.states.filter(s => parseInt(s.countryid) === parseInt(countryId));
        filtered.forEach(s => {
            const opt = new Option(s.statename, s.id);
            if (parseInt(s.id) === parseInt(selectedValue)) opt.selected = true;
            targetSelect.appendChild(opt);
        });
    }

    function cascadeDistrictOptions(stateId, targetSelectId, selectedValue = '') {
        const targetSelect = document.getElementById(targetSelectId);
        targetSelect.innerHTML = '<option value="">Select District</option>';
        if (!stateId) return;
        const filtered = rawGeoData.districts.filter(d => parseInt(d.stateid) === parseInt(stateId));
        filtered.forEach(d => {
            const opt = new Option(d.districtname, d.id);
            if (parseInt(d.id) === parseInt(selectedValue)) opt.selected = true;
            targetSelect.appendChild(opt);
        });
    }

    function cascadeHQOptions(districtId, targetSelectId, selectedValue = '') {
        const targetSelect = document.getElementById(targetSelectId);
        targetSelect.innerHTML = '<option value="">Select HQ</option>';
        if (!districtId) return;
        const filtered = rawGeoData.hqs.filter(h => parseInt(h.districtid) === parseInt(districtId));
        filtered.forEach(h => {
            const opt = new Option(h.hqname, h.id);
            if (parseInt(h.id) === parseInt(selectedValue)) opt.selected = true;
            targetSelect.appendChild(opt);
        });
    }

    function filterCountryTable() {
        const searchVal = document.getElementById('countrySearchInput').value.toLowerCase().trim();
        const tbody = document.getElementById('countryTableBody');
        tbody.innerHTML = '';
        let i = 1;
        rawGeoData.countries.forEach(c => {
            if (searchVal && !c.countryname.toLowerCase().includes(searchVal)) return;
            const escName = escapeHtml(c.countryname);
            const safeName = escName.replace(/'/g, "\\'");
            tbody.innerHTML += `<tr class="clickable-row" onclick="editRecord('country', ${c.id}, '${safeName}')">
                <td style="text-align: center; color:#64748b;">${i++}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
            </tr>`;
        });
    }

    function filterStateTable() {
        const countryVal = document.getElementById('stateCountryFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('stateSearchInput').value.toLowerCase().trim();
        const tbody = document.getElementById('stateTableBody');
        tbody.innerHTML = '';
        let i = 1;
        rawGeoData.states.forEach(s => {
            const countryName = (s.countryname || '').toLowerCase();
            if (countryVal && countryName !== countryVal) return;
            if (searchVal && !s.statename.toLowerCase().includes(searchVal)) return;
            
            const escName = escapeHtml(s.statename);
            const escCountry = escapeHtml(s.countryname || '-');
            const safeName = escName.replace(/'/g, "\\'");
            tbody.innerHTML += `<tr class="clickable-row" data-country="${escCountry}" onclick="editRecord('state', ${s.id}, '${safeName}', ${s.countryid})">
                <td style="text-align: center; color:#64748b;">${i++}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                <td style="color:#64748b;">${escCountry}</td>
            </tr>`;
        });
    }

    function filterDistrictTable() {
        const countryVal = document.getElementById('districtCountryFilter').value.toLowerCase().trim();
        const stateVal = document.getElementById('districtStateFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('districtSearchInput').value.toLowerCase().trim();
        const tbody = document.getElementById('districtTableBody');
        tbody.innerHTML = '';
        let i = 1;
        rawGeoData.districts.forEach(d => {
            const countryName = (d.countryname || '').toLowerCase();
            const stateName = (d.statename || '').toLowerCase();
            if (countryVal && countryName !== countryVal) return;
            if (stateVal && stateName !== stateVal) return;
            if (searchVal && !d.districtname.toLowerCase().includes(searchVal)) return;
            
            const escName = escapeHtml(d.districtname);
            const escState = escapeHtml(d.statename);
            const escCountry = escapeHtml(d.countryname || '-');
            const safeName = escName.replace(/'/g, "\\'");
            tbody.innerHTML += `<tr class="clickable-row" data-country="${escCountry}" data-state="${escState}" onclick="editRecord('district', ${d.id}, '${safeName}', ${d.stateid}, ${d.countryid})">
                <td style="text-align: center; color:#64748b;">${i++}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                <td style="color:#64748b;">${escState}</td>
                <td style="color:#64748b; font-weight:bold;">${escCountry}</td>
            </tr>`;
        });
    }

    function filterHqTable() {
        const countryVal = document.getElementById('hqCountryFilter').value.toLowerCase().trim();
        const stateVal = document.getElementById('hqStateFilter').value.toLowerCase().trim();
        const districtVal = document.getElementById('hqDistrictFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('hqSearchInput').value.toLowerCase().trim();
        const tbody = document.getElementById('hqTableBody');
        tbody.innerHTML = '';
        let i = 1;
        rawGeoData.hqs.forEach(h => {
            const countryName = (h.countryname || '').toLowerCase();
            const stateName = (h.statename || '').toLowerCase();
            const districtName = (h.districtname || '').toLowerCase();

            if (countryVal && countryName !== countryVal) return;
            if (stateVal && stateName !== stateVal) return;
            if (districtVal && districtName !== districtVal) return;
            if (searchVal && !h.hqname.toLowerCase().includes(searchVal)) return;

            const escName = escapeHtml(h.hqname);
            const escDist = escapeHtml(h.districtname);
            const escState = escapeHtml(h.statename);
            const escCountry = escapeHtml(h.countryname || '-');
            const safeName = escName.replace(/'/g, "\\'");

            tbody.innerHTML += `<tr class="clickable-row" data-country="${escCountry}" data-state="${escState}" data-district="${escDist}" onclick="editRecord('hq', ${h.id}, '${safeName}', ${h.districtid}, ${h.countryid}, ${h.stateid})">
                <td style="text-align: center; color:#64748b;">${i++}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                <td style="color:#64748b;">${escDist}</td>
                <td style="color:#64748b;">${escState}</td>
                <td style="color:#64748b; font-weight:bold;">${escCountry}</td>
            </tr>`;
        });
    }

    function filterCityTable() {
        const countryVal = document.getElementById('cityCountryFilter').value.toLowerCase().trim();
        const stateVal = document.getElementById('cityStateFilter').value.toLowerCase().trim();
        const districtVal = document.getElementById('cityDistrictFilter').value.toLowerCase().trim();
        const hqVal = document.getElementById('cityHqFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('citySearchInput').value.toLowerCase().trim();
        const tbody = document.getElementById('cityTableBody');
        tbody.innerHTML = '';
        let i = 1;
        rawGeoData.cities.forEach(c => {
            const countryName = (c.countryname || '').toLowerCase();
            const stateName = (c.statename || '').toLowerCase();
            const districtName = (c.districtname || '').toLowerCase();
            const hqName = (c.hqname || '').toLowerCase();

            if (countryVal && countryName !== countryVal) return;
            if (stateVal && stateName !== stateVal) return;
            if (districtVal && districtName !== districtVal) return;
            if (hqVal && hqName !== hqVal) return;
            if (searchVal && !c.cityname.toLowerCase().includes(searchVal)) return;

            const escName = escapeHtml(c.cityname);
            const escHq = escapeHtml(c.hqname);
            const escDist = escapeHtml(c.districtname);
            const escState = escapeHtml(c.statename);
            const escCountry = escapeHtml(c.countryname || '-');
            const safeName = escName.replace(/'/g, "\\'");

            tbody.innerHTML += `<tr class="clickable-row" data-country="${escCountry}" data-state="${escState}" data-district="${escDist}" data-hq="${escHq}" onclick="editRecord('city', ${c.id}, '${safeName}', ${c.hqid}, ${c.countryid}, ${c.stateid}, ${c.districtid})">
                <td style="text-align: center; color:#64748b;">${i++}</td>
                <td style="color:#0f172a; font-weight:bold;">${escName}</td>
                <td style="color:#64748b;">${escHq}</td>
                <td style="color:#64748b;">${escDist}</td>
                <td style="color:#64748b;">${escState}</td>
                <td style="color:#64748b; font-weight:bold;">${escCountry}</td>
            </tr>`;
        });
    }

    function editRecord(type, id, name, parentId = null, countryId = null, stateId = null, districtId = null) {
        let formId = type + 'Form';
        let form = document.getElementById(formId);
        
        form.elements['id'].value = id;
        form.querySelector('.btn-primary').innerHTML = 'Update';
        form.querySelector('.btn-secondary').style.display = 'inline-flex';

        if (type === 'country') {
            form.elements['countryname'].value = name;
        } else if (type === 'state') {
            form.elements['countryid'].value = parentId;
            form.elements['statename'].value = name;
        } else if (type === 'district') {
            document.getElementById('countrySelectForDistrictForm').value = countryId;
            cascadeStateOptions(countryId, 'stateSelectForDistrictForm', parentId);
            form.elements['districtname'].value = name;
        } else if (type === 'hq') {
            document.getElementById('countrySelectForHQForm').value = countryId;
            cascadeStateOptions(countryId, 'stateSelectForHQForm', stateId);
            cascadeDistrictOptions(stateId, 'districtSelectForHQForm', parentId);
            form.elements['hqname'].value = name;
        } else if (type === 'city') {
            document.getElementById('countrySelectForCityForm').value = countryId;
            cascadeStateOptions(countryId, 'stateSelectForCityForm', stateId);
            cascadeDistrictOptions(stateId, 'districtSelectForCityForm', districtId);
            cascadeHQOptions(districtId, 'hqSelectForCityForm', parentId);
            form.elements['cityname'].value = name;
        }
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function cancelEdit(formId, defaultBtnText) {
        let form = document.getElementById(formId);
        form.reset();
        form.elements['id'].value = '';
        form.querySelector('.btn-primary').innerHTML = defaultBtnText;
        form.querySelector('.btn-secondary').style.display = 'none';
        
        if (formId === 'districtForm') {
            document.getElementById('stateSelectForDistrictForm').innerHTML = '<option value="">Select State</option>';
        } else if (formId === 'hqForm') {
            document.getElementById('stateSelectForHQForm').innerHTML = '<option value="">Select State</option>';
            document.getElementById('districtSelectForHQForm').innerHTML = '<option value="">Select District</option>';
        } else if (formId === 'cityForm') {
            document.getElementById('stateSelectForCityForm').innerHTML = '<option value="">Select State</option>';
            document.getElementById('districtSelectForCityForm').innerHTML = '<option value="">Select District</option>';
            document.getElementById('hqSelectForCityForm').innerHTML = '<option value="">Select HQ</option>';
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function updateUI(data) {
        rawGeoData = data; 
        
        let cOpts = '<option value="">Select Country</option>';
        data.countries.forEach(c => { cOpts += `<option value="${c.id}">${escapeHtml(c.countryname)}</option>`; });
        
        document.getElementById('countrySelectForStateForm').innerHTML = cOpts;
        document.getElementById('countrySelectForDistrictForm').innerHTML = cOpts;
        document.getElementById('countrySelectForHQForm').innerHTML = cOpts;
        document.getElementById('countrySelectForCityForm').innerHTML = cOpts;

        let sFilterOpts = '<option value="">All Countries</option>';
        data.countries.forEach(c => {
            const selected = (c.countryname.toLowerCase().trim() === 'india') ? 'selected' : '';
            sFilterOpts += `<option value="${escapeHtml(c.countryname)}" ${selected}>${escapeHtml(c.countryname)}</option>`;
        });
        document.getElementById('stateCountryFilter').innerHTML = sFilterOpts;
        document.getElementById('districtCountryFilter').innerHTML = sFilterOpts;
        document.getElementById('hqCountryFilter').innerHTML = sFilterOpts;
        document.getElementById('cityCountryFilter').innerHTML = sFilterOpts;

        let sdFilterOpts = '<option value="">All States</option>';
        data.states.forEach(s => {
            sdFilterOpts += `<option value="${escapeHtml(s.statename)}" data-country="${escapeHtml(s.countryname)}">${escapeHtml(s.statename)}</option>`;
        });
        document.getElementById('districtStateFilter').innerHTML = sdFilterOpts;
        document.getElementById('hqStateFilter').innerHTML = sdFilterOpts;
        document.getElementById('cityStateFilter').innerHTML = sdFilterOpts;

        cascadeFilterStates('districtCountryFilter', 'districtStateFilter');
        cascadeFilterStates('hqCountryFilter', 'hqStateFilter');
        cascadeFilterDistricts('hqStateFilter', 'hqDistrictFilter');
        cascadeFilterStates('cityCountryFilter', 'cityStateFilter');
        cascadeFilterDistricts('cityStateFilter', 'cityDistrictFilter');
        cascadeFilterHQs('cityDistrictFilter', 'cityHqFilter');

        filterCountryTable(); filterStateTable(); filterDistrictTable(); filterHqTable(); filterCityTable();
    }

    function submitForm(e, action, formId, defaultBtnText) {
        e.preventDefault();
        const formObj = document.getElementById(formId);
        const formData = new FormData(formObj);
        formData.append('ajax_action', action);

        fetch('', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if (data.status === 'success') {
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                cancelEdit(formId, defaultBtnText); updateUI(data.data); 
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        }).catch(err => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error occurred.');
        });
    }

    function exportCountryToExcel() {
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;"><th>Sl.No</th><th>Country Name</th></tr>`;
        let i = 1;
        const searchVal = document.getElementById('countrySearchInput').value.toLowerCase().trim();
        rawGeoData.countries.forEach(c => {
            if (searchVal && !c.countryname.toLowerCase().includes(searchVal)) return;
            html += `<tr><td>${i++}</td><td>${escapeHtml(c.countryname)}</td></tr>`;
        });
        triggerDownload(html + "</table>", "Geography_Countries_Report");
    }

    function exportStateToExcel() {
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;"><th>Sl.No</th><th>State Name</th><th>Country Name</th></tr>`;
        let i = 1;
        const countryVal = document.getElementById('stateCountryFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('stateSearchInput').value.toLowerCase().trim();
        rawGeoData.states.forEach(s => {
            const countryName = (s.countryname || '').toLowerCase();
            if (countryVal && countryName !== countryVal) return;
            if (searchVal && !s.statename.toLowerCase().includes(searchVal)) return;
            html += `<tr><td>${i++}</td><td>${escapeHtml(s.statename)}</td><td>${escapeHtml(s.countryname || '-')}</td></tr>`;
        });
        triggerDownload(html + "</table>", "Geography_States_Report");
    }

    function exportDistrictToExcel() {
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;"><th>Sl.No</th><th>District Name</th><th>State</th><th>Country</th></tr>`;
        let i = 1;
        const countryVal = document.getElementById('districtCountryFilter').value.toLowerCase().trim();
        const stateVal = document.getElementById('districtStateFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('districtSearchInput').value.toLowerCase().trim();
        rawGeoData.districts.forEach(d => {
            const countryName = (d.countryname || '').toLowerCase();
            const stateName = (d.statename || '').toLowerCase();
            if (countryVal && countryName !== countryVal) return;
            if (stateVal && stateName !== stateVal) return;
            if (searchVal && !d.districtname.toLowerCase().includes(searchVal)) return;
            html += `<tr><td>${i++}</td><td>${escapeHtml(d.districtname)}</td><td>${escapeHtml(d.statename)}</td><td>${escapeHtml(d.countryname || '-')}</td></tr>`;
        });
        triggerDownload(html + "</table>", "Geography_Districts_Report");
    }

    function exportHqToExcel() {
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;"><th>Sl.No</th><th>HQ Name</th><th>District</th><th>State</th><th>Country</th></tr>`;
        let i = 1;
        const countryVal = document.getElementById('hqCountryFilter').value.toLowerCase().trim();
        const stateVal = document.getElementById('hqStateFilter').value.toLowerCase().trim();
        const districtVal = document.getElementById('hqDistrictFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('hqSearchInput').value.toLowerCase().trim();
        rawGeoData.hqs.forEach(h => {
            const countryName = (h.countryname || '').toLowerCase();
            const stateName = (h.statename || '').toLowerCase();
            const districtName = (h.districtname || '').toLowerCase();
            if (countryVal && countryName !== countryVal) return;
            if (stateVal && stateName !== stateVal) return;
            if (districtVal && districtName !== districtVal) return;
            if (searchVal && !h.hqname.toLowerCase().includes(searchVal)) return;
            html += `<tr><td>${i++}</td><td>${escapeHtml(h.hqname)}</td><td>${escapeHtml(h.districtname)}</td><td>${escapeHtml(h.statename)}</td><td>${escapeHtml(h.countryname || '-')}</td></tr>`;
        });
        triggerDownload(html + "</table>", "Geography_HQ_Report");
    }

    function exportCityToExcel() {
        let html = `<table border="1"><tr style="background:#1e2a78; color:white;"><th>Sl.No</th><th>City / Area</th><th>HQ Name</th><th>District</th><th>State</th><th>Country</th></tr>`;
        let i = 1;
        const countryVal = document.getElementById('cityCountryFilter').value.toLowerCase().trim();
        const stateVal = document.getElementById('cityStateFilter').value.toLowerCase().trim();
        const districtVal = document.getElementById('cityDistrictFilter').value.toLowerCase().trim();
        const hqVal = document.getElementById('cityHqFilter').value.toLowerCase().trim();
        const searchVal = document.getElementById('citySearchInput').value.toLowerCase().trim();
        rawGeoData.cities.forEach(c => {
            const countryName = (c.countryname || '').toLowerCase();
            const stateName = (c.statename || '').toLowerCase();
            const districtName = (c.districtname || '').toLowerCase();
            const hqName = (c.hqname || '').toLowerCase();
            if (countryVal && countryName !== countryVal) return;
            if (stateVal && stateName !== stateVal) return;
            if (districtVal && districtName !== districtVal) return;
            if (hqVal && hqName !== hqVal) return;
            if (searchVal && !c.cityname.toLowerCase().includes(searchVal)) return;
            html += `<tr><td>${i++}</td><td>${escapeHtml(c.cityname)}</td><td>${escapeHtml(c.hqname)}</td><td>${escapeHtml(c.districtname)}</td><td>${escapeHtml(c.statename)}</td><td>${escapeHtml(c.countryname || '-')}</td></tr>`;
        });
        triggerDownload(html + "</table>", "Geography_Cities_Report");
    }

    function triggerDownload(htmlStr, filename) {
        const a = document.createElement("a");
        a.href = URL.createObjectURL(new Blob([htmlStr], { type: 'application/vnd.ms-excel' }));
        a.download = `${filename}_${new Date().toISOString().slice(0, 10)}.xls`;
        a.click();
    }

    document.addEventListener('DOMContentLoaded', () => { filterStateTable(); });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>