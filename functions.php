<?php

// - Airtable CSV has: Japanese field names (Col C)
// - Schema tells us: English names to use in SQL (Col E), MySQL types (Col G)
// - Map Japanese CSV headers → English SQL column names

const SCHEMA_COL_BASE = 0;               // Column A - Base ID
const SCHEMA_COL_TABLE = 1;              // Column B - Table name
const SCHEMA_COL_FIELD_NAME_JP = 2;      // Column C - Japanese name
const SCHEMA_COL_FIELD_TYPE = 3;         // Column D - Airtable field type
const SCHEMA_COL_FIELD_NAME_EN = 4;      // Column E - English name
const SCHEMA_COL_SQL_TYPE = 6;           // Column G - MySQL data type

function parseSchema($file, $original_name = '', $filter_table_name = null) {
    $schema = [];
    $extension = strtolower(pathinfo($original_name ?: $file, PATHINFO_EXTENSION));
    $rows = [];

    if ($extension === 'xlsx') {
        $rows = readXlsxRows($file);
    } else {
        $rows = readCsvRows($file);
    }

    $ft_raw = $filter_table_name !== null ? trim((string)$filter_table_name) : '';
    $ft = $ft_raw !== '' ? normalizeHeaderKey(convertToUtf8($ft_raw)) : null;

    foreach ($rows as $row) {
        if (count($row) < 7) continue;
        if (empty($row[SCHEMA_COL_BASE])) continue;

        if ($ft !== null) {
            $cell_tbl = isset($row[SCHEMA_COL_TABLE]) ? trim(convertToUtf8($row[SCHEMA_COL_TABLE])) : '';
            if ($cell_tbl !== '' && normalizeHeaderKey($cell_tbl) !== $ft) {
                continue;
            }
        }
        
        $jp_name = isset($row[SCHEMA_COL_FIELD_NAME_JP]) ? trim(convertToUtf8($row[SCHEMA_COL_FIELD_NAME_JP])) : '';
        $en_name = isset($row[SCHEMA_COL_FIELD_NAME_EN]) ? trim(convertToUtf8($row[SCHEMA_COL_FIELD_NAME_EN])) : '';
        $field_type = isset($row[SCHEMA_COL_FIELD_TYPE]) ? trim(convertToUtf8($row[SCHEMA_COL_FIELD_TYPE])) : '';
        $sql_type = isset($row[SCHEMA_COL_SQL_TYPE]) ? trim(convertToUtf8($row[SCHEMA_COL_SQL_TYPE])) : '';
        
        // Need both Japanese (for CSV matching) and English (for SQL)
        if (empty($jp_name) || empty($en_name) || empty($sql_type)) {
            continue;
        }
        
        // Clean SQL type
        $sql_type = preg_replace('/[\r\n\t]+/', ' ', $sql_type);
        $sql_type = trim($sql_type);
        $sql_type = convertPostgresToMySQL($sql_type);

        // Primary "No" formula is usually a numeric record id; schema often wrongly uses DATE → MySQL stores NULL.
        $sql_u = strtoupper($sql_type);
        if (in_array($field_type, ['formula', 'rollup', 'multipleLookupValues'], true)
            && sqlTypeTemporalKind($sql_u) !== null
            && (normalizeFieldLabelForSchemaMatch($jp_name) === 'no'
                || normalizeFieldLabelForSchemaMatch($en_name) === 'no')
        ) {
            $sql_type = 'VARCHAR(50)';
        }
        
        if (!empty($sql_type)) {
            $schema[$jp_name] = [
                'jp_name' => $jp_name,      // For matching CSV headers
                'en_name' => $en_name,      // For SQL column name
                'type' => $field_type,      // For logic (formula/multipleSelects/etc)
                'sql_type' => $sql_type,    // For CREATE TABLE
                'jp_key' => normalizeHeaderKey($jp_name),
                'en_key' => normalizeHeaderKey($en_name)
            ];
        }
    }
    
    return $schema;
}

function readCsvRows($file) {
    $rows = [];
    if (($handle = fopen($file, 'r')) === false) {
        return $rows;
    }

    // Skip header row.
    fgetcsv($handle, 0, ',', '"', '\\');
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function readXlsxRows($file) {
    if (!class_exists('ZipArchive')) {
        return readXlsxRowsUsingCom($file);
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new Exception("Unable to open Excel schema file.");
    }

    $shared_strings = [];
    $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($shared_xml !== false) {
        $shared_sxml = @simplexml_load_string($shared_xml);
        if ($shared_sxml !== false && isset($shared_sxml->si)) {
            foreach ($shared_sxml->si as $si) {
                if (isset($si->t)) {
                    $shared_strings[] = (string)$si->t;
                } else {
                    $text = '';
                    if (isset($si->r)) {
                        foreach ($si->r as $run) {
                            $text .= (string)$run->t;
                        }
                    }
                    $shared_strings[] = $text;
                }
            }
        }
    }

    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet_xml === false) {
        throw new Exception("Excel schema must contain sheet1.");
    }

    $sheet = @simplexml_load_string($sheet_xml);
    if ($sheet === false || !isset($sheet->sheetData)) {
        throw new Exception("Invalid Excel schema format.");
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $row_data = [];
        foreach ($row->c as $cell) {
            $cell_ref = isset($cell['r']) ? (string)$cell['r'] : '';
            $col_index = excelColumnIndex($cell_ref);
            $cell_type = isset($cell['t']) ? (string)$cell['t'] : '';
            $value = '';

            if ($cell_type === 's') {
                $shared_idx = isset($cell->v) ? (int)$cell->v : -1;
                $value = isset($shared_strings[$shared_idx]) ? $shared_strings[$shared_idx] : '';
            } elseif ($cell_type === 'inlineStr') {
                $value = isset($cell->is->t) ? (string)$cell->is->t : '';
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }

            $row_data[$col_index] = $value;
        }

        if (empty($row_data)) {
            continue;
        }

        ksort($row_data);
        $max_index = max(array_keys($row_data));
        $normalized = array_fill(0, $max_index + 1, '');
        foreach ($row_data as $index => $value) {
            $normalized[$index] = $value;
        }
        $rows[] = $normalized;
    }

    // Skip header row.
    if (!empty($rows)) {
        array_shift($rows);
    }

    return $rows;
}

