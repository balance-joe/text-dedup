from __future__ import annotations

import argparse, ctypes, hashlib, json, os, re, time, unicodedata
from pathlib import Path
import numpy as np

PERMUTATIONS, NGRAM, MASK = 128, 5, (1 << 64) - 1
ROOT = Path(__file__).resolve().parent.parent
TOKEN_RE = re.compile(r"\[[^\[\]]{1,20}\]")
SPACE_RE = re.compile(r"\s+")

def args():
    p=argparse.ArgumentParser(); p.add_argument("-input","--input",dest="input",type=Path,default=ROOT/"runtime/php-compat-baseline-1000.json"); p.add_argument("--repeat",type=int,default=1); return p.parse_args()
def is_emoji(c):
    n=ord(c); return any(a<=n<=b for a,b in [(0x1F600,0x1F64F),(0x1F300,0x1F5FF),(0x1F680,0x1F6FF),(0x1F1E0,0x1F1FF),(0x1F900,0x1F9FF),(0x1FA00,0x1FAFF),(0x2600,0x27BF),(0x231A,0x231B),(0x23E9,0x23F3),(0x23F8,0x23FA),(0x25AA,0x25AB),(0x25B6,0x25C0),(0x25FB,0x25FE),(0x2934,0x2935),(0x2B05,0x2B07),(0x2B1B,0x2B1C),(0xFE00,0xFE0F)]) or n in (0x2B50,0x2B55,0x3030,0x303D,0x3297,0x3299,0x200D,0x20E3)
def normalize(v):
    t=unicodedata.normalize("NFKC",str(v or "")).replace("\u200b","").replace("\ufeff",""); original=SPACE_RE.sub("",t).lower(); t="".join(c for c in t if not is_emoji(c)); t=TOKEN_RE.sub("",t); t=re.sub(r"\d+","0",t).replace("0.0","0"); t="".join(c for c in t if "\u4e00"<=c<="\u9fff" or "\u3000"<=c<="\u303f" or "\uff00"<=c<="\uffef"); return SPACE_RE.sub("",t).lower() or original
def digest(s,n): return hashlib.blake2b(s.encode(),digest_size=n).digest()
def grams(t): return [] if not t else [t] if len(t)<=NGRAM else [t[i:i+NGRAM] for i in range(len(t)-NGRAM+1)]
def simhash(t):
    items=grams(t)
    if not items:return 0
    blob=b"".join(digest(g,16) for g in items)
    matrix=np.frombuffer(blob,dtype=np.uint8).reshape(len(items),16)
    bits=np.unpackbits(matrix[:,::-1],axis=1,bitorder="little")
    winners=bits.sum(axis=0)*2>=len(items)
    packed=np.packbits(winners.astype(np.uint8),bitorder="little")
    return int.from_bytes(packed.tobytes(),"little")
def stable(s): return int.from_bytes(digest(s,8),"big")
A=[stable(f"minhash-perm-{i}")|1 for i in range(128)]; B=[stable(f"minhash-offset-{i}") for i in range(128)]
NP_A=np.asarray(A,dtype=np.uint64); NP_B=np.asarray(B,dtype=np.uint64)
def minhash(t):
    unique=set(grams(t))
    if not unique:return [MASK]*128
    hashes=np.asarray([stable(g) for g in unique],dtype=np.uint64)
    return (hashes[:,None]*NP_A[None,:]+NP_B[None,:]).min(axis=0).tolist()
def low(t):
    tokens=TOKEN_RE.findall(t); return not t or len(t)<30 or (bool(tokens) and sum(map(len,tokens))/len(t)>=.65) or sum(c.isalnum() or "\u4e00"<=c<="\u9fff" for c in t)<10
