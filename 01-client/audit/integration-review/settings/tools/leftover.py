import json,os,re,sys
root,js=sys.argv[1],sys.argv[2]
d=json.load(open(js))
seen={(r['file'],r['line']) for r in d['recs']}|{(x['file'],x['line']) for x in d['dyn']}
EX={'vendor','tests','node_modules','audit','archive','scratch','Claude','docs','db_backups'}
for dp,dns,fns in os.walk(root):
    rel=os.path.relpath(dp,root); p=[] if rel=='.' else rel.split(os.sep)
    if p and p[0] in EX: dns[:]=[];continue
    dns[:]=[x for x in dns if x not in('vendor','node_modules','tests')]
    for f in fns:
        if not f.endswith('.php'):continue
        fp=os.path.join(dp,f); r=os.path.relpath(fp,root)
        for i,l in enumerate(open(fp,errors='replace')):
            if re.search(r"(::|->)(setting|setSetting)\(",l) and (r,i+1) not in seen:
                s=l.strip()
                if s.startswith(('*','//','/*')):continue
                print(r,i+1,s[:150])
