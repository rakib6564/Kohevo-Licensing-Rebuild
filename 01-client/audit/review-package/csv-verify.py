import csv,os,sys,collections
root,out=sys.argv[1],sys.argv[2]
blob=[]
for top in ['admin','includes','src','plugins','api','public']:
    for dp,dn,fn in os.walk(os.path.join(root,top)):
        if any(x in dp for x in ('/tests','node_modules','/lang','/vendor')): continue
        for f in fn:
            if f.endswith(('.php','.mjs','.js','.json')):
                blob.append(open(os.path.join(dp,f),encoding='utf-8',errors='ignore').read())
blob='\n'.join(blob); low=blob.lower()
plug={'backups','booking','coaching','forms','licensing','mcp-gateway','media-library','membership','multilang-translate','stripe-payment','studio-builder'}
rows=list(csv.DictReader(open(os.path.join(root,'audit/kohevo-settings-widget-inventory.csv'),encoding='utf-8',newline='')))
res=[]
for i,r in enumerate(rows,start=2):
    k=r['Setting_Or_Widget'].strip(); c=r['Component']
    cands=[k]
    if c in plug: cands=[f'{c}.{k}',k]
    exact=any(f"'{x}'" in blob or f'"{x}"' in blob for x in cands)
    sub=k.lower() in low
    st='VERIFIED' if exact else ('PARTIAL' if sub else 'NOT_FOUND')
    res.append([i,r['Domain'],c,k,st])
with open(out,'w',newline='',encoding='utf-8') as f:
    w=csv.writer(f, lineterminator='\n'); w.writerow(['csv_line','Domain','Component','Setting_Or_Widget','source_match'])
    w.writerows(res)
print(collections.Counter(x[4] for x in res))
print(collections.Counter((x[1],x[4]) for x in res))