def process_memory():
    result={"process_current_rss_mb":None,"process_peak_rss_mb":None,"process_metric":"unavailable on this operating system"}
    try:
        values={}
        for line in Path("/proc/self/status").read_text().splitlines():
            fields=line.split()
            if len(fields)>=2 and fields[0] in ("VmRSS:","VmHWM:"): values[fields[0]]=round(int(fields[1])/1024,3)
        result.update(process_current_rss_mb=values.get("VmRSS:"),process_peak_rss_mb=values.get("VmHWM:"),process_metric="Linux /proc/self/status VmRSS and VmHWM")
        return result
    except OSError: pass
    if os.name=="nt":
        class Counters(ctypes.Structure):
            _fields_=[("cb",ctypes.c_ulong),("PageFaultCount",ctypes.c_ulong),("PeakWorkingSetSize",ctypes.c_size_t),("WorkingSetSize",ctypes.c_size_t),("QuotaPeakPagedPoolUsage",ctypes.c_size_t),("QuotaPagedPoolUsage",ctypes.c_size_t),("QuotaPeakNonPagedPoolUsage",ctypes.c_size_t),("QuotaNonPagedPoolUsage",ctypes.c_size_t),("PagefileUsage",ctypes.c_size_t),("PeakPagefileUsage",ctypes.c_size_t)]
        kernel32=ctypes.WinDLL("kernel32",use_last_error=True); psapi=ctypes.WinDLL("psapi",use_last_error=True)
        kernel32.GetCurrentProcess.restype=ctypes.c_void_p
        psapi.GetProcessMemoryInfo.argtypes=[ctypes.c_void_p,ctypes.POINTER(Counters),ctypes.c_ulong]; psapi.GetProcessMemoryInfo.restype=ctypes.c_int
        counters=Counters(); counters.cb=ctypes.sizeof(counters)
        if psapi.GetProcessMemoryInfo(kernel32.GetCurrentProcess(),ctypes.byref(counters),counters.cb):
            result.update(process_current_rss_mb=round(counters.WorkingSetSize/1024/1024,3),process_peak_rss_mb=round(counters.PeakWorkingSetSize/1024/1024,3),process_metric="Windows process WorkingSetSize and PeakWorkingSetSize")
    return result
def scope_value(source,scope):
    title,content=normalize(source.get("title")),normalize(source.get("content")); text=(content or title) if scope=="content" else title; ch=hashlib.md5(content.encode()).hexdigest() if content else None; th=hashlib.md5(title.encode()).hexdigest() if title else None; exact=(ch or th) if scope=="content" else th; sv=simhash(text); sig=minhash(text)
    return {"normalized_title":title,"normalized_content":content,"raw_hash_hex":hashlib.md5(("title\x1f"+str(source.get("title") or "")+"\x1econtent\x1f"+str(source.get("content") or "")).encode()).hexdigest(),"content_hash_hex":ch,"title_hash_hex":th,"primary_text":text if scope=="content" else None,"text":text,"text_len":len(text),"low_information":low(text),"exact_hash":exact,"simhash_hex":f"{sv:032x}","simhash_hi_pg_bigint":int.from_bytes(sv.to_bytes(16,"big")[:8],"big",signed=True),"simhash_lo_pg_bigint":int.from_bytes(sv.to_bytes(16,"big")[8:],"big",signed=True),"simhash_bands":[[i,f"{(sv>>(i*16))&0xffff:04x}"] for i in range(8)],"minhash_signature_uint64":sig,"minhash_bands_uint64":[[i,sig[i]] for i in range(32)]}
def verify(record):
    expected=record["expected_from_canonical_input"]
    for scope in (["content","title"] if expected["title_scope"] is not None else ["content"]):
        actual=scope_value(record["canonical_input"],scope); es=expected[f"{scope}_scope"]
        wanted={"normalized_title":expected["normalized_title"],"normalized_content":expected["normalized_content"],"raw_hash_hex":expected["raw_hash_hex"],"content_hash_hex":expected["content_hash_hex"],"title_hash_hex":expected["title_hash_hex"],"primary_text":expected["primary_text"] if scope=="content" else None,**{k:es[k] for k in ("text","text_len","low_information","exact_hash","simhash_hex","simhash_hi_pg_bigint","simhash_lo_pg_bigint","simhash_bands","minhash_signature_uint64","minhash_bands_uint64")}}
        for k,v in wanted.items():
            if actual[k]!=v:return {"doc_pk":record["doc_pk"],"scope":scope,"field":k}
def main():
    a=args(); records=json.load(a.input.open(encoding="utf-8"))["records"]; times=[]; failures=[]
    for n in range(a.repeat):
        started=time.perf_counter(); current=[x for r in records if (x:=verify(r))]; times.append(time.perf_counter()-started)
        if n==0: failures=current
    best=min(times); scopes=sum(2 if r["expected_from_canonical_input"]["title_scope"] is not None else 1 for r in records); print(json.dumps({"language":"python","contract":"full-fingerprint","records":len(records),"scopes":scopes,"repeat":a.repeat,"elapsed_seconds":round(best,6),"best_elapsed_seconds":round(best,6),"average_elapsed_seconds":round(sum(times)/len(times),6),"records_per_second":round(len(records)/best,2),"memory":process_memory(),"failure_count":len(failures),"failures":failures[:5],"status":"passed" if not failures else "failed"},ensure_ascii=False,indent=2)); raise SystemExit(bool(failures))
if __name__=="__main__": main()
