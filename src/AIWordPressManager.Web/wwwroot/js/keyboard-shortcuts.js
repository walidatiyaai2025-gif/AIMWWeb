window.aiwmShortcuts = (() => {
  let open = false;
  const rows = [
    ["Navigation","التنقل","Ctrl + K","Command palette","لوحة الأوامر"],
    ["Navigation","التنقل","Ctrl + Shift + P","Recent pages & favorites","الصفحات الأخيرة والمفضلة"],
    ["Navigation","التنقل","Alt + H","Dashboard","لوحة التحكم"],
    ["Navigation","التنقل","Alt + S","Sites","المواقع"],
    ["Workspace","مساحة العمل","Alt + E","Execution center","مركز التنفيذ"],
    ["Workspace","مساحة العمل","Alt + M","Media manager","إدارة الوسائط"],
    ["Workspace","مساحة العمل","Alt + O","SEO audit","تدقيق SEO"],
    ["Appearance","المظهر","Ctrl + Alt + T","Toggle light/dark mode","تبديل الوضع الفاتح والداكن"],
    ["Help","المساعدة","? / Ctrl + /","Keyboard shortcuts","اختصارات لوحة المفاتيح"],
    ["General","عام","Esc","Close open panels","إغلاق النوافذ المفتوحة"]
  ];

  function isArabic(){ return document.documentElement.lang === "ar" || document.body.dir === "rtl"; }
  function close(){ document.getElementById("aiwm-shortcuts")?.remove(); open=false; }
  function openPanel(){
    if(open){ close(); return; }
    open=true; const ar=isArabic();
    const overlay=document.createElement("div"); overlay.id="aiwm-shortcuts"; overlay.className="shortcut-overlay";
    overlay.innerHTML=`<section class="shortcut-dialog" role="dialog" aria-modal="true"><header><div><span>⌨</span><div><strong>${ar?"اختصارات لوحة المفاتيح":"Keyboard shortcuts"}</strong><small>${ar?"تنقل أسرع داخل النظام":"Work faster across the application"}</small></div></div><button type="button">×</button></header><div class="shortcut-list">${rows.map(r=>`<article><div><strong>${ar?r[4]:r[3]}</strong><small>${ar?r[1]:r[0]}</small></div><kbd>${r[2]}</kbd></article>`).join("")}</div><footer>${ar?"يمكن استخدام الاختصارات من أي صفحة ما لم يكن المؤشر داخل حقل كتابة.":"Shortcuts work from any page unless focus is inside a text field."}</footer></section>`;
    overlay.addEventListener("click",e=>{if(e.target===overlay)close();});
    overlay.querySelector("button")?.addEventListener("click",close); document.body.appendChild(overlay);
  }
  function editing(t){return t instanceof HTMLInputElement||t instanceof HTMLTextAreaElement||t?.isContentEditable;}
  document.addEventListener("keydown",e=>{
    if(e.key==="Escape"){close();return;}
    if(editing(e.target)) return;
    const key=e.key.toLowerCase();
    if((e.ctrlKey&&key==="/")||e.key==="?"){e.preventDefault();openPanel();return;}
    if(e.altKey&&key==="h") location.href="/";
    if(e.altKey&&key==="s") location.href="/sites";
    if(e.altKey&&key==="e") location.href="/module/execution";
    if(e.altKey&&key==="m") location.href="/module/media";
    if(e.altKey&&key==="o") location.href="/module/seo-audit";
    if(e.ctrlKey&&e.altKey&&key==="t"){e.preventDefault();window.appTheme?.toggleMode?.();}
  });
  return { open:openPanel, close };
})();