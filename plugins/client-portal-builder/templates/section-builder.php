<?php
/**
 * BriefSync Client Portal — Briefbuilder / "Build More" Section
 * Accessed via ?section=builder on the portal shortcode
 *
 * Variables available from shortcode.php:
 *   $user, $client, $user_id, $cm (CPP_Client_Manager)
 */

if (!defined('ABSPATH')) exit;

// ── Data ─────────────────────────────────────────────
global $wpdb;
$is_admin = current_user_can('manage_options');
$pages_table = $wpdb->prefix . 'cpp_portal_pages';

// Get pages
if ($is_admin) {
    $pages = $wpdb->get_results("SELECT * FROM {$pages_table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A);
} else {
    $pages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$pages_table} WHERE client_id = %d ORDER BY updated_at DESC LIMIT 100",
        intval($client['id'])
    ), ARRAY_A);
}
$pages = $pages ?: array();

$total_pages = count($pages);
$published_count = count(array_filter($pages, function($p) { return $p['status'] === 'published'; }));
$draft_count = $total_pages - $published_count;

// Sidebar data
$uploads_table = $wpdb->prefix . 'cpp_uploads';
$total_uploads = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$uploads_table} WHERE client_id = %d", $client['id']));
$plans = get_option('cpp_plans', array());
$plan_key = !empty($client['plan']) ? $client['plan'] : 'website';
$current_plan = isset($plans[$plan_key]) ? $plans[$plan_key] : null;
$display_name = $user->display_name;
$name_parts = explode(' ', trim($display_name));
$initials = count($name_parts) >= 2
    ? strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1))
    : strtoupper(substr($display_name, 0, 2));
$sidebar_plan_label = !empty($current_plan['label'])
    ? $current_plan['label']
    : ucwords(str_replace('_', ' ', $plan_key)) . ' Plan';

$builder_nonce = wp_create_nonce('cpp_builder_nonce');
$preset_nonce = wp_create_nonce('cpp_preset_nonce');
$portal_url = get_permalink();
?>

<script>document.body.classList.add('cpp-portal-page');</script>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/boxicons@latest/css/boxicons.min.css">
<link rel="stylesheet" href="<?php echo plugins_url('assets/css/frest/core.css', dirname(__FILE__, 2)); ?>">
<link rel="stylesheet" href="<?php echo plugins_url('assets/css/frest/theme-default.css', dirname(__FILE__, 2)); ?>">
<link rel="stylesheet" href="<?php echo plugins_url('assets/css/frest/demo.css', dirname(__FILE__, 2)); ?>">

<style>
/* ─── ELEMENTOR RESET ─────────────────────────────────── */
#Top_bar, header, .sticky-header, #wdes-menu-top-header,
.wdes-menu-navbar, .wdes-mob-btn, .menu-d-mob,
.clientarea-shortcuts, #logo,
footer, .wdes-nav-wrap, .wdes-nav-mobile-menu,
.elementor-location-header, .elementor-location-footer,
#Footer { display: none !important; }
.mce-notification, .mce-widget.mce-notification { display: none !important; }

html, body { background: #f2f2f6 !important; margin: 0 !important; padding: 0 !important; }

.e-con, .e-con-inner, .e-con-boxed,
.elementor-widget-container, .elementor-shortcode,
.elementor-element.elementor-element-populated,
[data-elementor-type="wp-page"],
.elementor .elementor-section.elementor-section-boxed > .elementor-container,
.elementor-container, .elementor-row, .elementor-column,
.elementor-column-wrap, .elementor-widget-wrap,
.elementor-section, .elementor-section-wrap,
.elementor-top-section, .elementor-inner-section,
.elementor-section-content-middle,
.elementor > .elementor-inner,
body .elementor-bc-flex-widget .elementor-widget-wrap {
    max-width: 100% !important; width: 100% !important; padding: 0 !important; margin: 0 !important;
    box-sizing: border-box !important;
}

/* ─── PORTAL WRAPPER ──────────────────────────────────── */
#cpb-portal.cpp-dashboard {
    --bs-nav: #0b1829;
    --bs-nav-hover: #112742;
    --bs-accent: #1E78CD;
    --bs-accent-light: #e6f0fa;
    --bs-accent-bright: #389FD8;
    --bs-radius: 0.3125rem;
    --bs-transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    --sidebar-w: 264px;
    font-family: 'IBM Plex Sans', sans-serif;
    background: #f2f2f6;
    color: #2b2c40;
    min-height: 100vh;
    overflow-x: hidden;
    display: flex;
    font-size: 15px;
    box-sizing: border-box;
}
#cpb-portal.cpp-dashboard *, #cpb-portal.cpp-dashboard *::before, #cpb-portal.cpp-dashboard *::after {
    box-sizing: border-box;
}

/* ─── SIDEBAR ─────────────────────────────────────────── */
#cpb-portal .cpp-sidebar { width: var(--sidebar-w); background: var(--bs-nav); position: fixed; top: 0; left: 0; bottom: 0; display: flex; flex-direction: column; z-index: 100; transition: transform 0.3s ease; box-shadow: 0 0 20px rgba(0,0,0,0.15); }
#cpb-portal .cpp-sidebar-brand { padding: 4px 12px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid rgba(255,255,255,0.05); flex-shrink: 0; }
#cpb-portal .cpp-sidebar-brand-icon { width: 28px; height: 28px; background: linear-gradient(135deg, var(--bs-accent), var(--bs-accent-bright)); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; color: #fff; flex-shrink: 0; box-shadow: 0 2px 8px rgba(30,120,205,0.35); }
#cpb-portal .cpp-sidebar-brand-text { font-family: 'Instrument Serif', serif; font-size: 1.1rem; color: #fff; letter-spacing: -0.01em; line-height: 1; }
#cpb-portal .cpp-sidebar-nav { flex: 1; padding: 0 8px; overflow-y: auto; }
#cpb-portal .cpp-sidebar-nav::-webkit-scrollbar { width: 4px; }
#cpb-portal .cpp-sidebar-nav::-webkit-scrollbar-track { background: transparent; }
#cpb-portal .cpp-sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
#cpb-portal .cpp-sidebar-section-label { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: rgba(255,255,255,0.25); padding: 8px 10px 2px; }
#cpb-portal .cpp-sidebar-link { display: flex; align-items: center; gap: 9px; padding: 6px 12px; border-radius: var(--bs-radius); color: rgba(255,255,255,0.55); text-decoration: none; font-size: 0.82rem; font-weight: 500; transition: var(--bs-transition); margin-bottom: 1px; position: relative; cursor: pointer; background: transparent; border: none; width: 100%; text-align: left; font-family: inherit; }
#cpb-portal .cpp-sidebar-link:hover { background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.85); text-decoration: none; }
#cpb-portal .cpp-sidebar-link.active { background: linear-gradient(135deg, var(--bs-accent), var(--bs-accent-bright)); color: #fff; box-shadow: 0 2px 10px rgba(30,120,205,0.35); }
#cpb-portal .cpp-sidebar-link.active::before { display: none; }
#cpb-portal .cpp-sidebar-link i { font-size: 1.15rem; width: 22px; text-align: center; flex-shrink: 0; }
#cpb-portal .cpp-sidebar-badge { margin-left: auto; background: rgba(255,255,255,0.15); color: #fff; font-size: 0.68rem; font-weight: 700; padding: 2px 8px; border-radius: 10px; flex-shrink: 0; }
#cpb-portal .cpp-sidebar-link.active .cpp-sidebar-badge { background: rgba(255,255,255,0.25); }
#cpb-portal .cpp-sidebar-badge.warning { background: #ff9f43; color: #fff; }
#cpb-portal .cpp-sidebar-user { padding: 2px 10px; margin: 2px 8px 4px; background: rgba(255,255,255,0.03); border-radius: 10px; display: flex; align-items: center; gap: 12px; border: 1px solid rgba(255,255,255,0.04); flex-shrink: 0; }
#cpb-portal .cpp-sidebar-avatar { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #1E78CD, #389FD8); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 0.9rem; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.04); }
#cpb-portal .cpp-sidebar-user-info { flex: 1; min-width: 0; }
#cpb-portal .cpp-sidebar-user-name { font-size: 0.88rem; font-weight: 600; color: #d0d7e4; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#cpb-portal .cpp-sidebar-user-plan { font-size: 0.74rem; color: #4d5a73; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#cpb-portal .cpp-sidebar-user-actions { margin-left: auto; display: flex; gap: 4px; flex-shrink: 0; }
#cpb-portal .cpp-sidebar-user-btn { width: 30px; height: 30px; border-radius: 7px; border: none; background: transparent; color: #4d5a73; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; transition: var(--bs-transition); text-decoration: none; }
#cpb-portal .cpp-sidebar-user-btn:hover { background: rgba(255,255,255,0.06); color: #8892a8; text-decoration: none; }
#cpb-portal .cpp-mobile-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
#cpb-portal .cpp-mobile-overlay.show { display: block; }

/* ─── MAIN CONTENT + TOPBAR ───────────────────────────── */
#cpb-portal .cpp-main-content { margin-left: var(--sidebar-w); flex: 1; min-height: 100vh; min-width: 0; display: flex; flex-direction: column; }
#cpb-portal .cpp-topbar { height: 64px; background: #fff; border-bottom: none; box-shadow: 0 2px 8px rgba(47,43,61,0.06); display: flex; align-items: center; padding: 0 32px; position: sticky; top: 0; z-index: 50; flex-shrink: 0; }
#cpb-portal .cpp-topbar-left { display: flex; align-items: center; gap: 16px; }
#cpb-portal .cpp-topbar-hamburger { display: none; width: 38px; height: 38px; border-radius: 8px; border: none; box-shadow: 0 1px 4px rgba(47,43,61,0.08); background: #fff; cursor: pointer; align-items: center; justify-content: center; font-size: 1.1rem; color: #6f6b7d; transition: var(--bs-transition); flex-shrink: 0; }
#cpb-portal .cpp-topbar-breadcrumb { font-size: 0.88rem; color: #6f6b7d; }
#cpb-portal .cpp-topbar-breadcrumb strong { color: #2b2c40; font-weight: 600; }
#cpb-portal .cpp-topbar-breadcrumb a { color: #6f6b7d; text-decoration: none; }
#cpb-portal .cpp-topbar-breadcrumb a:hover { color: var(--bs-accent); }
#cpb-portal .cpp-topbar-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
#cpb-portal .cpp-topbar-btn { width: 38px; height: 38px; border-radius: 9px; border: none; box-shadow: 0 1px 4px rgba(47,43,61,0.08); background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; color: #6f6b7d; position: relative; transition: var(--bs-transition); flex-shrink: 0; }
#cpb-portal .cpp-topbar-btn:hover { background: #f2f2f6; color: #2b2c40; }

/* ─── CONTENT AREA ────────────────────────────────────── */
#cpb-portal .cpp-content-area { padding: 1.5rem; width: 100%; max-width: 100%; }

