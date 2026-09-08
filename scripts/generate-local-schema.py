"""Compile the SQL examples in newsql.md into an ordered, executable baseline."""
from pathlib import Path
import re
import json

root = Path(__file__).resolve().parents[1]
source = (root / 'newsql.md').read_text(encoding='utf-8-sig')
blocks = re.findall(r'```sql\s*\n(.*?)```', source, re.S)
ddl = '\n'.join(block for block in blocks if 'CREATE TABLE ' in block)
ddl = re.sub(r'--[^\n]*', '', ddl)
sequences = {name: statement for statement, name in re.findall(r'(CREATE SEQUENCE\s+(\w+)\b[^;]*;)', ddl)}
tables = {}
foreign_keys = []
partial_indexes = []

def split_columns(body):
    parts, start, depth, quoted = [], 0, 0, False
    for offset, char in enumerate(body):
        if char == "'":
            quoted = not quoted
        elif not quoted:
            depth += (char == '(') - (char == ')')
            if char == ',' and depth == 0:
                parts.append(body[start:offset].strip())
                start = offset + 1
    parts.append(body[start:].strip())
    return parts

for name, body in re.findall(r'CREATE TABLE\s+(\w+)\s*\((.*?)\n\);', ddl, re.S):
    columns = []
    for column in split_columns(body):
        if re.match(r'CONSTRAINT\s+\w+\s+FOREIGN KEY', column):
            foreign_keys.append(f'ALTER TABLE {name} ADD {column};')
            continue
        partial = re.match(r'CONSTRAINT\s+(\w+)\s+UNIQUE\s*(\([^)]*\))\s+WHERE\s+(.*)', column, re.S)
        if partial:
            index, fields, condition = partial.groups()
            partial_indexes.append(f'CREATE UNIQUE INDEX {index} ON {name} {fields} WHERE {condition};')
            continue
        if re.fullmatch(r'id\s+bigint\s+NOT NULL', column):
            sequence = f'{name}_id_seq'
            column += f" DEFAULT nextval('{sequence}')"
        if name == 'users' and re.fullmatch(r'entity_uuid\s+uuid\s+NOT NULL', column):
            column += ' DEFAULT gen_random_uuid()'
        for sequence in re.findall(r"nextval\('([^']+)'", column):
            sequences.setdefault(sequence, f'CREATE SEQUENCE {sequence} START WITH 1 INCREMENT BY 1;')
        columns.append(column)
    tables[name] = columns

assert len(tables) == len(re.findall(r'CREATE TABLE ', ddl)), 'Unparsed table definition'
indexes = re.findall(r'CREATE (?:UNIQUE )?INDEX\s+[^;]+;', ddl)
comments = re.findall(r'COMMENT ON\s+[^;]+;', ddl)
ownership = []
for name, columns in tables.items():
    for column in columns:
        sequence = re.search(r"nextval\('([^']+)'", column)
        if sequence:
            ownership.append(f'ALTER SEQUENCE {sequence[1]} OWNED BY {name}.{column.split()[0]};')

output = [
    '-- Generated from newsql.md by scripts/generate-local-schema.py.',
    '-- Empty database only. FK creation is deferred until all tables exist.',
    '-- Missing bigint sequences / users UUID default and partial UNIQUE syntax are repaired.',
    *sequences.values(),
    *[f'CREATE TABLE {name} (\n    ' + ',\n    '.join(columns) + '\n);' for name, columns in tables.items()],
    *ownership, *indexes, *partial_indexes, *comments, *foreign_keys,
]
(root / 'database/schema').mkdir(exist_ok=True)
(root / 'database/schema/newsql-baseline.sql').write_text('\n\n'.join(output) + '\n', encoding='utf-8')
(root / 'database/schema/newsql-manifest.json').write_text(json.dumps(tables, ensure_ascii=False, indent=2), encoding='utf-8')
print(f'{len(tables)} tables, {len(sequences)} sequences, {len(foreign_keys)} foreign keys, {len(indexes) + len(partial_indexes)} indexes')
