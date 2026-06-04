<?php
session_start();

// Include config and functions
include 'config.php';
include 'functions.php';

$result = null;
$error = null;
$debug_sql = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a confirmation of overwrite
    if (isset($_POST['confirm_overwrite'])) {
        $_SESSION['confirm_overwrite'] = true;
    }
    
    // Handle file uploads
    if (isset($_FILES['airtable_csv']) && isset($_FILES['schema_excel']) && isset($_POST['table_suffix'])) {
        $table_suffix = trim($_POST['table_suffix']);
        
        // Validate suffix
        if (empty($table_suffix) || !preg_match('/^[a-zA-Z0-9_]+$/', $table_suffix)) {
            $error = "Invalid table suffix. Use only letters, numbers, and underscores.";
        } else {
            try {
                // Upload and process files
                $airtable_file = $_FILES['airtable_csv']['tmp_name'];
                $schema_file = $_FILES['schema_excel']['tmp_name'];
                $schema_name = isset($_FILES['schema_excel']['name']) ? $_FILES['schema_excel']['name'] : '';
                
                if (!file_exists($airtable_file) || !file_exists($schema_file)) {
                    throw new Exception("Files not uploaded properly.");
                }

                $schema_ext = strtolower(pathinfo($schema_name, PATHINFO_EXTENSION));
                if ($schema_ext !== 'xlsx') {
                    throw new Exception("Schema file must be an Excel .xlsx file.");
                }
                
                // Parse schema Excel (.xlsx)
                $schema_table_filter = isset($_POST['schema_table_filter'])
                    ? trim((string)$_POST['schema_table_filter'])
                    : '';
                $schema = parseSchema(
                    $schema_file,
                    $schema_name,
                    $schema_table_filter !== '' ? $schema_table_filter : null
                );

                $dupes = findDuplicateSchemaEnColumns($schema);
                $debug_sql = "Schema parsed. Total fields: " . count($schema) . "\n\n";
                if (!empty($dupes)) {
                    $debug_sql .= formatDuplicateSchemaEnColumnsMessage($dupes) . "\n\n";
                }
                $count = 0;
                foreach ($schema as $name => $info) {
                    $debug_sql .= ($count+1) . ". Field: '$name' | Type: '{$info['type']}' | SQL: '{$info['sql_type']}'\n";
                    $count++;
                    if ($count >= 20) {
                        $debug_sql .= "\n... and " . (count($schema) - 20) . " more fields\n";
                        break;
                    }
                }
                
                // Stream CSV row-by-row (avoids loading huge exports into memory)
                $result = migrateDataFromCsvFile($conn, $schema, $airtable_file, $table_suffix);
                
                // Clear session
                unset($_SESSION['confirm_overwrite']);
                
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
                if ($conn->error) {
                    $error .= " | MySQL Error: " . $conn->error;
                }
            }
        }
    }
}

// Get existing tables for overwrite check
$table_suffix = isset($_POST['table_suffix']) ? trim($_POST['table_suffix']) : '';
$old_air_table = "old_air_" . $table_suffix;
$gb_inside_table = "gb_inside_" . $table_suffix;

