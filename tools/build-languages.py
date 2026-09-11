#!/usr/bin/env python3
"""Extract English messages and rebuild POT, PO, MO, and WordPress script catalogs.

Requires PHP and GNU gettext (msgfmt). Japanese translations are maintained in
languages/atshift-members-ja.po. Existing translations are preserved by msgid.
"""
import argparse
import hashlib
import json
import re
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default='php')
parser.add_argument('--msgfmt', default=shutil.which('msgfmt') or 'msgfmt')
args = parser.parse_args()
extractor = r'''$root=$argv[1];$out=[];
foreach(array_merge([$root.'/atshift-members.php'],glob($root.'/includes/*.php')) as $file){
 $tokens=token_get_all(file_get_contents($file));$count=count($tokens);
 for($i=0;$i<$count;$i++){
  $t=$tokens[$i];if(!is_array($t)||$t[0]!==T_STRING||!in_array($t[1],['__','esc_html__','esc_attr__','_e','esc_html_e','esc_attr_e'],true))continue;
  $j=$i+1;while($j<$count&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
  if(($tokens[$j]??null)!=='(')continue;$j++;
  while($j<$count&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
  $literal=$tokens[$j]??null;if(!is_array($literal)||$literal[0]!==T_CONSTANT_ENCAPSED_STRING)throw new RuntimeException('Nonliteral message in '.$file);
  // Only evaluate PHP string literals emitted by token_get_all, never expressions.
  $msg=eval('return '.$literal[1].';');
  $out[]=['msgid'=>$msg,'reference'=>substr($file,strlen($root)+1).':'.$literal[2]];
 }
}
echo json_encode($out,JSON_UNESCAPED_UNICODE);'''
rows = json.loads(subprocess.check_output([args.php, '-r', extractor, str(ROOT)], text=True))
script_messages = {}
js_literal = r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\""
for path in sorted((ROOT / 'assets').glob('*.js')):
    text = path.read_text()
    for match in re.finditer(r'\b__\(\s*(' + js_literal + r")\s*,\s*'atshift-members'\s*\)", text):
        raw = match[1]
        msg = re.sub(r'\\([\\\'\"])', r'\1', raw[1:-1]).replace(r'\n', '\n')
        relative = path.relative_to(ROOT).as_posix()
        rows.append({'msgid': msg, 'reference': relative + ':' + str(text[:match.start()].count('\n') + 1)})
        script_messages.setdefault(relative, set()).add(msg)
header = (ROOT / 'atshift-members.php').read_text()
rows.append({'msgid': re.search(r'^ \* Description: (.*)$', header, re.M)[1], 'reference': 'atshift-members.php:4'})
messages = {}
for row in rows:
    messages.setdefault(row['msgid'], set()).add(row['reference'])
if any(re.search('[ぁ-んァ-ン一-龥]', msg) for msg in messages):
    raise SystemExit('Catalog source messages must be English.')

def read_po(path):
    entries = {}; current = {}; field = None
    if not path.exists():
        return entries
    for line in path.read_text().splitlines() + ['']:
        if not line.strip():
            if current.get('msgid'):
                entries[current['msgid']] = current.get('msgstr', '')
            current = {}; field = None
        elif line.startswith(('msgid ', 'msgstr ')):
            field, value = line.split(' ', 1); current[field] = json.loads(value)
        elif line.startswith('"') and field:
            current[field] += json.loads(line)
    return entries

languages = ROOT / 'languages'
ja = read_po(languages / 'atshift-members-ja.po')
missing = sorted(set(messages) - {key for key, value in ja.items() if value})
if missing:
    raise SystemExit('Missing Japanese translations:\n' + '\n'.join(missing))
fmt = re.compile(r'%(?:\d+\$)?[sd]')
for msg in messages:
    if set(fmt.findall(msg)) != set(fmt.findall(ja[msg])):
        raise SystemExit('Placeholder mismatch: ' + msg)
    if set(re.findall(r'\{\w+\}', msg)) != set(re.findall(r'\{\w+\}', ja[msg])):
        raise SystemExit('Email placeholder mismatch: ' + msg)

version = re.search(r'^ \* Version: (.*)$', header, re.M)[1]
def quote(value): return json.dumps(value, ensure_ascii=False)
def write_po(path, locale, translations):
    meta = ('Project-Id-Version: atshift Members ' + version + '\n'
            'Report-Msgid-Bugs-To: https://plugins.at-shift.net/\n'
            'POT-Creation-Date: 2026-09-11 00:00+0000\n'
            'PO-Revision-Date: 2026-09-11 00:00+0000\n'
            'Last-Translator: atshift\nLanguage-Team: atshift\n'
            'Language: ' + locale + '\nMIME-Version: 1.0\n'
            'Content-Type: text/plain; charset=UTF-8\n'
            'Content-Transfer-Encoding: 8bit\n'
            'Plural-Forms: ' + ('nplurals=1; plural=0;' if locale == 'ja' else 'nplurals=2; plural=(n != 1);') + '\n'
            'X-Domain: atshift-members\n')
    chunks = ['# Translation catalog for atshift Members.\n# Licensed under GPL-2.0-or-later.\nmsgid ""\nmsgstr ' + quote(meta)]
    for msg in sorted(messages):
        comment = ''
        if fmt.search(msg):
            comment = '#. translators: Placeholders represent the named item, path, field identifier, or count described in this message.\n#, ' + ('javascript-format' if all(ref.startswith('assets/') for ref in messages[msg]) else 'php-format') + '\n'
        chunks.append(comment + '#: ' + ' '.join(sorted(messages[msg])) + '\nmsgid ' + quote(msg) + '\nmsgstr ' + quote(translations.get(msg, '')))
    path.write_text('\n\n'.join(chunks) + '\n')

write_po(languages / 'atshift-members.pot', '', {})
for locale, translations in [('ja', ja), ('en_US', {msg: msg for msg in messages})]:
    po = languages / ('atshift-members-' + locale + '.po')
    write_po(po, locale, translations)
    subprocess.run([args.msgfmt, '--check', '--check-format', '-o', str(po.with_suffix('.mo')), str(po)], check=True)
    for source, ids in script_messages.items():
        data = {'translation-revision-date': '2026-09-11 00:00+0000', 'generator': 'atshift Members build-languages.py', 'domain': 'atshift-members', 'source': source,
                'locale_data': {'messages': {'': {'domain': 'atshift-members', 'lang': locale, 'plural-forms': 'nplurals=1; plural=0;' if locale == 'ja' else 'nplurals=2; plural=(n != 1);'}, **{msg: [translations[msg]] for msg in sorted(ids)}}}}
        filename = 'atshift-members-' + locale + '-' + hashlib.md5(source.encode()).hexdigest() + '.json'
        (languages / filename).write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')
print(f'{len(messages)} English source messages; Japanese and English catalogs complete; {sum(map(len, script_messages.values()))} script messages.')