function readXlsxRowsUsingCom($file) {
    if (!class_exists('COM')) {
        throw new Exception(
            "ZipArchive is not available, and COM is disabled. " .
            "Enable PHP zip extension (php_zip.dll) in XAMPP, or enable COM."
        );
    }

    $excel = null;
    $workbook = null;

    try {
        $excel = new COM('Excel.Application');
        $excel->Visible = false;
        $excel->DisplayAlerts = false;

        $workbook = $excel->Workbooks->Open(realpath($file));
        $sheet = $workbook->Worksheets(1);
        $used_range = $sheet->UsedRange;
        $row_count = (int)$used_range->Rows->Count;
        $col_count = (int)$used_range->Columns->Count;

        $rows = [];
        for ($r = 2; $r <= $row_count; $r++) { // skip header row
            $row = [];
            $has_value = false;

            for ($c = 1; $c <= $col_count; $c++) {
                $cell_value = $sheet->Cells($r, $c)->Value;
                $cell_value = $cell_value === null ? '' : (string)$cell_value;
                $row[] = $cell_value;
                if ($cell_value !== '') {
                    $has_value = true;
                }
            }

            if ($has_value) {
                $rows[] = $row;
            }
        }

        $workbook->Close(false);
        $excel->Quit();
        $workbook = null;
        $excel = null;

        return $rows;
    } catch (Exception $e) {
        if ($workbook) {
            $workbook->Close(false);
        }
        if ($excel) {
            $excel->Quit();
        }
        throw new Exception(
            "Failed to read Excel schema. Enable PHP zip extension (php_zip.dll) in XAMPP for reliable .xlsx support. " .
            "Technical detail: " . $e->getMessage()
        );
    }
}

function excelColumnIndex($cell_ref) {
    if (!preg_match('/^[A-Z]+/', $cell_ref, $matches)) {
        return 0;
    }

    $letters = $matches[0];
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
    }

    return $index - 1;
}

function convertPostgresToMySQL($pg_type) {
    $pg_type = trim($pg_type);
    
    // Array types → JSON
    if (strpos($pg_type, '[]') !== false) {
        return 'JSON';
    }
    
    if (strpos($pg_type, 'TIMESTAMPTZ') !== false) {
        return 'DATETIME';
    }
    
    if (strpos($pg_type, 'TIMESTAMP') !== false) {
        return 'DATETIME';
    }
    
    if (strpos($pg_type, 'INTERVAL') !== false) {
        return 'VARCHAR(50)';
    }
    
    if (strpos($pg_type, 'NUMERIC') !== false) {
        if (preg_match('/NUMERIC\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $pg_type, $matches)) {
            return 'DECIMAL(' . $matches[1] . ',' . $matches[2] . ')';
        }
        return 'DECIMAL(10,2)';
    }
    
    if ($pg_type === 'INTEGER') {
        return 'INT';
    }
    
    if ($pg_type === 'BIGINT') {
        return 'BIGINT';
    }
    
    if ($pg_type === 'JSONB' || $pg_type === 'JSON') {
        return 'JSON';
    }
    
    if (stripos($pg_type, 'serial') !== false) {
        return 'VARCHAR(50)';
    }
    
    // Remove modifiers
    $pg_type = preg_replace('/\s*PRIMARY\s+KEY/i', '', $pg_type);
    $pg_type = preg_replace('/\s*AUTO_INCREMENT/i', '', $pg_type);
    $pg_type = preg_replace('/\s+/', ' ', $pg_type);
    $pg_type = trim($pg_type);
    
    return !empty($pg_type) ? $pg_type : 'TEXT';
}

/**
 * Read and normalize the first CSV row as column headers (BOM-safe).
 *
 * @param resource $handle
 * @return array|false
 */
function readAirtableCsvHeaders($handle) {
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        return false;
    }

    return array_map(function ($h) {
        $h = trim(convertToUtf8($h));
        if (strpos($h, "\xEF\xBB\xBF") === 0) {
            $h = substr($h, 3);
        }
        if (strpos($h, "\ufeff") === 0) {
            $h = substr($h, 1);
        }
        return $h;
    }, $headers);
}

