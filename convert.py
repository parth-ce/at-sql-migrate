"""
MySQL dump → PostgreSQL-ish SQL for Neon.

Fixes tinyint(1) / bit(1) columns: INSERT values must be true/false, not 0/1
(PostgreSQL rejects integer for boolean). Only those columns are rewritten so
integer 0/1 elsewhere stay intact.

MySQL dumps escape apostrophes inside single-quoted values as \\' (e.g. JSON
filenames). PostgreSQL uses doubled quotes (''). Unconverted \\' breaks Neon
with: syntax error at or near "\\".
"""
import re
import sys
from collections import defaultdict

# Default paths (override with: python convert.py input.sql output.sql)
INPUT_SQL = "old_air_test.sql"
OUTPUT_SQL = "old_air_test_postgresql.sql"

# Column definitions in CREATE TABLE (comma-separated; avoids CHECK(json_valid(`col`)))
BOOL_IN_CREATE = re.compile(
    r"(?:^|,)\s*`([^`]+)`\s+"
    r"(tinyint\s*\(\s*1\s*\)(?:\s+unsigned)?|"
    r"bit\s*\(\s*1\s*\)|"
    r"bool(?:ean)?)(?=[,\s]|$)",
    re.IGNORECASE,
)

INSERT_HEAD = re.compile(
    r"INSERT\s+INTO\s+`([^`]+)`\s*\(([^)]+)\)\s*VALUES\s*",
    re.IGNORECASE | re.DOTALL,
)


def collect_boolean_columns(sql: str) -> dict[str, set[str]]:
    """table_name_lower -> set of column_name_lower that are MySQL boolean-ish."""
    out: dict[str, set[str]] = defaultdict(set)
    in_create: str | None = None

    for line in sql.splitlines():
        if in_create is None:
            m = re.match(r"CREATE\s+TABLE\s+`([^`]+)`\s*\(", line, re.IGNORECASE)
            if m:
                in_create = m.group(1).lower()
                line = line[m.end() :]
            else:
                continue
        # in_create set: scan this line for bool columns, then maybe close table
        if re.search(r"\)\s*ENGINE\s*=", line, re.IGNORECASE) or re.search(
            r"\)\s*;\s*$", line.strip()
        ):
            in_create = None
            continue

        for bm in BOOL_IN_CREATE.finditer(line):
            out[in_create].add(bm.group(1).lower())

    return out


def find_statement_end(sql: str, start: int) -> int:
    """Index just past the ';' that ends this INSERT (handles strings)."""
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
    return n


def split_value_rows(values_blob: str) -> list[str]:
    """Split VALUES blob into one string per row, each '(...)' including parens."""
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
    """Split one row's inside '( ... )' on top-level commas (respects strings, nesting)."""
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
    """If token is MySQL-style 0/1 boolean literal, return false/true; else None."""
    t = token.strip()
    if t in ("0", "'0'"):
        return "false"
    if t in ("1", "'1'"):
        return "true"
    return None


def decode_mysql_string_inner(s: str) -> str:
    """Undo MySQL C-style escapes inside a single-quoted SQL string body (no outer quotes)."""
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
            if nxt == "0":
                out.append("\0")
            elif nxt == "'":
                out.append("'")
            elif nxt == '"':
                out.append('"')
            elif nxt == "\\":
                out.append("\\")
            elif nxt == "n":
                out.append("\n")
            elif nxt == "r":
                out.append("\r")
            elif nxt == "t":
                out.append("\t")
            elif nxt == "b":
                out.append("\b")
            elif nxt == "Z":
                out.append("\x1a")
            elif nxt == "%" or nxt == "_":
                out.append(nxt)
            else:
                out.append(nxt)
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
    if len(t) >= 2 and t[0].lower() == "x" and t[1] == "'":
        return False
    return True


def mysql_string_literal_to_pg(token: str) -> str:
    """Convert a MySQL-style '...' literal to PostgreSQL-safe '...' (escapes quotes as '')."""
    t = token.strip()
    inner = t[1:-1]
    decoded = decode_mysql_string_inner(inner)
    return "'" + decoded.replace("'", "''") + "'"


def rewrite_insert_statement(
    full_insert: str, columns_csv: str, bool_cols: set[str]
) -> str:
    # Do not split on commas inside `...` column names (e.g. over_100,000_yen_...).
    tick_cols = re.findall(r"`([^`]+)`", columns_csv)
    if tick_cols:
        cols = [c.strip() for c in tick_cols]
    else:
        cols = [c.strip().strip("`").strip('"') for c in columns_csv.split(",")]
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
            if idx >= len(vals):
                continue
            rep = bool_literal_for_value(vals[idx])
            if rep is not None:
                vals[idx] = rep
        new_chunks.append("(" + ", ".join(vals) + ")")

    return full_insert[:head_end] + ",\n".join(new_chunks) + full_insert[end - 1 : end]


def fix_insert_booleans(sql: str, bool_map: dict[str, set[str]]) -> str:
    out: list[str] = []
    pos = 0
    for m in INSERT_HEAD.finditer(sql):
        table = m.group(1).lower()
        cols_part = m.group(2)
        stmt_start = m.start()
        end = find_statement_end(sql, m.end())
        full = sql[stmt_start:end]
        bcols = bool_map.get(table, set())
        # Single-table dumps often rename only INSERT or only CREATE; bool_map then misses.
        if not bcols and len(bool_map) == 1:
            bcols = next(iter(bool_map.values()))
        full = rewrite_insert_statement(full, cols_part, bcols)
        out.append(sql[pos:stmt_start])
        out.append(full)
        pos = end
    out.append(sql[pos:])
    return "".join(out)


def mysql_to_postgres(sql: str) -> str:
    # MySQL-only: phpMyAdmin appends ALTER ... MODIFY ... AUTO_INCREMENT=... — not valid in PostgreSQL.
    sql = re.sub(
        r"\bALTER\s+TABLE\s+(?:`[^`]+`|\"[^\"]+\")\s+MODIFY\s+[^;]+;",
        "",
        sql,
        flags=re.IGNORECASE | re.DOTALL,
    )

    bool_map = collect_boolean_columns(sql)
    sql = fix_insert_booleans(sql, bool_map)

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

    # Catch MODIFY left over if dump used different quoting or script order changed.
    content = re.sub(
        r'\bALTER\s+TABLE\s+"[^"]+"\s+MODIFY\s+[^;]+;',
        "",
        content,
        flags=re.IGNORECASE | re.DOTALL,
    )

    return content


def main() -> None:
    in_path = sys.argv[1] if len(sys.argv) > 1 else INPUT_SQL
    out_path = sys.argv[2] if len(sys.argv) > 2 else OUTPUT_SQL

    with open(in_path, "r", encoding="utf-8") as f:
        raw = f.read()

    content = mysql_to_postgres(raw)

    with open(out_path, "w", encoding="utf-8") as f:
        f.write(content)

    print(f"Conversion complete: {out_path}")


if __name__ == "__main__":
    main()
