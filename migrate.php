<?php
@ini_set('upload_max_filesize', '512M');
@ini_set('post_max_size', '512M');
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '0');
set_time_limit(0);

session_start();

// Include config and functions
include 'config.php';
include 'functions.php';

$result = null;
$error = null;
$inbox_dir = __DIR__ . DIRECTORY_SEPARATOR . 'inbox';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length > 0 && empty($_POST) && empty($_FILES)) {
        $error = 'POST body too large for PHP limits. Copy CSV and .xlsx into migrate/inbox/, use "Use inbox files" below, then restart Apache after php.ini changes.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    // Check if this is a confirmation of overwrite
    if (isset($_POST['confirm_overwrite'])) {
        $_SESSION['confirm_overwrite'] = true;
    }
    
    // Handle file uploads or inbox paths (large files — no HTTP upload)
    $has_upload = isset($_FILES['airtable_csv'], $_FILES['schema_excel'])
        && is_uploaded_file($_FILES['airtable_csv']['tmp_name'] ?? '')
        && is_uploaded_file($_FILES['schema_excel']['tmp_name'] ?? '');
    $use_inbox = !empty($_POST['use_inbox']);

    if (($has_upload || $use_inbox) && isset($_POST['table_suffix'])) {
        $table_suffix = trim($_POST['table_suffix']);
        
        // Validate suffix
        if (empty($table_suffix) || !preg_match('/^[a-zA-Z0-9_]+$/', $table_suffix)) {
            $error = "Invalid table suffix. Use only letters, numbers, and underscores.";
        } else {
            try {
                if ($use_inbox) {
                    $csv_name = basename((string)($_POST['inbox_csv'] ?? ''));
                    $xlsx_name = basename((string)($_POST['inbox_xlsx'] ?? ''));
                    if ($csv_name === '' || $xlsx_name === '') {
                        throw new Exception('Inbox mode: enter CSV and .xlsx filenames.');
                    }
                    $airtable_file = $inbox_dir . DIRECTORY_SEPARATOR . $csv_name;
                    $schema_file = $inbox_dir . DIRECTORY_SEPARATOR . $xlsx_name;
                    $schema_name = $xlsx_name;
                } else {
                    $airtable_file = $_FILES['airtable_csv']['tmp_name'];
                    $schema_file = $_FILES['schema_excel']['tmp_name'];
                    $schema_name = isset($_FILES['schema_excel']['name']) ? $_FILES['schema_excel']['name'] : '';
                }
                
                if (!file_exists($airtable_file) || !file_exists($schema_file)) {
                    throw new Exception($use_inbox
                        ? 'Inbox files not found. Place them in migrate/inbox/.'
                        : 'Files not uploaded properly.');
                }
                if ($use_inbox) {
                    $real_inbox = realpath($inbox_dir);
                    $real_csv = realpath($airtable_file);
                    $real_xlsx = realpath($schema_file);
                    if ($real_inbox === false || $real_csv === false || $real_xlsx === false
                        || strpos($real_csv, $real_inbox) !== 0 || strpos($real_xlsx, $real_inbox) !== 0) {
                        throw new Exception('Inbox paths must stay inside migrate/inbox/.');
                    }
                    $airtable_file = $real_csv;
                    $schema_file = $real_xlsx;
                }

                $schema_ext = strtolower(pathinfo($schema_name, PATHINFO_EXTENSION));
                if ($schema_ext !== 'xlsx') {
                    throw new Exception("Schema file must be an Excel .xlsx file.");
                }

                $schema_table_filter = isset($_POST['schema_table_filter'])
                    ? trim((string)$_POST['schema_table_filter'])
                    : '';
                if ($schema_table_filter !== '' && !preg_match('/^[\p{L}\p{N}_\s\-.]+$/u', $schema_table_filter)) {
                    throw new Exception('Schema table filter has invalid characters.');
                }
                
                // Parse schema Excel (.xlsx); optional filter = exact table name from schema column B
                $schema = parseSchema(
                    $schema_file,
                    $schema_name,
                    $schema_table_filter !== '' ? $schema_table_filter : null
                );

                // Stream CSV row-by-row (avoids loading huge exports into memory)
                $result = migrateDataFromCsvFile($conn, $schema, $airtable_file, $table_suffix);
                
                // Clear session
                unset($_SESSION['confirm_overwrite']);
                
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
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
    </style>
</head>
<body>

<div class="container">
    <h1>Airtable Data Migration Tool</h1>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($result): ?>
        <div class="results">
            <h2>Migration Complete</h2>
            
            <div class="table-info">
                <strong>✓ old_air_<?php echo htmlspecialchars($table_suffix); ?></strong>
                Fields: <?php echo $result['old_air']['field_count']; ?> | Rows: <?php echo $result['old_air']['row_count']; ?>
                <?php if (!empty($result['old_air']['failed_inserts'])): ?>
                    <br><span style="color:#c00;">Failed INSERTs: <?php echo (int)$result['old_air']['failed_inserts']; ?>
                    <?php if (!empty($result['old_air']['last_insert_error'])): ?>
                        — <?php echo htmlspecialchars($result['old_air']['last_insert_error']); ?>
                    <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="table-info">
                <strong>✓ gb_inside_<?php echo htmlspecialchars($table_suffix); ?></strong>
                Fields: <?php echo $result['gb_inside']['field_count']; ?> | Rows: <?php echo $result['gb_inside']['row_count']; ?>
                <?php if (!empty($result['gb_inside']['failed_inserts'])): ?>
                    <br><span style="color:#c00;">Failed INSERTs: <?php echo (int)$result['gb_inside']['failed_inserts']; ?>
                    <?php if (!empty($result['gb_inside']['last_insert_error'])): ?>
                        — <?php echo htmlspecialchars($result['gb_inside']['last_insert_error']); ?>
                    <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($result['duplicate_en_message'])): ?>
                <div class="warning">
                    <strong>Schema note — duplicate English column names</strong>
                    <pre style="margin-top:8px;white-space:pre-wrap;font-size:12px;font-family:monospace;"><?php echo htmlspecialchars($result['duplicate_en_message']); ?></pre>
                </div>
            <?php endif; ?>
            
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
    
    <?php if ($tables_exist && !isset($_SESSION['confirm_overwrite'])): ?>
        <div class="confirm-box">
            <p>Tables you'll be creating are: <strong>old_air_<?php echo htmlspecialchars($table_suffix); ?></strong> and <strong>gb_inside_<?php echo htmlspecialchars($table_suffix); ?></strong></p>
            <p>Do you want to proceed with this data?</p>
            <form method="POST">
                <input type="hidden" name="table_suffix" value="<?php echo htmlspecialchars($table_suffix); ?>">
                <input type="hidden" name="airtable_csv" value="">
                <input type="hidden" name="schema_excel" value="">
                <div class="confirm-buttons">
                    <button type="button" onclick="document.querySelector('form').style.display='none';">Cancel</button>
                    <button type="submit" name="confirm_overwrite" value="1" class="btn-danger">Yes, Proceed</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <form method="POST" enctype="multipart/form-data" id="migrate-form">
            <div class="form-group">
                <label><input type="checkbox" name="use_inbox" value="1" id="use_inbox"> Use inbox files (copy into <code>migrate/inbox/</code> — no browser upload)</label>
            </div>
            <div class="form-group" id="upload-fields">
                <label for="airtable_csv">Airtable CSV (airtable_data.csv)</label>
                <input type="file" id="airtable_csv" name="airtable_csv" accept=".csv">
            </div>
            
            <div class="form-group" id="upload-schema-fields">
                <label for="schema_excel">Schema Excel (schema.xlsx)</label>
                <input type="file" id="schema_excel" name="schema_excel" accept=".xlsx">
            </div>
            <div class="form-group" id="inbox-fields" style="display:none;">
                <label for="inbox_csv">Inbox CSV filename</label>
                <input type="text" id="inbox_csv" name="inbox_csv" placeholder="airtable_data.csv">
                <label for="inbox_xlsx" style="margin-top:12px;">Inbox .xlsx filename</label>
                <input type="text" id="inbox_xlsx" name="inbox_xlsx" placeholder="schema.xlsx">
            </div>
            
            <div class="form-group">
                <label for="table_suffix">Table suffix (e.g., table_name)</label>
                <input type="text" id="table_suffix" name="table_suffix" placeholder="table_name" required>
            </div>

            <div class="form-group">
                <label for="schema_table_filter">Schema table name (optional)</label>
                <input type="text" id="schema_table_filter" name="schema_table_filter" placeholder="Exact value from schema Excel column B if the file lists multiple tables">
                <small style="display:block;color:#555;margin-top:6px;font-size:12px;">If column B is empty for all rows, leave blank. When set, only those rows are used (prevents the wrong table overwriting field names like No / no).</small>
            </div>
            
            <button type="submit">Migrate Data</button>
        </form>
        <script>
        (function () {
            var cb = document.getElementById('use_inbox');
            var upload = document.getElementById('upload-fields');
            var uploadSchema = document.getElementById('upload-schema-fields');
            var inbox = document.getElementById('inbox-fields');
            var csv = document.getElementById('airtable_csv');
            var xlsx = document.getElementById('schema_excel');
            function sync() {
                var on = cb && cb.checked;
                if (upload) upload.style.display = on ? 'none' : '';
                if (uploadSchema) uploadSchema.style.display = on ? 'none' : '';
                if (inbox) inbox.style.display = on ? '' : 'none';
                if (csv) csv.required = !on;
                if (xlsx) xlsx.required = !on;
            }
            if (cb) cb.addEventListener('change', sync);
            sync();
        })();
        </script>
    <?php endif; ?>
    
</div>

</body>
</html>