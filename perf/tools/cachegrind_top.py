#!/usr/bin/env python3
"""Summarise an xdebug cachegrind file: top functions by self cost and by inclusive cost.
usage: cachegrind_top.py FILE [N]
Costs are xdebug time units; percentages are of total self cost. Inclusive = sum of call-site costs."""
import sys, re, collections

path = sys.argv[1]
n = int(sys.argv[2]) if len(sys.argv) > 2 else 30
names = {}

def resolve(tok):
    m = re.match(r'\((\d+)\)(?: (.*))?$', tok)
    if not m: return tok
    if m.group(2): names[m.group(1)] = m.group(2)
    return names.get(m.group(1), m.group(1))

self_c = collections.Counter(); incl_c = collections.Counter(); calls = collections.Counter()
cur = callee = None; next_is_call_cost = False
with open(path, errors='replace') as f:
    for line in f:
        line = line.rstrip('\n')
        if line.startswith('fn='): cur = resolve(line[3:])
        elif line.startswith('cfn='): callee = resolve(line[4:])
        elif line.startswith('calls='):
            calls[callee] += int(line[6:].split()[0]); next_is_call_cost = True
        elif line and line[0].isdigit():
            parts = line.split()
            if len(parts) < 2: continue
            cost = int(parts[1])
            if next_is_call_cost:
                incl_c[callee] += cost; next_is_call_cost = False
            else:
                self_c[cur] += cost
total = sum(self_c.values()) or 1
for title, c in (('SELF', self_c), ('INCLUSIVE', incl_c)):
    print(f'\n== {title} (total self = {total})')
    for fn, v in c.most_common(n):
        print(f'{100*v/total:6.1f}%  calls={calls.get(fn,0):<8} {fn}')
