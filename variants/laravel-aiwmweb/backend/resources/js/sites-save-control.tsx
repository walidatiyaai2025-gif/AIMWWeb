import React,{useMemo,useState}from'react';
import{useMutation,useQuery,useQueryClient}from'@tanstack/react-query';
import{ApiError,apiRequest,type FrontendContext}from'./core';
import{useLocale}from'./i18n';
export const SITES_SAVE_OPERATION_ID='AIMW-BILL-54E4003BE9';
type SiteRow={id:number;name?:string;url?:string;status?:string};
export const canonicalSitesSaveCollection=(c:FrontendContext)=>c.api.sites===`/api/tenants/${encodeURIComponent(c.tenant.slug)}/sites`?c.api.sites:null;
const cleanUrl=(v:string)=>{try{const u=new URL(v.trim());return['http:','https:'].includes(u.protocol)?v.trim().replace(/\/+$/,''):null}catch{return null}};
export function SitesSaveControl({context}:{context:FrontendContext}){
 const{locale}=useLocale(),qc=useQueryClient(),ep=canonicalSitesSaveCollection(context),allowed=context.permissions.includes('*')||context.permissions.includes('sites.manage');
 const[id,setId]=useState<number|null>(null),[name,setName]=useState(''),[url,setUrl]=useState(''),[msg,setMsg]=useState<{r:'status'|'alert';t:string}|null>(null);
 const q=useQuery({queryKey:['sites-save-control',context.tenant.slug,ep],queryFn:()=>apiRequest<SiteRow[]>(ep!),enabled:allowed&&!!ep});
 const sites=useMemo(()=>Array.isArray(q.data)?q.data:[],[q.data]);
 const m=useMutation({mutationFn:async()=>{if(!ep)throw new Error('Sites save contract is unavailable.');const n=name.trim(),u=cleanUrl(url);if(!n)throw new Error(locale==='ar'?'اسم الموقع مطلوب.':'Site name is required.');if(!u)throw new Error(locale==='ar'?'رابط الموقع غير صالح.':'Site URL is invalid.');const saved=await apiRequest<SiteRow>(id===null?ep:`${ep}/${id}`,{method:id===null?'POST':'PATCH',body:JSON.stringify({name:n,url:u})});const sid=Number(saved.id??id);if(!Number.isInteger(sid)||sid<1)throw new Error('Save response did not identify the persisted site.');const rr=await q.refetch();if(rr.error)throw rr.error;const row=(Array.isArray(rr.data)?rr.data:[]).find(x=>x.id===sid);if(!row||row.name!==n||String(row.url??'').replace(/\/+$/,'')!==u)throw new Error('Site save completed but authoritative Sites reread did not match the persisted values.');return row},
 onSuccess:async s=>{await qc.invalidateQueries({queryKey:['workspace',context.tenant.slug,'sites']});setId(null);setName('');setUrl('');setMsg({r:'status',t:locale==='ar'?'تم حفظ الموقع والتحقق من البيانات.':`Saved ${s.name??s.id} and verified authoritative state.`})},
 onError:e=>setMsg({r:'alert',t:e instanceof ApiError||e instanceof Error?e.message:'Site save failed.'})});
 if(!allowed||!ep)return null;
 return <section className="panel data-panel" aria-label={locale==='ar'?'حفظ موقع':'Save site'}>
  <header className="panel-header"><div><span className="workspace-kicker">TENANT SITE PROFILE</span><h2>{id===null?(locale==='ar'?'إضافة موقع':'Add site'):(locale==='ar'?'تعديل الموقع':'Edit site')}</h2></div></header>
  {msg?<p role={msg.r}>{msg.t}</p>:null}
  <form onSubmit={e=>{e.preventDefault();setMsg(null);m.mutate()}}>
   <label>{locale==='ar'?'الاسم':'Name'}<input aria-label={locale==='ar'?'الاسم':'Name'} value={name} onChange={e=>setName(e.target.value)} disabled={m.isPending}/></label>
   <label>{locale==='ar'?'الرابط':'URL'}<input aria-label="URL" value={url} onChange={e=>setUrl(e.target.value)} disabled={m.isPending}/></label>
   <div className="toolbar-actions"><button type="submit" className="btn primary" data-canonical-operation={SITES_SAVE_OPERATION_ID} disabled={m.isPending}>{m.isPending?(locale==='ar'?'جارٍ الحفظ…':'Saving…'):(locale==='ar'?'حفظ':'Save')}</button><button type="button" className="btn" disabled={m.isPending} onClick={()=>{setId(null);setName('');setUrl('');setMsg(null)}}>{locale==='ar'?'إلغاء':'Cancel'}</button></div>
  </form>
  {q.error?<p role="alert">{q.error instanceof Error?q.error.message:String(q.error)}</p>:null}
  {sites.length?<table className="data-table"><thead><tr><th>ID</th><th>Name</th><th>URL</th><th>Action</th></tr></thead><tbody>{sites.map(s=><tr key={s.id}><td>{s.id}</td><td>{s.name??'—'}</td><td>{s.url??'—'}</td><td><button type="button" className="btn" disabled={m.isPending} onClick={()=>{setId(s.id);setName(s.name??'');setUrl(s.url??'');setMsg(null)}}>{locale==='ar'?'تعديل':'Edit'}</button></td></tr>)}</tbody></table>:null}
 </section>
}