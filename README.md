# Airtable → MySQL Migration Tool

Web-based utility for migrating Airtable exports into MySQL (XAMPP). It reads an Airtable CSV plus a schema spreadsheet, creates two related tables per run, and streams large CSVs without loading the full file into memory.

Optional Python scripts convert MySQL dumps to PostgreSQL-compatible SQL for [Neon](https://neon.tech) when tables exceed upload or editor size limits.

## What it does

For each migration (identified by a **table suffix**, e.g. `kinmu_kanri`):

| Table | Purpose |
|-------|---------|
| `old_air_{suffix}` | Full snapshot: all fields from the schema, including formula / rollup / lookup values as stored in the export |
| `gb_inside_{suffix}` | Application-facing subset: excludes computed Airtable field types (formula, rollup, lookup, etc.) |

The schema Excel (`.xlsx`) maps Japanese CSV column headers to English SQL column names and MySQL types. See `functions.php` for column layout (Base ID, table name, JP/EN names, Airtable type, SQL type).

## Requirements

- [XAMPP](https://www.apachefriends.org/) (or equivalent): Apache + MySQL + PHP
- PHP extensions: `mysqli`, `zip` (for `.xlsx` schema files; Windows may fall back to COM)
- Python 3 (optional): for `convert.py` / `convert_into_multiple.py` when targeting Neon

## Quick start

### 1. Clone and configure

```text
htdocs/migrate/
├── migrate.php      # Web UI
├── config.php       # MySQL connection (edit before use)
├── functions.php    # Schema parsing and migration logic
├── migrate_debug.php
└── inbox/           # Local only — not in git (place large files here)
```

Edit `config.php` with your MySQL host, user, password, and database name (default in repo: `localhost`, `root`, empty password, `staff_db`).

### 2. Start services

1. Start **Apache** and **MySQL** in XAMPP.
2. Open: `http://localhost/migrate/migrate.php`

### 3. Prepare inputs

- **Airtable CSV** — export with Japanese field names (headers must match schema column C).
- **Schema `.xlsx`** — field definitions (English names in column E, MySQL types in column G).
- **Table suffix** — alphanumeric + underscore only (e.g. `eg1`, `kinmu_kanri`). Becomes `old_air_{suffix}` and `gb_inside_{suffix}`.

Optional: **Schema table filter** — if the workbook lists multiple Airtable tables, enter the exact table name (column B) to migrate one table only.

### 4. Run migration

1. Upload the CSV and `.xlsx`, or use **Use inbox files** (recommended for large exports).
2. Confirm overwrite if tables already exist.
3. Review row counts and any failed INSERT messages on the results page.

For uploads over PHP limits, copy files into `migrate/inbox/`, enable inbox mode, and ensure limits match `.htaccess` / `.user.ini` (512M upload/post, extended execution time). Restart Apache after changing `php.ini`.

## Large files and Neon (PostgreSQL)

MySQL dumps for very large tables (150MB+) are awkward in the Neon SQL editor. Use the Python helpers after exporting from MySQL:

| Script | Role |
|--------|------|
| `convert.py` | Single-file MySQL dump → PostgreSQL-ish SQL (boolean `0/1` → `true/false`, escape fixes) |
| `convert_into_multiple.py` | Same conversion, split into schema + numbered data chunks for sequential runs in Neon |

Example:

```powershell
python convert.py old_air_kinmu_kanri.sql old_air_kinmu_kanri_postgresql.sql
python convert_into_multiple.py old_air_kinmu_kanri.sql neon_parts --max-mb 45
```

Run `*_01_schema.sql` first, then `*_02_data_*.sql` in numeric order (see any `*_RUN_ORDER.txt` generated alongside chunks).

Local folders `kanri-postgre-sql/` and `neon_test_parts/` hold SQL artifacts and are **not** tracked in git.

## Debugging and utilities

| File | Use |
|------|-----|
| `migrate_debug.php` | Verbose run; shows parsed schema samples |
| `_check_dupes.php` | Duplicate checks on migrated data |
| `_zipcheck.php` | Zip / XLSX read diagnostics |

Older implementations are kept under `v1.0` … `v8.0` for reference; those directories are gitignored. The active entrypoint is the repo root `migrate.php` + `functions.php`.

## Repository layout (tracked)

```text
migrate/
├── README.md
├── migrate.php, functions.php, config.php
├── migrate_debug.php
├── convert.py, convert_into_multiple.py
├── .htaccess, .user.ini          # PHP limits for this directory
├── at-sql-migrate.txt            # Internal migration notes / changelog
└── PHP_SETUP_GUIDE.md, TROUBLESHOOTING.md   # Local docs (*.md ignored except README)
```

## Git ignore policy

Not committed (see `.gitignore`):

- `inbox/` — CSV / XLSX uploads
- `kanri-postgre-sql/`, `neon_test_parts/` — generated SQL
- `v*/` — version snapshots
- `*.md` except **README.md**
- `__pycache__/`

**Security:** Review `config.php` before pushing; do not commit production passwords or customer data exports.

## Troubleshooting

- **POST body too large** — Use inbox mode; raise `upload_max_filesize` / `post_max_size`; restart Apache.
- **SQL syntax near a column name** — Often leading/trailing spaces in schema; use `migrate_debug.php` and ensure schema column E names are clean.
- **Linked / lookup fields NULL** — Confirm Airtable field types in the schema match export format (`multipleLookupValues`, etc.).
- **Dates as `0000-00-00`** — Empty dates in MySQL; optional cosmetic fix only.
- **Neon syntax errors on booleans or quotes** — Re-run through `convert.py` or `convert_into_multiple.py`.

More detail: `PHP_SETUP_GUIDE.md` and `TROUBLESHOOTING.md` in this folder (local copies; not pushed by default).

## License

Internal tooling — use and modify as needed for your migration workflow.
