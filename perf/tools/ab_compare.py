#!/usr/bin/env python3
"""Compare baseline vs fork JSON results across runs. usage: ab_compare.py perf/results/runs"""
import json, sys, glob, os, statistics, collections
root = sys.argv[1]
data = collections.defaultdict(lambda: collections.defaultdict(list))
for f in glob.glob(os.path.join(root, '*', '*.json')):
    label, _, name = os.path.basename(f)[:-5].partition('-')
    d = json.load(open(f))
    data[name][label].append(d['median_ms'])
print(f"\n{'benchmark':<52}{'baseline ms':>12}{'fork ms':>10}{'change':>9}   runs(b/f)")
for name in sorted(data):
    b, k = data[name].get('baseline'), data[name].get('fork')
    if not b or not k: continue
    mb, mk = statistics.median(b), statistics.median(k)
    print(f"{name:<52}{mb:>12.3f}{mk:>10.3f}{(mk-mb)/mb*100:>8.1f}%   {len(b)}/{len(k)}  spread b={max(b)-min(b):.2f} f={max(k)-min(k):.2f}")