/**
 * Build one associative record from a CSV row (same keys as parseAirtableCSV).
 */
function airtableCsvRowToRecord(array $headers, array $row) {
    $record = [];
    for ($i = 0; $i < count($headers); $i++) {
        $record[$headers[$i]] = isset($row[$i]) ? convertToUtf8($row[$i]) : null;
    }
    return $record;
}

function parseAirtableCSV($file) {
    $data = [];
    if (($handle = fopen($file, 'r')) !== false) {
        $headers = readAirtableCsvHeaders($handle);
        if ($headers !== false) {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($row) !== count($headers)) {
                    continue;
                }
                $data[] = airtableCsvRowToRecord($headers, $row);
            }
        }
        fclose($handle);
    }

    return $data;
}

/**
 * Migrate from an Airtable CSV file without loading all rows into memory (large exports).
 */
function migrateDataFromCsvFile($conn, $schema, $csvPath, $suffix) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    if (!is_readable($csvPath)) {
        throw new Exception('CSV file is not readable.');
    }

    $handle = fopen($csvPath, 'r');
    if ($handle === false) {
        throw new Exception('Could not open CSV file.');
    }

    $headers = readAirtableCsvHeaders($handle);
    if ($headers === false) {
        fclose($handle);
        throw new Exception('CSV file is empty or invalid.');
    }

    try {
        $generator = function () use ($handle, $headers) {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($row) !== count($headers)) {
                    continue;
                }
                yield airtableCsvRowToRecord($headers, $row);
            }
        };

        return migrateDataFromIterable($conn, $schema, $generator(), $suffix);
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
}

/**
 * Find CSV value for a schema field when CSV column titles differ from schema
 * jp/en labels (e.g. Airtable export "No." vs schema row "No").
 *
 * @param bool $found set to true if a matching CSV column exists (value may be null or '')
 * @return mixed|null
 */
function recordValueForSchemaField(array $record, array $field_info, &$found) {
    $found = false;
    $jp = isset($field_info['jp_name']) ? trim((string)$field_info['jp_name']) : '';
    $en = isset($field_info['en_name']) ? trim((string)$field_info['en_name']) : '';

    foreach ([$jp, $en] as $k) {
        if ($k !== '' && array_key_exists($k, $record)) {
            $found = true;
            return $record[$k];
        }
    }

    $targets = [];
    foreach ([$jp, $en] as $x) {
        if ($x !== '') {
            $m = normalizeFieldLabelForSchemaMatch($x);
            if ($m !== '') {
                $targets[$m] = true;
            }
        }
    }
    if (isset($targets['no'])) {
        $targets['number'] = true;
    }
    foreach ($record as $csv_h => $val) {
        $m = normalizeFieldLabelForSchemaMatch($csv_h);
        if ($m !== '' && isset($targets[$m])) {
            $found = true;
            return $val;
        }
    }

    return null;
}

/**
 * Schema row maps to SQL column "no" (Airtable primary / record id).
 */
function isSchemaFieldPrimaryRecordNo(array $schema_info) {
    $jp = isset($schema_info['jp_name']) ? (string) $schema_info['jp_name'] : '';
    $en = isset($schema_info['en_name']) ? (string) $schema_info['en_name'] : '';

    return normalizeFieldLabelForSchemaMatch($jp) === 'no'
        || normalizeFieldLabelForSchemaMatch($en) === 'no';
}

/**
 * CSV headers Airtable uses for the same logical field as schema English "no".
 */
function primaryRecordNoCsvAliasLabels() {
    return ['number', 'record id', 'record_id', 'recordid', 'No.', 'No'];
}

/**
 * Airtable CSV often leaves the primary "No." / record-id formula column blank while
 * 🌟スタッフカード【CE】 (or similar) still contains ["651020"] or plain 651020. Copy into
 * the primary no column. CSV may call the primary column "number" while schema uses "no".
 */
function isStaffCardCeJsonHeader($header) {
    $h = (string) $header;
    if ($h === '') {
        return false;
    }
    if (preg_match('/staff[\s_]*card.*\bce\b/ui', $h) && !preg_match('/\b(gt|me)\b/ui', $h)) {
        return true;
    }
    if (function_exists('mb_strpos')) {
        if (mb_strpos($h, 'スタッフカード', 0, 'UTF-8') !== false && mb_strpos($h, '【CE】', 0, 'UTF-8') !== false) {
            return mb_strpos($h, '【GT】', 0, 'UTF-8') === false && mb_strpos($h, '【ME】', 0, 'UTF-8') === false;
        }
    } else {
        if (strpos($h, 'スタッフカード') !== false && strpos($h, '【CE】') !== false) {
            return strpos($h, '【GT】') === false && strpos($h, '【ME】') === false;
        }
    }
    return false;
}

/**
 * Parse CE staff-card cell: JSON array ["651020"] or plain digits — same rules as enrich.
 *
 * @return string|null
 */
