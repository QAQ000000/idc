#!/usr/bin/env python3
"""Check or refresh DATABASE.md CREATE TABLE excerpts without connecting to MySQL."""

import argparse
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
import re
import sys
from typing import Optional


ROOT = Path(__file__).resolve().parents[1]
SCHEMA = ROOT / "database/schema.sql"
DICTIONARY = ROOT / "docs/DATABASE.md"
EXPECTED_TABLES = 27
CREATE_TABLE = re.compile(
    r"^[ \t]*CREATE[ \t]+TABLE[ \t]+(?:IF[ \t]+NOT[ \t]+EXISTS[ \t]+)?`(?P<name>[^`]+)`[ \t]*\(",
    re.MULTILINE | re.IGNORECASE,
)
SQL_FENCE = re.compile(r"^```sql[^\S\r\n]*\r?\n(?P<body>.*?)^```[^\S\r\n]*(?:\r?\n|$)", re.MULTILINE | re.DOTALL)
TABLE_OPTION = re.compile(
    r"(?P<name>ENGINE|(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET|COLLATE)|COMMENT)\b\s*=?\s*",
    re.IGNORECASE,
)
OPTION_IDENTIFIER = re.compile(r"[A-Za-z_][A-Za-z0-9_]*")


@dataclass(frozen=True)
class Table:
    name: str
    start: int
    end: int
    sql: str


def quoted_end(source: str, start: int) -> int:
    quote = source[start]
    index = start + 1
    while index < len(source):
        if source[index] == "\\":
            index += 2
            continue
        if source[index] == quote:
            if index + 1 < len(source) and source[index + 1] == quote:
                index += 2
                continue
            return index + 1
        index += 1
    raise ValueError("Unterminated SQL quoted value")


def comment_end(source: str, start: int) -> Optional[int]:
    if source.startswith("/*", start):
        closing = source.find("*/", start + 2)
        if closing < 0:
            raise ValueError("Unterminated SQL block comment")
        return closing + 2
    if source[start] == "#" or (
        source.startswith("--", start)
        and (start + 2 == len(source) or source[start + 2].isspace())
    ):
        closing = source.find("\n", start)
        return len(source) if closing < 0 else closing + 1
    return None


def skip_trivia(source: str, start: int) -> int:
    index = start
    while index < len(source):
        if source[index].isspace():
            index += 1
            continue
        closing = comment_end(source, index)
        if closing is None:
            break
        index = closing
    return index


def statement_end(source: str, start: int) -> int:
    """Accept the repository's CREATE TABLE subset; never consume a following SQL statement."""
    match = CREATE_TABLE.match(source, start)
    if match is None:
        raise ValueError("Expected CREATE TABLE")
    index = match.end()
    depth = 1
    while index < len(source) and depth:
        char = source[index]
        if char in "'\"`":
            index = quoted_end(source, index)
            continue
        closing = comment_end(source, index)
        if closing is not None:
            index = closing
            continue
        if char == "(":
            depth += 1
        elif char == ")":
            depth -= 1
        elif char == ";":
            raise ValueError(f"{match['name']}: CREATE TABLE closes before its column list is balanced")
        index += 1
    if depth:
        raise ValueError(f"{match['name']}: CREATE TABLE column list is not closed")

    # Support only options used by schema.sql. Unknown syntax fails closed before any write.
    # In particular, a missing ';' cannot make SELECT/ALTER/CREATE part of this excerpt.
    seen_options = set()
    while index < len(source):
        index = skip_trivia(source, index)
        if index == len(source):
            break
        if source[index] == ";":
            return index + 1
        option = TABLE_OPTION.match(source, index)
        if option is None:
            raise ValueError(f"{match['name']}: unsupported CREATE TABLE tail or missing terminating semicolon")
        name = re.sub(r"\s+", " ", option['name'].upper()).removeprefix("DEFAULT ")
        name = "CHARSET" if re.fullmatch(r"CHARACTER\s+SET", name) else name
        if name in seen_options:
            raise ValueError(f"{match['name']}: duplicate table option {name}")
        seen_options.add(name)
        index = option.end()
        if name == "COMMENT":
            if index == len(source) or source[index] not in "'\"":
                raise ValueError(f"{match['name']}: table COMMENT requires a quoted value")
            index = quoted_end(source, index)
        else:
            value = OPTION_IDENTIFIER.match(source, index)
            if value is None:
                raise ValueError(f"{match['name']}: invalid value for table option {name}")
            index = value.end()
        if index < len(source) and not source[index].isspace() and source[index] != ";" and comment_end(source, index) is None:
            raise ValueError(f"{match['name']}: table options must be separated")
    raise ValueError(f"{match['name']}: CREATE TABLE statement has no terminating semicolon")


def tables_in(source: str, offset: int = 0) -> list[Table]:
    tables = []
    index = 0
    while index < len(source):
        closing = comment_end(source, index)
        if closing is not None:
            index = closing
            continue
        if source[index] in "'\"`":
            index = quoted_end(source, index)
            continue
        match = CREATE_TABLE.match(source, index)
        if match is not None:
            end = statement_end(source, index)
            tables.append(Table(match["name"], offset + index, offset + end, source[index:end]))
            index = end
        else:
            index += 1
    return tables


def unique_tables(tables: list[Table], label: str) -> dict[str, Table]:
    duplicates = sorted(name for name, count in Counter(table.name for table in tables).items() if count > 1)
    if duplicates:
        raise ValueError(f"{label}: duplicate tables: {', '.join(duplicates)}")
    if len(tables) != EXPECTED_TABLES:
        raise ValueError(f"{label}: expected {EXPECTED_TABLES} tables, found {len(tables)}")
    return {table.name: table for table in tables}


def synchronize(write: bool) -> int:
    schema_text = SCHEMA.read_bytes().decode("utf-8")
    document = DICTIONARY.read_bytes().decode("utf-8")
    schema = unique_tables(tables_in(schema_text), "schema.sql")
    document_tables = []
    for fence in SQL_FENCE.finditer(document):
        document_tables.extend(tables_in(fence["body"], fence.start("body")))
    dictionary = unique_tables(document_tables, "DATABASE.md")
    missing = sorted(schema.keys() - dictionary.keys())
    extra = sorted(dictionary.keys() - schema.keys())
    if missing or extra:
        raise ValueError(f"Table names differ: missing={missing}, extra={extra}")
    changed = [name for name in schema if schema[name].sql != dictionary[name].sql]
    if not changed:
        print(f"PASS: {EXPECTED_TABLES} CREATE TABLE excerpts match schema.sql, including SQL comments.")
        return 0
    if not write:
        print("FAIL: DATABASE.md differs from schema.sql: " + ", ".join(changed), file=sys.stderr)
        print("Run python3 scripts/sync_schema_docs.py --write to refresh generated excerpts.", file=sys.stderr)
        return 1
    for table in sorted(document_tables, key=lambda item: item.start, reverse=True):
        document = document[:table.start] + schema[table.name].sql + document[table.end:]
    DICTIONARY.write_bytes(document.encode("utf-8"))
    print(f"Updated {len(changed)} CREATE TABLE excerpts; all text outside those statements was preserved.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--check", action="store_true", help="check only (the default); never modify files")
    mode.add_argument("--write", action="store_true", help="refresh the 27 generated CREATE TABLE excerpts")
    args = parser.parse_args()
    try:
        return synchronize(args.write)
    except (OSError, UnicodeError, ValueError) as error:
        print(f"FAIL: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
