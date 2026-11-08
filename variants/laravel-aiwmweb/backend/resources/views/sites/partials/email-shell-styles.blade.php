{{-- Shared site-email dashboard shell used by Settings and Diagnostics. Page-specific form styles remain in each view. --}}
<style data-aiwm-site-email-shell-styles>
    .site-email-shell {
        --bg: #0b0f17; --surface: #151923; --surface2: #1b2130;
        --border: #30394c; --text: #f4f6fa; --muted: #9aa5b5;
        --accent: #c5a45d; --accent-soft: rgba(197,164,93,.16);
        display: grid; grid-template-columns: minmax(230px, 284px) minmax(0, 1fr);
        min-height: 100vh; background: var(--bg); color: var(--text);
    }
    .site-email-shell, .site-email-shell * { box-sizing: border-box; }
    .site-email-shell a { color: #e0bf73; }
    .site-email-shell .site-email-sidebar {
        position: sticky; top: 0; height: 100vh; min-width: 0;
        overflow-y: auto; background: var(--surface); border-inline-end: 1px solid var(--border);
        display: flex; flex-direction: column; gap: 16px; padding: 18px;
    }
    .site-email-shell .brand { display: flex; gap: 12px; align-items: center; text-decoration: none; color: var(--text); }
    .site-email-shell .brand-mark { background: var(--accent); color: #15120b; border-radius: 10px; padding: 10px; font-weight: 800; }
    .site-email-shell .brand-copy { display: grid; gap: 2px; min-width: 0; }
    .site-email-shell .brand-copy small, .site-email-shell .sidebar-footer { color: var(--muted); overflow-wrap: anywhere; }
    .site-email-shell .main-nav { display: grid; gap: 8px; }
    .site-email-shell .nav-section { border: 1px solid var(--border); border-radius: 12px; padding: 6px; }
    .site-email-shell .nav-section summary { padding: 10px; color: var(--muted); font-weight: 700; cursor: pointer; }
    .site-email-shell .nav-links { display: grid; gap: 4px; }
    .site-email-shell .nav-links a { display: block; border-radius: 10px; padding: 11px 12px; min-height: 44px; text-decoration: none; color: var(--text); }
    .site-email-shell .nav-links a.active, .site-email-shell .nav-links a:hover { background: var(--accent-soft); color: #e0bf73; }
    .site-email-shell .sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 16px; display: grid; gap: 4px; }
    .site-email-shell .main-area { min-width: 0; }
    .site-email-shell .site-email-topbar {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 16px; padding: 16px 24px; margin: 0;
        background: var(--bg); border: 0; border-bottom: 1px solid var(--border); border-radius: 0;
    }
    .site-email-shell .topbar-title { min-width: 0; }
    .site-email-shell .site-email-topbar h1 { margin: 8px 0 0; font-size: clamp(18px, 2vw, 24px); }
    .site-email-shell .breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 12px; color: var(--muted); }
    .site-email-shell .topbar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .site-email-shell .site-email-content {
        width: min(1180px, calc(100% - 32px)); margin: 24px auto 48px;
        max-width: none; min-width: 0; padding: 0;
    }
    .site-email-shell .site-email-content > header, .site-email-shell .site-email-content > section {
        background: var(--surface); border: 1px solid var(--border); border-radius: 16px;
        padding: clamp(16px, 2vw, 24px); margin-bottom: 16px;
    }
    .site-email-shell :is(input, select, button) { max-width: 100%; }
    .site-email-shell :is(input, select) { background: var(--surface2); color: var(--text); border-color: #526077; }
    .site-email-shell button { background: var(--accent); color: #17130d; }
    .site-email-shell .site-email-content table { min-width: 500px; }
    .site-email-shell .site-email-content .table-scroll { max-width: 100%; overflow-x: auto; }
    .site-email-shell :focus-visible { outline: 3px solid #e0bf73; outline-offset: 3px; }
    @media (max-width: 760px) {
        .site-email-shell { grid-template-columns: minmax(0, 1fr); }
        .site-email-shell .site-email-sidebar { position: static; height: auto; overflow: visible; padding: 12px; }
        .site-email-shell .nav-links { grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); }
        .site-email-shell .sidebar-footer { display: none; }
        .site-email-shell .site-email-topbar { padding: 12px 16px; }
        .site-email-shell .site-email-content { margin: 16px auto 32px; }
    }
</style>