function extractRecordIdFromStaffCardCeColumn(array $record) {
    $jsonKey = null;
    foreach (array_keys($record) as $h) {
        if (isStaffCardCeJsonHeader($h)) {
            $jsonKey = $h;
            break;
        }
    }
    if ($jsonKey === null || !array_key_exists($jsonKey, $record)) {
        return null;
    }

    $raw = trim((string) $record[$jsonKey]);
    if ($raw === '' || strtoupper($raw) === 'OK') {
        return null;
    }

    $dec = json_decode($raw, true);
    if (is_array($dec) && $dec !== []) {
        $first = $dec[0];
        if (!is_scalar($first)) {
            return null;
        }
        $s = trim((string) $first);
        return $s !== '' ? $s : null;
    }
    if (preg_match('/^\d{5,12}$/', $raw)) {
        return $raw;
    }

    return null;
}

function enrichRecordPrimaryNoFromStaffCardJson(array $record) {
    $fromStaff = extractRecordIdFromStaffCardCeColumn($record);

    $noKey = null;
    foreach (array_keys($record) as $h) {
        $m = normalizeFieldLabelForSchemaMatch($h);
        if ($m === 'no' || $m === 'number') {
            $noKey = $h;
            break;
        }
    }

    // Some CSV exports omit the primary "No."/"number" column entirely. Staff CE still has the id;
    // buildInsertSQL matches the literal key `no` to the schema English column `no`.
    if ($noKey === null) {
        if ($fromStaff !== null && $fromStaff !== '') {
            $record['no'] = $fromStaff;
        }
        return $record;
    }

    $cur = isset($record[$noKey]) ? trim((string) $record[$noKey]) : '';
    if ($cur !== '') {
        return $record;
    }

    if ($fromStaff !== null && $fromStaff !== '') {
        $record[$noKey] = $fromStaff;
    }
    return $record;
}

/**
 * Shared migration logic: accepts any iterable of associative records (array or generator).
 *
 * @param iterable $records
 */
function migrateDataFromIterable($conn, $schema, $records, $suffix) {
    global $_SESSION;
    
    $old_air_table = 'old_air_' . $suffix;
    $gb_inside_table = 'gb_inside_' . $suffix;
    
    $old_air_exists = tableExists($conn, $old_air_table);
    $gb_inside_exists = tableExists($conn, $gb_inside_table);
    
    if (($old_air_exists || $gb_inside_exists) && !isset($_SESSION['confirm_overwrite'])) {
        throw new Exception("Tables exist and overwrite not confirmed.");
    }
    
    if ($old_air_exists && isset($_SESSION['confirm_overwrite'])) {
        $conn->query("DROP TABLE IF EXISTS `$old_air_table`");
    }
    if ($gb_inside_exists && isset($_SESSION['confirm_overwrite'])) {
        $conn->query("DROP TABLE IF EXISTS `$gb_inside_table`");
    }
    
    // Separate base fields from excluded fields
    $base_fields = [];
    $excluded_fields = [];
    
    foreach ($schema as $jp_name => $field_info) {
        if (in_array($field_info['type'], ['formula', 'rollup', 'multipleLookupValues'])) {
            $excluded_fields[] = [
                'name' => $field_info['en_name'],
                'type' => $field_info['type']
            ];
        } else {
            $base_fields[$jp_name] = $field_info;
        }
    }
    
    $duplicate_en_columns = findDuplicateSchemaEnColumns($schema);
    $duplicate_en_message = formatDuplicateSchemaEnColumnsMessage($duplicate_en_columns);

    // Create tables
    $old_air_sql = createTableSQL($old_air_table, $schema);
    if (!$conn->query($old_air_sql)) {
        $detail = $duplicate_en_message !== '' ? "\n\n" . $duplicate_en_message : '';
        throw new Exception("Failed to create old_air table: " . $conn->error . $detail);
    }
    
    $gb_inside_sql = createTableSQL($gb_inside_table, $base_fields);
    if (!$conn->query($gb_inside_sql)) {
        $gb_dups = findDuplicateSchemaEnColumns($base_fields);
        $detail = formatDuplicateSchemaEnColumnsMessage($gb_dups);
        $detail = $detail !== '' ? "\n\n" . $detail : '';
        throw new Exception("Failed to create gb_inside table: " . $conn->error . $detail);
    }
    
    // Insert rows (one pass — required for generators / large CSV streaming)
    $old_air_rows = 0;
    $gb_inside_rows = 0;
    $old_air_insert_fail = 0;
    $old_air_last_err = '';
    $gb_inside_insert_fail = 0;
    $gb_inside_last_err = '';
    foreach ($records as $record) {
        $record = enrichRecordPrimaryNoFromStaffCardJson($record);

        $insert_sql = buildInsertSQL($old_air_table, $record, $schema);
        if ($insert_sql && $conn->query($insert_sql)) {
            $old_air_rows++;
        } else {
            $old_air_insert_fail++;
            if ($insert_sql) {
                $old_air_last_err = $conn->error;
            } else {
                $old_air_last_err = 'No CSV columns matched the schema for this row (buildInsertSQL empty).';
            }
        }

        $filtered_record = [];
        foreach ($base_fields as $jp_name => $field_info) {
            $matched = false;
            $v = recordValueForSchemaField($record, $field_info, $matched);
            if ($matched) {
                $filtered_record[$jp_name] = $v;
            }
        }

        if (!empty($filtered_record)) {
            $insert_sql_gb = buildInsertSQL($gb_inside_table, $filtered_record, $base_fields);
            if ($insert_sql_gb && $conn->query($insert_sql_gb)) {
                $gb_inside_rows++;
            } elseif ($insert_sql_gb) {
                $gb_inside_insert_fail++;
                $gb_inside_last_err = $conn->error;
            }
        }
    }
    
    return [
        'old_air' => [
            'field_count' => count($schema),
            'row_count' => $old_air_rows,
            'failed_inserts' => $old_air_insert_fail,
            'last_insert_error' => $old_air_last_err
        ],
        'gb_inside' => [
            'field_count' => count($base_fields),
            'row_count' => $gb_inside_rows,
            'failed_inserts' => $gb_inside_insert_fail,
            'last_insert_error' => $gb_inside_last_err
        ],
        'excluded_fields' => $excluded_fields,
        'duplicate_en_columns' => $duplicate_en_columns,
        'duplicate_en_message' => $duplicate_en_message,
    ];
}

