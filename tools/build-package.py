#!/usr/bin/env python3
"""Build the installable plugin from an explicit runtime-file allowlist."""
import argparse
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('output', type=Path, help='Destination ZIP path')
args = parser.parse_args()
files = [root / 'atshift-members.php', root / 'readme.txt']
files += sorted((root / 'includes').glob('*.php'))
files += sorted(p for p in (root / 'assets').iterdir() if p.suffix in {'.css', '.js'})
files += sorted(p for p in (root / 'languages').iterdir() if p.suffix in {'.pot', '.po', '.mo', '.json'})
if args.output.exists():
    parser.error('Destination already exists; choose a new ZIP path.')
args.output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(args.output, 'x', ZIP_DEFLATED) as archive:
    for path in files:
        if path.is_symlink():
            raise ValueError(f'Unexpected symlink: {path}')
        archive.write(path, 'atshift-members/' + path.relative_to(root).as_posix())
print(f'{args.output}: {len(files)} runtime files')
