#!/usr/bin/env python3
"""Summarise Excimer collapsed stacks: top functions by self and inclusive share of samples.
usage: collapsed_top.py FILE [N]"""
import sys, collections
path = sys.argv[1]; n = int(sys.argv[2]) if len(sys.argv) > 2 else 30
self_c = collections.Counter(); incl_c = collections.Counter(); total = 0
for line in open(path):
    line = line.rstrip('\n')
    if not line: continue
    stack, _, cnt = line.rpartition(' ')
    cnt = int(cnt); frames = stack.split(';'); total += cnt
    self_c[frames[-1]] += cnt
    for fr in set(frames): incl_c[fr] += cnt
print(f'total samples: {total}')
for title, c in (('SELF', self_c), ('INCLUSIVE', incl_c)):
    print(f'\n== {title}')
    for fn, v in c.most_common(n): print(f'{100*v/total:6.1f}%  {fn[:150]}')