function migrateData($conn, $schema, $airtable_data, $suffix) {
    return migrateDataFromIterable($conn, $schema, $airtable_data, $suffix);
}

function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

/**
 * Schema rows whose English SQL name (column E) collides after normalization.
 * Japanese names (column C) can differ while still mapping to the same SQL column.
 *
 * @return array<string, list<array{jp_name:string, en_name:string, type:string, sql_type:string}>>
 */
function findDuplicateSchemaEnColumns(array $schema) {
    $by_en = [];

    foreach ($schema as $jp_name => $field_info) {
        $en_name = trim((string) (isset($field_info['en_name']) ? $field_info['en_name'] : ''));
        if ($en_name === '') {
            continue;
        }

        $en_key = normalizeHeaderKey($en_name);
        if (!isset($by_en[$en_key])) {
            $by_en[$en_key] = [];
        }

        $by_en[$en_key][] = [
            'jp_name' => isset($field_info['jp_name']) ? (string) $field_info['jp_name'] : (string) $jp_name,
            'en_name' => $en_name,
            'type' => isset($field_info['type']) ? (string) $field_info['type'] : '',
            'sql_type' => isset($field_info['sql_type']) ? (string) $field_info['sql_type'] : '',
        ];
    }

    return array_filter($by_en, function ($rows) {
        return count($rows) > 1;
    });
}

/**
 * Human-readable summary when schema column E repeats for different column C rows.
 */
function formatDuplicateSchemaEnColumnsMessage(array $duplicates) {
    if (empty($duplicates)) {
        return '';
    }

    $lines = [
        'Schema Excel has multiple Japanese fields (column C) mapped to the same English SQL name (column E).',
        'MySQL cannot create two columns with the same name — the migrator keeps the first row and skips the rest.',
        'If the skipped row is the one your CSV uses, that field may be empty after migration.',
        '',
    ];

    foreach ($duplicates as $en_key => $rows) {
        $lines[] = "SQL column `{$rows[0]['en_name']}` (normalized: {$en_key}):";
        foreach ($rows as $i => $row) {
            $suffix = $i === 0 ? ' ← kept for CREATE TABLE' : ' ← skipped (duplicate EN name)';
            $lines[] = "  - JP: {$row['jp_name']} | type: {$row['type']} | SQL: {$row['sql_type']}{$suffix}";
        }
        $lines[] = '';
    }

    $lines[] = 'Tip: filter by schema table name (column B) if the Excel lists multiple Airtable tables.';

    return implode("\n", $lines);
}

function createTableSQL($table_name, $schema) {
    $columns = [];
    $columns[] = '`id` INT PRIMARY KEY AUTO_INCREMENT';
    
    $debug_info = "Creating table: $table_name\n";
    $debug_info .= "Schema rows: " . count($schema) . "\n\n";

    $used_en_keys = [];
    
    foreach ($schema as $jp_name => $field_info) {
        $en_name = trim($field_info['en_name']);
        $sql_type = trim($field_info['sql_type']);
        
        if (empty($en_name) || empty($sql_type)) {
            $debug_info .= "SKIP: Empty en_name or sql_type (JP: $jp_name)\n";
            continue;
        }

        $en_key = normalizeHeaderKey($en_name);
        if ($en_key === 'id') {
            $debug_info .= "SKIP RESERVED: `$en_name` (JP: $jp_name) — table already has auto-increment `id`\n";
            continue;
        }
        if (isset($used_en_keys[$en_key])) {
            $debug_info .= "SKIP DUPLICATE EN: `$en_name` (JP: $jp_name) — already defined from JP: {$used_en_keys[$en_key]}\n";
            continue;
        }
        $used_en_keys[$en_key] = $jp_name;
        
        $safe_name = '`' . str_replace('`', '``', $en_name) . '`';
        $col_def = "$safe_name $sql_type";
        $columns[] = $col_def;
        
        $debug_info .= "Col: $en_name (JP: $jp_name) = $sql_type\n";
    }

    $debug_info .= "\nSQL columns created: " . (count($columns) - 1) . "\n";
    
    $_SESSION['create_table_debug'] = $debug_info;
    
    $columns_sql = implode(", ", $columns);
    $sql = "CREATE TABLE IF NOT EXISTS `$table_name` ($columns_sql)";
    
    return $sql;
}