$tables_exist = false;
if ($table_suffix) {
    $check_query = "SHOW TABLES LIKE '$old_air_table' OR SHOW TABLES LIKE '$gb_inside_table'";
    $result_check = $conn->query("SHOW TABLES LIKE '$old_air_table'");
    if ($result_check && $result_check->num_rows > 0) {
        $tables_exist = true;
    }
    $result_check = $conn->query("SHOW TABLES LIKE '$gb_inside_table'");
    if ($result_check && $result_check->num_rows > 0) {
        $tables_exist = true;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Airtable Data Migration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { font-size: 28px; margin-bottom: 30px; color: #000; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 14px; margin-bottom: 8px; color: #333; font-weight: 500; }
        input[type="file"], input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ccc; font-size: 14px; }
        input[type="text"] { font-family: monospace; }
        button { padding: 12px 24px; background: #000; color: #fff; border: none; font-size: 14px; cursor: pointer; }
        button:hover { background: #333; }
        .error { background: #fee; border: 1px solid #fcc; padding: 12px; margin-bottom: 20px; color: #c00; font-size: 14px; }
        .success { background: #efe; border: 1px solid #cfc; padding: 12px; margin-bottom: 20px; color: #060; font-size: 14px; }
        .warning { background: #ffc; border: 1px solid #fc9; padding: 12px; margin-bottom: 20px; color: #660; font-size: 14px; }
        .results { background: #fff; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; }
        .results h2 { font-size: 18px; margin-bottom: 15px; }
        .table-info { background: #f9f9f9; border-left: 3px solid #000; padding: 12px; margin-bottom: 15px; font-size: 13px; }
        .table-info strong { display: block; margin-bottom: 4px; }
        .excluded-fields { margin-top: 10px; }
        .excluded-fields strong { display: block; margin-bottom: 6px; }
        .excluded-fields ul { margin-left: 20px; font-size: 13px; }
        .excluded-fields li { margin-bottom: 2px; }
        .confirm-box { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin-bottom: 20px; }
        .confirm-box p { margin-bottom: 12px; font-size: 14px; color: #333; }
        .confirm-buttons { display: flex; gap: 10px; }
        .confirm-buttons button { padding: 10px 20px; font-size: 13px; }
        .btn-danger { background: #cc0000; }
        .btn-danger:hover { background: #990000; }
        .debug { background: #f0f0f0; border: 1px solid #999; padding: 12px; margin-bottom: 20px; font-family: monospace; font-size: 12px; white-space: pre-wrap; overflow-x: auto; }
    </style>
</head>
<body>

<div class="container">
    <h1>Airtable Data Migration Tool</h1>
    
    <?php if ($debug_sql): ?>
        <div class="debug"><?php echo htmlspecialchars($debug_sql); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['create_table_debug'])): ?>
        <div class="debug"><?php echo htmlspecialchars($_SESSION['create_table_debug']); ?></div>
        <?php unset($_SESSION['create_table_debug']); ?>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($result): ?>
        <div class="results">
            <h2>Migration Complete</h2>
            
            <div class="table-info">
                <strong>✓ old_air_<?php echo htmlspecialchars($table_suffix); ?></strong>
                Fields: <?php echo $result['old_air']['field_count']; ?> | Rows: <?php echo $result['old_air']['row_count']; ?>
            </div>
            
            <div class="table-info">
                <strong>✓ gb_inside_<?php echo htmlspecialchars($table_suffix); ?></strong>
                Fields: <?php echo $result['gb_inside']['field_count']; ?> | Rows: <?php echo $result['gb_inside']['row_count']; ?>
            </div>
            
            <?php if (!empty($result['excluded_fields'])): ?>
                <div class="excluded-fields">
                    <strong>Excluded fields from gb_inside_<?php echo htmlspecialchars($table_suffix); ?>:</strong>
                    <ul>
                        <?php foreach ($result['excluded_fields'] as $field): ?>
                            <li><?php echo htmlspecialchars($field['name']); ?> (<?php echo htmlspecialchars($field['type']); ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($tables_exist && !$_SESSION['confirm_overwrite']): ?>
        <div class="confirm-box">
            <p>Tables <strong>old_air_<?php echo htmlspecialchars($table_suffix); ?></strong> and/or <strong>gb_inside_<?php echo htmlspecialchars($table_suffix); ?></strong> already exist.</p>
            <p>Do you want to overwrite them with new data?</p>
            <form method="POST">
                <input type="hidden" name="table_suffix" value="<?php echo htmlspecialchars($table_suffix); ?>">
                <input type="hidden" name="airtable_csv" value="">
                <input type="hidden" name="schema_excel" value="">
                <div class="confirm-buttons">
                    <button type="button" onclick="document.querySelector('form').style.display='none';">Cancel</button>
                    <button type="submit" name="confirm_overwrite" value="1" class="btn-danger">Yes, Overwrite</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="airtable_csv">Airtable CSV (data)</label>
                <input type="file" id="airtable_csv" name="airtable_csv" accept=".csv" required>
            </div>
            
            <div class="form-group">
                <label for="schema_excel">Schema Excel (schema.xlsx)</label>
                <input type="file" id="schema_excel" name="schema_excel" accept=".xlsx" required>
            </div>
            
            <div class="form-group">
                <label for="table_suffix">Table suffix (e.g., eg1)</label>
                <input type="text" id="table_suffix" name="table_suffix" placeholder="eg1" required>
            </div>
            
            <div class="form-group">
                <label for="schema_table_filter">Schema table name (optional)</label>
                <input type="text" id="schema_table_filter" name="schema_table_filter" placeholder="Exact value from schema Excel column B if the file lists multiple tables">
            </div>
            
            <button type="submit">Migrate Data</button>
        </form>
    <?php endif; ?>
    
</div>

</body>
</html>