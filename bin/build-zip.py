#!/usr/bin/env python3
"""Builds dist/dhee-vedic-calendar-<version>.zip, leaving out what .distignore lists.

    python3 bin/build-zip.py

The version comes from the plugin header, and the zip holds one top-level
folder, dhee-vedic-calendar/, which is what WordPress's "Upload Plugin" and the
wordpress.org directory both expect.
"""
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SLUG = 'dhee-vedic-calendar'


def main():
    header = open(os.path.join(ROOT, SLUG + '.php'), encoding='utf-8').read()
    version = re.search(r'^\s*\*\s*Version:\s*(\S+)', header, re.M).group(1)
    readme = open(os.path.join(ROOT, 'readme.txt'), encoding='utf-8').read()
    stable = re.search(r'^Stable tag:\s*(\S+)', readme, re.M).group(1)
    if stable != version:
        sys.exit(f'readme.txt says Stable tag {stable}, the plugin header says Version {version}')

    ignored = set()
    for line in open(os.path.join(ROOT, '.distignore'), encoding='utf-8'):
        line = line.strip()
        if line and not line.startswith('#'):
            ignored.add(line.strip('/'))

    os.makedirs(os.path.join(ROOT, 'dist'), exist_ok=True)
    out = os.path.join(ROOT, 'dist', f'{SLUG}-{version}.zip')
    count = 0
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for folder, dirs, files in os.walk(ROOT):
            rel = os.path.relpath(folder, ROOT)
            if rel == '.':
                dirs[:] = sorted(d for d in dirs if d not in ignored and d != 'dist')
                files = [f for f in files if f not in ignored]
            else:
                dirs.sort()
            for name in sorted(files):
                path = os.path.join(folder, name)
                z.write(path, os.path.join(SLUG, os.path.relpath(path, ROOT)))
                count += 1
    print(f'{out}: {count} files, {os.path.getsize(out):,} bytes')


if __name__ == '__main__':
    main()