function buildInsertSQL($table_name, $record, $schema) {
    global $conn;

    // Build normalized lookup once so JP/EN header variants can match reliably.
    $schema_lookup = [];
    foreach ($schema as $schema_key => $schema_info) {
        $raw_jp = isset($schema_info['jp_name']) ? $schema_info['jp_name'] : $schema_key;
        $raw_en = isset($schema_info['en_name']) ? $schema_info['en_name'] : '';

        $keys_to_register = [];
        foreach ([$raw_jp, $raw_en] as $raw) {
            if ($raw === '' || $raw === null) {
                continue;
            }
            $keys_to_register[] = $raw;
            $keys_to_register[] = normalizeHeaderKey($raw);
            $keys_to_register[] = normalizeFieldLabelForSchemaMatch($raw);
        }
        foreach (array_unique(array_filter($keys_to_register)) as $k) {
            $schema_lookup[$k] = $schema_info;
        }
        if (isSchemaFieldPrimaryRecordNo($schema_info)) {
            foreach (primaryRecordNoCsvAliasLabels() as $alias) {
                $a = trim($alias);
                if ($a === '') {
                    continue;
                }
                $schema_lookup[$a] = $schema_info;
                $schema_lookup[normalizeHeaderKey($a)] = $schema_info;
                $schema_lookup[normalizeFieldLabelForSchemaMatch($a)] = $schema_info;
            }
        }
    }

    // One SQL column per en_name: if several CSV headers map to the same column, prefer a
    // non-empty value (avoids duplicate `no` in INSERT and "NULL wins" when MySQL rejects duplicates).
    $merged = [];

    foreach ($record as $csv_header => $csv_value) {
        $field_info = null;

        if (isset($schema_lookup[$csv_header])) {
            $field_info = $schema_lookup[$csv_header];
        } else {
            $normalized_header = normalizeHeaderKey($csv_header);
            if (isset($schema_lookup[$normalized_header])) {
                $field_info = $schema_lookup[$normalized_header];
            } elseif (isset($schema_lookup[normalizeFieldLabelForSchemaMatch($csv_header)])) {
                $field_info = $schema_lookup[normalizeFieldLabelForSchemaMatch($csv_header)];
            }
        }

        if (!$field_info) {
            continue;
        }

        $en_column_name = trim((string) $field_info['en_name']);
        if ($en_column_name === '') {
            continue;
        }

        $field_type = $field_info['type'];
        $sql_type = isset($field_info['sql_type']) ? $field_info['sql_type'] : '';
        $sql_value = convertValueForSQL($csv_value, $field_type, $sql_type);

        if (!isset($merged[$en_column_name])) {
            $merged[$en_column_name] = [
                'field_type' => $field_type,
                'sql_type' => $sql_type,
                'sql_value' => $sql_value,
            ];
        } else {
            $prev = $merged[$en_column_name]['sql_value'];
            $prev_empty = ($prev === null || $prev === '');
            $new_empty = ($sql_value === null || $sql_value === '');
            if ($prev_empty && !$new_empty) {
                $merged[$en_column_name]['sql_value'] = $sql_value;
                $merged[$en_column_name]['field_type'] = $field_type;
                $merged[$en_column_name]['sql_type'] = $sql_type;
            }
        }
    }

    // If `no` was not mapped from any CSV column (or only empty), try fuzzy match once.
    foreach ($schema as $schema_info) {
        if (!isSchemaFieldPrimaryRecordNo($schema_info)) {
            continue;
        }
        $en = trim((string) (isset($schema_info['en_name']) ? $schema_info['en_name'] : ''));
        if ($en === '') {
            continue;
        }
        $cur = isset($merged[$en]) ? $merged[$en]['sql_value'] : null;
        if ($cur !== null && $cur !== '') {
            break;
        }
        $found = false;
        $v = recordValueForSchemaField($record, $schema_info, $found);
        if (!$found) {
            continue;
        }
        $merged[$en] = [
            'field_type' => $schema_info['type'],
            'sql_type' => isset($schema_info['sql_type']) ? $schema_info['sql_type'] : '',
            'sql_value' => convertValueForSQL(
                $v,
                $schema_info['type'],
                isset($schema_info['sql_type']) ? $schema_info['sql_type'] : ''
            ),
        ];
        break;
    }

    if (empty($merged)) {
        return null;
    }

    $fields = [];
    $values = [];
    foreach ($merged as $en_column_name => $cell) {
        $safe_column = '`' . str_replace('`', '``', $en_column_name) . '`';
        $fields[] = $safe_column;
        $sql_value = $cell['sql_value'];
        if ($sql_value === null) {
            $values[] = 'NULL';
        } else {
            $escaped_value = $conn->real_escape_string((string) $sql_value);
            $values[] = "'" . $escaped_value . "'";
        }
    }

    $fields_sql = implode(', ', $fields);
    $values_sql = implode(', ', $values);

    return "INSERT INTO `$table_name` ($fields_sql) VALUES ($values_sql)";
}

/**
 * Whether the SQL column stores a calendar date or clock time (not generic VARCHAR).
 *
 * @return 'date'|'datetime'|null
 */
