"""
MySQL dump → multiple PostgreSQL SQL files for Neon (schema + data chunks).

Run order: *_01_schema.sql first, then *_02_data_*.sql in numeric order.

Usage:
  python convert_into_multiple.py old_air_kinmu_kanri.sql neon_parts
  python convert_into_multiple.py input.sql --max-files 10
  python convert_into_multiple.py input.sql --max-mb 45
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import defaultdict
from pathlib import Path

INPUT_SQL = "old_air_kinmu_kanri.sql"
OUTPUT_DIR = "neon_parts"
DEFAULT_MAX_FILES = 10
DEFAULT_MAX_ROWS = 150
MIN_CHUNK_MB = 5
MAX_CHUNK_MB = 120

BOOL_IN_CREATE = re.compile(
    r"(?:^|,)\s*(?:`([^`]+)`|\"([^\"]+)\")\s+"
    r"(?:tinyint\s*\(\s*1\s*\)(?:\s+unsigned)?|"
    r"bit\s*\(\s*1\s*\)|"
    r"bool(?:ean)?)(?=[,\s]|$)",
    re.IGNORECASE,
)

INSERT_HEAD = re.compile(
    r"INSERT\s+INTO\s+(?:`([^`]+)`|\"([^\"]+)\")\s*\(([^)]+)\)\s*VALUES\s*",
    re.IGNORECASE | re.DOTALL,
)

CREATE_TABLE = re.compile(
    r"CREATE\s+TABLE\s+(?:`([^`]+)`|\"([^\"]+)\")\s*\(",
    re.IGNORECASE,
)


def collect_boolean_columns_stream(path: Path) -> dict[str, set[str]]:
    out: dict[str, set[str]] = defaultdict(set)
    in_create: str | None = None

    with path.open("r", encoding="utf-8", errors="replace") as f:
        for line in f:
            if in_create is None:
                m = CREATE_TABLE.match(line)
                if m:
                    in_create = (m.group(1) or m.group(2)).lower()
                    line = line[m.end() :]
                else:
                    continue
            if re.search(r"\)\s*ENGINE\s*=", line, re.IGNORECASE) or re.search(
                r"\)\s*;\s*$", line.strip()
            ):
                in_create = None
                continue
            for bm in BOOL_IN_CREATE.finditer(line):
                col = bm.group(1) or bm.group(2)
                if col:
                    out[in_create].add(col.lower())
    return out


def find_statement_end(sql: str, start: int) -> int:
    i = start
    n = len(sql)
    in_string = False
    while i < n:
        c = sql[i]
        if in_string:
            if c == "\\" and i + 1 < n:
                i += 2
                continue
            if c == "'":
                if i + 1 < n and sql[i + 1] == "'":
                    i += 2
                    continue
                in_string = False
            i += 1
        else:
            if c == "'":
                in_string = True
            elif c == ";":
                return i + 1
            i += 1
    return -1


def insert_statement_complete(buf: str) -> bool:
    m = INSERT_HEAD.search(buf)
    if not m:
        return False
    end = find_statement_end(buf, m.end())
    return end > 0 and buf[end:].strip() == ""


def split_value_rows(values_blob: str) -> list[str]:
    rows: list[str] = []
    i = 0
    n = len(values_blob)
    while i < n:
        while i < n and values_blob[i] in " \t\n\r":
            i += 1
        if i >= n or values_blob[i] != "(":
            break
        depth = 0
        start = i
        in_string = False
        while i < n:
            c = values_blob[i]
            if in_string:
                if c == "\\" and i + 1 < n:
                    i += 2
                    continue
                if c == "'":
                    if i + 1 < n and values_blob[i + 1] == "'":
                        i += 2
                        continue
                    in_string = False
                i += 1
            else:
                if c == "'":
                    in_string = True
                    i += 1
                elif c == "(":
                    depth += 1
                    i += 1
                elif c == ")":
                    depth -= 1
                    i += 1
                    if depth == 0:
                        rows.append(values_blob[start:i])
                        break
                else:
                    i += 1
        while i < n and values_blob[i] in " \t\n\r,":
            i += 1
    return rows


def split_row_values(inner: str) -> list[str]:
    inner = inner.strip()
    if inner.startswith("(") and inner.endswith(")"):
        inner = inner[1:-1]
    parts: list[str] = []
    i = 0
    start = 0
    n = len(inner)
    in_string = False
    depth = 0
    while i < n:
        c = inner[i]
        if in_string:
            if c == "\\" and i + 1 < n:
                i += 2
                continue
            if c == "'":
                if i + 1 < n and inner[i + 1] == "'":
                    i += 2
                    continue
                in_string = False
            i += 1
        else:
            if c == "'":
                in_string = True
                i += 1
            elif c == "(":
                depth += 1
                i += 1
            elif c == ")":
                depth -= 1
                i += 1
            elif c == "," and depth == 0:
                parts.append(inner[start:i].strip())
                start = i + 1
                i += 1
            else:
                i += 1
    parts.append(inner[start:].strip())
    return parts


def bool_literal_for_value(token: str) -> str | None:
    t = token.strip()
    if t in ("0", "'0'"):
        return "false"
    if t in ("1", "'1'"):
        return "true"
    return None


def decode_mysql_string_inner(s: str) -> str:
    out: list[str] = []
    i = 0
    n = len(s)
    while i < n:
        if s[i] == "'" and i + 1 < n and s[i + 1] == "'":
            out.append("'")
            i += 2
            continue
        if s[i] == "\\" and i + 1 < n:
            nxt = s[i + 1]
            escapes = {
                "0": "\0",
                "'": "'",
                '"': '"',
                "\\": "\\",
                "n": "\n",
                "r": "\r",
                "t": "\t",
                "b": "\b",
                "Z": "\x1a",
            }
            out.append(escapes.get(nxt, nxt))
            i += 2
            continue
        out.append(s[i])
        i += 1
    return "".join(out)


def is_mysql_single_quoted_string_literal(token: str) -> bool:
    t = token.strip()
    if len(t) < 2 or t[0] != "'" or t[-1] != "'":
        return False
    if t.startswith(("b'", "B'")):
        return False
    return not (len(t) >= 2 and t[0].lower() == "x" and t[1] == "'")


def mysql_string_literal_to_pg(token: str) -> str:
    t = token.strip()
    decoded = decode_mysql_string_inner(t[1:-1])
    return "'" + decoded.replace("'", "''") + "'"


def rewrite_insert_statement(
    full_insert: str, columns_csv: str, bool_cols: set[str]
) -> str:
    tick_cols = re.findall(r"`([^`]+)`", columns_csv)
    if not tick_cols:
        tick_cols = re.findall(r'"([^"]+)"', columns_csv)
    cols = tick_cols if tick_cols else [
        c.strip().strip("`").strip('"') for c in columns_csv.split(",")
    ]
    bool_indices = {i for i, c in enumerate(cols) if c.lower() in bool_cols}

    m = INSERT_HEAD.search(full_insert)
    if not m:
        return full_insert
    head_end = m.end()
    end = find_statement_end(full_insert, head_end)
    values_blob = full_insert[head_end : end - 1]

    rows = split_value_rows(values_blob)
    new_chunks: list[str] = []
    for row in rows:
        vals = split_row_values(row)
        if len(vals) != len(cols):
            new_chunks.append(row)
            continue
        for i in range(len(vals)):
            if is_mysql_single_quoted_string_literal(vals[i]):
                vals[i] = mysql_string_literal_to_pg(vals[i])
        for idx in bool_indices:
            if idx < len(vals):
                rep = bool_literal_for_value(vals[idx])
                if rep is not None:
                    vals[idx] = rep
        new_chunks.append("(" + ", ".join(vals) + ")")

    return full_insert[:head_end] + ",\n".join(new_chunks) + full_insert[end - 1 : end]


def convert_insert(stmt: str, bool_map: dict[str, set[str]]) -> str:
    m = INSERT_HEAD.search(stmt)
    if not m:
        return apply_pg_transforms(stmt)
    table = (m.group(1) or m.group(2)).lower()
    cols_part = m.group(3)
    bcols = bool_map.get(table, set())
    if not bcols and len(bool_map) == 1:
        bcols = next(iter(bool_map.values()))
    stmt = rewrite_insert_statement(stmt, cols_part, bcols)
    return apply_pg_transforms(stmt)


def apply_pg_transforms(sql: str) -> str:
    sql = re.sub(
        r"\bALTER\s+TABLE\s+(?:`[^`]+`|\"[^\"]+\")\s+MODIFY\s+[^;]+;",
        "",
        sql,
        flags=re.IGNORECASE | re.DOTALL,
    )
    content = sql.replace("`", '"')
    content = re.sub(r" CHARACTER SET [^\s]+ COLLATE [^\s]+", "", content)
    content = re.sub(r" CHECK \(json_valid\([^)]+\)\)", "", content)
    content = re.sub(
        r"int\(11\) NOT NULL AUTO_INCREMENT", "SERIAL PRIMARY KEY", content
    )
    content = content.replace("AUTO_INCREMENT", "")
    content = re.sub(r"int\(11\)", "INTEGER", content)
    content = re.sub(r"bigint\(20\)", "BIGINT", content)
    content = re.sub(r"tinyint\(1\)", "BOOLEAN", content)
    content = content.replace("datetime", "TIMESTAMP")
    content = content.replace("longtext", "TEXT")
    content = re.sub(r' ENGINE=InnoDB DEFAULT CHARSET=[^\;]+', "", content)
    content = re.sub(
        r'\bALTER\s+TABLE\s+"[^"]+"\s+MODIFY\s+[^;]+;',
        "",
        content,
        flags=re.IGNORECASE | re.DOTALL,
    )
    return content


def convert_schema(sql: str) -> str:
    return apply_pg_transforms(sql)


def chunk_insert_statement(stmt: str, max_rows: int) -> list[str]:
    m = INSERT_HEAD.search(stmt)
    if not m:
        return [stmt]
    head_end = m.end()
    end = find_statement_end(stmt, head_end)
    values_blob = stmt[head_end : end - 1]
    rows = split_value_rows(values_blob)
    if len(rows) <= max_rows:
        return [stmt]
    head = stmt[:head_end]
    tail = stmt[end - 1 : end]
    chunks: list[str] = []
    for i in range(0, len(rows), max_rows):
        part_rows = rows[i : i + max_rows]
        chunks.append(head + ",\n".join(part_rows) + tail)
    return chunks


def is_already_postgresql(path: Path) -> bool:
    with path.open("r", encoding="utf-8", errors="replace") as f:
        for _ in range(300):
            line = f.readline()
            if not line:
                break
            if re.search(r'CREATE\s+TABLE\s+"', line, re.IGNORECASE):
                return True
            if re.search(r"INSERT\s+INTO\s+`", line, re.IGNORECASE):
                return False
    return False


def iter_dump(path: Path):
    """Yield ('schema', text) once, then ('insert', text) for each statement."""
    with path.open("r", encoding="utf-8", errors="replace") as f:
        preamble: list[str] = []
        insert_lines: list[str] | None = None
        schema_emitted = False

        while True:
            line = f.readline()
            if not line:
                break

            if insert_lines is not None:
                insert_lines.append(line)
                if ";" in line:
                    buf = "".join(insert_lines)
                    if insert_statement_complete(buf):
                        yield "insert", buf
                        insert_lines = None
                continue

            if re.match(r"\s*INSERT\s+INTO", line, re.IGNORECASE):
                if preamble and not schema_emitted:
                    yield "schema", "".join(preamble)
                    schema_emitted = True
                    preamble = []
                insert_lines = [line]
                if insert_statement_complete(line):
                    yield "insert", line
                    insert_lines = None
            elif not schema_emitted:
                preamble.append(line)

        if insert_lines:
            yield "insert", "".join(insert_lines)
        if preamble and not schema_emitted:
            yield "schema", "".join(preamble)


class MultiFileWriter:
    def __init__(self, out_dir: Path, stem: str, max_bytes: int, max_rows: int):
        self.out_dir = out_dir
        self.stem = stem
        self.max_bytes = max_bytes
        self.max_rows = max_rows
        self.data_part = 0
        self.buf: list[str] = []
        self.buf_size = 0
        self.files: list[str] = []
        self.schema_written = False

    def write_schema(self, sql: str) -> None:
        if self.schema_written:
            return
        p = self.out_dir / f"{self.stem}_01_schema.sql"
        p.write_text(
            "-- Neon: run this file FIRST (CREATE TABLE, etc.)\n\n" + sql,
            encoding="utf-8",
        )
        self.files.append(p.name)
        self.data_part = 2
        self.schema_written = True

    def _flush_data(self) -> None:
        if not self.buf or self.data_part < 2:
            return
        n = self.data_part - 1
        p = self.out_dir / f"{self.stem}_{self.data_part:02d}_data_{n:03d}.sql"
        body = "\n\n".join(self.buf)
        p.write_text(
            f"-- Neon: data chunk {n} - run after schema, before later data files\n\n"
            + body
            + "\n",
            encoding="utf-8",
        )
        self.files.append(p.name)
        self.data_part += 1
        self.buf = []
        self.buf_size = 0

    def add_insert(self, stmt: str, *, already_pg: bool = False) -> None:
        if already_pg:
            pieces = (
                chunk_insert_statement(stmt, self.max_rows)
                if len(stmt.encode("utf-8")) > self.max_bytes
                else [stmt]
            )
        else:
            pieces = chunk_insert_statement(stmt, self.max_rows)
        for piece in pieces:
            size = len(piece.encode("utf-8"))
            if size > self.max_bytes:
                raise ValueError(
                    f"Single INSERT chunk still {size / 1e6:.1f} MB after "
                    f"--max-rows {self.max_rows}; lower --max-rows"
                )
            if self.buf and self.buf_size + size > self.max_bytes:
                self._flush_data()
            self.buf.append(piece.rstrip())
            self.buf_size += size + 2

    def finish(self) -> None:
        self._flush_data()
        manifest = self.out_dir / f"{self.stem}_RUN_ORDER.txt"
        lines = [
            "Run these files in Neon SQL Editor in this order:",
            "",
        ]
        for i, name in enumerate(self.files, 1):
            lines.append(f"  {i}. {name}")
        lines.extend(["", f"Total data files: {max(0, len(self.files) - 1)}"])
        manifest.write_text("\n".join(lines) + "\n", encoding="utf-8")


def max_mb_for_file_count(in_path: Path, max_files: int) -> float:
    """Pick chunk size so schema + data parts stay within max_files."""
    data_parts = max(1, max_files - 1)
    size_mb = in_path.stat().st_size / (1024 * 1024)
    chunk = size_mb / data_parts
    return max(MIN_CHUNK_MB, min(MAX_CHUNK_MB, chunk))


def convert_to_parts(
    in_path: Path,
    out_dir: Path,
    max_mb: float,
    max_rows: int,
    max_files: int | None = None,
) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    stem = in_path.stem
    max_bytes = int(max_mb * 1024 * 1024)

    already_pg = is_already_postgresql(in_path)
    bool_map: dict[str, set[str]] = {}
    if not already_pg:
        print(f"Scanning boolean columns in {in_path.name}...")
        bool_map = collect_boolean_columns_stream(in_path)
    else:
        print(f"{in_path.name} looks like PostgreSQL already - skipping boolean rewrite.")

    writer = MultiFileWriter(out_dir, stem, max_bytes, max_rows)
    insert_count = 0
    schema_done = False

    target = f"<= {max_files} files" if max_files else ""
    print(
        f"Converting and splitting ({max_mb:.0f} MB per data file{', ' + target if target else ''}, "
        f"max {max_rows} rows per INSERT)..."
    )
    for kind, block in iter_dump(in_path):
        if kind == "schema":
            writer.write_schema(
                apply_pg_transforms(block) if not already_pg else block
            )
            schema_done = True
            print(f"  schema: {writer.files[-1]}")
        else:
            pg_stmt = (
                block if already_pg else convert_insert(block, bool_map)
            )
            writer.add_insert(pg_stmt, already_pg=already_pg)
            insert_count += 1
            if insert_count % 500 == 0:
                print(f"  ... {insert_count} INSERT statements processed")

    if not schema_done:
        raise SystemExit("No CREATE/ schema section found before INSERTs.")

    writer.finish()
    print(f"\nDone: {insert_count} INSERT statements → {len(writer.files)} SQL files")
    print(f"Output folder: {out_dir.resolve()}")
    print(f"Run order: {stem}_RUN_ORDER.txt")


def main() -> None:
    ap = argparse.ArgumentParser(
        description="Convert MySQL dump to multiple PostgreSQL SQL files for Neon."
    )
    ap.add_argument("input", nargs="?", default=INPUT_SQL, help="Input .sql dump")
    ap.add_argument(
        "output_dir",
        nargs="?",
        default=OUTPUT_DIR,
        help="Output directory for split files",
    )
    ap.add_argument(
        "--max-files",
        type=int,
        default=DEFAULT_MAX_FILES,
        help=f"Target total SQL files including schema (default {DEFAULT_MAX_FILES})",
    )
    ap.add_argument(
        "--max-mb",
        type=float,
        default=None,
        help="Max MB per data file (overrides --max-files if set)",
    )
    ap.add_argument(
        "--max-rows",
        type=int,
        default=DEFAULT_MAX_ROWS,
        help=f"Max rows per INSERT when splitting huge statements (default {DEFAULT_MAX_ROWS})",
    )
    args = ap.parse_args()

    in_path = Path(args.input)
    if not in_path.is_file():
        sys.exit(f"Input not found: {in_path}")

    if args.max_mb is not None:
        max_mb = args.max_mb
    else:
        max_mb = max_mb_for_file_count(in_path, args.max_files)
        print(
            f"Input ~{in_path.stat().st_size / 1e6:.0f} MB -> "
            f"~{max_mb:.0f} MB per data file (target {args.max_files} files total)"
        )

    convert_to_parts(
        in_path,
        Path(args.output_dir),
        max_mb,
        args.max_rows,
        max_files=args.max_files,
    )


if __name__ == "__main__":
    main()
