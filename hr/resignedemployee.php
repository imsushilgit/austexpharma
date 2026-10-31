<?php
ob_start();
require_once __DIR__ . '/../includes/core.php';

// Unauthenticated Requests handling
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Fetch Resigned / Left Employees Only (Sorted by Resignation Date DESC)
$resignedFetchQuery = "
    SELECT e.*, 
           COALESCE(m.empname, e.reporting_manager) as display_manager 
    FROM employees e 
    LEFT JOIN employees m ON (e.reporting_manager = m.empcode OR e.reporting_manager = m.empname)
    WHERE e.resignation_date IS NOT NULL 
      AND e.resignation_date != '0000-00-00' 
      AND e.resignation_date != ''
    ORDER BY e.resignation_date DESC, e.empname ASC
";

try {
    $resignedEmployees = scope_filter_rows($ctx, $pdo->query($resignedFetchQuery)->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {$resignedEmployees = [];
}

$pageTitle = "Resigned Employees Ledger";
include __DIR__ . '/../includes/header.php';
?>


<div class="panel-box">
    <div class="panel-title">
        <span>Resigned / Left Employees Directory</span>
        <button type="button" class="btn-export" onclick="exportResignedToExcel()">Export to Excel</button>
    </div>
    <div class="table-container">
        <table class="compact-table" id="resignedTable">
            <thead>
                <tr>
                    <th style="text-align: center;">Sl.</th>
                    <th>Empl Code</th>
                    <th>Employee Name</th>
                    <th>Resign Date</th>
                    <th>Division</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>HQ</th>
                    <th>State</th>
                    <th>Mobile. No</th>
                    <th>Email</th>
                    <th>Gender</th>
                    <th>Joining Date</th>
                    <th>DOB</th>
                    <th>Reporting Manager</th>
                    <th>Bank A/C No.</th>
                    <th>IFSC Code</th>
                    <th>PAN No</th>
                    <th>Aadhar No.</th>
                    <th>Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resignedEmployees)): ?>
                    <tr><td colspan="20" style="text-align: center; color: #64748b; padding: 30px; font-weight: bold;">No resigned employees found in the records.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($resignedEmployees as$e): ?>
                    <tr>
                        <td style="text-align: center; color: #64748b;"><?= $i++; ?></td>
                        <td style="color:#1d4ed8; font-family:monospace; font-weight:bold;"><?= htmlspecialchars($e['empcode']); ?></td>
                        <td style="color:#0f172a; font-weight:bold;"><?= htmlspecialchars($e['empname']); ?></td>
                        <td><span class="resign-badge"><?= $e['resignation_date'] ? date('d-M-Y', strtotime($e['resignation_date'])) : '-'; ?></span></td>
                        <td><?= htmlspecialchars($e['division'] ?? '-'); ?></td>
                        <td><?= htmlspecialchars($e['department'] ?? '-'); ?></td>
                        <td style="color: #475569;"><?= htmlspecialchars($e['designation'] ?? '-'); ?></td>
                        <td style="font-weight:bold;"><?= htmlspecialchars($e['hq']); ?></td>
                        <td><?= htmlspecialchars($e['state']); ?></td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($e['mobile'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['email'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['gender'] ?: '-'); ?></td>
                        <td><?= $e['joining_date'] ? date('d-M-Y', strtotime($e['joining_date'])) : '-'; ?></td>
                        <td><?= $e['dob'] ? date('d-M-Y', strtotime($e['dob'])) : '-'; ?></td>
                        <td><?= htmlspecialchars($e['display_manager'] ?: '-'); ?></td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($e['bank_account'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['ifsc_code'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['pan_no'] ?: '-'); ?></td>
                        <td><?= htmlspecialchars($e['aadhar_no'] ?: '-'); ?></td>
                        <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($e['address'] ?: '') ?>"><?= htmlspecialchars($e['address'] ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const resignedData = <?= json_encode($resignedEmployees) ?>;

    function escapeHtml(text) {
        if (!text && text !== 0) return '';
        return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function exportResignedToExcel() {
        if (!resignedData || resignedData.length === 0) {
            if (typeof AppAlert !== 'undefined') AppAlert.warning('No data to export.'); else alert('No data to export.');
            return;
        }
        
        let html = `<table border="1"><tr style="background:#dc2626; color:white;">
            <th>Sl.</th><th>Empl Code</th><th>Employee Name</th><th>Resign Date</th><th>Division</th><th>Department</th><th>Designation</th><th>HQ</th><th>State</th><th>Mobile. No</th><th>Email</th><th>Gender</th><th>Joining Date</th><th>DOB</th><th>Reporting Manager</th><th>Bank A/C No.</th><th>IFSC Code</th><th>PAN No</th><th>Aadhar No.</th><th>Address</th></tr>`;
        
        resignedData.forEach((e, i) => {
            html += `<tr>
                <td>${i + 1}</td>
                <td>${escapeHtml(e.empcode)}</td>
                <td>${escapeHtml(e.empname)}</td>
                <td>${e.resignation_date ? e.resignation_date : '-'}</td>
                <td>${escapeHtml(e.division || '-')}</td>
                <td>${escapeHtml(e.department || '-')}</td>
                <td>${escapeHtml(e.designation || '-')}</td>
                <td>${escapeHtml(e.hq)}</td>
                <td>${escapeHtml(e.state)}</td>
                <td>${escapeHtml(e.mobile || '-')}</td>
                <td>${escapeHtml(e.email || '-')}</td>
                <td>${escapeHtml(e.gender || '-')}</td>
                <td>${e.joining_date ? e.joining_date : '-'}</td>
                <td>${e.dob ? e.dob : '-'}</td>
                <td>${escapeHtml(e.display_manager || '-')}</td>
                <td style="mso-number-format:'\@';">${escapeHtml(e.bank_account || '-')}</td>
                <td>${escapeHtml(e.ifsc_code || '-')}</td>
                <td>${escapeHtml(e.pan_no || '-')}</td>
                <td style="mso-number-format:'\@';">${escapeHtml(e.aadhar_no || '-')}</td>
                <td>${escapeHtml(e.address || '-')}</td>
            </tr>`;
        });
        
        html += `</table>`;
        const a = document.createElement("a");
        a.href = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' }));
        a.download = `Resigned_Employees_${new Date().toISOString().slice(0,10)}.xls`;
        a.click();
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>