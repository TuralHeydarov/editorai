#!/usr/bin/env python3
"""Prepare a private, atomic import for an EMPTY migrated EditorAI PG schema.

This never connects to PostgreSQL or alters the SQLite snapshot. Output contains
private data and must never be committed, printed, or imported into a live schema
before the reviewed migration gate. The default target is editorai.
"""
import argparse
import decimal
import json
import os
from pathlib import Path
import re
import sqlite3

TABLES = (
    'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
    'jobs', 'job_batches', 'failed_jobs', 'projects', 'clips',
    'personal_access_tokens',
)
JSON_COLUMNS = {
    'projects': {'settings', 'conversation_history'},
    'clips': {'broll_items', 'broll_keywords', 'sound_effects', 'background_music', 'render_payload'},
}
DECIMAL_COLUMNS = {'trim_start', 'trim_end', 'duration'}


def literal(value):
    if value is None:
        return 'NULL'
    if isinstance(value, bytes):
        raise ValueError('Unexpected binary value; import refused')
    value = str(value)
    if '\x00' in value:
        raise ValueError('NUL cannot be represented in PostgreSQL text')
    return "'" + value.replace("'", "''") + "'"


def prepare(snapshot, schema):
    if not re.fullmatch(r'editorai(?:_[a-z0-9_]+)?', schema):
        raise ValueError('Only an isolated editorai schema is allowed')
    uri = Path(snapshot).resolve().as_uri() + '?mode=ro'
    db = sqlite3.connect(uri, uri=True)
    try:
        if db.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
            raise ValueError('SQLite integrity failure')
        if db.execute('PRAGMA foreign_key_check').fetchall():
            raise ValueError('SQLite foreign-key failure')
        actual = {r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if actual != set(TABLES) | {'migrations', 'sqlite_sequence'}:
            raise ValueError('Unknown/missing source tables; import refused')
        migration_names = sorted(r[0] for r in db.execute('SELECT migration FROM migrations'))
        quoted_migrations = ','.join(literal(m) for m in migration_names)
        statements = ['BEGIN;', 'SET LOCAL standard_conforming_strings = on;',
                      f'SET LOCAL search_path = "{schema}";']
        statements.append(
            'DO $$ BEGIN IF (SELECT count(*) FROM migrations) <> '
            + str(len(migration_names))
            + f' OR EXISTS (SELECT 1 FROM migrations WHERE migration NOT IN ({quoted_migrations})) '
            + "THEN RAISE EXCEPTION 'Migration sets differ'; END IF; END $$;"
        )
        for table in TABLES:
            statements.append(
                f'DO $$ BEGIN IF EXISTS (SELECT 1 FROM "{table}" LIMIT 1) '
                + f"THEN RAISE EXCEPTION 'Target {table} is not empty'; END IF; END $$;"
            )
        report = {'source_tables': {}, 'schema': schema, 'orphan_projects_preserved':
                  db.execute('SELECT count(*) FROM projects WHERE user_id IS NULL').fetchone()[0],
                  'source_is_read_only': True, 'import_executed': False}
        sequences = dict(db.execute('SELECT name,seq FROM sqlite_sequence'))
        for table in TABLES:
            columns = [r[1] for r in db.execute(f'PRAGMA table_info("{table}")')]
            if any(not re.fullmatch('[a-z_][a-z0-9_]*', c) for c in columns):
                raise ValueError('Unexpected column name')
            names = ','.join('"'+c+'"' for c in columns)
            rows = db.execute(f'SELECT * FROM "{table}"').fetchall()
            report['source_tables'][table] = len(rows)
            for row in rows:
                for column, value in zip(columns, row):
                    if value is not None and column in JSON_COLUMNS.get(table, set()):
                        json.loads(value)
                    if table == 'clips' and column in DECIMAL_COLUMNS and value is not None:
                        number = decimal.Decimal(str(value))
                        if not number.is_finite() or number != number.quantize(decimal.Decimal('.001')) or abs(number) >= 10_000_000:
                            raise ValueError('Clip decimal cannot be imported losslessly')
                statements.append(f'INSERT INTO "{table}" ({names}) VALUES ('
                                  + ','.join(literal(v) for v in row) + ');')
            if table in sequences:
                position = int(sequences[table])
                statements.append(
                    f"SELECT setval(pg_get_serial_sequence('{schema}.{table}', 'id'), "
                    + str(max(1, position)) + ', ' + ('true' if position > 0 else 'false') + ');'
                )
        statements.append('COMMIT;')
        return '\n'.join(statements) + '\n', report
    finally:
        db.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--schema', default='editorai')
    args = parser.parse_args()
    sql, report = prepare(args.snapshot, args.schema)
    fd = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as stream:
        stream.write(sql)
    print(json.dumps(report))


if __name__ == '__main__':
    main()
