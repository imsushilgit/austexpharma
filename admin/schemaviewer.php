<?php
session_start();

// Basic security check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/../includes/core.php';

try {
    // 1. Get current Database Name
    $dbName =$pdo->query('SELECT DATABASE()')->fetchColumn();

    // 2. Get all tables in the Database
    $tables =$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    // 3. Extract Column info for each table
    $schemaData = [];
    foreach ($tables as$table) {
        $columns =$pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $schemaData[$table] =$columns;
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$pageTitle = "Database Schema Viewer";
include __DIR__ . '/../includes/header.php';
?>


<div class="schema-container">
    
    <!-- Database Overview Header -->
    <div class="db-header-panel">
        <div>
            <h2>Database Schema Details</h2>
            <div style="font-size: 12px; color: #94a3b8; margin-top: 5px;">Active MySQL Connection Data</div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 11px; color: #cbd5e1; margin-bottom: 4px;">Connected Database:</div>
            <div class="badge"><?= htmlspecialchars($dbName ?: 'Unknown DB'); ?></div>
            <div style="font-size: 11px; margin-top: 6px; color: #94a3b8;">Total Tables: <?= count($tables); ?></div>
        </div>
    </div>

    <!-- Iterate through each Table -->
    <?php foreach ($schemaData as $tableName =>$columns): ?>
    <div class="table-card">
        <div class="table-card-header">
            <span>&#128193; Table: <?= htmlspecialchars($tableName); ?></span>
            <span style="font-size: 11px; color: var(--ink-soft); font-weight: normal;"><?= count($columns); ?> Columns</span>
        </div>
        
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 20%;">Field Name</th>
                    <th style="width: 20%;">Data Type</th>
                    <th style="width: 10%; text-align: center;">Null</th>
                    <th style="width: 15%; text-align: center;">Key</th>
                    <th style="width: 15%;">Default Value</th>
                    <th style="width: 20%;">Extra</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns as$col): ?>
                <tr>
                    <td style="font-weight: 600; color: var(--navy);"><?= htmlspecialchars($col['Field']); ?></td>
                    <td class="type-col"><?= htmlspecialchars($col['Type']); ?></td>
                    <td style="text-align: center; color: <?= $col['Null'] === 'YES' ? '#94a3b8' : '#b91c1c'; ?>; font-weight: bold;">
                        <?= htmlspecialchars($col['Null']); ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ($col['Key'] === 'PRI'): ?>
                            <span class="pk-badge">PRIMARY KEY</span>
                        <?php elseif ($col['Key'] === 'UNI'): ?>
                            <span class="pk-badge" style="background:#e0f2fe; color:#0369a1;">UNIQUE</span>
                        <?php elseif ($col['Key'] === 'MUL'): ?>
                            <span class="pk-badge" style="background:#f3f4f6; color:#4b5563;">INDEX</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-family: monospace; color: var(--ink-soft);">
                        <?= $col['Default'] !== null ? htmlspecialchars($col['Default']) : '<em>NULL</em>'; ?>
                    </td>
                    <td style="font-size: 10px; color: var(--sky); font-weight: bold;">
                        <?= htmlspecialchars($col['Extra']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>