function sqlTypeTemporalKind($sql_type_upper) {
    $s = strtoupper((string)$sql_type_upper);
    if (preg_match('/\b(DATETIME|TIMESTAMP)\b/', $s)) {
        return 'datetime';
    }
    if (preg_match('/\bDATE\b/', $s)) {
        return 'date';
    }
    return null;
}

/**
 * Last-resort parse for Airtable/CSV date strings (ISO-8601, offsets, etc.).
 *
 * @return \DateTimeImmutable|null
 */
function dateTimeImmutableParseLenient($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    // Airtable error tokens are not dates
    if (preg_match('/^#\s*(ERROR|VALUE|REF|NUM)!/i', $value)) {
        return null;
    }
    try {
        $im = new \DateTimeImmutable($value);
        $y = (int)$im->format('Y');
        if ($y < 1 || $y > 9999) {
            return null;
        }
        return $im;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Convert CSV value to SQL-compatible format based on Airtable field type
 */
function convertValueForSQL($value, $field_type, $sql_type = '') {
    $sql_type_upper = strtoupper((string)$sql_type);
    $field_type = trim((string)$field_type);
    $value = is_string($value) ? trim($value) : $value;

    // Handle empty values
    if ($value === null || $value === '') {
        if (strpos($sql_type_upper, 'JSON') !== false) {
            return encodeJsonValue([]);
        }
        // For DATE/DATETIME/TIMESTAMP, return NULL instead of empty string
        if (sqlTypeTemporalKind($sql_type_upper) !== null) {
            return NULL;
        }
        return '';
    }

    if (isBooleanLikeField($field_type, $sql_type_upper)) {
        return normalizeBooleanValue($value);
    }

    $is_computed = in_array($field_type, ['formula', 'rollup', 'multipleLookupValues'], true);

    // Short all-digit computed values (e.g. Airtable record id 651020) must not go through DATE/DATETIME
    // parsing or MySQL may store NULL when the column is typed as DATE by mistake.
    if ($is_computed && is_scalar($value)) {
        $s = trim((string) $value);
        if ($s !== '' && preg_match('/^\d{5,7}$/', $s)) {
            return $s;
        }
    }

    // Numeric columns: Airtable formulas often export with thousands separators
    if (is_string($value) && preg_match('/^\d{1,3}(,\d{3})*(\.\d+)?$/', $value)) {
        if (preg_match('/\b(DECIMAL|NUMERIC|DOUBLE|FLOAT|REAL|INT|BIGINT)\b/', $sql_type_upper)) {
            return str_replace(',', '', $value);
        }
    }

    $temporal = sqlTypeTemporalKind($sql_type_upper);
    if ($temporal === 'date') {
        $parsed = normalizeDateValue($value);
        if ($parsed !== null) {
            return $parsed;
        }
        // Formulas like record id "651020" are often mis-typed as DATE in schema → keep CSV text
        if ($is_computed) {
            return is_scalar($value) ? (string) $value : '';
        }
        return null;
    }
    if ($temporal === 'datetime') {
        $parsed = normalizeDateTimeValue($value);
        if ($parsed !== null) {
            return $parsed;
        }
        if ($is_computed) {
            return is_scalar($value) ? (string) $value : '';
        }
        return null;
    }
    
    // multipleSelects: "item1,item2" → ["item1", "item2"]
    if ($field_type === 'multipleSelects' || $field_type === 'multiSelect') {
        if (strtoupper(trim($value)) === 'OK') {
            return encodeJsonValue([]);
        }
        
        if (strpos($value, ',') !== false) {
            $items = array_map('trim', explode(',', $value));
            $items = array_filter($items, function($item) {
                return !empty($item) && strtoupper($item) !== 'OK';
            });
            return encodeJsonValue(array_values($items));
        }
        
        return encodeJsonValue([$value]);
    }
    
    // multipleRecordLinks: similar to multipleSelects
    if ($field_type === 'multipleRecordLinks' || $field_type === 'multiRecordLinks') {
        if (strtoupper(trim($value)) === 'OK' || empty($value)) {
            return encodeJsonValue([]);
        }
        
        if (strpos($value, ',') !== false) {
            $items = array_map('trim', explode(',', $value));
            $items = array_filter($items);
            return encodeJsonValue(array_values($items));
        }
        
        return encodeJsonValue([$value]);
    }

    if ($field_type === 'multipleAttachments' || $field_type === 'attachments') {
        return convertAttachmentValue($value);
    }

    // Fallback: if SQL column is JSON, force valid JSON regardless of field_type label.
    if (strpos($sql_type_upper, 'JSON') !== false) {
        $trimmed = trim((string)$value);
        if ($trimmed === '' || strtoupper($trimmed) === 'OK') {
            return encodeJsonValue([]);
        }

        // If already valid JSON array/object/string/number, keep it.
        json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return encodeJsonValue(json_decode($trimmed, true));
        }

        // Common CSV content: comma-separated values -> JSON array.
        if (strpos($trimmed, ',') !== false) {
            $items = array_map('trim', explode(',', $trimmed));
            $items = array_values(array_filter($items, function($item) {
                return $item !== '' && strtoupper($item) !== 'OK';
            }));
            return encodeJsonValue($items);
        }

        // Single plain token -> JSON array with one entry.
        return encodeJsonValue([$trimmed]);
    }
    
    // All other types: keep as-is
    return $value;
}

function encodeJsonValue($value) {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function isBooleanLikeField($field_type, $sql_type_upper) {
    return strpos($sql_type_upper, 'BOOLEAN') !== false || $field_type === 'checkbox';
}

function normalizeBooleanValue($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '0';
    }

    $truthy = ['1', 'true', 'yes', 'y', 'on', 'checked', 'check', 'ok', '〇', '○', '◯', '✓', '✔'];
    $falsy = ['0', 'false', 'no', 'n', 'off', 'unchecked', '×', '✗', '✕', '-'];

    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    if (in_array($lower, $truthy, true)) {
        return '1';
    }
    if (in_array($lower, $falsy, true)) {
        return '0';
    }

    return '0';
}

function normalizeDateValue($value) {
    $value = trim((string)$value);
    
    // Empty or zero date → NULL
    if ($value === '' || $value === '0000-00-00') {
        return NULL;
    }

    $formats = ['Y-m-d', 'n/j/Y', 'n/j/y', 'm/d/Y', 'm/d/y', 'Y/m/d', 'd/m/Y', 'd-m-Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            $result = $dt->format('Y-m-d');
            // Check if result is a zero date
            if ($result === '0000-00-00') {
                return NULL;
            }
            return $result;
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false && $timestamp > 0) {
        $result = date('Y-m-d', $timestamp);
        if ($result === '0000-00-00') {
            return NULL;
        }
        return $result;
    }

    $lenient = dateTimeImmutableParseLenient($value);
    if ($lenient !== null) {
        return $lenient->format('Y-m-d');
    }

    // Parsing failed → NULL
    return NULL;
}

function normalizeDateTimeValue($value) {
    $value = trim((string)$value);
    
    // Empty or zero datetime → NULL
    if ($value === '' || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
        return NULL;
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'n/j/Y H:i:s',
        'n/j/Y H:i',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'Y/m/d H:i:s',
        'Y/m/d H:i',
        DateTime::ATOM,
    ];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            $result = $dt->format('Y-m-d H:i:s');
            // Check if result is a zero datetime
            if ($result === '0000-00-00 00:00:00') {
                return NULL;
            }
            return $result;
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false && $timestamp > 0) {
        $result = date('Y-m-d H:i:s', $timestamp);
        if ($result === '0000-00-00 00:00:00') {
            return NULL;
        }
        return $result;
    }

    $lenient = dateTimeImmutableParseLenient($value);
    if ($lenient !== null) {
        return $lenient->format('Y-m-d H:i:s');
    }

    // Parsing failed → NULL
    return NULL;
}

