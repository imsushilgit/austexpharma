<?php 
$currentPage = basename($_SERVER['PHP_SELF']); 

$ctx = [];
if (isset($pdo) && function_exists('get_user_context')) {
    $ctx = get_user_context($pdo);
} else {
    $ctx = [
        'is_admin'   => (isset($_SESSION['user_role']) && strtoupper($_SESSION['user_role']) === 'ADMIN'),
        'is_manager' => false,
        'is_mr'      => true,
        'can_write'  => false,
        'empcode'    => $_SESSION['empcode'] ?? '',
        'designation'=> $_SESSION['designation'] ?? '',
        'user_role'  => $_SESSION['user_role'] ?? 'Staff',
    ];
}

$isAdmin   = !empty($ctx['is_admin']);
$isManager = !empty($ctx['is_manager']);
$isMR      = !empty($ctx['is_mr']);

$department = strtoupper(trim($_SESSION['department'] ?? ''));
$isHR       = $isAdmin || ($department === 'HR' || $department === 'ADMIN');

$sidebarPerms = [];
if (!$isAdmin && isset($pdo)) {
    try {
        $st = $pdo->prepare("SELECT page_name FROM page_permissions WHERE role_name IN (?, ?, ?) AND can_view = 1");
        $st->execute([$ctx['empcode'], $ctx['designation'], $ctx['user_role']]);
        $allowedPages = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allowedPages as $ap) {
            $sidebarPerms[strtolower(trim($ap))] = true;
        }
    } catch (Exception $e) {}
}

function showLink($pageFile, $isAdmin, $sidebarPerms) {
    if ($isAdmin || $pageFile === 'dashboard.php') return true;
    return isset($sidebarPerms[strtolower($pageFile)]);
}

