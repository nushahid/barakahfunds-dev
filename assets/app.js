function setAmount(targetId, value){
  const input=document.getElementById(targetId);
  if(!input) return;
  const current=parseFloat(input.value||'0');
  input.value=(current+value).toFixed(2);
}
function filterPeople(inputId, rowClass){
  const q=(document.getElementById(inputId)?.value||'').toLowerCase();
  document.querySelectorAll('.'+rowClass).forEach(row=>{
    const text=(row.getAttribute('data-search')||'').toLowerCase();
    row.style.display=text.includes(q)?'flex':'none';
  });
}
function toggleSection(toggleId, sectionId){
  const toggle=document.getElementById(toggleId);
  const section=document.getElementById(sectionId);
  if(!toggle || !section) return;
  section.style.display = toggle.checked ? 'grid' : 'none';
  if(section.classList.contains('stack')) section.style.display = toggle.checked ? 'block' : 'none';
}
function toggleSidebar(force){
  const sidebar=document.getElementById('sidebar');
  const backdrop=document.querySelector('.sidebar-backdrop');
  if(!sidebar || !backdrop) return;
  const open = typeof force === 'boolean' ? force : !sidebar.classList.contains('open');
  sidebar.classList.toggle('open', open);
  backdrop.classList.toggle('show', open);
}
function isMobileSidebarModeDisabled(){
  return window.matchMedia('(max-width: 960px)').matches;
}
function applySidebarMode(){
  const compactStored = localStorage.getItem('sidebar_mode') === 'icons';
  const compact = !isMobileSidebarModeDisabled() && compactStored;
  document.body.classList.toggle('sidebar-icons-only', compact);
  const toggleButtons = document.querySelectorAll('.sidebar-mode-btn');
  toggleButtons.forEach(btn=>{ btn.style.display = isMobileSidebarModeDisabled() ? 'none' : 'flex'; });
  document.dispatchEvent(new CustomEvent('sidebarModeChanged', { detail: { compact } }));
}
function toggleSidebarMode(){
  if (isMobileSidebarModeDisabled()) return;
  const compact = localStorage.getItem('sidebar_mode') === 'icons';
  localStorage.setItem('sidebar_mode', compact ? 'full' : 'icons');
  applySidebarMode();
}
document.addEventListener('DOMContentLoaded',()=>{
  applySidebarMode();
  window.addEventListener('resize', applySidebarMode);
  document.querySelectorAll('[data-toggle-target]').forEach(el=>{
    const target=el.getAttribute('data-toggle-target');
    const fn=()=>toggleSection(el.id, target);
    el.addEventListener('change', fn);
    fn();
  });
});