/* ─── BUILDER SPECIFIC ────────────────────────────────── */
.bldr-stat-card { display: flex; align-items: center; gap: 16px; }
.bldr-stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0; color: #fff; }
.bldr-stat-icon.primary { background: linear-gradient(135deg, #1E78CD, #389FD8); box-shadow: 0 2px 8px rgba(30,120,205,0.25); }
.bldr-stat-icon.success { background: linear-gradient(135deg, #28c76f, #48DA89); box-shadow: 0 2px 8px rgba(40,199,111,0.25); }
.bldr-stat-icon.warning { background: linear-gradient(135deg, #ff9f43, #FFB976); box-shadow: 0 2px 8px rgba(255,159,67,0.25); }

.bldr-action-btn { display: inline-flex; align-items: center; gap: 4px; font-size: 0.74rem; font-weight: 600; padding: 2px 10px; border-radius: 6px; white-space: nowrap; }
.bldr-action-btn i { font-size: 0.95rem; }

.bldr-block-type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.bldr-block-type-card { border: 2px solid #e9ecef; border-radius: 10px; padding: 20px 16px; text-align: center; cursor: pointer; transition: var(--bs-transition); background: #fff; }
.bldr-block-type-card:hover { border-color: var(--bs-accent); background: var(--bs-accent-light); }
.bldr-block-type-card i { font-size: 1.8rem; color: var(--bs-accent); margin-bottom: 8px; display: block; }
.bldr-block-type-card span { font-size: 0.88rem; font-weight: 600; color: #2b2c40; }

.bldr-canvas-block { border: 1px solid #e9ecef; border-radius: 10px; padding: 16px 20px; margin-bottom: 12px; background: #fff; position: relative; transition: var(--bs-transition); }
.bldr-canvas-block:hover { border-color: var(--bs-accent); box-shadow: 0 2px 12px rgba(30,120,205,0.08); }
.bldr-canvas-block .bldr-block-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.bldr-canvas-block .bldr-block-label { font-weight: 600; font-size: 0.92rem; display: flex; align-items: center; gap: 8px; }
.bldr-canvas-block .bldr-block-label i { color: var(--bs-accent); font-size: 1rem; }
.bldr-canvas-block .bldr-block-actions { display: flex; gap: 4px; }
.bldr-canvas-block .bldr-block-actions button { width: 30px; height: 30px; border-radius: 6px; border: 1px solid #e9ecef; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; color: #6f6b7d; transition: var(--bs-transition); }
.bldr-canvas-block .bldr-block-actions button:hover { background: #f2f2f6; color: #2b2c40; }
.bldr-canvas-block .bldr-block-actions button.danger:hover { background: #fff0f0; color: #ea5455; border-color: #ea5455; }
.bldr-canvas-block .bldr-block-preview { font-size: 0.84rem; color: #6f6b7d; }

.bldr-empty-canvas { text-align: center; padding: 60px 20px; color: #a5a3ae; }
.bldr-empty-canvas i { font-size: 3rem; margin-bottom: 12px; display: block; color: #d0cfe0; }

/* ─── LIST TOPBAR (matches VF topbar) ────────────────── */
.bldr-list-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 24px; flex-wrap: wrap; gap: 12px;
}
.bldr-list-topbar h1 {
    margin: 0; font-size: 1.3rem; font-weight: 700; color: #2b2c40;
    display: flex; align-items: center;
}
.bldr-list-topbar-right { display: flex; align-items: center; gap: 8px; }
.bldr-add-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 18px; border-radius: 8px; border: none;
    background: var(--bs-accent); color: #fff; font-size: 0.85rem; font-weight: 600;
    cursor: pointer; transition: all 0.15s; font-family: inherit;
}
.bldr-add-btn:hover { background: #1565b0; }
.bldr-add-btn i { font-size: 1.1rem; }

/* ─── GALLERY GRID (same as VF gallery) ─────────────── */
.bldr-gallery { padding: 0 24px 24px; flex: 1; }
.bldr-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }

/* Add Page card (matches VF upload card) */
.bldr-add-card {
    background: #fff; border: 2px dashed #e2e8f0; border-radius: 12px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-height: 250px; cursor: pointer; transition: border-color 0.2s, background 0.2s; padding: 20px;
}
.bldr-add-card:hover { border-color: var(--bs-accent); background: #f0f7ff; }
.bldr-add-card p { font-size: 14px; color: #6f6b7d; margin: 4px 0; }

/* Page cards (matches VF image cards) */
.bldr-page-card {
    background: #fff; border-radius: 12px; overflow: hidden;
    border: 1px solid #e2e8f0; transition: box-shadow 0.2s, transform 0.15s;
}
.bldr-page-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.08); transform: translateY(-2px); }

.bldr-page-card-link { cursor: pointer; display: block; text-decoration: none; color: inherit; }
.bldr-page-card-link:hover { text-decoration: none; color: inherit; }

.bldr-page-card-thumb { width: 100%; height: 180px; object-fit: cover; display: block; background: #e5e7eb; }
.bldr-page-card-placeholder { display: flex; align-items: center; justify-content: center; }

.bldr-page-card-body { padding: 14px 16px; }
.bldr-page-card-title { font-size: 14px; font-weight: 600; margin: 0 0 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #2b2c40; }
.bldr-page-card-meta { font-size: 12px; color: #94a3b8; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.bldr-page-badge { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
.bldr-page-badge.published { background: rgba(40,199,111,0.12); color: #28c76f; }
.bldr-page-badge.draft { background: rgba(255,159,67,0.12); color: #ff9f43; }
.bldr-page-badge.blocks { background: rgba(30,120,205,0.1); color: var(--bs-accent); }

/* Card action bar */
.bldr-page-card-actions {
    display: flex; gap: 4px; padding: 8px 16px 12px;
    border-top: 1px solid #f5f5f5;
}
.bldr-page-card-actions button {
    width: 32px; height: 32px; border-radius: 8px; border: 1px solid #e9ecef;
    background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem; color: #64748b; transition: all 0.15s;
}
.bldr-page-card-actions button:hover { background: #f0f7ff; border-color: var(--bs-accent); color: var(--bs-accent); }
.bldr-page-card-actions button.danger { margin-left: auto; }
.bldr-page-card-actions button.danger:hover { background: #fff0f0; border-color: #ea5455; color: #ea5455; }

@media (max-width: 768px) {
    .bldr-gallery { padding: 0 16px 16px; }
    .bldr-gallery-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
    .bldr-page-card-thumb { height: 120px; }
    .bldr-add-card { min-height: 150px; }
    .bldr-list-topbar { padding: 12px 16px; }
}

/* ─── MODAL ───────────────────────────────────────────── */
.bldr-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050; display: none; align-items: center; justify-content: center; }
.bldr-modal-backdrop.show { display: flex; }
.bldr-modal { background: #fff; border-radius: 12px; width: 90%; max-width: 640px; max-height: 85vh; overflow-y: auto; box-shadow: 0 12px 48px rgba(0,0,0,0.2); }
.bldr-modal-header { padding: 20px 24px; border-bottom: 1px solid #e9ecef; display: flex; align-items: center; justify-content: space-between; }
.bldr-modal-header h5 { margin: 0; font-size: 1.05rem; font-weight: 600; }
.bldr-modal-header .bldr-modal-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #6f6b7d; transition: var(--bs-transition); }
.bldr-modal-header .bldr-modal-close:hover { background: #f2f2f6; }
.bldr-modal-body { padding: 24px; }
.bldr-modal-footer { padding: 16px 24px; border-top: 1px solid #e9ecef; display: flex; justify-content: flex-end; gap: 8px; }

/* ─── TOAST ───────────────────────────────────────────── */
.bldr-toast { position: fixed; bottom: 24px; right: 24px; background: #0b1829; color: #fff; padding: 14px 22px; border-radius: 0.375rem; font-size: 0.88rem; font-weight: 500; z-index: 2000; display: none; align-items: center; gap: 10px; box-shadow: 0 8px 32px rgba(0,0,0,0.25); animation: bldrToastIn 0.3s ease; }
.bldr-toast.show { display: flex; }
@keyframes bldrToastIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* ─── RESPONSIVE ──────────────────────────────────────── */
@media (max-width: 768px) {
    #cpb-portal .cpp-sidebar { transform: translateX(-100%); }
    #cpb-portal .cpp-sidebar.mobile-open { transform: translateX(0); width: 100%; }
    #cpb-portal .cpp-main-content { margin-left: 0; }
    #cpb-portal .cpp-topbar-hamburger { display: flex; }
    #cpb-portal .cpp-topbar { padding: 0 16px; }
    #cpb-portal .cpp-content-area { padding: 1rem; }
    .bldr-block-type-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
}
@media (max-width: 480px) {
    #cpb-portal .cpp-content-area { padding: 0.75rem; }
    .bldr-action-btn span { display: none; }
    .bldr-action-btn { padding: 4px 7px; gap: 0; }
}
/* Actions column: wrap buttons so table doesn't overflow */
.bldr-actions-wrap { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
#bldr-pages-list td { vertical-align: middle; }
#bldr-pages-list .bldr-action-btn { font-size: 0.74rem; padding: 2px 10px; }

@media (max-width: 1200px) {
    /* Hide Last Modified to free horizontal space */
    #bldr-pages-list tr td:nth-child(5),
    .table thead tr th:nth-child(5) { display: none !important; }
}
@media (max-width: 992px) {
    /* Pages table: hide Blocks, Feedback, Status, Last Modified - show only Page Name + Actions */
    #bldr-pages-list tr td:nth-child(2),
    #bldr-pages-list tr td:nth-child(3),
    #bldr-pages-list tr td:nth-child(4),
    #bldr-pages-list tr td:nth-child(5),
    .table thead tr th:nth-child(2),
    .table thead tr th:nth-child(3),
    .table thead tr th:nth-child(4),
    .table thead tr th:nth-child(5) { display: none !important; }
    .table-responsive { overflow-x: hidden !important; }
}

/* ─── SAVE AS TEMPLATE POPUP ─────────────────────────── */
.sat-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100001; align-items:center; justify-content:center; }
.sat-overlay.show { display:flex; }
.sat-popup { background:#fff; border-radius:10px; padding:28px; width:100%; max-width:440px; box-shadow:0 10px 40px rgba(0,0,0,0.25); }
.sat-popup h5 { margin:0 0 20px; font-size:1.1rem; font-weight:600; display:flex; align-items:center; gap:8px; }
.sat-popup h5 i { color:#1E78CD; }
.sat-popup .sat-field { margin-bottom:14px; }
.sat-popup .sat-field label { display:block; font-size:0.82rem; font-weight:600; color:#2b2c40; margin-bottom:4px; }
.sat-popup .sat-field input, .sat-popup .sat-field select {
    width:100%; padding:8px 12px; border:1px solid #d9dee3; border-radius:6px; font-size:0.88rem; font-family:inherit;
}
.sat-popup .sat-field input:focus, .sat-popup .sat-field select:focus {
    outline:none; border-color:#1E78CD; box-shadow:0 0 0 2px rgba(30,120,205,0.12);
}
.sat-popup .sat-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
</style>

<div id="cpb-portal" class="cpp-dashboard">

    <?php if (!wp_style_is('bootstrap-icons', 'enqueued')): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php endif; ?>
    <?php if (!wp_style_is('dm-sans-font', 'enqueued') && !wp_style_is('google-fonts-dm-sans', 'enqueued')): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
    <?php endif; ?>

    <!-- Mobile Overlay -->
    <div class="cpp-mobile-overlay" id="cpp-mobile-overlay" onclick="bldrCloseSidebar()"></div>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- SIDEBAR                                             -->
    <!-- ═══════════════════════════════════════════════════ -->
    <?php $active_section = 'builder'; include __DIR__ . '/partials/sidebar.php'; ?>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- MAIN CONTENT                                        -->
    <!-- ═══════════════════════════════════════════════════ -->
    <div class="cpp-main-content">

        <!-- Topbar -->
        <div class="cpp-topbar">
            <div class="cpp-topbar-left">
                <button class="cpp-topbar-hamburger" onclick="bldrToggleSidebar()" aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <div class="cpp-topbar-breadcrumb">
                    <a href="<?php echo esc_url($portal_url); ?>">Portal</a> &nbsp;/&nbsp; <strong>Briefbuilder</strong>
                </div>
            </div>
            <div class="cpp-topbar-right">
                <button class="cpp-topbar-btn" title="Back to Dashboard" onclick="window.location.href='<?php echo esc_url($portal_url); ?>'">
                    <i class="bi bi-grid-1x2"></i>
                </button>
            </div>
        </div>

        <!-- Content Area -->
        <div class="cpp-content-area">

            <!-- ════════════════════════════════════════════════ -->
            <!-- VIEW: PAGES LIST                                 -->
            <!-- ════════════════════════════════════════════════ -->
            <div id="bldr-list-view">

                <!-- Page Header (matches VF topbar style) -->
                <div class="bldr-list-topbar">
                    <h1><img src="<?php echo CPB_PLUGIN_URL; ?>assets/icons/bubblecons/builder.png" alt="" style="width:28px;height:28px;object-fit:contain;vertical-align:middle;margin-right:8px;"> Briefbuilder</h1>
                    <div class="bldr-list-topbar-right">
                        <button class="bldr-add-btn" onclick="bldrNewPage()">
                            <i class="bx bx-plus-circle"></i> New Page
                        </button>
                    </div>
                </div>

                <!-- Gallery Grid (same layout as Visual Feedback) -->
                <div class="bldr-gallery">
                    <div class="bldr-gallery-grid">
                        <!-- Add Page card (matches VF upload card) -->
                        <div class="bldr-add-card" onclick="bldrNewPage()">
                            <img src="<?php echo CPB_PLUGIN_URL; ?>assets/icons/bubblecons/builder.png" alt="" style="width:42px;height:42px;object-fit:contain;margin-bottom:8px;">
                            <p style="font-weight:600;">New Page</p>
                            <span style="font-size:12px;color:#9ca3af;">Create a new page</span>
                        </div>

                        <?php foreach ($pages as $pg):
                            $blocks = json_decode($pg['blocks_json'] ?: '[]', true);
                            $block_count = is_array($blocks) ? count($blocks) : 0;
                            $is_published = ($pg['status'] === 'published');
                            $time_ago = human_time_diff(strtotime($pg['updated_at'])) . ' ago';
                            // Get first block thumbnail if available
                            $thumb_url = '';
                            if (is_array($blocks) && !empty($blocks[0]['url'])) {
                                $thumb_url = $blocks[0]['url'];
                            }
                            $colors = array('#1E78CD','#28c76f','#ff9f43','#ea5455','#7367f0','#00cfe8');
                            $color_idx = crc32($pg['title']) % count($colors);
                            $preview_color = $colors[$color_idx];
                        ?>
                        <div class="bldr-page-card" data-status="<?php echo esc_attr($pg['status']); ?>">
                            <div class="bldr-page-card-link" onclick="bldrEditPage(<?php echo intval($pg['id']); ?>)">
                                <?php if ($thumb_url): ?>
                                <img class="bldr-page-card-thumb" src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($pg['title']); ?>" loading="lazy">
                                <?php else: ?>
                                <div class="bldr-page-card-thumb bldr-page-card-placeholder" style="background: linear-gradient(135deg, <?php echo $preview_color; ?>18, <?php echo $preview_color; ?>08);">
                                    <i class="bx bx-file" style="font-size:2.5rem;color:<?php echo $preview_color; ?>;opacity:0.5;"></i>
                                </div>
                                <?php endif; ?>
                                <div class="bldr-page-card-body">
                                    <div class="bldr-page-card-title"><?php echo esc_html($pg['title']); ?></div>
                                    <div class="bldr-page-card-meta">
                                        <span><?php echo esc_html($time_ago); ?></span>
                                        <?php if ($is_published): ?>
                                        <span class="bldr-page-badge published">Published</span>
                                        <?php else: ?>
                                        <span class="bldr-page-badge draft">Draft</span>
                                        <?php endif; ?>
                                        <?php if ($block_count > 0): ?>
                                        <span class="bldr-page-badge blocks"><?php echo $block_count; ?> block<?php echo $block_count !== 1 ? 's' : ''; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="bldr-page-card-actions">
                                <button onclick="fbOpenViewer(<?php echo intval($pg['id']); ?>, '<?php echo esc_js($pg['title']); ?>')" title="Feedback"><i class="bx bx-comment-detail"></i></button>
                                <button onclick="bldrEditPage(<?php echo intval($pg['id']); ?>)" title="Edit"><i class="bx bx-edit-alt"></i></button>
                                <?php if ($is_admin): ?>
                                <button onclick="satOpen(<?php echo intval($pg['id']); ?>, '<?php echo esc_js($pg['title']); ?>')" title="Template"><i class="bx bx-bookmark-plus"></i></button>
                                <?php endif; ?>
                                <button class="danger" onclick="bldrDeletePage(<?php echo intval($pg['id']); ?>, '<?php echo esc_js($pg['title']); ?>')" title="Delete"><i class="bx bx-trash"></i></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div><!-- /bldr-list-view -->

            <!-- Editor view placeholder (overlay is used instead) -->
            <div id="bldr-editor-view" style="display:none;"></div>

        </div><!-- /cpp-content-area -->

    </div><!-- /cpp-main-content -->

</div><!-- /cpb-portal -->

<!-- ═══════════════════════════════════════════════════════ -->
<!-- FULLSCREEN BUILDER OVERLAY                              -->
<!-- ═══════════════════════════════════════════════════════ -->
<div id="bldr-overlay" class="bldr-overlay" style="display:none;">

    <!-- Top Bar -->
    <div class="bldr-overlay-topbar">
        <div class="bldr-topbar-left">
            <button class="bldr-back-btn" onclick="bldrCloseOverlay()">
                <i class="bx bx-arrow-back"></i>
            </button>
            <div class="bldr-topbar-info">
                <h5 class="bldr-topbar-title" id="bldr-overlay-title">New Page</h5>
                <small class="bldr-topbar-slug" id="bldr-overlay-slug"></small>
            </div>
            <span id="bldr-block-count" class="bldr-block-badge">0 blocks</span>
        </div>
        <div class="bldr-topbar-right">
            <button class="bldr-save-draft-btn" onclick="bldrSavePage('draft')">
                <i class="bx bx-save"></i> <span>Save Draft</span>
            </button>
            <?php if ($is_admin): ?>
            <button class="bldr-save-draft-btn bldr-tpl-btn" onclick="satOpenFromOverlay()" id="bldr-sat-btn">
                <i class="bx bx-bookmark-plus"></i> <span>Template</span>
            </button>
            <?php endif; ?>
            <button class="bldr-publish-btn" onclick="bldrSavePage('published')">
                <i class="bx bx-check-circle"></i> Publish
            </button>
        </div>
    </div>

    <!-- Builder Body -->
    <div class="bldr-overlay-body">

        <!-- LEFT: Block Library Sidebar -->
        <div class="bldr-library-sidebar" id="bldr-library-sidebar">
            <div class="bldr-library-header">
                <div class="bldr-lib-title">
                    <i class="bx bx-category"></i> Blocks
                </div>
                <?php if ($is_admin): ?>
                <button class="bldr-lib-upload-btn" onclick="document.getElementById('bldr-lib-upload-input').click()" title="Upload block image">
                    <i class="bx bx-plus"></i>
                </button>
                <input type="file" accept="image/*" id="bldr-lib-upload-input" style="display:none;" onchange="bldrLibUploadAsset(this)">
                <?php endif; ?>
            </div>
            <!-- Search -->
            <div class="bldr-lib-search">
                <i class="bx bx-search"></i>
                <input type="text" id="bldr-lib-search-input" placeholder="Search blocks..." oninput="bldrSearchLib(this.value)">
            </div>
            <!-- Category chips -->
            <div class="bldr-lib-cats" id="bldr-lib-cats"></div>
            <!-- Block grid -->
            <div class="bldr-library-body" id="bldr-library-body">
                <div class="bldr-lib-loading">
                    <div class="bldr-spinner"></div>
                    <p>Loading blocks...</p>
                </div>
            </div>
        </div>

        <!-- RIGHT: Collection / Page Canvas -->
        <div class="bldr-collection-main">
            <!-- Page Title Input -->
            <div class="bldr-title-area">
                <div class="bldr-title-row">
                    <div class="bldr-title-field">
                        <label>Page Title</label>
                        <input type="text" id="bldr-page-title" placeholder="Enter page title..." oninput="bldrUpdateSlug()">
                    </div>
                    <div class="bldr-slug-field">
                        <label>Slug</label>
                        <input type="text" id="bldr-page-slug" placeholder="page-slug">
                    </div>
                </div>
            </div>

            <!-- Collection Area -->
            <div class="bldr-collection-area" id="bldr-collection-area">
                <div class="bldr-collection-empty" id="bldr-collection-empty">
                    <div class="bldr-empty-icon"><i class="bx bx-layer-plus"></i></div>
                    <h6>Start building your page</h6>
                    <p>Browse blocks from the library and click to add them here</p>
                </div>
                <div id="bldr-collection-list"></div>
            </div>
        </div>

    </div>

    <!-- Mobile Library Toggle -->
    <button class="bldr-mobile-lib-btn" id="bldr-mobile-lib-toggle" onclick="bldrToggleLibrary()">
        <i class="bx bx-category"></i>
    </button>
    <div class="bldr-mobile-overlay-bg" id="bldr-mobile-overlay-bg" onclick="bldrCloseLibrary()"></div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- BLOCK CONTENT EDIT MODAL (used for inline form editing) -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="bldr-modal-backdrop" id="bldr-block-edit-modal">
    <div class="bldr-modal">
        <div class="bldr-modal-header">
            <h5 id="bldr-edit-modal-title">Edit Block Content</h5>
            <button class="bldr-modal-close" onclick="bldrCloseEditModal()"><i class="bx bx-x"></i></button>
        </div>
        <div class="bldr-modal-body" id="bldr-edit-modal-body">
            <!-- Populated by JS -->
        </div>
        <div class="bldr-modal-footer">
            <button class="btn btn-label-secondary" onclick="bldrCloseEditModal()">Cancel</button>
            <button class="btn btn-primary" onclick="bldrSaveBlock()"><i class="bx bx-check me-1"></i> Save Content</button>
        </div>
    </div>
</div>

<style>
/* ═══════════════════════════════════════════════════════
   FULLSCREEN BUILDER OVERLAY — BriefSync Creator
   ═══════════════════════════════════════════════════════ */
.bldr-overlay {
    position: fixed; inset: 0; z-index: 100001;
    background: #f0f2f8; overflow: hidden;
    display: flex; flex-direction: column;
    font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* ── Top Bar ── */
.bldr-overlay-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 20px; height: 56px; min-height: 56px;
    background: linear-gradient(135deg, #1a1f36 0%, #2b2c40 100%);
    flex-shrink: 0; z-index: 10; gap: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.15);
}
.bldr-topbar-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.bldr-topbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.bldr-back-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border: 1px solid rgba(255,255,255,0.15); border-radius: 8px;
    background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.8); font-size: 17px;
    cursor: pointer; transition: all .15s; flex-shrink: 0;
}
.bldr-back-btn:hover { background: rgba(255,255,255,0.15); color: #fff; }
.bldr-topbar-info { min-width: 0; }
.bldr-topbar-title { margin: 0; font-size: 14px; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bldr-topbar-slug { font-size: 11px; color: rgba(255,255,255,0.45); }
.bldr-block-badge {
    font-size: 11px; font-weight: 600; padding: 3px 10px;
    background: rgba(30,120,205,0.3); color: #7dd3fc; border-radius: 20px;
    white-space: nowrap; flex-shrink: 0;
}
.bldr-save-draft-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 14px; border: 1px solid rgba(255,255,255,0.15); border-radius: 8px;
    background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.85); font-size: 12px; font-weight: 600;
    cursor: pointer; transition: all .15s; white-space: nowrap;
}
.bldr-save-draft-btn:hover { background: rgba(255,255,255,0.15); color: #fff; }
.bldr-save-draft-btn.bldr-tpl-btn { background: rgba(40,199,111,0.2); border-color: rgba(40,199,111,0.3); color: #6ee7b7; }
.bldr-save-draft-btn.bldr-tpl-btn:hover { background: rgba(40,199,111,0.35); color: #a7f3d0; }
.bldr-publish-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 18px; border: none; border-radius: 8px;
    background: linear-gradient(135deg, #1E78CD, #389FD8); color: #fff; font-size: 12px; font-weight: 700;
    cursor: pointer; transition: all .2s; white-space: nowrap;
    box-shadow: 0 2px 8px rgba(30,120,205,0.3);
}
.bldr-publish-btn:hover { box-shadow: 0 4px 16px rgba(30,120,205,0.45); transform: translateY(-1px); }

/* ── Body ── */
.bldr-overlay-body { flex: 1; display: flex; overflow: hidden; }

/* ── Library Sidebar ── */
.bldr-library-sidebar {
    width: 300px; min-width: 300px; background: #fff;
    border-right: 1px solid #e5e7eb; display: flex; flex-direction: column;
    height: 100%; overflow: hidden;
}
.bldr-library-header {
    padding: 14px 16px; background: #fff;
    font-size: 13px; font-weight: 700; color: #2b2c40;
    display: flex; align-items: center; gap: 8px; flex-shrink: 0;
    border-bottom: 1px solid #f0f0f0;
}
.bldr-lib-title { display: flex; align-items: center; gap: 6px; }
.bldr-lib-title i { font-size: 18px; color: var(--bs-accent,#1e7ecd); }
.bldr-lib-upload-btn {
    margin-left: auto; background: var(--bs-accent,#1e7ecd); border: none;
    color: #fff; border-radius: 6px; width: 28px; height: 28px; cursor: pointer;
    font-size: 16px; transition: all .15s; display: inline-flex; align-items: center; justify-content: center;
}
.bldr-lib-upload-btn:hover { background: #1a6abf; transform: scale(1.05); }

/* Search */
.bldr-lib-search {
    padding: 8px 12px; position: relative; flex-shrink: 0;
}
.bldr-lib-search i {
    position: absolute; left: 22px; top: 50%; transform: translateY(-50%);
    color: #a1a1aa; font-size: 15px; pointer-events: none;
}
.bldr-lib-search input {
    width: 100%; padding: 8px 10px 8px 32px; border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 12px; color: #2b2c40; background: #f8f9fa; transition: all .15s;
}
.bldr-lib-search input:focus { outline: none; border-color: var(--bs-accent,#1e7ecd); background: #fff; box-shadow: 0 0 0 3px rgba(30,120,205,0.08); }
.bldr-lib-search input::placeholder { color: #b4b4b4; }

/* Category chips - hidden */
.bldr-lib-cats { display: none; }

/* Block grid body */
.bldr-library-body { flex: 1; overflow-y: auto; padding: 8px 12px 16px; }
.bldr-lib-loading { text-align: center; padding: 40px 16px; color: #a1a1aa; }
.bldr-spinner {
    width: 24px; height: 24px; border: 3px solid #e9ecef; border-top-color: var(--bs-accent,#1e7ecd);
    border-radius: 50%; animation: bldrSpin 0.6s linear infinite; margin: 0 auto 10px;
}
@keyframes bldrSpin { to { transform: rotate(360deg); } }

/* Category sections - BriefSync themed accordion */
.bldr-lib-category { margin-bottom: 6px; }
.bldr-lib-cat-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 11px 14px; cursor: pointer; font-weight: 700; font-size: 12px;
    text-transform: uppercase; letter-spacing: 0.5px; color: #3b4a5e;
    transition: all .2s; user-select: none; border-radius: 10px;
    background: #f4f6fa; border: 1px solid #e8ecf2;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.bldr-lib-cat-header:hover { background: #e8edf5; border-color: #d0d8e5; color: #1a2b42; box-shadow: 0 2px 6px rgba(0,0,0,0.07); }
.bldr-lib-cat-header:not(.collapsed) { background: linear-gradient(135deg, #1a1f36 0%, #2b2c40 100%); color: #fff; border-color: transparent; box-shadow: 0 2px 8px rgba(26,31,54,0.18); }
.bldr-lib-cat-header:not(.collapsed):hover { opacity: 0.92; }
.bldr-lib-cat-header .bx-chevron-down { transition: transform .2s; font-size: 16px; }
.bldr-lib-cat-header.collapsed .bx-chevron-down { transform: rotate(-90deg); }
.bldr-lib-cat-header .cat-count {
    font-size: 10px; font-weight: 700; background: rgba(30,120,205,0.15);
    color: var(--bs-accent,#1e7ecd); padding: 2px 8px; border-radius: 10px; margin-left: 6px;
}
.bldr-lib-cat-header:not(.collapsed) .cat-count { background: rgba(255,255,255,0.2); color: #fff; }
.bldr-lib-cat-body { display: none; padding: 8px 0; }
.bldr-lib-items { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }

/* Block Thumbnails in Library */
.bldr-lib-block {
    border-radius: 10px; overflow: hidden; cursor: pointer;
    border: 2px solid #f0f0f0; transition: all .2s;
    position: relative; background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.bldr-lib-block:hover {
    border-color: var(--bs-accent,#1e7ecd); transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(30,120,205,0.12);
}
.bldr-lib-block img { width: 100%; height: 90px; object-fit: cover; display: block; }
.bldr-lib-block-name {
    font-size: 10px; padding: 6px 8px; color: #566a7f; font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    text-align: center; background: #fafbfc;
}
.bldr-lib-block.added { border-color: #10b981; }
.bldr-lib-block.added img { opacity: 0.5; }
.bldr-lib-block.added::after {
    content: '\2713'; position: absolute; top: 6px; right: 6px;
    background: #10b981; color: #fff; width: 20px; height: 20px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: bold; box-shadow: 0 2px 6px rgba(16,185,129,0.3);
}
/* Hover add indicator */
.bldr-lib-block:not(.added)::before {
    content: '+'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0);
    width: 32px; height: 32px; border-radius: 50%; background: var(--bs-accent,#1e7ecd); color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700;
    transition: transform .2s; z-index: 2; box-shadow: 0 4px 12px rgba(30,120,205,0.3);
}
.bldr-lib-block:not(.added):hover::before { transform: translate(-50%, -50%) scale(1); }

/* ── Collection Main Area ── */
.bldr-collection-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.bldr-title-area {
    padding: 14px 24px; background: #fff; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.bldr-title-row { display: flex; gap: 16px; }
.bldr-title-field { flex: 2; }
.bldr-slug-field { flex: 1; }
.bldr-title-area label {
    font-size: 10px; font-weight: 700; color: #8b8fa3; text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 4px; display: block;
}
.bldr-title-area input {
    width: 100%; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 14px; color: #2b2c40; transition: all .15s; background: #fafbfc;
}
.bldr-title-area input:focus { outline: none; border-color: var(--bs-accent,#1e7ecd); background: #fff; box-shadow: 0 0 0 3px rgba(30,120,205,0.08); }

/* ── Collection Area ── */
.bldr-collection-area { flex: 1; overflow-y: auto; padding: 20px 24px; background: #f0f2f8; }
.bldr-collection-empty {
    text-align: center; padding: 80px 20px; color: #a1a1aa;
}
.bldr-empty-icon {
    width: 64px; height: 64px; border-radius: 16px; background: linear-gradient(135deg, rgba(30,120,205,0.08), rgba(56,159,216,0.12));
    display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;
}
.bldr-empty-icon i { font-size: 28px; color: var(--bs-accent,#1e7ecd); }
.bldr-collection-empty h6 { font-size: 16px; font-weight: 700; color: #4b5563; margin-bottom: 6px; }
.bldr-collection-empty p { font-size: 13px; color: #9ca3af; max-width: 280px; margin: 0 auto; line-height: 1.5; }

/* ── Collection Block Items ── */
.bldr-coll-item {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
    overflow: hidden; margin-bottom: 16px; transition: all .2s;
}
.bldr-coll-item:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.06); border-color: #d0d4dc; }
.bldr-coll-item img { width: 100%; max-height: 400px; object-fit: contain; background: #f8f9fa; display: block; }
.bldr-coll-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 16px; border-top: 1px solid #f0f0f0; flex-wrap: wrap; gap: 8px;
}
.bldr-coll-name { font-weight: 700; font-size: 13px; color: #2b2c40; }
.bldr-coll-meta { font-size: 11px; color: #a1a1aa; margin-left: 8px; }
.bldr-coll-actions { display: flex; align-items: center; gap: 4px; }
.bldr-coll-actions button {
    width: 30px; height: 30px; font-size: 14px; border: 1px solid #e5e7eb; border-radius: 8px;
    background: #fff; color: #6b7280; cursor: pointer; transition: all .15s;
    display: inline-flex; align-items: center; justify-content: center; padding: 0;
}
.bldr-coll-actions button:hover { background: #f0f2f8; border-color: #d0d4dc; color: #2b2c40; }
.bldr-coll-actions button.danger { color: #ea5455; }
.bldr-coll-actions button.danger:hover { background: #fef2f2; border-color: #fca5a5; }
.bldr-form-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; padding: 4px 10px; border-radius: 20px; font-weight: 600; cursor: pointer;
    transition: all .15s;
}
.bldr-form-badge.filled { background: #d1fae5; color: #065f46; }
.bldr-form-badge.filled:hover { background: #a7f3d0; }
.bldr-form-badge.empty { background: #fef3c7; color: #92400e; }
.bldr-form-badge.empty:hover { background: #fde68a; }

/* ── Inline Block Form ── */
.bldr-coll-form {
    padding: 16px; background: #f8faff; border-top: 1px solid #e0ecff; display: none;
}
.bldr-coll-form label { font-size: 11px; font-weight: 700; color: #566a7f; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 4px; display: block; }
.bldr-coll-form input, .bldr-coll-form textarea {
    width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 13px; margin-bottom: 10px; background: #fff;
}
.bldr-coll-form input:focus, .bldr-coll-form textarea:focus { outline: none; border-color: var(--bs-accent,#1e7ecd); box-shadow: 0 0 0 3px rgba(30,120,205,0.08); }

/* ── Mobile ── */
.bldr-mobile-lib-btn {
    position: fixed; bottom: 20px; right: 20px;
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, #1E78CD, #389FD8); color: #fff; border: none;
    font-size: 22px; display: none; align-items: center; justify-content: center;
    box-shadow: 0 4px 16px rgba(30,120,205,0.35); cursor: pointer; z-index: 100003;
}
.bldr-mobile-overlay-bg {
    position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 100002; display: none; backdrop-filter: blur(2px);
}
.bldr-mobile-overlay-bg.show { display: block; }

@media (max-width: 991px) {
    .bldr-library-sidebar {
        position: fixed; top: 0; left: 0; bottom: 0; width: 85%; max-width: 340px;
        z-index: 100004; transform: translateX(-100%); transition: transform .3s;
        box-shadow: 4px 0 24px rgba(0,0,0,0.15);
    }
    .bldr-library-sidebar.open { transform: translateX(0); }
    .bldr-mobile-lib-btn { display: flex; }
    .bldr-title-row { flex-direction: column; gap: 10px; }
    .bldr-topbar-right .bldr-save-draft-btn span { display: none; }
    .bldr-topbar-right .bldr-save-draft-btn { padding: 7px 10px; }
}
@media (min-width: 992px) {
    .bldr-mobile-lib-btn { display: none !important; }
    .bldr-mobile-overlay-bg { display: none !important; }
}
@media (max-width: 768px) {
    .bldr-overlay-topbar { padding: 0 12px; height: 48px; min-height: 48px; }
    .bldr-coll-item img { display: none; }
    .bldr-coll-item .bldr-coll-form { display: none !important; }
    .bldr-coll-bar { border-top: none; }
    .bldr-coll-actions button[title="Move up"],
    .bldr-coll-actions button[title="Move down"] { display: none; }
    .bldr-form-badge { display: none; }
    .bldr-collection-area { padding: 12px; }
}

/* ── Scrollbars ── */
.bldr-library-body::-webkit-scrollbar, .bldr-collection-area::-webkit-scrollbar { width: 4px; }
.bldr-library-body::-webkit-scrollbar-track, .bldr-collection-area::-webkit-scrollbar-track { background: transparent; }
.bldr-library-body::-webkit-scrollbar-thumb, .bldr-collection-area::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.bldr-library-body::-webkit-scrollbar-thumb:hover, .bldr-collection-area::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
</style>

<!-- Toast -->
<div class="bldr-toast" id="bldr-toast"></div>

<?php if ($is_admin): ?>
<!-- Save as Template Popup -->
<div class="sat-overlay" id="sat-overlay" onclick="if(event.target===this)satClose()">
    <div class="sat-popup">
        <h5><i class="bx bx-bookmark-plus"></i> Save as Template</h5>
        <input type="hidden" id="sat-page-id" value="">
        <div class="sat-field">
            <label>Template Name</label>
            <input type="text" id="sat-name" placeholder="e.g. Shop Page, Landing Page">
        </div>
        <div class="sat-field">
            <label>Save Under Plan</label>
            <select id="sat-plan">
                <option value="website">Website</option>
                <option value="ecommerce">Ecommerce</option>
                <option value="membership">Membership</option>
                <option value="subscription">Subscription</option>
            </select>
        </div>
        <div class="sat-field">
            <label>Page Type</label>
            <select id="sat-page-type">
                <option value="Home">Home</option>
                <option value="Services">Services</option>
                <option value="Features">Features</option>
                <option value="Pricing">Pricing</option>
                <option value="Contact Us">Contact Us</option>
                <option value="Shop">Shop</option>
                <option value="Product">Product</option>
                <option value="Cart">Cart</option>
                <option value="Checkout">Checkout</option>
                <option value="Account">Account</option>
                <option value="Custom">Custom</option>
            </select>
        </div>
        <div class="sat-actions">
            <button class="btn btn-outline-secondary" onclick="satClose()">Cancel</button>
            <button class="btn btn-primary" id="sat-save-btn" onclick="satSave()">
                <i class="bx bx-save me-1"></i> Save as Template
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- FEEDBACK VIEWER OVERLAY                                  -->
<!-- ═══════════════════════════════════════════════════════ -->
<div id="fb-viewer" style="display:none;">
    <!-- Top Bar -->
    <div class="fb-topbar">
        <div class="fb-topbar-left">
            <button class="fb-back-btn" onclick="fbCloseViewer()"><i class="bx bx-arrow-back"></i> Back</button>
            <h5 class="fb-topbar-title" id="fb-page-title">Page Feedback</h5>
        </div>
        <div class="fb-topbar-right">
            <span class="fb-counter" id="fb-counter">0 open / 0 resolved</span>
            <label class="fb-mode-toggle" id="fb-mode-label">
                <input type="checkbox" id="fb-pin-mode" onchange="fbTogglePinMode(this.checked)">
                <span class="fb-mode-slider"></span>
                <span class="fb-mode-text">Pin Mode</span>
            </label>
            <button class="fb-upload-btn" onclick="document.getElementById('fb-image-input').click()" title="Upload image for collaboration">
                <i class="bx bx-image-add"></i> Upload Image
            </button>
            <input type="file" id="fb-image-input" accept="image/*" style="display:none;" onchange="fbUploadImage(this)">
            <select class="fb-filter" id="fb-filter" onchange="fbFilterComments(this.value)">
                <option value="all">All Comments</option>
                <option value="open">Open Only</option>
                <option value="resolved">Resolved Only</option>
            </select>
        </div>
    </div>
    <!-- Mobile Tab Bar -->
    <div class="fb-tab-bar" id="fb-tab-bar">
        <button class="active" onclick="fbSwitchTab('preview')"><i class="bx bx-image"></i> Preview</button>
        <button onclick="fbSwitchTab('comments')"><i class="bx bx-message-dots"></i> Comments <span class="fb-tab-badge" id="fb-tab-badge" style="display:none;">0</span></button>
    </div>
    <!-- Body -->
    <div class="fb-body">
        <!-- Page Preview with pins -->
        <div class="fb-preview-area" id="fb-preview-area">
            <div class="fb-page-content" id="fb-page-content">
                <p class="text-muted text-center py-5">Loading page...</p>
            </div>
            <!-- Pins rendered here by JS -->
        </div>
        <!-- Comments Panel -->
        <div class="fb-comments-panel" id="fb-comments-panel">
            <div class="fb-panel-header">
                <i class="bx bx-message-dots"></i> Comments
                <span class="fb-panel-count" id="fb-panel-count">0</span>
            </div>
            <div class="fb-panel-body" id="fb-panel-body">
                <div class="fb-panel-empty" id="fb-panel-empty">
                    <i class="bx bx-pointer"></i>
                    <p>Enable Pin Mode and click on the page to leave feedback</p>
                </div>
                <div id="fb-comments-list"></div>
            </div>
        </div>
    </div>
    <!-- New comment popover (shown when clicking on page in pin mode) -->
    <div class="fb-popover" id="fb-popover" style="display:none;">
        <div class="fb-popover-arrow"></div>
        <textarea id="fb-popover-text" placeholder="Leave your feedback..." rows="3"></textarea>
        <div class="fb-popover-attach" id="fb-popover-attach">
            <input type="file" id="fb-popover-file" accept="image/*" style="display:none;" onchange="fbPopoverFileSelected(this)">
            <button type="button" class="fb-popover-attach-btn" onclick="document.getElementById('fb-popover-file').click()" title="Attach image"><i class="bx bx-image-add"></i></button>
            <span class="fb-popover-filename" id="fb-popover-filename"></span>
        </div>
        <div id="fb-popover-preview" style="display:none;margin-top:6px;">
            <img id="fb-popover-preview-img" src="" style="max-width:100%;max-height:120px;border-radius:4px;border:1px solid #e5e7eb;">
            <button type="button" class="fb-popover-remove-img" onclick="fbPopoverRemoveFile()" style="margin-left:6px;background:none;border:none;color:#d00;cursor:pointer;font-size:14px;" title="Remove"><i class="bx bx-x"></i></button>
        </div>
        <div class="fb-popover-actions">
            <button class="fb-popover-cancel" onclick="fbCancelPin()">Cancel</button>
            <button class="fb-popover-submit" onclick="fbSubmitPin()"><i class="bx bx-send"></i> Post</button>
        </div>
    </div>

    <!-- Bottom sheet for viewing a single comment (mobile) -->
    <div class="fb-sheet-overlay" id="fb-sheet-overlay" style="display:none;" onclick="fbCloseSheet()"></div>
    <div class="fb-sheet" id="fb-sheet" style="display:none;">
        <div class="fb-sheet-drag" id="fb-sheet-drag"><div class="fb-sheet-handle"></div></div>
        <div class="fb-sheet-header">
            <span class="fb-sheet-num" id="fb-sheet-num">1</span>
            <span class="fb-sheet-author" id="fb-sheet-author"></span>
            <span class="fb-sheet-time" id="fb-sheet-time"></span>
            <button class="fb-sheet-close" onclick="fbCloseSheet()"><i class="bx bx-x"></i></button>
        </div>
        <div class="fb-sheet-body" id="fb-sheet-body">
            <div class="fb-sheet-text" id="fb-sheet-text"></div>
            <div class="fb-sheet-actions" id="fb-sheet-actions"></div>
            <div class="fb-sheet-replies" id="fb-sheet-replies"></div>
            <div class="fb-sheet-reply-input">
                <input type="text" placeholder="Reply..." id="fb-sheet-reply-input" onkeydown="if(event.key==='Enter')fbSheetReply()">
                <button onclick="fbSheetReply()">Reply</button>
            </div>
        </div>
    </div>
</div>

<style>
/* ═══ FEEDBACK VIEWER ═══════════════════════════════════ */
#fb-viewer {
    position: fixed; inset: 0; z-index: 100001;
    background: #f5f5f9; overflow: hidden;
    display: flex; flex-direction: column;
    font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
}
.fb-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 20px; background: linear-gradient(135deg, #1E78CD, #389FD8);
    border-bottom: none;
    flex-shrink: 0; gap: 12px; box-shadow: 0 2px 8px rgba(30,120,205,0.25);
    flex-wrap: wrap;
}
.fb-topbar-left { display: flex; align-items: center; gap: 14px; }
.fb-topbar-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.fb-back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border: 1px solid rgba(255,255,255,0.3); border-radius: 8px;
    background: rgba(255,255,255,0.15); color: #fff; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .15s;
}
.fb-back-btn:hover { background: rgba(255,255,255,0.25); }
.fb-topbar-title { margin: 0; font-size: 15px; font-weight: 700; color: #fff; }
.fb-counter {
    font-size: 12px; font-weight: 600; padding: 4px 12px;
    background: rgba(255,255,255,0.2); color: #fff; border-radius: 20px;
}
.fb-upload-btn {
    padding: 5px 12px; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px;
    font-size: 12px; font-weight: 600; color: #fff; background: rgba(30,120,205,0.5); cursor: pointer;
    display: inline-flex; align-items: center; gap: 5px; transition: 0.2s;
}
.fb-upload-btn:hover { background: rgba(30,120,205,0.8); }
.fb-upload-btn i { font-size: 15px; }
.fb-uploaded-img { max-width: 100%; border-radius: 8px; margin-top: 12px; border: 2px dashed rgba(30,120,205,0.3); padding: 4px; }
.fb-filter {
    padding: 2px 10px; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px;
    font-size: 12px; color: #fff; background: rgba(255,255,255,0.15); cursor: pointer;
}
/* Toggle switch */
.fb-mode-toggle {
    display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    font-size: 12px; font-weight: 600; color: rgba(255,255,255,0.9); user-select: none;
}
.fb-mode-toggle input { display: none; }
.fb-mode-slider {
    width: 36px; height: 20px; background: #d0d4dc; border-radius: 10px;
    position: relative; transition: background .2s;
}
.fb-mode-slider::after {
    content: ''; position: absolute; top: 2px; left: 2px;
    width: 16px; height: 16px; background: #fff; border-radius: 50%;
    transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.fb-mode-toggle input:checked + .fb-mode-slider { background: #1E78CD; }
.fb-mode-toggle input:checked + .fb-mode-slider::after { transform: translateX(16px); }

/* Body layout */
.fb-body { flex: 1; display: flex; overflow: hidden; }
.fb-preview-area {
    flex: 1; overflow-y: auto; position: relative; cursor: default;
    background: #fff; margin: 16px; margin-right: 0; border-radius: 12px;
    border: 1px solid #e9ecef; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.fb-preview-area.pin-mode { cursor: crosshair; }
.fb-page-content { padding: 0; min-height: 400px; position: relative; overflow: visible; }
.fb-page-content img { max-width: 100%; height: auto; display: block; }

/* Pins */
.fb-pin {
    position: absolute; z-index: 10; transform: translate(-50%, -100%);
    cursor: pointer; transition: transform .15s;
}
.fb-pin:hover { transform: translate(-50%, -100%) scale(1.15); }
.fb-pin-marker {
    width: 28px; height: 28px; border-radius: 50%; display: flex;
    align-items: center; justify-content: center; font-size: 11px;
    font-weight: 700; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    border: 2px solid #fff;
}
.fb-pin-marker.open { background: #ea5455; }
.fb-pin-marker.resolved { background: #28c76f; }
.fb-pin-marker.active { box-shadow: 0 0 0 4px rgba(30,120,205,0.3), 0 2px 8px rgba(0,0,0,0.25); }
.fb-pin-tail {
    width: 2px; height: 8px; background: #fff; margin: 0 auto;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

/* Comments panel */
.fb-comments-panel {
    width: 340px; min-width: 340px; background: #fff; border-left: 1px solid #e9ecef;
    display: flex; flex-direction: column; margin: 16px; margin-left: 12px;
    border-radius: 12px; border: 1px solid #e9ecef; overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.fb-panel-header {
    padding: 14px 16px; background: linear-gradient(135deg, #1E78CD, #389FD8); color: #fff;
    font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px;
    flex-shrink: 0;
}
.fb-panel-header i { font-size: 16px; }
.fb-panel-count {
    margin-left: auto; font-size: 11px; background: rgba(255,255,255,0.15);
    padding: 2px 8px; border-radius: 10px;
}
.fb-panel-body { flex: 1; overflow-y: auto; padding: 0; }
.fb-panel-empty {
    text-align: center; padding: 40px 20px; color: #a1a1aa;
}
.fb-panel-empty i { font-size: 2.5rem; display: block; margin-bottom: 8px; opacity: 0.4; }
.fb-panel-empty p { font-size: 13px; margin: 0; }

/* Comment card */
.fb-comment {
    padding: 14px 16px; border-bottom: 1px solid #f1f5f9;
    cursor: pointer; transition: background .15s;
}
.fb-comment:hover { background: #f8f9fb; }
.fb-comment.active { background: #e7f0ff; }
.fb-comment.resolved-comment { opacity: 0.6; }
.fb-comment-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.fb-comment-num {
    width: 22px; height: 22px; border-radius: 50%; font-size: 10px; font-weight: 700;
    color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.fb-comment-num.open { background: #ea5455; }
.fb-comment-num.resolved { background: #28c76f; }
.fb-comment-author { font-size: 12px; font-weight: 700; color: #2b2c40; }
.fb-comment-time { font-size: 11px; color: #a1a1aa; margin-left: auto; }
.fb-comment-text { font-size: 13px; color: #555; line-height: 1.5; margin-bottom: 8px; }
.fb-comment-actions { display: flex; gap: 6px; }
.fb-comment-actions button {
    padding: 2px 10px; font-size: 11px; border: 1px solid #e9ecef; border-radius: 5px;
    background: #fff; cursor: pointer; transition: all .15s; font-weight: 600;
}
.fb-comment-actions button:hover { background: #f5f5f9; }
.fb-comment-actions .fb-resolve-btn { color: #28c76f; }
.fb-comment-actions .fb-resolve-btn:hover { background: #ecfdf5; border-color: #28c76f; }
.fb-comment-actions .fb-reopen-btn { color: #ff9f43; }
.fb-comment-actions .fb-reopen-btn:hover { background: #fff7ed; border-color: #ff9f43; }
.fb-comment-actions .fb-delete-btn { color: #ea5455; }
.fb-comment-actions .fb-delete-btn:hover { background: #fff5f5; border-color: #ea5455; }

/* Reply section */
.fb-replies { margin-top: 8px; padding-left: 30px; }
.fb-reply { padding: 8px 0; border-top: 1px solid #f5f5f9; }
.fb-reply-header { display: flex; align-items: center; gap: 6px; margin-bottom: 3px; }
.fb-reply-author { font-size: 11px; font-weight: 700; color: #2b2c40; }
.fb-reply-time { font-size: 10px; color: #a1a1aa; }
.fb-reply-text { font-size: 12px; color: #666; line-height: 1.4; }
.fb-reply-input { display: flex; gap: 6px; margin-top: 8px; }
.fb-reply-input input {
    flex: 1; padding: 2px 10px; border: 1px solid #d9dee3; border-radius: 5px;
    font-size: 12px;
}
.fb-reply-input input:focus { outline: none; border-color: #1E78CD; }
.fb-reply-input button {
    padding: 5px 12px; border: none; background: #1E78CD; color: #fff;
    border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer;
}

/* New comment popover */
.fb-popover {
    position: absolute; z-index: 1000; background: #fff; border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18); padding: 12px; width: 280px;
    border: 1px solid #e9ecef;
}
.fb-popover textarea {
    width: 100%; padding: 8px 10px; border: 1px solid #d9dee3; border-radius: 6px;
    font-size: 13px; font-family: inherit; resize: none;
}
.fb-popover textarea:focus { outline: none; border-color: #1E78CD; }
.fb-popover-attach { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
.fb-popover-attach-btn { background: none; border: 1px solid #d9dee3; border-radius: 4px; padding: 4px 8px; cursor: pointer; color: #566a7f; font-size: 16px; line-height: 1; }
.fb-popover-attach-btn:hover { background: #f0f4ff; border-color: #1E78CD; color: #1E78CD; }
.fb-popover-filename { font-size: 11px; color: #888; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px; }
.fb-comment-attachment { margin-top: 6px; }
.fb-comment-attachment img { max-width: 100%; max-height: 160px; border-radius: 6px; border: 1px solid #e5e7eb; cursor: pointer; }
.fb-comment-attachment img:hover { border-color: #1E78CD; }
.fb-popover-actions { display: flex; justify-content: flex-end; gap: 6px; margin-top: 8px; }
.fb-popover-cancel {
    padding: 6px 14px; border: 1px solid #e9ecef; border-radius: 6px;
    background: #fff; color: #566a7f; font-size: 12px; font-weight: 600; cursor: pointer;
}
.fb-popover-submit {
    padding: 6px 14px; border: none; border-radius: 6px;
    background: #1E78CD; color: #fff; font-size: 12px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; gap: 4px;
}
.fb-popover-submit:hover { background: #1a6abf; }

@keyframes fbPulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.3); } }

/* Mobile tab bar */
.fb-tab-bar { display: none; }
.fb-tab-bar button {
    flex: 1; padding: 10px; border: none; background: #fff; font-size: 13px;
    font-weight: 600; color: #6f6b7d; cursor: pointer; position: relative;
    border-bottom: 2px solid transparent; transition: all .15s;
}
.fb-tab-bar button.active { color: #1E78CD; border-bottom-color: #1E78CD; }
.fb-tab-bar button .fb-tab-badge {
    font-size: 10px; background: #ea5455; color: #fff; padding: 1px 6px;
    border-radius: 10px; margin-left: 4px; font-weight: 700;
}

/* Responsive */
@media (max-width: 768px) {
    .fb-body { flex-direction: column; position: relative; }
    .fb-tab-bar { display: flex; background: #fff; border-bottom: 1px solid #e9ecef; flex-shrink: 0; }
    .fb-preview-area { margin: 0; border-radius: 0; border: none; flex: 1; }
    .fb-preview-area.fb-hidden-tab { display: none; }
    .fb-comments-panel {
        width: 100%; min-width: 100%; margin: 0; border-radius: 0; border: none;
        flex: 1; overflow-y: auto;
    }
    .fb-comments-panel.fb-hidden-tab { display: none; }
    .fb-topbar { padding: 8px 12px; }
    .fb-topbar-left { gap: 8px; }
    .fb-topbar-right { gap: 6px; }
    .fb-topbar-title { font-size: 13px; }
    .fb-counter { display: none !important; }
    .fb-filter { display: none !important; }
    .fb-mode-toggle { order: -1; }
    .fb-popover {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        top: auto !important;
        width: 100% !important;
        border-radius: 14px 14px 0 0;
        box-shadow: 0 -4px 24px rgba(0,0,0,0.18);
        padding: 16px;
        z-index: 100010;
    }
    .fb-popover textarea { font-size: 15px; padding: 10px 12px; }
    .fb-popover-actions { margin-top: 10px; }
    .fb-popover-cancel, .fb-popover-submit { padding: 10px 20px; font-size: 14px; }
    .fb-popover-arrow { display: none; }
    .fb-pin { pointer-events: auto; }
}

/* ═══ BOTTOM SHEET (mobile comment viewer) ═══════════════ */
.fb-sheet-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.3);
    z-index: 100011; transition: opacity .2s;
}
.fb-sheet {
    position: fixed; left: 0; right: 0; bottom: 0;
    z-index: 100012; background: #fff;
    border-radius: 16px 16px 0 0;
    box-shadow: 0 -4px 30px rgba(0,0,0,0.2);
    max-height: 80vh; min-height: 25vh;
    display: flex; flex-direction: column;
    transition: transform .25s cubic-bezier(0.4, 0, 0.2, 1);
    will-change: transform;
}
.fb-sheet-drag {
    display: flex; justify-content: center; padding: 10px 0 4px;
    cursor: grab; touch-action: none;
}
.fb-sheet-handle {
    width: 36px; height: 4px; border-radius: 2px; background: #d0d5dd;
}
.fb-sheet-header {
    display: flex; align-items: center; gap: 8px;
    padding: 4px 16px 10px; border-bottom: 1px solid #f0f0f4;
}
.fb-sheet-num {
    width: 26px; height: 26px; border-radius: 50%;
    background: #ea5455; color: #fff; font-size: 12px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.fb-sheet-num.resolved { background: #28c76f; }
.fb-sheet-author { font-weight: 600; font-size: 14px; color: #2b2c40; }
.fb-sheet-time { font-size: 12px; color: #a5a3ae; margin-left: auto; }
.fb-sheet-close {
    border: none; background: none; font-size: 22px; color: #6f6b7d;
    cursor: pointer; padding: 0 0 0 8px; line-height: 1;
}
.fb-sheet-body {
    padding: 12px 16px 16px; overflow-y: auto; flex: 1;
}
.fb-sheet-text { font-size: 14px; color: #2b2c40; line-height: 1.5; margin-bottom: 10px; }
.fb-sheet-actions { display: flex; gap: 8px; margin-bottom: 12px; }
.fb-sheet-actions button {
    padding: 5px 14px; border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; gap: 4px;
}
.fb-sheet-actions .fb-resolve-btn {
    border: 1px solid #28c76f; background: #e8f8ef; color: #28c76f;
}
.fb-sheet-actions .fb-reopen-btn {
    border: 1px solid #ff9f43; background: #fff5eb; color: #ff9f43;
}
.fb-sheet-actions .fb-delete-btn {
    border: 1px solid #ea5455; background: #fde8e8; color: #ea5455;
}
.fb-sheet-replies { margin-bottom: 10px; }
.fb-sheet-replies .fb-reply {
    padding: 8px 0; border-bottom: 1px solid #f5f5f9;
}
.fb-sheet-replies .fb-reply-header {
    display: flex; align-items: center; gap: 6px; margin-bottom: 3px;
}
.fb-sheet-replies .fb-reply-author { font-weight: 600; font-size: 13px; color: #2b2c40; }
.fb-sheet-replies .fb-reply-time { font-size: 11px; color: #a5a3ae; }
.fb-sheet-replies .fb-reply-text { font-size: 13px; color: #566a7f; }
.fb-sheet-reply-input {
    display: flex; gap: 8px; padding-top: 8px; border-top: 1px solid #f0f0f4;
}
.fb-sheet-reply-input input {
    flex: 1; padding: 8px 12px; border: 1px solid #d9dee3; border-radius: 6px;
    font-size: 14px; font-family: inherit;
}
.fb-sheet-reply-input input:focus { outline: none; border-color: #1E78CD; }
.fb-sheet-reply-input button {
    padding: 8px 16px; border: none; background: #1E78CD; color: #fff;
    border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;
}
@media (min-width: 769px) {
    .fb-sheet, .fb-sheet-overlay { display: none !important; }
}
</style>

<script>
(function() {
    'use strict';

    /* ── Config ─────────────────────────────────────────── */
    var AJAX_URL = '<?php echo admin_url("admin-ajax.php"); ?>';
    var bldProxyUrl = '<?php echo esc_js(rest_url("cpp-proxy/v1/ajax")); ?>';
    var bldRestNonce = '<?php echo wp_create_nonce("wp_rest"); ?>';
    function bldProxy(fd) {
        var params = {};
        if (fd instanceof FormData) { fd.forEach(function(v,k){ params[k] = v; }); }
        else { params = fd; }
        var qs = Object.keys(params).map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch(bldProxyUrl + '?' + qs, {
            method: 'PUT', credentials: 'same-origin',
            headers: { 'X-WP-Nonce': bldRestNonce }
        });
    }
    var REST_BASE = '<?php echo esc_url(rest_url("cpp-builder/v1")); ?>';
    var NONCE    = '<?php echo $builder_nonce; ?>';
    var PRESET_NONCE = '<?php echo $preset_nonce; ?>';
    var IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
    var _restNonceCache = null;

    function getRestNonce() {
        if (_restNonceCache) return Promise.resolve(_restNonceCache);
        return fetch(AJAX_URL + '?action=rest-nonce', { credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(n) { _restNonceCache = n.trim(); return _restNonceCache; });
    }

    function bldrRestGet(path, cb) {
        getRestNonce().then(function(nonce) {
            return fetch(REST_BASE + path, {
                method: 'GET', credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(r) {
            if (r.success) cb(null, r.data);
            else cb(r.message || 'Error');
        })
        .catch(function(e) { cb(e.message || 'Network error'); });
    }

    function bldrRestCall(method, path, body, cb) {
        getRestNonce().then(function(nonce) {
            return fetch(REST_BASE + path, {
                method: method, credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(r) {
            if (r.success) cb(null, r.data);
            else cb(r.message || 'Error');
        })
        .catch(function(e) { cb(e.message || 'Network error'); });
    }

    /* ── State ──────────────────────────────────────────── */
    var currentPageId = null;
    var currentBlocks = [];   // [{id, url, name, category, form_data:{}, timestamp}]
    var editingBlockIndex = null;
    var libraryLoaded = false;

    /* ── Block Type Definitions (for content forms) ────── */
    var BLOCK_TYPES = {
        hero: {
            label: 'Hero',
            icon: 'bx bx-star',
            fields: [
                { key: 'title', label: 'Title', type: 'text', placeholder: 'Welcome to Our Site' },
                { key: 'subtitle', label: 'Subtitle', type: 'textarea', placeholder: 'A brief description...' },
                { key: 'bg_image', label: 'Background Image URL', type: 'text', placeholder: 'https://...' },
                { key: 'cta_text', label: 'CTA Button Text', type: 'text', placeholder: 'Get Started' },
                { key: 'cta_link', label: 'CTA Button Link', type: 'text', placeholder: 'https://...' }
            ],
            preview: function(c) { return (c.title || 'Untitled Hero') + (c.subtitle ? ' — ' + c.subtitle.substring(0, 60) : ''); }
        },
        features: {
            label: 'Features',
            icon: 'bx bx-grid-alt',
            fields: [
                { key: 'title', label: 'Section Title', type: 'text', placeholder: 'Our Features' },
                { key: 'item1_icon', label: 'Feature 1 Icon (boxicon class)', type: 'text', placeholder: 'bx bx-rocket' },
                { key: 'item1_title', label: 'Feature 1 Title', type: 'text', placeholder: 'Fast Performance' },
                { key: 'item1_desc', label: 'Feature 1 Description', type: 'textarea', placeholder: 'Description...' },
                { key: 'item2_icon', label: 'Feature 2 Icon', type: 'text', placeholder: 'bx bx-shield' },
                { key: 'item2_title', label: 'Feature 2 Title', type: 'text', placeholder: 'Secure' },
                { key: 'item2_desc', label: 'Feature 2 Description', type: 'textarea', placeholder: 'Description...' },
                { key: 'item3_icon', label: 'Feature 3 Icon', type: 'text', placeholder: 'bx bx-support' },
                { key: 'item3_title', label: 'Feature 3 Title', type: 'text', placeholder: '24/7 Support' },
                { key: 'item3_desc', label: 'Feature 3 Description', type: 'textarea', placeholder: 'Description...' }
            ],
            preview: function(c) { return (c.title || 'Features') + ' — ' + [c.item1_title, c.item2_title, c.item3_title].filter(Boolean).join(', '); }
        },
        pricing: {
            label: 'Pricing',
            icon: 'bx bx-dollar-circle',
            fields: [
                { key: 'title', label: 'Section Title', type: 'text', placeholder: 'Pricing Plans' },
                { key: 'plan1_name', label: 'Plan 1 Name', type: 'text', placeholder: 'Basic' },
                { key: 'plan1_price', label: 'Plan 1 Price', type: 'text', placeholder: '$9/mo' },
                { key: 'plan1_features', label: 'Plan 1 Features (one per line)', type: 'textarea', placeholder: 'Feature A\nFeature B' },
                { key: 'plan2_name', label: 'Plan 2 Name', type: 'text', placeholder: 'Pro' },
                { key: 'plan2_price', label: 'Plan 2 Price', type: 'text', placeholder: '$29/mo' },
                { key: 'plan2_features', label: 'Plan 2 Features (one per line)', type: 'textarea', placeholder: 'Feature A\nFeature B\nFeature C' },
                { key: 'plan3_name', label: 'Plan 3 Name', type: 'text', placeholder: 'Enterprise' },
                { key: 'plan3_price', label: 'Plan 3 Price', type: 'text', placeholder: '$99/mo' },
                { key: 'plan3_features', label: 'Plan 3 Features (one per line)', type: 'textarea', placeholder: 'Everything in Pro\nPriority Support' }
            ],
            preview: function(c) { return (c.title || 'Pricing') + ' — ' + [c.plan1_name, c.plan2_name, c.plan3_name].filter(Boolean).join(', '); }
        },
        contact: {
            label: 'Contact',
            icon: 'bx bx-envelope',
            fields: [
                { key: 'title', label: 'Title', type: 'text', placeholder: 'Get in Touch' },
                { key: 'subtitle', label: 'Subtitle', type: 'textarea', placeholder: 'We\'d love to hear from you...' }
            ],
            preview: function(c) { return (c.title || 'Contact') + (c.subtitle ? ' — ' + c.subtitle.substring(0, 60) : ''); }
        },
        gallery: {
            label: 'Gallery',
            icon: 'bx bx-images',
            fields: [
                { key: 'title', label: 'Section Title', type: 'text', placeholder: 'Our Gallery' },
                { key: 'image1', label: 'Image 1 URL', type: 'text', placeholder: 'https://...' },
                { key: 'image2', label: 'Image 2 URL', type: 'text', placeholder: 'https://...' },
                { key: 'image3', label: 'Image 3 URL', type: 'text', placeholder: 'https://...' },
                { key: 'image4', label: 'Image 4 URL', type: 'text', placeholder: 'https://...' },
                { key: 'image5', label: 'Image 5 URL', type: 'text', placeholder: 'https://...' },
                { key: 'image6', label: 'Image 6 URL', type: 'text', placeholder: 'https://...' }
            ],
            preview: function(c) { var n = ['image1','image2','image3','image4','image5','image6'].filter(function(k){ return c[k]; }).length; return (c.title || 'Gallery') + ' — ' + n + ' image(s)'; }
        },
        testimonials: {
            label: 'Testimonials',
            icon: 'bx bx-conversation',
            fields: [
                { key: 'title', label: 'Section Title', type: 'text', placeholder: 'What Our Clients Say' },
                { key: 'person1_name', label: 'Person 1 Name', type: 'text', placeholder: 'John Doe' },
                { key: 'person1_quote', label: 'Person 1 Quote', type: 'textarea', placeholder: 'Great service...' },
                { key: 'person2_name', label: 'Person 2 Name', type: 'text', placeholder: 'Jane Smith' },
                { key: 'person2_quote', label: 'Person 2 Quote', type: 'textarea', placeholder: 'Highly recommend...' },
                { key: 'person3_name', label: 'Person 3 Name', type: 'text', placeholder: 'Bob Wilson' },
                { key: 'person3_quote', label: 'Person 3 Quote', type: 'textarea', placeholder: 'Excellent work...' }
            ],
            preview: function(c) { return (c.title || 'Testimonials') + ' — ' + [c.person1_name, c.person2_name, c.person3_name].filter(Boolean).join(', '); }
        },
        faq: {
            label: 'FAQ',
            icon: 'bx bx-help-circle',
            fields: [
                { key: 'title', label: 'Section Title', type: 'text', placeholder: 'Frequently Asked Questions' },
                { key: 'q1', label: 'Question 1', type: 'text', placeholder: 'How do I get started?' },
                { key: 'a1', label: 'Answer 1', type: 'textarea', placeholder: 'Simply sign up...' },
                { key: 'q2', label: 'Question 2', type: 'text', placeholder: 'What is your pricing?' },
                { key: 'a2', label: 'Answer 2', type: 'textarea', placeholder: 'We offer...' },
                { key: 'q3', label: 'Question 3', type: 'text', placeholder: '' },
                { key: 'a3', label: 'Answer 3', type: 'textarea', placeholder: '' },
                { key: 'q4', label: 'Question 4', type: 'text', placeholder: '' },
                { key: 'a4', label: 'Answer 4', type: 'textarea', placeholder: '' },
                { key: 'q5', label: 'Question 5', type: 'text', placeholder: '' },
                { key: 'a5', label: 'Answer 5', type: 'textarea', placeholder: '' }
            ],
            preview: function(c) { var n = ['q1','q2','q3','q4','q5'].filter(function(k){ return c[k]; }).length; return (c.title || 'FAQ') + ' — ' + n + ' question(s)'; }
        },
        cta: {
            label: 'Call to Action',
            icon: 'bx bx-bullseye',
            fields: [
                { key: 'title', label: 'Title', type: 'text', placeholder: 'Ready to Get Started?' },
                { key: 'description', label: 'Description', type: 'textarea', placeholder: 'Join thousands of satisfied customers...' },
                { key: 'button_text', label: 'Button Text', type: 'text', placeholder: 'Start Now' },
                { key: 'button_link', label: 'Button Link', type: 'text', placeholder: 'https://...' }
            ],
            preview: function(c) { return (c.title || 'CTA') + (c.description ? ' — ' + c.description.substring(0, 60) : ''); }
        }
    };

    /* ══════════════════════════════════════════════════════
       OVERLAY BUILDER - NEW SCROLLING DESIGN
       ══════════════════════════════════════════════════════ */

    /* ── Portal Sidebar Toggle ─────────────────────────── */
    window.bldrToggleSidebar = function() {
        document.getElementById('cpp-sidebar').classList.toggle('mobile-open');
        document.getElementById('cpp-mobile-overlay').classList.toggle('show');
    };
    window.bldrCloseSidebar = function() {
        document.getElementById('cpp-sidebar').classList.remove('mobile-open');
        document.getElementById('cpp-mobile-overlay').classList.remove('show');
    };

    /* ── Toast ──────────────────────────────────────────── */
    function showToast(msg, type) {
        var t = document.getElementById('bldr-toast');
        t.textContent = msg;
        t.className = 'bldr-toast show' + (type ? ' ' + type : '');
        setTimeout(function() { t.classList.remove('show'); }, 3000);
    }

    /* ── AJAX Helper ────────────────────────────────────── */
    function bldrAjax(action, data, cb) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', NONCE);
        for (var k in data) {
            if (data.hasOwnProperty(k)) fd.append(k, data[k]);
        }
        bldProxy(fd)
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (r.success) { cb(null, r.data); }
                else { cb(r.data ? r.data.message || 'Error' : 'Error'); }
            })
            .catch(function(e) { cb(e.message || 'Network error'); });
    }

    /* ── Pages List ─────────────────────────────────────── */
    function refreshPagesList() {
        bldrRestGet('/pages', function(err, data) {
            if (err) { showToast('Error: ' + err, 'error'); return; }
            var tbody = document.getElementById('bldr-pages-list');
            if (!data.pages || data.pages.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No pages yet. Click "New Page" to get started.</td></tr>';
                return;
            }
            var html = '';
            data.pages.forEach(function(pg) {
                var blocks = [];
                try { blocks = JSON.parse(pg.blocks_json || '[]'); } catch(e) {}
                var bc = Array.isArray(blocks) ? blocks.length : 0;
                var statusBadge = pg.status === 'published'
                    ? '<span class="badge bg-label-success">Published</span>'
                    : '<span class="badge bg-label-warning">Draft</span>';
                var d = new Date(pg.updated_at);
                var dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                html += '<tr>'
                    + '<td><strong>' + escHtml(pg.title) + '</strong><br><small class="text-muted">/' + escHtml(pg.slug) + '</small></td>'
                    + '<td><span class="badge bg-label-primary">' + bc + ' blocks</span></td>'
                    + '<td><span class="fb-count-cell" data-page-id="' + pg.id + '"><span class="badge bg-label-secondary">--</span></span></td>'
                    + '<td>' + statusBadge + '</td>'
                    + '<td>' + dateStr + '</td>'
                    + '<td>'
                    + '<button class="btn btn-sm btn-outline-primary bldr-action-btn" onclick="fbOpenViewer(' + pg.id + ', \'' + escHtml(pg.title).replace(/'/g, "\\'") + '\')" title="Comment"><i class="bx bx-comment-detail"></i><span> Comment</span></button> '
                    + '<button class="btn btn-sm btn-outline-warning bldr-action-btn" onclick="fbOpenViewerPinMode(' + pg.id + ', \'' + escHtml(pg.title).replace(/'/g, "\\'") + '\')" title="Pinpoint"><i class="bx bx-map-pin"></i><span> Pinpoint</span></button> '
                    + '<button class="btn btn-sm btn-outline-success bldr-action-btn" onclick="bldrEditPage(' + pg.id + ')" title="Brief Builder"><i class="bx bx-edit-alt"></i><span> Edit</span></button> '
                    + (IS_ADMIN ? '<button class="btn btn-sm btn-icon btn-outline-success" onclick="satOpen(' + pg.id + ', \'' + escHtml(pg.title).replace(/'/g, "\\'") + '\')" title="Save as Template"><i class="bx bx-bookmark-plus"></i></button> ' : '')
                    + '<button class="btn btn-sm btn-icon btn-outline-danger" onclick="bldrDeletePage(' + pg.id + ', \'' + escHtml(pg.title).replace(/'/g, "\\'") + '\')"><i class="bx bx-trash"></i></button>'
                    + '</td>'
                    + '</tr>';
            });
            tbody.innerHTML = html;
        });
    }

    /* ── Open Overlay Builder ───────────────────────────── */
    function openOverlay(title, isEdit) {
        var overlay = document.getElementById('bldr-overlay');
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        document.getElementById('bldr-overlay-title').textContent = title || 'New Page';
        document.getElementById('bldr-overlay-slug').textContent = '';

        // Load block library
        if (!libraryLoaded) {
            loadBlockLibrary();
        } else {
            refreshLibraryMarks();
        }
        renderCollection();
        updateBlockCount();
    }

    window.bldrCloseOverlay = function() {
        document.getElementById('bldr-overlay').style.display = 'none';
        document.body.style.overflow = '';
        document.getElementById('bldr-list-view').style.display = '';
        refreshPagesList();
    };

    /* ── Filter pages grid ──────────────────────────────── */
    window.bldrFilter = function(status, btn) {
        document.querySelectorAll('.bldr-filter-btn').forEach(function(b) { b.classList.remove('active'); });
        if (btn) btn.classList.add('active');
        document.querySelectorAll('.bldr-page-card').forEach(function(card) {
            if (status === 'all' || card.dataset.status === status) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    };

    /* ── New Page ───────────────────────────────────────── */
    window.bldrNewPage = function() {
        currentPageId = null;
        currentBlocks = [];
        document.getElementById('bldr-page-title').value = '';
        document.getElementById('bldr-page-slug').value = '';
        document.getElementById('bldr-list-view').style.display = 'none';
        openOverlay('New Page', false);
    };

    /* ── Edit Page ──────────────────────────────────────── */
    window.bldrEditPage = function(id) {
        bldrRestGet('/page/' + id, function(err, data) {
            if (err) { showToast('Error: ' + err, 'error'); return; }
            var pg = data.page;
            currentPageId = pg.id;
            try { currentBlocks = JSON.parse(pg.blocks_json || '[]'); } catch(e) { currentBlocks = []; }
            if (!Array.isArray(currentBlocks)) currentBlocks = [];
            document.getElementById('bldr-page-title').value = pg.title || '';
            document.getElementById('bldr-page-slug').value = pg.slug || '';
            document.getElementById('bldr-list-view').style.display = 'none';
            openOverlay('Edit: ' + (pg.title || 'Untitled'), true);
        });
    };

    /* ── Delete Page ────────────────────────────────────── */
    window.bldrDeletePage = function(id, title) {
        if (!confirm('Delete page "' + title + '"? This cannot be undone.')) return;
        bldrRestCall('DELETE', '/page/' + id, {}, function(err) {
            if (err) { showToast('Error: ' + err, 'error'); return; }
            showToast('Page deleted.', 'success');
            refreshPagesList();
        });
    };

    /* ── Save as Template ─────────────────────────────── */
    window.satOpen = function(pageId, pageName) {
        if (!IS_ADMIN) return;
        document.getElementById('sat-page-id').value = pageId;
        document.getElementById('sat-name').value = pageName || '';
        document.getElementById('sat-plan').value = 'website';
        document.getElementById('sat-page-type').value = 'Home';
        document.getElementById('sat-overlay').classList.add('show');
        var sb = document.querySelector('.cpp-sidebar'); if(sb) sb.style.display = 'none';
    };

    window.satClose = function() {
        document.getElementById('sat-overlay').classList.remove('show');
        var sb = document.querySelector('.cpp-sidebar'); if(sb) sb.style.display = '';
    };

    window.satOpenFromOverlay = function() {
        if (!IS_ADMIN || !currentPageId) {
            showToast('Save the page first before saving as template.', 'warning');
            return;
        }
        var title = document.getElementById('bldr-page-title').value.trim() || 'Untitled';
        satOpen(currentPageId, title);
    };

    window.satSave = function() {
        if (!IS_ADMIN) return;
        var pageId = document.getElementById('sat-page-id').value;
        var name = document.getElementById('sat-name').value.trim();
        var planType = document.getElementById('sat-plan').value;
        var pageType = document.getElementById('sat-page-type').value;

        if (!name) { showToast('Template name is required.', 'error'); return; }

        var btn = document.getElementById('sat-save-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Saving...';

        /* First fetch the page data via REST */
        fbRestGet('/wp-json/cpp-builder/v1/page/' + pageId)
            .then(function(res) {
                if (!res.success || !res.data.page) throw new Error('Failed to load page');
                var pg = res.data.page;
                var blocks = [];
                try { blocks = JSON.parse(pg.blocks_json || '[]'); } catch(e) {}

                /* Generate content_html from blocks */
                var html = '';
                blocks.forEach(function(block) {
                    if (block.url) {
                        html += '<div style="position:relative;">';
                        html += '<img src="' + block.url + '" style="width:100%;display:block;" alt="' + (block.name || '') + '">';
                        if (block.form_data) {
                            var fd = block.form_data;
                            var hasContent = Object.keys(fd).some(function(k) { return fd[k]; });
                            if (hasContent) {
                                html += '<div style="padding:16px 20px;background:#f8f9fa;border-top:1px solid #e9ecef;">';
                                Object.keys(fd).forEach(function(k) {
                                    if (fd[k]) {
                                        html += '<div style="margin-bottom:4px;"><strong style="font-size:11px;color:#6b7280;text-transform:uppercase;">' + k.replace(/_/g, ' ') + ':</strong> <span style="font-size:13px;color:#2b2c40;">' + fd[k] + '</span></div>';
                                    }
                                });
                                html += '</div>';
                            }
                        }
                        html += '</div>';
                    }
                });

                if (!html) throw new Error('No visual content to save as template');

                /* Get first block image as thumbnail */
                var thumbnail = '';
                if (blocks.length > 0 && blocks[0].url) thumbnail = blocks[0].url;

                /* Create preset via REST PUT */
                return fbRestCall('PUT', '/wp-json/cpp-builder/v1/preset', {
                    name: name,
                    plan_type: planType,
                    page_type: pageType,
                    description: 'Created from page: ' + (pg.title || name),
                    content_html: html,
                    block_data: pg.blocks_json || '[]',
                    thumbnail_url: thumbnail
                });
            })
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bx bx-save me-1"></i> Save as Template';
                if (res.success) {
                    showToast('Template "' + name + '" saved under ' + planType + '!', 'success');
                    satClose();
                } else {
                    showToast(res.message || 'Failed to save template.', 'error');
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bx bx-save me-1"></i> Save as Template';
                showToast(err.message || 'Network error.', 'error');
            });
    };

    /* ── Save Page ──────────────────────────────────────── */
    window.bldrSavePage = function(status) {
        var titleEl = document.getElementById('bldr-page-title');
        var title = titleEl.value.trim();
        var slug = document.getElementById('bldr-page-slug').value.trim();
        if (!title) {
            showToast('Please enter a page title before saving.', 'warning');
            titleEl.style.border = '2px solid #ea5455';
            titleEl.focus();
            try { titleEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(e) {}
            setTimeout(function(){ titleEl.style.border = ''; }, 2500);
            return;
        }
        if (!slug) slug = slugify(title);

        var payload = {
            title: title, slug: slug, status: status,
            blocks_json: JSON.stringify(currentBlocks)
        };
        if (currentPageId) payload.page_id = currentPageId;

        bldrRestCall('PUT', '/pages', payload, function(err, data) {
            if (err) { showToast('Error: ' + err, 'error'); return; }
            currentPageId = data.id;
            showToast(status === 'published' ? 'Page published!' : 'Draft saved.', 'success');
            /* Close editor and return to page list after brief delay */
            setTimeout(function() { bldrCloseOverlay(); }, 600);
        });
    };

    /* ═══════════════════════════════════════════════
       BLOCK LIBRARY (Left Sidebar)
       ═══════════════════════════════════════════════ */

    var allLibFolders = []; // cached folder list for chips

    function loadBlockLibrary() {
        var body = document.getElementById('bldr-library-body');
        var catsEl = document.getElementById('bldr-lib-cats');
        body.innerHTML = '<div class="bldr-lib-loading"><div class="bldr-spinner"></div><p>Loading blocks...</p></div>';
        catsEl.innerHTML = '';

        bldrRestGet('/assets?folder=', function(err, data) {
            if (err) {
                body.innerHTML = '<div style="padding:20px;color:#ea5455;text-align:center;">Error: ' + escHtml(err) + '</div>';
                return;
            }

            var folders = data.folders || [];
            var files = data.files || [];
            allLibFolders = folders;
            libraryLoaded = true;

            if (folders.length === 0 && files.length === 0) {
                body.innerHTML = '<div style="text-align:center;padding:40px 16px;color:#a1a1aa;">'
                    + '<i class="bx bx-folder-open" style="font-size:2.5rem;display:block;margin-bottom:8px;"></i>'
                    + '<div>No blocks found</div>'
                    + '<div style="font-size:0.8rem;margin-top:4px;">Upload images to wp-content/uploads/briefsync-builder/</div>'
                    + '</div>';
                return;
            }

            // Render category chips
            var chipsHtml = '<span class="bldr-lib-cat-chip active" data-cat="all" onclick="bldrFilterCat(this,\'all\')">All</span>';
            folders.forEach(function(f) {
                chipsHtml += '<span class="bldr-lib-cat-chip" data-cat="' + escAttr(f.name) + '" onclick="bldrFilterCat(this,\'' + escAttr(f.name) + '\')">'
                    + escHtml(f.name) + ' <span class="chip-count">' + f.count + '</span></span>';
            });
            if (files.length > 0) {
                chipsHtml += '<span class="bldr-lib-cat-chip" data-cat="other" onclick="bldrFilterCat(this,\'other\')">Other <span class="chip-count">' + files.length + '</span></span>';
            }
            catsEl.innerHTML = chipsHtml;

            var html = '';

            // Render category folders as accordion sections
            folders.forEach(function(f) {
                var safeKey = f.name.replace(/[^a-z0-9]/gi, '-');
                html += '<div class="bldr-lib-category" data-catname="' + escAttr(f.name.toLowerCase()) + '">'
                    + '<div class="bldr-lib-cat-header collapsed" data-target="bldr-cat-' + safeKey + '" onclick="bldrToggleCategory(this)">'
                    + '<span>' + escHtml(f.name) + ' <span class="cat-count">' + f.count + '</span></span>'
                    + '<i class="bx bx-chevron-down"></i>'
                    + '</div>'
                    + '<div class="bldr-lib-cat-body" id="bldr-cat-' + safeKey + '" data-folder="' + escAttr(f.name) + '" data-loaded="0">'
                    + '<div class="bldr-lib-loading" style="padding:16px;"><div class="bldr-spinner"></div></div>'
                    + '</div>'
                    + '</div>';
            });

            // Root-level images (not in folders)
            if (files.length > 0) {
                html += '<div class="bldr-lib-category" data-catname="other">'
                    + '<div class="bldr-lib-cat-header" data-target="bldr-cat-root" onclick="bldrToggleCategory(this)">'
                    + '<span>Other <span class="cat-count">' + files.length + '</span></span>'
                    + '<i class="bx bx-chevron-down"></i>'
                    + '</div>'
                    + '<div class="bldr-lib-cat-body" id="bldr-cat-root" style="display:block;">'
                    + '<div class="bldr-lib-items">';
                files.forEach(function(img) {
                    html += renderLibBlock(img, '');
                });
                html += '</div></div></div>';
            }

            body.innerHTML = html;
        });
    }

    /* ── Category chip filter ─────────────────────────────── */
    window.bldrFilterCat = function(chipEl, catName) {
        // Update active chip
        document.querySelectorAll('.bldr-lib-cat-chip').forEach(function(c) { c.classList.remove('active'); });
        chipEl.classList.add('active');

        var cats = document.querySelectorAll('.bldr-lib-category');
        if (catName === 'all') {
            cats.forEach(function(c) { c.style.display = ''; });
            return;
        }
        cats.forEach(function(c) {
            c.style.display = (c.getAttribute('data-catname') === catName.toLowerCase()) ? '' : 'none';
        });
        // Auto-expand the selected category
        var visibleCat = document.querySelector('.bldr-lib-category[data-catname="' + catName.toLowerCase() + '"]');
        if (visibleCat) {
            var header = visibleCat.querySelector('.bldr-lib-cat-header');
            if (header && header.classList.contains('collapsed')) {
                bldrToggleCategory(header);
            }
        }
    };

    /* ── Search blocks ────────────────────────────────────── */
    window.bldrSearchLib = function(query) {
        var q = (query || '').toLowerCase().trim();
        var blocks = document.querySelectorAll('.bldr-lib-block');
        var cats = document.querySelectorAll('.bldr-lib-category');
        if (!q) {
            blocks.forEach(function(b) { b.style.display = ''; });
            cats.forEach(function(c) { c.style.display = ''; });
            return;
        }
        cats.forEach(function(c) { c.style.display = ''; });
        blocks.forEach(function(b) {
            var name = (b.getAttribute('data-name') || '').toLowerCase();
            var cat = (b.getAttribute('data-category') || '').toLowerCase();
            b.style.display = (name.indexOf(q) !== -1 || cat.indexOf(q) !== -1) ? '' : 'none';
        });
        // Expand all categories during search
        document.querySelectorAll('.bldr-lib-cat-body').forEach(function(body) {
            body.style.display = 'block';
            if (body.getAttribute('data-loaded') === '0') {
                var folder = body.getAttribute('data-folder');
                if (folder) loadCategoryImages(folder, body);
            }
        });
        document.querySelectorAll('.bldr-lib-cat-header').forEach(function(h) { h.classList.remove('collapsed'); });
    };

    /* ── Toggle Category Accordion ─────────────────────── */
    window.bldrToggleCategory = function(header) {
        var targetId = header.getAttribute('data-target');
        var body = document.getElementById(targetId);
        var isOpen = body.style.display === 'block';

        // Collapse all others
        var allBodies = document.querySelectorAll('.bldr-lib-cat-body');
        var allHeaders = document.querySelectorAll('.bldr-lib-cat-header');
        for (var i = 0; i < allBodies.length; i++) {
            if (allBodies[i].id !== targetId) {
                allBodies[i].style.display = 'none';
                allHeaders[i].classList.add('collapsed');
            }
        }

        if (isOpen) {
            body.style.display = 'none';
            header.classList.add('collapsed');
        } else {
            body.style.display = 'block';
            header.classList.remove('collapsed');

            // Lazy-load images for this category
            if (body.getAttribute('data-loaded') === '0') {
                var folder = body.getAttribute('data-folder');
                loadCategoryImages(folder, body);
            }
        }
    };

    function loadCategoryImages(folder, container) {
        bldrRestGet('/assets?folder=' + encodeURIComponent(folder), function(err, data) {
            if (err) {
                container.innerHTML = '<div style="padding:12px;color:#ea5455;">Error loading</div>';
                return;
            }
            container.setAttribute('data-loaded', '1');
            var files = data.files || [];
            if (files.length === 0) {
                container.innerHTML = '<div style="padding:16px;text-align:center;color:#a1a1aa;font-size:12px;">No images in this category</div>';
                return;
            }
            var html = '<div class="bldr-lib-items">';
            files.forEach(function(img) {
                html += renderLibBlock(img, folder);
            });
            html += '</div>';
            container.innerHTML = html;
            refreshLibraryMarks();
        });
    }

    function renderLibBlock(img, category) {
        var isAdded = currentBlocks.some(function(b) { return b.url === img.url; });
        return '<div class="bldr-lib-block' + (isAdded ? ' added' : '') + '"'
            + ' data-url="' + escAttr(img.url) + '"'
            + ' data-name="' + escAttr(img.name) + '"'
            + ' data-category="' + escAttr(category) + '"'
            + ' title="' + escAttr(img.name) + '"'
            + ' onclick="bldrAddBlockFromLib(this)">'
            + '<img src="' + escAttr(img.url) + '" alt="' + escAttr(img.name) + '" loading="lazy">'
            + '<div class="bldr-lib-block-name">' + escHtml(img.name.replace(/\.[^.]+$/, '')) + '</div>'
            + '</div>';
    }

    function refreshLibraryMarks() {
        var blocks = document.querySelectorAll('.bldr-lib-block');
        for (var i = 0; i < blocks.length; i++) {
            var url = blocks[i].getAttribute('data-url');
            var isAdded = currentBlocks.some(function(b) { return b.url === url; });
            blocks[i].classList.toggle('added', isAdded);
        }
    }

    /* ── Add Block from Library Click ──────────────────── */
    window.bldrAddBlockFromLib = function(el) {
        var url = el.getAttribute('data-url');
        var name = el.getAttribute('data-name');
        var category = el.getAttribute('data-category');

        // Check if already added
        if (currentBlocks.some(function(b) { return b.url === url; })) {
            showToast('"' + name.replace(/\.[^.]+$/, '') + '" already in collection', 'warning');
            return;
        }

        // Map category to block type for form fields
        var catLower = (category || '').toLowerCase().replace(/[^a-z]/g, '');
        var typeMap = {
            'hero': 'hero', 'heroes': 'hero', 'header': 'hero', 'headers': 'hero', 'banner': 'hero',
            'features': 'features', 'feature': 'features', 'services': 'features',
            'pricing': 'pricing', 'price': 'pricing', 'plans': 'pricing',
            'contact': 'contact', 'contacts': 'contact', 'form': 'contact', 'forms': 'contact',
            'gallery': 'gallery', 'galleries': 'gallery', 'portfolio': 'gallery',
            'testimonials': 'testimonials', 'testimonial': 'testimonials', 'reviews': 'testimonials',
            'faq': 'faq', 'faqs': 'faq', 'questions': 'faq',
            'cta': 'cta', 'calltoaction': 'cta', 'footer': 'cta', 'footers': 'cta'
        };

        currentBlocks.push({
            url: url,
            name: name,
            category: category,
            type: typeMap[catLower] || 'hero',
            form_data: {},
            timestamp: Date.now()
        });

        el.classList.add('added');
        renderCollection();
        updateBlockCount();
        showToast('Added: ' + name.replace(/\.[^.]+$/, ''), 'success');

        // Close mobile library
        document.getElementById('bldr-library-sidebar').classList.remove('open');
        document.getElementById('bldr-mobile-overlay-bg').classList.remove('show');
    };

    /* ═══════════════════════════════════════════════
       COLLECTION (Right Main Area)
       ═══════════════════════════════════════════════ */

    function renderCollection() {
        var empty = document.getElementById('bldr-collection-empty');
        var list = document.getElementById('bldr-collection-list');

        if (currentBlocks.length === 0) {
            empty.style.display = '';
            list.innerHTML = '';
            return;
        }

        empty.style.display = 'none';
        var html = '';
        currentBlocks.forEach(function(block, idx) {
            var displayName = (block.name || 'Block').replace(/\.[^.]+$/, '');
            var hasForm = block.form_data && Object.keys(block.form_data).length > 0;
            var bt = BLOCK_TYPES[block.type] || null;

            html += '<div class="bldr-coll-item" data-index="' + idx + '">';

            // Image preview
            if (block.url) {
                html += '<img src="' + escAttr(block.url) + '" alt="' + escAttr(displayName) + '">';
            }

            // Info bar
            html += '<div class="bldr-coll-bar">'
                + '<div><span class="bldr-coll-name">' + escHtml(displayName) + '</span>'
                + '<span class="bldr-coll-meta">' + escHtml(block.category || '') + '</span></div>'
                + '<div class="bldr-coll-actions">';

            // Form badge
            if (bt && bt.fields && bt.fields.length > 0) {
                if (hasForm) {
                    html += '<span class="bldr-form-badge filled" onclick="bldrToggleForm(' + idx + ')"><i class="bx bx-check-circle"></i> Content filled</span>';
                } else {
                    html += '<span class="bldr-form-badge empty" onclick="bldrToggleForm(' + idx + ')"><i class="bx bx-pencil"></i> Add content</span>';
                }
            }

            // Action buttons
            html += '<button onclick="bldrMoveBlock(' + idx + ',-1)" title="Move up"' + (idx === 0 ? ' disabled' : '') + '><i class="bx bx-up-arrow-alt"></i></button>';
            html += '<button onclick="bldrMoveBlock(' + idx + ',1)" title="Move down"' + (idx === currentBlocks.length - 1 ? ' disabled' : '') + '><i class="bx bx-down-arrow-alt"></i></button>';
            if (bt && bt.fields) {
                html += '<button onclick="bldrToggleForm(' + idx + ')" title="Edit content"><i class="bx bx-edit-alt"></i></button>';
            }
            html += '<button onclick="bldrDuplicateBlock(' + idx + ')" title="Duplicate"><i class="bx bx-copy"></i></button>';
            html += '<button class="danger" onclick="bldrRemoveBlock(' + idx + ')" title="Remove"><i class="bx bx-trash"></i></button>';
            html += '</div></div>';

            // Inline content form (hidden by default)
            if (bt && bt.fields) {
                html += '<div class="bldr-coll-form" id="bldr-form-' + idx + '">';
                html += buildInlineForm(block, idx, bt);
                html += '</div>';
            }

            html += '</div>';
        });
        list.innerHTML = html;
    }

    function buildInlineForm(block, idx, bt) {
        var fd = block.form_data || {};
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
        bt.fields.forEach(function(f) {
            var val = fd[f.key] || '';
            var fullWidth = f.type === 'textarea' ? ' style="grid-column:1/-1;"' : '';
            h += '<div' + fullWidth + '>';
            h += '<label>' + escHtml(f.label) + '</label>';
            if (f.type === 'textarea') {
                h += '<textarea rows="2" data-idx="' + idx + '" data-key="' + f.key + '" onchange="bldrUpdateFormField(this)" placeholder="' + escAttr(f.placeholder || '') + '">' + escHtml(val) + '</textarea>';
            } else {
                h += '<input type="text" data-idx="' + idx + '" data-key="' + f.key + '" onchange="bldrUpdateFormField(this)" value="' + escAttr(val) + '" placeholder="' + escAttr(f.placeholder || '') + '">';
            }
            h += '</div>';
        });
        h += '</div>';
        return h;
    }

    /* ── Toggle Inline Form ────────────────────────────── */
    window.bldrToggleForm = function(idx) {
        var form = document.getElementById('bldr-form-' + idx);
        if (form) {
            form.style.display = form.style.display === 'block' ? 'none' : 'block';
        }
    };

    /* ── Update Form Field ─────────────────────────────── */
    window.bldrUpdateFormField = function(el) {
        var idx = parseInt(el.getAttribute('data-idx'));
        var key = el.getAttribute('data-key');
        if (!currentBlocks[idx].form_data) currentBlocks[idx].form_data = {};
        currentBlocks[idx].form_data[key] = el.value;
    };

    /* ── Move Block ────────────────────────────────────── */
    window.bldrMoveBlock = function(idx, dir) {
        var newIdx = idx + dir;
        if (newIdx < 0 || newIdx >= currentBlocks.length) return;
        var tmp = currentBlocks[idx];
        currentBlocks[idx] = currentBlocks[newIdx];
        currentBlocks[newIdx] = tmp;
        renderCollection();
    };

    /* ── Remove Block ──────────────────────────────────── */
    window.bldrRemoveBlock = function(idx) {
        if (!confirm('Remove this block?')) return;
        currentBlocks.splice(idx, 1);
        renderCollection();
        updateBlockCount();
        refreshLibraryMarks();
        showToast('Block removed.', 'info');
    };

    /* ── Duplicate Block ───────────────────────────────── */
    window.bldrDuplicateBlock = function(idx) {
        var original = currentBlocks[idx];
        var clone = JSON.parse(JSON.stringify(original));
        currentBlocks.splice(idx + 1, 0, clone);
        renderCollection();
        updateBlockCount();
        showToast('Block duplicated.', 'success');
    };

    /* ── Block Count ───────────────────────────────────── */
    function updateBlockCount() {
        var badge = document.getElementById('bldr-block-count');
        if (badge) badge.textContent = currentBlocks.length + ' block' + (currentBlocks.length !== 1 ? 's' : '');
    }

    /* ── Block Edit Modal (fallback for older data) ────── */
    window.bldrOpenEditModal = function(idx) {
        editingBlockIndex = idx;
        var block = currentBlocks[idx];
        var bt = BLOCK_TYPES[block.type];
        if (!bt) return;

        document.getElementById('bldr-edit-modal-title').textContent = 'Edit ' + bt.label + ' Content';
        var body = document.getElementById('bldr-edit-modal-body');
        var html = '';
        bt.fields.forEach(function(f) {
            var val = (block.form_data && block.form_data[f.key]) || (block.content && block.content[f.key]) || '';
            if (f.type === 'textarea') {
                html += '<div class="mb-3"><label class="form-label">' + f.label + '</label>'
                    + '<textarea class="form-control bldr-field" data-key="' + f.key + '" rows="3" placeholder="' + escAttr(f.placeholder || '') + '">' + escHtml(val) + '</textarea></div>';
            } else {
                html += '<div class="mb-3"><label class="form-label">' + f.label + '</label>'
                    + '<input type="text" class="form-control bldr-field" data-key="' + f.key + '" value="' + escAttr(val) + '" placeholder="' + escAttr(f.placeholder || '') + '"></div>';
            }
        });
        body.innerHTML = html;
        document.getElementById('bldr-block-edit-modal').classList.add('show');
    };

    window.bldrCloseEditModal = function() {
        document.getElementById('bldr-block-edit-modal').classList.remove('show');
        editingBlockIndex = null;
    };

    window.bldrSaveBlock = function() {
        if (editingBlockIndex === null) return;
        var fields = document.querySelectorAll('#bldr-edit-modal-body .bldr-field');
        var fd = {};
        fields.forEach(function(el) { fd[el.getAttribute('data-key')] = el.value; });
        if (!currentBlocks[editingBlockIndex].form_data) currentBlocks[editingBlockIndex].form_data = {};
        for (var k in fd) { currentBlocks[editingBlockIndex].form_data[k] = fd[k]; }
        bldrCloseEditModal();
        renderCollection();
        showToast('Content updated.', 'success');
    };

    /* ── Library Upload (Admin) ────────────────────────── */
    window.bldrLibUploadAsset = function(input) {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        var folder = prompt('Upload to which category folder? (leave empty for root)') || '';
        getRestNonce().then(function(nonce) {
            return fetch(REST_BASE + '/assets/upload', {
                method: 'PUT', credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/octet-stream',
                    'X-WP-Nonce': nonce,
                    'X-Filename': file.name,
                    'X-Folder': folder
                },
                body: file
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(r) {
            if (r.success) {
                showToast('Uploaded: ' + r.data.name, 'success');
                libraryLoaded = false;
                loadBlockLibrary();
            } else {
                showToast('Upload error: ' + (r.message || 'Unknown'), 'error');
            }
        })
        .catch(function(e) { showToast('Upload failed: ' + e.message, 'error'); });
        input.value = '';
    };

    /* ── Mobile Library Toggle ─────────────────────────── */
    window.bldrToggleLibrary = function() {
        document.getElementById('bldr-library-sidebar').classList.toggle('open');
        document.getElementById('bldr-mobile-overlay-bg').classList.toggle('show');
    };
    window.bldrCloseLibrary = function() {
        document.getElementById('bldr-library-sidebar').classList.remove('open');
        document.getElementById('bldr-mobile-overlay-bg').classList.remove('show');
    };

    /* ── ESC key closes overlay ────────────────────────── */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var overlay = document.getElementById('bldr-overlay');
            if (overlay && overlay.style.display !== 'none') {
                bldrCloseOverlay();
            }
        }
    });

    /* ── Slug Helper ────────────────────────────────────── */
    function slugify(str) {
        return str.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }
    window.bldrUpdateSlug = function() {
        var title = document.getElementById('bldr-page-title').value;
        document.getElementById('bldr-page-slug').value = slugify(title);
    };

    /* ── Escape Helpers ─────────────────────────────────── */
    function escHtml(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }
    function escAttr(str) {
        return escHtml(str).replace(/"/g, '&quot;');
    }

    /* ════════════════════════════════════════════════════
       FEEDBACK VIEWER
       ════════════════════════════════════════════════════ */
    var fbRestNonce = '';
    var fbRestNonceReady = fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' })
        .then(function(r) { return r.text(); })
        .then(function(n) { fbRestNonce = n.trim(); return fbRestNonce; });

    function fbRestCall(method, url, body) {
        return fbRestNonceReady.then(function() {
            var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': fbRestNonce } };
            if (body && (method === 'PUT' || method === 'PATCH')) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            return fetch(url, opts).then(function(r) { return r.json(); });
        });
    }
    function fbRestGet(url) {
        return fbRestNonceReady.then(function() {
            return fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': fbRestNonce } })
                .then(function(r) { return r.json(); });
        });
    }

    var fbPageId = null;
    var fbComments = [];
    var fbPinMode = false;
    var fbPendingPin = null; // {x, y} percentage-based
    var fbActiveComment = null;
    var fbFilter = 'all';

    window.fbOpenViewer = function(pageId, pageTitle) {
        fbPageId = pageId;
        fbComments = [];
        fbActiveComment = null;
        fbPinMode = false;
        document.getElementById('fb-pin-mode').checked = false;
        document.getElementById('fb-filter').value = 'all';
        fbFilter = 'all';
        document.getElementById('fb-page-title').textContent = pageTitle + ' — Feedback';
        document.getElementById('fb-page-content').innerHTML = '<p class="text-muted text-center py-5">Loading page...</p>';
        document.getElementById('fb-viewer').style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Reset mobile tabs to Preview on open
        var previewArea = document.getElementById('fb-preview-area');
        var commentsPanel = document.getElementById('fb-comments-panel');
        previewArea.classList.remove('fb-hidden-tab');
        commentsPanel.classList.add('fb-hidden-tab');
        var tabBtns = document.querySelectorAll('#fb-tab-bar button');
        if (tabBtns.length >= 2) {
            tabBtns[0].classList.add('active');
            tabBtns[1].classList.remove('active');
        }

        // Load page content via REST GET
        fbRestGet('/wp-json/cpp-builder/v1/page/' + pageId)
            .then(function(res) {
                if (res.success && res.data.page) {
                    var blocks = [];
                    try { blocks = JSON.parse(res.data.page.blocks_json || '[]'); } catch(e) {}
                    fbRenderPageContent(blocks);
                } else {
                    document.getElementById('fb-page-content').innerHTML = '<p class="text-danger text-center py-5">Failed to load page.</p>';
                }
            });

        // Load comments
        fbLoadComments();
    };

    window.fbOpenViewerPinMode = function(pageId, pageTitle) {
        fbOpenViewer(pageId, pageTitle);
        // Auto-enable pin mode after a short delay to let content load
        setTimeout(function() {
            fbPinMode = true;
            document.getElementById('fb-pin-mode').checked = true;
            document.getElementById('fb-preview-area').style.cursor = 'crosshair';
        }, 1500);
    };

    window.fbCloseViewer = function() {
        document.getElementById('fb-viewer').style.display = 'none';
        document.body.style.overflow = '';
        fbCancelPin();
        // Refresh comment counts on list
        fbLoadCommentCounts();
    };

    window.fbTogglePinMode = function(enabled) {
        fbPinMode = enabled;
        var area = document.getElementById('fb-preview-area');
        if (enabled) {
            area.classList.add('pin-mode');
        } else {
            area.classList.remove('pin-mode');
            fbCancelPin();
        }
    };

    window.fbFilterComments = function(val) {
        fbFilter = val;
        fbRenderComments();
        fbRenderPins();
    };

    function fbRenderPageContent(blocks) {
        // Render blocks into HTML - use block images or generate placeholder sections
        var contentDiv = document.getElementById('fb-page-content');
        if (!blocks || blocks.length === 0) {
            contentDiv.innerHTML = '<div style="padding:60px 20px;text-align:center;color:#a1a1aa;"><i class="bx bx-file" style="font-size:3rem;display:block;margin-bottom:10px;"></i><p>This page has no blocks yet.</p></div>';
            return;
        }

        var html = '';
        blocks.forEach(function(block) {
            if (block.url) {
                // Image-based block
                html += '<div class="fb-block-section" style="position:relative;">';
                html += '<img src="' + escAttr(block.url) + '" style="width:100%;display:block;" alt="' + escAttr(block.name || '') + '">';
                if (block.form_data) {
                    var fd = block.form_data;
                    var hasContent = Object.keys(fd).some(function(k) { return fd[k]; });
                    if (hasContent) {
                        html += '<div style="padding:16px 20px;background:#f8f9fa;border-top:1px solid #e9ecef;">';
                        Object.keys(fd).forEach(function(k) {
                            if (fd[k]) {
                                html += '<div style="margin-bottom:4px;"><strong style="font-size:11px;color:#6b7280;text-transform:uppercase;">' + escHtml(k.replace(/_/g, ' ')) + ':</strong> <span style="font-size:13px;color:#2b2c40;">' + escHtml(fd[k]) + '</span></div>';
                            }
                        });
                        html += '</div>';
                    }
                }
                html += '</div>';
            }
        });

        if (!html) {
            html = '<div style="padding:40px 20px;text-align:center;color:#a1a1aa;"><p>No visual content to display.</p></div>';
        }
        contentDiv.innerHTML = html;

        // Wait for images to load before rendering pins
        var imgs = contentDiv.querySelectorAll('img');
        var loaded = 0;
        var total = imgs.length;
        function onAllLoaded() {
            fbRenderPins();
        }
        if (total === 0) { onAllLoaded(); }
        else {
            imgs.forEach(function(img) {
                if (img.complete) { loaded++; if (loaded >= total) onAllLoaded(); }
                else {
                    img.onload = img.onerror = function() { loaded++; if (loaded >= total) onAllLoaded(); };
                }
            });
        }

        // Attach click/touch handler for pin mode
        function handlePinClick(e) {
            if (!fbPinMode) return;
            e.preventDefault();
            var touch = e.touches ? e.touches[0] || e.changedTouches[0] : e;
            var rect = contentDiv.getBoundingClientRect();
            var scrollTop = contentDiv.parentElement.scrollTop || 0;
            var x = ((touch.clientX - rect.left) / rect.width * 100).toFixed(2);
            var yPx = touch.clientY - rect.top + scrollTop;
            var y = (yPx / contentDiv.offsetHeight * 100).toFixed(2);
            fbShowPopover(x, y, touch.clientX, touch.clientY);
        }
        contentDiv.onclick = handlePinClick;

        /* Touch: track if user scrolled vs tapped */
        var touchStartY = 0;
        var touchMoved = false;
        contentDiv.addEventListener('touchstart', function(e) {
            touchStartY = e.touches[0].clientY;
            touchMoved = false;
        }, { passive: true });
        contentDiv.addEventListener('touchmove', function(e) {
            if (Math.abs(e.touches[0].clientY - touchStartY) > 10) touchMoved = true;
        }, { passive: true });
        contentDiv.addEventListener('touchend', function(e) {
            if (!fbPinMode || touchMoved) return;
            e.preventDefault();
            var touch = e.changedTouches[0];
            var rect = contentDiv.getBoundingClientRect();
            var scrollTop = contentDiv.parentElement.scrollTop || 0;
            var x = ((touch.clientX - rect.left) / rect.width * 100).toFixed(2);
            var yPx = touch.clientY - rect.top + scrollTop;
            var y = (yPx / contentDiv.offsetHeight * 100).toFixed(2);
            fbShowPopover(x, y, touch.clientX, touch.clientY);
        }, { passive: false });
    }

    function fbShowPopover(pinX, pinY, screenX, screenY) {
        fbPendingPin = { x: parseFloat(pinX), y: parseFloat(pinY) };
        var popover = document.getElementById('fb-popover');
        var area = document.getElementById('fb-preview-area');
        var rect = area.getBoundingClientRect();

        // Position popover near the click
        var left = screenX - rect.left + 10;
        var top = screenY - rect.top + area.scrollTop + 10;

        // Keep popover in bounds
        if (left + 290 > rect.width) left = left - 300;
        if (left < 10) left = 10;

        // Vertical bounds - keep popover visible
        var popHeight = 160;
        var areaVisible = rect.height;
        if (top + popHeight > area.scrollTop + areaVisible) {
            top = area.scrollTop + areaVisible - popHeight - 10;
        }
        if (top < area.scrollTop + 10) top = area.scrollTop + 10;

        // On mobile, popover is fixed to bottom via CSS, these coords are ignored
        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
        popover.style.display = 'block';
        document.getElementById('fb-popover-text').value = '';
        document.getElementById('fb-popover-text').focus();

        // Show temp pin
        fbRenderPins();
    }

    window.fbCancelPin = function() {
        fbPendingPin = null;
        document.getElementById('fb-popover').style.display = 'none';
        fbRenderPins();
    };

    window.fbUploadImage = function(input) {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        if (!file.type.startsWith('image/')) { showToast('Please select an image file', 'error'); input.value = ''; return; }
        if (file.size > 10 * 1024 * 1024) { showToast('File too large (max 10MB)', 'error'); input.value = ''; return; }

        showToast('Uploading image...', 'info');
        fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {credentials: 'same-origin'})
        .then(function(r) { return r.text(); })
        .then(function(restNonce) {
            return fetch('/wp-json/cpp-feedback/v1/upload', {
                method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/octet-stream', 'X-WP-Nonce': restNonce.trim(), 'X-Filename': file.name },
                body: file
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success && resp.url) {
                var contentEl = document.getElementById('fb-page-content');
                var imgDiv = document.createElement('div');
                imgDiv.style.cssText = 'padding:12px;text-align:center;';
                imgDiv.innerHTML = '<img src="' + resp.url + '" class="fb-uploaded-img" alt="Uploaded for collaboration">';
                contentEl.appendChild(imgDiv);
                showToast('Image uploaded! You can now drop pins on it.', 'success');
            } else {
                showToast('Upload failed: ' + (resp.message || 'Unknown error'), 'error');
            }
            input.value = '';
        })
        .catch(function(err) {
            showToast('Upload error: ' + err.message, 'error');
            input.value = '';
        });
    };

    // Popover file attachment state
    var fbPopoverAttachment = null; // Will hold { file: File, url: string } after upload

    window.fbPopoverFileSelected = function(input) {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        if (!file.type.startsWith('image/')) { showToast('Please select an image file', 'error'); input.value = ''; return; }
        if (file.size > 10 * 1024 * 1024) { showToast('File too large (max 10MB)', 'error'); input.value = ''; return; }

        document.getElementById('fb-popover-filename').textContent = file.name;

        // Show preview
        var reader = new FileReader();
        reader.onload = function(e) {
            var prev = document.getElementById('fb-popover-preview-img');
            prev.src = e.target.result;
            document.getElementById('fb-popover-preview').style.display = 'flex';
        };
        reader.readAsDataURL(file);

        // Upload immediately
        showToast('Uploading image...', 'info');
        fetch('/wp-admin/admin-ajax.php?action=rest-nonce', {credentials: 'same-origin'})
        .then(function(r) { return r.text(); })
        .then(function(restNonce) {
            return fetch('/wp-json/cpp-feedback/v1/upload', {
                method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/octet-stream', 'X-WP-Nonce': restNonce.trim(), 'X-Filename': file.name },
                body: file
            });
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success && resp.url) {
                fbPopoverAttachment = { url: resp.url };
                showToast('Image attached!', 'success');
            } else {
                showToast('Upload failed: ' + (resp.message || 'Unknown error'), 'error');
                fbPopoverRemoveFile();
            }
        })
        .catch(function(err) {
            showToast('Upload error: ' + err.message, 'error');
            fbPopoverRemoveFile();
        });
        input.value = '';
    };

    window.fbPopoverRemoveFile = function() {
        fbPopoverAttachment = null;
        document.getElementById('fb-popover-filename').textContent = '';
        document.getElementById('fb-popover-preview').style.display = 'none';
        document.getElementById('fb-popover-preview-img').src = '';
        document.getElementById('fb-popover-file').value = '';
    };

    window.fbSubmitPin = function() {
        if (!fbPendingPin) return;
        var text = document.getElementById('fb-popover-text').value.trim();
        if (!text) return;

        document.querySelector('.fb-popover-submit').disabled = true;

        var payload = {
            pin_x: fbPendingPin.x,
            pin_y: fbPendingPin.y,
            comment_text: text
        };
        if (fbPopoverAttachment && fbPopoverAttachment.url) {
            payload.attachment_url = fbPopoverAttachment.url;
        }

        fbRestCall('PUT', '/wp-json/cpp-builder/v1/page/' + fbPageId + '/comments', payload)
        .then(function(res) {
            document.querySelector('.fb-popover-submit').disabled = false;
            if (res.success) {
                fbPopoverRemoveFile();
                fbCancelPin();
                fbLoadComments();
            } else {
                alert(res.message || 'Failed to add comment.');
            }
        })
        .catch(function() {
            document.querySelector('.fb-popover-submit').disabled = false;
        });
    };

    function fbLoadComments() {
        fbRestGet('/wp-json/cpp-builder/v1/page/' + fbPageId + '/comments')
            .then(function(res) {
                if (res.success) {
                    fbComments = res.data.comments || [];
                    fbRenderComments();
                    fbRenderPins();
                    fbUpdateCounter();
                }
            });
    }

    function fbUpdateCounter() {
        var top = fbComments.filter(function(c) { return !parseInt(c.parent_id); });
        var open = top.filter(function(c) { return c.status === 'open'; }).length;
        var resolved = top.filter(function(c) { return c.status === 'resolved'; }).length;
        document.getElementById('fb-counter').textContent = open + ' open / ' + resolved + ' resolved';
        document.getElementById('fb-panel-count').textContent = top.length;
        // Update mobile tab badge
        var badge = document.getElementById('fb-tab-badge');
        if (badge) {
            if (open > 0) { badge.textContent = open; badge.style.display = ''; }
            else { badge.style.display = 'none'; }
        }
    }

    /* ── Mobile tab switching ────────────────────── */
    window.fbSwitchTab = function(tab) {
        var preview = document.getElementById('fb-preview-area');
        var panel = document.getElementById('fb-comments-panel');
        var btns = document.querySelectorAll('#fb-tab-bar button');
        btns.forEach(function(b) { b.classList.remove('active'); });

        if (tab === 'preview') {
            btns[0].classList.add('active');
            preview.classList.remove('fb-hidden-tab');
            panel.classList.add('fb-hidden-tab');
        } else {
            btns[1].classList.add('active');
            preview.classList.add('fb-hidden-tab');
            panel.classList.remove('fb-hidden-tab');
        }
    };

    function fbRenderPins() {
        // Remove existing pins
        var contentEl = document.getElementById('fb-page-content');
        contentEl.querySelectorAll('.fb-pin').forEach(function(p) { p.remove(); });

        var contentH = Math.max(contentEl.offsetHeight, contentEl.scrollHeight, 1);
        var topComments = fbComments.filter(function(c) { return !parseInt(c.parent_id); });
        var num = 0;

        topComments.forEach(function(c, i) {
            if (fbFilter !== 'all' && c.status !== fbFilter) return;
            num++;
            var pin = document.createElement('div');
            pin.className = 'fb-pin';
            pin.style.left = c.pin_x + '%';
            pin.style.top = (parseFloat(c.pin_y) / 100 * contentH) + 'px';
            pin.innerHTML = '<div class="fb-pin-marker ' + c.status + (fbActiveComment == c.id ? ' active' : '') + '">' + num + '</div><div class="fb-pin-tail"></div>';
            pin.onclick = function(e) {
                e.stopPropagation();
                if (isMobileSheet()) {
                    fbOpenSheet(c.id);
                } else {
                    fbActiveComment = parseInt(c.id);
                    fbRenderComments();
                    fbRenderPins();
                    var commentEl = document.querySelector('.fb-comment[data-id="' + c.id + '"]');
                    if (commentEl) commentEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            };
            pin.ontouchend = function(e) {
                if (fbLongPressed) return; // was a drag, don't open sheet
                e.stopPropagation();
                e.preventDefault();
                if (isMobileSheet()) {
                    fbOpenSheet(c.id);
                } else {
                    fbActiveComment = parseInt(c.id);
                    fbRenderComments();
                    fbRenderPins();
                    var commentEl = document.querySelector('.fb-comment[data-id="' + c.id + '"]');
                    if (commentEl) commentEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            };
            contentEl.appendChild(pin);
            fbInitPinDrag(pin, parseInt(c.id), c.pin_x, c.pin_y);
        });

        // Pending pin
        if (fbPendingPin) {
            var pin = document.createElement('div');
            pin.className = 'fb-pin';
            pin.style.left = fbPendingPin.x + '%';
            pin.style.top = (fbPendingPin.y / 100 * contentH) + 'px';
            pin.innerHTML = '<div class="fb-pin-marker open" style="animation:fbPulse 1s infinite;">?</div><div class="fb-pin-tail"></div>';
            contentEl.appendChild(pin);
        }
    }

    function fbRenderComments() {
        var topComments = fbComments.filter(function(c) { return !parseInt(c.parent_id); });
        var listEl = document.getElementById('fb-comments-list');
        var emptyEl = document.getElementById('fb-panel-empty');

        var filtered = topComments.filter(function(c) {
            return fbFilter === 'all' || c.status === fbFilter;
        });

        if (filtered.length === 0) {
            emptyEl.style.display = '';
            listEl.innerHTML = '';
            return;
        }
        emptyEl.style.display = 'none';

        var html = '';
        var num = 0;
        filtered.forEach(function(c) {
            num++;
            var isActive = fbActiveComment == c.id;
            var replies = fbComments.filter(function(r) { return parseInt(r.parent_id) === parseInt(c.id); });
            var timeAgo = fbTimeAgo(c.created_at);

            html += '<div class="fb-comment' + (isActive ? ' active' : '') + (c.status === 'resolved' ? ' resolved-comment' : '') + '" data-id="' + c.id + '" onclick="fbClickComment(' + c.id + ',' + c.pin_x + ',' + c.pin_y + ')">';
            html += '<div class="fb-comment-header">';
            html += '<span class="fb-comment-num ' + c.status + '">' + num + '</span>';
            html += '<span class="fb-comment-author">' + escHtml(c.user_name) + '</span>';
            html += '<span class="fb-comment-time">' + timeAgo + '</span>';
            html += '</div>';
            html += '<div class="fb-comment-text">' + escHtml(c.comment_text) + '</div>';
            if (c.attachment_url) {
                html += '<div class="fb-comment-attachment"><img src="' + escHtml(c.attachment_url) + '" alt="Attachment" onclick="event.stopPropagation();window.open(\'' + escHtml(c.attachment_url) + '\',\'_blank\')"></div>';
            }
            html += '<div class="fb-comment-actions">';
            if (c.status === 'open') {
                html += '<button class="fb-resolve-btn" onclick="event.stopPropagation();fbResolveComment(' + c.id + ',\'resolved\')"><i class="bx bx-check"></i> Resolve</button>';
            } else {
                html += '<button class="fb-reopen-btn" onclick="event.stopPropagation();fbResolveComment(' + c.id + ',\'open\')"><i class="bx bx-revision"></i> Reopen</button>';
            }
            if (IS_ADMIN) {
                html += '<button class="fb-delete-btn" onclick="event.stopPropagation();fbDeleteComment(' + c.id + ')"><i class="bx bx-trash"></i></button>';
            }
            html += '</div>';

            // Replies
            if (replies.length > 0) {
                html += '<div class="fb-replies">';
                replies.forEach(function(r) {
                    html += '<div class="fb-reply">';
                    html += '<div class="fb-reply-header"><span class="fb-reply-author">' + escHtml(r.user_name) + '</span><span class="fb-reply-time">' + fbTimeAgo(r.created_at) + '</span></div>';
                    html += '<div class="fb-reply-text">' + escHtml(r.comment_text) + '</div>';
                    html += '</div>';
                });
                html += '</div>';
            }

            // Reply input
            html += '<div class="fb-reply-input">';
            html += '<input type="text" placeholder="Reply..." id="fb-reply-' + c.id + '" onkeydown="if(event.key===\'Enter\')fbReply(' + c.id + ')">';
            html += '<button onclick="fbReply(' + c.id + ')">Reply</button>';
            html += '</div>';

            html += '</div>';
        });

        listEl.innerHTML = html;
    }

    window.fbClickComment = function(id, pinX, pinY) {
        fbActiveComment = parseInt(id);
        fbRenderComments();
        fbRenderPins();
        // Scroll pin into view in preview area
        var contentEl = document.getElementById('fb-page-content');
        var contentH = Math.max(contentEl.offsetHeight, contentEl.scrollHeight, 1);
        var pinTop = (parseFloat(pinY) / 100 * contentH);
        document.getElementById('fb-preview-area').scrollTo({ top: Math.max(0, pinTop - 100), behavior: 'smooth' });
    };

    window.fbResolveComment = function(id, status) {
        fbRestCall('PUT', '/wp-json/cpp-builder/v1/comment/' + id + '/status', { status: status })
            .then(function() { fbLoadComments(); });
    };

    window.fbDeleteComment = function(id) {
        if (!confirm('Delete this comment and all replies?')) return;
        var fd = new FormData();
        fd.append('action', 'cpp_page_comment_delete');
        fd.append('nonce', NONCE);
        fd.append('id', id);
        bldProxy(fd)
            .then(function(r) { return r.json(); })
            .then(function() { fbLoadComments(); });
    };

    window.fbReply = function(parentId) {
        var input = document.getElementById('fb-reply-' + parentId);
        var text = input.value.trim();
        if (!text) return;

        input.disabled = true;
        fbRestCall('PUT', '/wp-json/cpp-builder/v1/comment/' + parentId + '/reply', { comment_text: text })
            .then(function(res) {
                input.disabled = false;
                input.value = '';
                if (res.success) fbLoadComments();
            });
    };

    function fbTimeAgo(dateStr) {
        var d = new Date(dateStr.replace(' ', 'T'));
        var now = new Date();
        var diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    /* ── Load comment counts for pages list ─────────── */
    function fbLoadCommentCounts() {
        fbRestGet('/wp-json/cpp-builder/v1/comments/counts')
            .then(function(res) {
                if (!res.success) return;
                var counts = res.data.counts || {};
                document.querySelectorAll('.fb-count-cell').forEach(function(cell) {
                    var pid = cell.dataset.pageId;
                    var c = counts[pid];
                    if (c && (c.open > 0)) {
                        cell.innerHTML = '<span class="badge bg-label-danger" style="font-size:10px;">' + c.open + ' open</span>';
                    } else if (c && c.total > 0) {
                        cell.innerHTML = '<span class="badge bg-label-success" style="font-size:10px;">All done</span>';
                    } else {
                        cell.innerHTML = '<span class="text-muted" style="font-size:11px;">No feedback</span>';
                    }
                });
            });
    }

    // Load counts on page load
    setTimeout(fbLoadCommentCounts, 500);

    // ═══ PIN DRAG TO REPOSITION ════════════════════════════
    var fbDragPin = null; // { commentId, el, startX, startY, origX, origY }
    var fbLongPressTimer = null;
    var fbLongPressed = false;

    function fbInitPinDrag(pinEl, commentId, pinX, pinY) {
        // Prevent native context menu / text selection on long press
        pinEl.style.webkitTouchCallout = 'none';
        pinEl.style.userSelect = 'none';
        pinEl.style.webkitUserSelect = 'none';
        pinEl.addEventListener('contextmenu', function(e) { e.preventDefault(); });

        // Long press to start drag
        pinEl.addEventListener('touchstart', function(e) {
            fbLongPressed = false;
            var touch = e.touches[0];
            fbLongPressTimer = setTimeout(function() {
                fbLongPressed = true;
                pinEl.style.opacity = '0.7';
                pinEl.style.zIndex = '10000';
                pinEl.style.transform = 'scale(1.2)';
                fbDragPin = {
                    commentId: commentId,
                    el: pinEl,
                    startX: touch.clientX,
                    startY: touch.clientY,
                    origPinX: parseFloat(pinX),
                    origPinY: parseFloat(pinY)
                };
                // Vibrate if supported to signal drag started
                if (navigator.vibrate) navigator.vibrate(30);
            }, 400);
        }, { passive: true });

        pinEl.addEventListener('touchmove', function(e) {
            if (fbLongPressTimer && !fbLongPressed) {
                clearTimeout(fbLongPressTimer);
                fbLongPressTimer = null;
            }
            if (!fbDragPin || fbDragPin.commentId !== commentId) return;
            e.preventDefault();
            var touch = e.touches[0];
            var contentEl = document.getElementById('fb-page-content');
            var rect = contentEl.getBoundingClientRect();
            var contentH = Math.max(contentEl.offsetHeight, contentEl.scrollHeight, 1);
            var contentW = contentEl.offsetWidth;

            var scrollTop = document.getElementById('fb-preview-area').scrollTop;
            var newPxX = touch.clientX - rect.left;
            var newPxY = touch.clientY - rect.top + scrollTop;

            var newPctX = Math.max(0, Math.min(100, (newPxX / contentW * 100)));
            var newPctY = Math.max(0, Math.min(100, (newPxY / contentH * 100)));

            pinEl.style.left = newPctX + '%';
            pinEl.style.top = (newPctY / 100 * contentH) + 'px';
        }, { passive: false });

        pinEl.addEventListener('touchend', function(e) {
            clearTimeout(fbLongPressTimer);
            fbLongPressTimer = null;
            if (!fbDragPin || fbDragPin.commentId !== commentId) return;
            if (!fbLongPressed) { fbDragPin = null; return; }
            e.preventDefault();
            e.stopPropagation();

            pinEl.style.opacity = '';
            pinEl.style.zIndex = '';
            pinEl.style.transform = '';

            // Calculate final position
            var contentEl = document.getElementById('fb-page-content');
            var contentH = Math.max(contentEl.offsetHeight, contentEl.scrollHeight, 1);
            var contentW = contentEl.offsetWidth;
            var newX = parseFloat(pinEl.style.left);
            var newY = (parseFloat(pinEl.style.top) / contentH * 100);

            // Update local data immediately so re-render doesn't reset position
            var comment = fbComments.find(function(c) { return parseInt(c.id) === commentId; });
            if (comment) {
                comment.pin_x = newX.toFixed(2);
                comment.pin_y = newY.toFixed(2);
            }

            // Save via AJAX
            var fd = new FormData();
            fd.append('action', 'cpp_page_comment_move');
            fd.append('nonce', NONCE);
            fd.append('id', commentId);
            fd.append('pin_x', newX.toFixed(2));
            fd.append('pin_y', newY.toFixed(2));
            bldProxy(fd)
                .then(function(r) { return r.json(); })
                .then(function() { fbRenderPins(); });

            fbDragPin = null;
            fbLongPressed = false;
        }, { passive: false });
    }

    // ═══ BOTTOM SHEET (mobile pin tap) ═══════════════════
    var fbSheetCommentId = null;
    var fbSheetDragStartY = 0;
    var fbSheetStartH = 0;
    var fbSheetDragging = false;

    function isMobileSheet() {
        return window.innerWidth <= 768;
    }

    window.fbOpenSheet = function(commentId) {
        var c = fbComments.find(function(x) { return parseInt(x.id) === parseInt(commentId); });
        if (!c) return;
        fbSheetCommentId = parseInt(commentId);

        // Find display number
        var topComments = fbComments.filter(function(x) { return !parseInt(x.parent_id); });
        var filtered = topComments.filter(function(x) { return fbFilter === 'all' || x.status === fbFilter; });
        var num = 0;
        for (var i = 0; i < filtered.length; i++) {
            num++;
            if (parseInt(filtered[i].id) === parseInt(commentId)) break;
        }

        var numEl = document.getElementById('fb-sheet-num');
        numEl.textContent = num;
        numEl.className = 'fb-sheet-num' + (c.status === 'resolved' ? ' resolved' : '');
        document.getElementById('fb-sheet-author').textContent = c.user_name || '';
        document.getElementById('fb-sheet-time').textContent = fbTimeAgo(c.created_at);
        document.getElementById('fb-sheet-text').textContent = c.comment_text || '';

        // Actions
        var actHtml = '';
        if (c.status === 'open') {
            actHtml += '<button class="fb-resolve-btn" onclick="fbSheetResolve(\'resolved\')"><i class="bx bx-check"></i> Resolve</button>';
        } else {
            actHtml += '<button class="fb-reopen-btn" onclick="fbSheetResolve(\'open\')"><i class="bx bx-revision"></i> Reopen</button>';
        }
        if (IS_ADMIN) {
            actHtml += '<button class="fb-delete-btn" onclick="fbSheetDelete()"><i class="bx bx-trash"></i> Delete</button>';
        }
        document.getElementById('fb-sheet-actions').innerHTML = actHtml;

        // Replies
        var replies = fbComments.filter(function(r) { return parseInt(r.parent_id) === parseInt(commentId); });
        var repHtml = '';
        replies.forEach(function(r) {
            repHtml += '<div class="fb-reply">';
            repHtml += '<div class="fb-reply-header"><span class="fb-reply-author">' + escHtml(r.user_name) + '</span><span class="fb-reply-time">' + fbTimeAgo(r.created_at) + '</span></div>';
            repHtml += '<div class="fb-reply-text">' + escHtml(r.comment_text) + '</div>';
            repHtml += '</div>';
        });
        document.getElementById('fb-sheet-replies').innerHTML = repHtml;
        document.getElementById('fb-sheet-reply-input').value = '';

        // Show sheet
        var sheet = document.getElementById('fb-sheet');
        var overlay = document.getElementById('fb-sheet-overlay');
        var alreadyOpen = sheet.style.display === 'flex';
        sheet.style.display = 'flex';
        if (!alreadyOpen) {
            sheet.style.height = '25vh';
            sheet.style.transform = 'translateY(100%)';
            overlay.style.display = 'block';
            requestAnimationFrame(function() {
                sheet.style.transform = 'translateY(0)';
            });
        } else {
            overlay.style.display = 'block';
        }

        // Highlight pin
        fbActiveComment = parseInt(commentId);
        fbRenderPins();
    };

    window.fbCloseSheet = function() {
        var sheet = document.getElementById('fb-sheet');
        var overlay = document.getElementById('fb-sheet-overlay');
        sheet.style.transform = 'translateY(100%)';
        setTimeout(function() {
            sheet.style.display = 'none';
            overlay.style.display = 'none';
        }, 250);
        fbSheetCommentId = null;
    };

    window.fbSheetResolve = function(status) {
        if (!fbSheetCommentId) return;
        var cid = fbSheetCommentId;
        var curH = document.getElementById('fb-sheet').style.height;
        fbResolveComment(cid, status);
        // Re-open sheet with same height after data reloads
        setTimeout(function() { fbOpenSheet(cid); document.getElementById('fb-sheet').style.height = curH; }, 600);
    };

    window.fbSheetDelete = function() {
        if (!fbSheetCommentId) return;
        fbDeleteComment(fbSheetCommentId);
        fbCloseSheet();
    };

    window.fbSheetReply = function() {
        if (!fbSheetCommentId) return;
        var input = document.getElementById('fb-sheet-reply-input');
        var text = input.value.trim();
        if (!text) return;

        var cid = fbSheetCommentId;
        var curH = document.getElementById('fb-sheet').style.height;
        input.value = '';

        fbRestCall('PUT', '/wp-json/cpp-builder/v1/comment/' + cid + '/reply', { comment_text: text })
            .then(function(res) {
                if (res.success) {
                    fbLoadComments();
                    setTimeout(function() { fbOpenSheet(cid); document.getElementById('fb-sheet').style.height = curH; }, 500);
                }
            });
    };

    // Drag to resize / dismiss
    var dragHandle = document.getElementById('fb-sheet-drag');
    if (dragHandle) {
        dragHandle.addEventListener('touchstart', function(e) {
            fbSheetDragging = true;
            fbSheetDragStartY = e.touches[0].clientY;
            var sheet = document.getElementById('fb-sheet');
            fbSheetStartH = sheet.offsetHeight;
            sheet.style.transition = 'none';
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!fbSheetDragging) return;
            var dy = fbSheetDragStartY - e.touches[0].clientY;
            var newH = Math.max(60, Math.min(window.innerHeight * 0.8, fbSheetStartH + dy));
            document.getElementById('fb-sheet').style.height = newH + 'px';
        }, { passive: true });

        document.addEventListener('touchend', function() {
            if (!fbSheetDragging) return;
            fbSheetDragging = false;
            var sheet = document.getElementById('fb-sheet');
            sheet.style.transition = '';
            var h = sheet.offsetHeight;
            var vh = window.innerHeight;
            // If dragged below 15% of viewport, dismiss
            if (h < vh * 0.15) {
                fbCloseSheet();
            } else if (h < vh * 0.35) {
                sheet.style.height = '25vh';
            } else if (h < vh * 0.6) {
                sheet.style.height = '50vh';
            } else {
                sheet.style.height = '75vh';
            }
        });
    }

    // Auto-open editor if redirected from preset activation with edit_page param
    var urlParams = new URLSearchParams(window.location.search);
    var autoEditId = urlParams.get('edit_page');
    if (autoEditId) {
        setTimeout(function() { bldrEditPage(parseInt(autoEditId)); }, 500);
    }

})();
</script>