$companyNameDisplay = $companyName ?? 'Company Name';
$logoTypeDisplay = $logoType ?? 'none';
$logoPathDisplay = $logoPath ?? '';
$logoHtmlDisplay = $logoHtml ?? '';
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <?php if ($logoTypeDisplay === 'image' && !empty($logoPathDisplay)): ?>
            <div style="height: 44px; display:flex; align-items:center; justify-content:center; flex-shrink: 0; width: 100%;">
                <img src="<?= BASE_URL . ltrim(htmlspecialchars($logoPathDisplay), '/') ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
            </div>
        <?php elseif ($logoTypeDisplay === 'html' && !empty($logoHtmlDisplay)): ?>
            <div style="flex-shrink:0; display:flex; align-items:center; width: 100%;">
                <?= $logoHtmlDisplay ?>
            </div>
        <?php else: ?>
            <h3 class="brand-text">
                <span class="name"><?= htmlspecialchars($companyNameDisplay) ?></span><br>
                <span class="sub" style="color: #00aeea; font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em;">Platform</span>
            </h3>
        <?php endif; ?>
    </div>

    <!-- MENU SEARCH -->
    <div class="sidebar-search-wrap">
        <div class="sidebar-search">
            <svg class="search-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" id="menuSearch" placeholder="Search menu..." autocomplete="off" spellcheck="false">
            <button type="button" id="menuSearchClear" class="search-clear" title="Clear" style="display:none;">&times;</button>
        </div>
        <div id="menuSearchResults" class="menu-search-results" style="display:none;"></div>
    </div>
    
    <nav class="sidebar-nav" id="sidebarNav">
        
        <!-- DASHBOARD (Level 1 direct link) -->
        <?php if (showLink('dashboard.php', $isAdmin, $sidebarPerms)): ?>
        <a href="<?= BASE_URL ?>dashboard.php" class="nav-master <?= $currentPage == 'dashboard.php' ? 'active' : '' ?>" style="text-decoration:none;">
            <span><i class="fa-solid fa-house" style="margin-right:8px; width:20px;"></i> Dashboard</span>
        </a>
        <?php endif; ?>

        <!-- CORE OPERATIONS (Master Section) -->
        <div class="nav-group" data-group="Core Operations">
            <button class="nav-master" type="button">
                <span><i class="fa-solid fa-box-open" style="margin-right:8px; width:20px;"></i> Core Operations</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <!-- Second Level Nested Accordion -->
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Billing & Orders <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('orders.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>billing/orders.php" class="<?= $currentPage == 'orders.php' ? 'active' : '' ?>">Order Management</a> <?php endif; ?>
                            <?php if (showLink('partyrates.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>billing/partyrates.php" class="<?= $currentPage == 'partyrates.php' ? 'active' : '' ?>">Party Rates</a> <?php endif; ?>
                            <?php if (showLink('billing.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>billing/billing.php" class="<?= $currentPage == 'billing.php' ? 'active' : '' ?>">Billing / Invoice</a> <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Sales Data <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('salesdata.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/salesdata.php">Sales Data</a> <?php endif; ?>
                            <?php if (showLink('primary.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/primary.php">Primary</a> <?php endif; ?>
                            <?php if (showLink('secondary.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/secondary.php">Secondary</a> <?php endif; ?>
                            <?php if (showLink('achievements.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/achievements.php">Achievements</a> <?php endif; ?>
                            <?php if (showLink('salestrend.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/salestrend.php">Sales Trend</a> <?php endif; ?>
                        </div>
                    </div>

                    <!-- Second Level Direct Links (Nodes) -->
                    <?php if (showLink('dispatch.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>dispatch/dispatch.php">Dispatch</a> <?php endif; ?>
                    <?php if (showLink('stock.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>inventory/stock.php">Stock</a> <?php endif; ?>
                    <?php if (showLink('partyledger.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>accounts/partyledger.php">Party Ledger</a> <?php endif; ?>
                    <?php if (showLink('collections.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>accounts/collections.php">Collections</a> <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isAdmin || $isManager || $isMR): ?>
        <!-- FIELD FORCE (Master Section) -->
        <div class="nav-group" data-group="Field Force">
            <button class="nav-master" type="button">
                <span><i class="fa-solid fa-users-gear" style="margin-right:8px; width:20px;"></i> Field Force</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Tour Plan <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('tourplan.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/tourplan.php">Create Tour Plan</a> <?php endif; ?>
                            <?php if (showLink('tourplancalendar.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/tourplancalendar.php">Tour Calendar</a> <?php endif; ?>
                            <?php if (($isAdmin || $isManager) && showLink('tourplanapproval.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/tourplanapproval.php">Approvals</a> <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Expense Claim <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('expense.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/expense.php">Submit Claim</a> <?php endif; ?>
                            <?php if (($isAdmin || $isManager) && showLink('expenseapproval.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/expenseapproval.php">Approvals</a> <?php endif; ?>
                        </div>
                    </div>

                    <?php if (showLink('doctors.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/doctors.php">Doctors</a> <?php endif; ?>
                    <?php if (showLink('dailyvisit.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/dailyvisit.php">Daily Visit Report</a> <?php endif; ?>
                    <?php if (showLink('secondaryentry.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/secondaryentry.php">Secondary Entry</a> <?php endif; ?>
                    <?php if (showLink('mystock.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>inventory/mystock.php">My Sample Stock</a> <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- HR & PAYROLL (Master Section) -->
        <div class="nav-group" data-group="HR & Payroll">
            <button class="nav-master" type="button">
                <span><i class="fa-solid fa-user-tie" style="margin-right:8px; width:20px;"></i> HR & Payroll</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Leave <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('leave.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/leave.php">Leave Request</a> <?php endif; ?>
                            <?php if (($isAdmin || $isManager || $isHR) && showLink('leaveapproval.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/leaveapproval.php">Leave Approval</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Payroll <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('salary.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/salary.php">Monthly Salary</a> <?php endif; ?>
                            <?php if (showLink('salaryslip.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/salaryslip.php">Salary Slip</a> <?php endif; ?>
                            <?php if (showLink('form16.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/form16.php">Form 16</a> <?php endif; ?>
                        </div>
                    </div>

                    <?php if (showLink('profile.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/profile.php">My Profile</a> <?php endif; ?>
                    <?php if (showLink('attendance.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/attendance.php">Daily Attendance</a> <?php endif; ?>
                    <?php if (showLink('holiday.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/holiday.php">Holiday Calendar</a> <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- MASTER DATA (Master Section) -->
        <div class="nav-group" data-group="Master Data">
            <button class="nav-master" type="button">
                <span><i class="fa-solid fa-database" style="margin-right:8px; width:20px;"></i> Master Data</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <?php if (showLink('employees.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/employees.php">Employee Master</a> <?php endif; ?>
                    <?php if (showLink('parties.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/parties.php">Parties Master</a> <?php endif; ?>
                    <?php if (showLink('products.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/products.php">Products Master</a> <?php endif; ?>
                    <?php if (showLink('geography.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/geography.php">Geographic Master</a> <?php endif; ?>
                    <?php if (showLink('masterupload.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/masterupload.php">Bulk Master Upload</a> <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ADMINISTRATION (Master Section) -->
        <div class="nav-group" data-group="Administration">
            <button class="nav-master" type="button">
                <span><i class="fa-solid fa-gears" style="margin-right:8px; width:20px;"></i> Administration</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <?php if (showLink('users.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/users.php">System Security</a> <?php endif; ?>
                    <?php if (showLink('permissions.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/permissions.php">Permissions</a> <?php endif; ?>
                    <?php if (showLink('settings.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/settings.php">App Settings</a> <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </nav>
</aside>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Top level accordion (Nav Master)
    document.querySelectorAll('.nav-master').forEach(master => {
        master.addEventListener('click', (e) => {
            // Only trigger if it's a button (links should just navigate)
            if(master.tagName.toLowerCase() !== 'button') return;
            
            const submenu = master.nextElementSibling;
            const isOpen = master.classList.contains('open');
            
            // Close other top level menus
            document.querySelectorAll('.nav-master').forEach(btn => {
                btn.classList.remove('open');
                if (btn.nextElementSibling) btn.nextElementSibling.style.maxHeight = null;
            });
            
            if (!isOpen && submenu) {
                master.classList.add('open');
                submenu.style.maxHeight = "2000px"; // Open container wide enough
            }
        });
    });

    // 2nd level accordion (Nav Sub Master)
    document.querySelectorAll('.nav-sub-master').forEach(subMaster => {
        subMaster.addEventListener('click', (e) => {
            e.stopPropagation(); // Don't trigger parent clicks
            const subMenu = subMaster.nextElementSibling;
            const isOpen = subMaster.classList.contains('open');
            
            // Close siblings in the same sub-menu
            const parentInner = subMaster.closest('.nav-submenu-inner');
            if (parentInner) {
                parentInner.querySelectorAll('.nav-sub-master').forEach(btn => {
                    btn.classList.remove('open');
                    if(btn.nextElementSibling) btn.nextElementSibling.style.maxHeight = null;
                });
            }

            if (!isOpen && subMenu) {
                subMaster.classList.add('open');
                subMenu.style.maxHeight = subMenu.scrollHeight + "px";
            }
        });
    });
});
</script>