function convertAttachmentValue($value) {
    $value = trim((string)$value);
    if ($value === '' || strtoupper($value) === 'OK') {
        return encodeJsonValue([]);
    }

    if (preg_match_all('/([^,\n]+?)\s*\((https?:\/\/[^)]+)\)/u', $value, $matches, PREG_SET_ORDER)) {
        $items = [];
        foreach ($matches as $match) {
            $items[] = [
                'name' => trim($match[1]),
                'url' => trim($match[2]),
            ];
        }
        if (!empty($items)) {
            return encodeJsonValue($items);
        }
    }

    return encodeJsonValue([$value]);
}

/**
 * Normalize header/schema key for resilient matching.
 */
function normalizeHeaderKey($value) {
    if ($value === null) {
        return '';
    }

    $value = (string)$value;
    if (strpos($value, "\xEF\xBB\xBF") === 0) {
        $value = substr($value, 3);
    }
    if (class_exists('Normalizer')) {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
        if ($normalized !== false) {
            $value = $normalized;
        }
    }
    $value = str_replace("\xEF\xBB\xBF", '', $value); // UTF-8 BOM bytes (any position)
    $value = preg_replace('/^\x{FEFF}/u', '', $value); // Unicode BOM
    $value = str_replace("\xC2\xA0", ' ', $value); // Non-breaking space
    $value = str_replace("\xE3\x80\x80", ' ', $value); // Full-width space
    $value = preg_replace('/[\r\n\t]+/u', ' ', $value);
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = str_replace(['（', '）', '【', '】'], ['(', ')', '[', ']'], $value);

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

/**
 * Match Airtable CSV headers to schema labels when the only difference is a
 * trailing period (e.g. CSV primary field "No." vs schema "No").
 */
function normalizeFieldLabelForSchemaMatch($value) {
    $v = normalizeHeaderKey($value);
    if ($v === '') {
        return '';
    }
    return preg_replace('/[\.．。｡…]+$/u', '', $v);
}

/**
 * Convert CSV text to UTF-8 to avoid header mismatch across exports.
 */
function convertToUtf8($value) {
    if ($value === null) {
        $value = '';
    } else {
        $value = (string) $value;
    }

    if ($value !== '' && function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $encoding = mb_detect_encoding($value, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ISO-8859-1', 'ASCII'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') {
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
            if ($converted !== false) {
                $value = $converted;
            }
        }
    }

    if (strpos($value, "\xEF\xBB\xBF") === 0) {
        $value = substr($value, 3);
    }

    return preg_replace('/^\x{FEFF}/u', '', $value);
}

?>