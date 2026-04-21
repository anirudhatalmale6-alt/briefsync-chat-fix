<?php
/**
 * Plugin Name: Client Portal: Briefcase
 * Description: Unified client portal with integrated visual block builder, submission workflow, support tickets, CRM, and client management.
 * Version:     2.0.0
 * Author:      Brief Connect
 * Text Domain: cpb
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ── Constants ────────────────────────────────── */
define('CPB_VERSION', '2.2.0');
define('CPB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CPB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CPB_PLUGIN_FILE', __FILE__);

/* ── Legacy aliases (backward compat) ─────────── */
if (!defined('CPP_PLUGIN_DIR'))  define('CPP_PLUGIN_DIR', CPB_PLUGIN_DIR);
if (!defined('CPP_PLUGIN_URL'))  define('CPP_PLUGIN_URL', CPB_PLUGIN_URL);
if (!defined('CPP_PLUGIN_VERSION')) define('CPP_PLUGIN_VERSION', CPB_VERSION);
if (!defined('CP_PLUGIN_DIR'))   define('CP_PLUGIN_DIR', CPB_PLUGIN_DIR);
if (!defined('CP_PLUGIN_URL'))   define('CP_PLUGIN_URL', CPB_PLUGIN_URL);
if (!defined('CP_VERSION'))      define('CP_VERSION', CPB_VERSION);
if (!defined('CP_PLUGIN_FILE'))  define('CP_PLUGIN_FILE', CPB_PLUGIN_FILE);
if (!defined('CPP_PLUGIN_FILE')) define('CPP_PLUGIN_FILE', CPB_PLUGIN_FILE);
if (!defined('BBP_PLUGIN_URL'))  define('BBP_PLUGIN_URL', CPB_PLUGIN_URL);
if (!defined('BBP_PLUGIN_PATH')) define('BBP_PLUGIN_PATH', CPB_PLUGIN_DIR);

/* ── Hide this plugin from the Plugins page for portal users ── */
add_filter('all_plugins', function ($plugins) {
    if (!current_user_can('manage_options')) {
        $basename = plugin_basename(__FILE__);
        unset($plugins[$basename]);
    }
    return $plugins;
});

/* ── Bootstrap: Builder classes ──────────────── */
require_once CPB_PLUGIN_DIR . 'includes/class-cpb-install.php';
require_once CPB_PLUGIN_DIR . 'includes/class-cpb-block-builder.php';
require_once CPB_PLUGIN_DIR . 'includes/class-cpb-submission-workflow.php';

/* ── Bootstrap: Legacy portal classes ────────── */
$cpb_legacy_dir = CPB_PLUGIN_DIR . 'includes/legacy/';
if (file_exists($cpb_legacy_dir . 'CPP_Install.php')) {
    require_once $cpb_legacy_dir . 'helpers/security.php';
    require_once $cpb_legacy_dir . 'CPP_Install.php';
    CPP_Install::maybe_migrate();
    require_once $cpb_legacy_dir . 'CPP_CPanel_API.php';
    require_once $cpb_legacy_dir . 'CPP_Client_Manager.php';
    require_once $cpb_legacy_dir . 'helpers/notifications.php';
    require_once $cpb_legacy_dir . 'template-functions.php';
    require_once $cpb_legacy_dir . 'form-questions.php';
    require_once $cpb_legacy_dir . 'install-forms.php';
    require_once $cpb_legacy_dir . 'CPP_Form_Manager.php';
    require_once $cpb_legacy_dir . 'CPP_Submission_Manager.php';
    require_once $cpb_legacy_dir . 'CPP_Upload_Manager.php';
    require_once $cpb_legacy_dir . 'CPP_Ticket_Manager.php';
    require_once $cpb_legacy_dir . 'CPP_Message_Manager.php';

    // Admin callbacks (dashboard, clients, settings, etc.)
    require_once $cpb_legacy_dir . 'admin-callbacks.php';

    // WHMCS AJAX handlers
    if (file_exists($cpb_legacy_dir . 'admin-menu.php')) {
        require_once $cpb_legacy_dir . 'admin-menu.php';
    }

    // User quota meta fields
    if (file_exists($cpb_legacy_dir . 'admin-user-meta.php')) {
        require_once $cpb_legacy_dir . 'admin-user-meta.php';
    }
}

/* ── Activation ───────────────────────────────── */
register_activation_hook(__FILE__, ['CPB_Install', 'activate']);

add_action('plugins_loaded', function () {
    $db_ver = get_option('cpb_db_version', '0');
    if (version_compare($db_ver, CPB_VERSION, '<')) {
        CPB_Install::create_tables();
        // Defer page creation to init when rewrite rules are ready
        add_action('init', function () {
            CPB_Install::create_pages();
            CPB_Install::install_default_categories();
            flush_rewrite_rules();
            update_option('cpb_db_version', CPB_VERSION);
        }, 99);
    }
});

/* ── Email Template ──────────────────────────────── */
require_once CPB_PLUGIN_DIR . 'includes/helpers/email-template.php';

/* ── Email Notifications ────────────────────────────── */
require_once CPB_PLUGIN_DIR . 'includes/helpers/email-notifications.php';

/* ── White-Label Branding ───────────────────────────── */
require_once CPB_PLUGIN_DIR . 'includes/helpers/whitelabel.php';

/* ── Client Onboarding ──────────────────────────────── */
require_once CPB_PLUGIN_DIR . 'includes/helpers/onboarding.php';

/* ── Password Sync: WP → WHMCS ──────────────────── */
add_action('after_password_reset', function($user, $new_pass) {
    if (class_exists('CPP_WHMCS_Integration')) {
        CPP_WHMCS_Integration::sync_password_to_whmcs($user, $new_pass);
    }
}, 10, 2);

/* ── Load portal core if files exist ──────────── */
if (file_exists(CPB_PLUGIN_DIR . 'portal/bootstrap.php')) {
    require_once CPB_PLUGIN_DIR . 'portal/bootstrap.php';
}

/* ══════════════════════════════════════════════════
   UNIFIED ADMIN MENU
   All menus under one "Client Portal: Briefcase"
   ══════════════════════════════════════════════════ */
add_action('admin_menu', 'cpb_admin_menu', 5);
function cpb_admin_menu() {
    $cap     = 'manage_options';
    $cap_dev = 'manage_options';
    if (current_user_can('cpp_manage_portal')) {
        $cap = 'cpp_manage_portal';
    }
    if (current_user_can('cpp_manage_settings')) {
        $cap_dev = 'cpp_manage_settings';
    }

    $parent = 'cpp-dashboard';

    // ── Top-level menu ─────────────────────────────
    global $wpdb;
    $menu_title = 'Client Portal';

    // Badge: count unread uploads + submissions
    $unread = 0;
    $ut = $wpdb->prefix . 'cpp_uploads';
    $st = $wpdb->prefix . 'cpp_submissions';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$ut}'") === $ut) {
        $unread += intval($wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE is_read = 0"));
    }
    if ($wpdb->get_var("SHOW TABLES LIKE '{$st}'") === $st) {
        $unread += intval($wpdb->get_var("SELECT COUNT(*) FROM {$st} WHERE is_read = 0"));
    }
    if ($unread > 0) {
        $menu_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $unread . '</span></span>';
    }

    add_menu_page(
        'Client Portal: Briefcase',
        $menu_title,
        $cap,
        'cpp-dashboard',
        'cpp_admin_dashboard',
        'dashicons-groups',
        25
    );

    // ── Portal submenus (from original portal plugin) ──
    add_submenu_page($parent, 'Overview', 'Overview', $cap, 'cpp-dashboard', 'cpp_admin_dashboard');
    add_submenu_page($parent, 'Manage Clients', 'Manage Clients', $cap, 'cpp-clients', 'cpp_admin_clients');
    add_submenu_page($parent, 'WHMCS Linking', 'WHMCS Linking', $cap, 'cpp-whmcs-linking', 'cpp_whmcs_linking_page');
    add_submenu_page($parent, 'Portal Settings', 'Portal Settings', $cap, 'cpp-settings', 'cpp_admin_settings');

    // Uploads with badge
    $uploads_title = 'Manage Uploads';
    $u_unread = 0;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$ut}'") === $ut) {
        $u_unread = intval($wpdb->get_var("SELECT COUNT(*) FROM {$ut} WHERE is_read = 0"));
    }
    if ($u_unread > 0) {
        $uploads_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $u_unread . '</span></span>';
    }
    add_submenu_page($parent, 'Manage Uploads', $uploads_title, $cap, 'cpp-uploads', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/uploads.php';
    });

    add_submenu_page($parent, 'Manage Invoices', 'Manage Invoices', $cap, 'cpp-invoices', 'cpp_admin_invoices_page');
    add_submenu_page($parent, 'All Products', 'All Products', $cap, 'cpp-all-products', 'cpp_admin_all_products');
    add_submenu_page($parent, 'All Members', 'All Members', $cap, 'cpp-all-members', 'cpp_admin_all_members');

    add_submenu_page($parent, 'Manage Forms', 'Manage Forms', $cap, 'cpp-forms', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/forms.php';
    });

    // Hidden: form edit
    add_submenu_page(null, 'Edit Form', 'Edit Form', $cap, 'cpp-forms-edit', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/form-edit.php';
    });

    // Submissions with badge
    $sub_title = 'Submissions';
    $s_unread = 0;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$st}'") === $st) {
        $s_unread = intval($wpdb->get_var("SELECT COUNT(*) FROM {$st} WHERE is_read = 0"));
    }
    if ($s_unread > 0) {
        $sub_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $s_unread . '</span></span>';
    }
    add_submenu_page($parent, 'Submissions', $sub_title, $cap, 'cpp-submissions', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/submissions.php';
    });

    // Hidden: submission view
    add_submenu_page(null, 'View Submission', 'View Submission', $cap, 'cpp-submission-view', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/submission-view.php';
    });

    // Hidden: client view
    add_submenu_page(null, 'View Client', 'View Client', $cap, 'cpp-client-view', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/client-view.php';
    });

    // Tickets with badge
    $tickets_title = 'Support Tickets';
    $tt = $wpdb->prefix . 'cpp_ticket_messages';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$tt}'") === $tt) {
        $t_unread = intval($wpdb->get_var("SELECT COUNT(DISTINCT ticket_id) FROM {$tt} WHERE sender_role = 'client' AND admin_read = 0"));
        if ($t_unread > 0) {
            $tickets_title .= ' <span class="update-plugins count-' . esc_attr($t_unread) . '"><span class="plugin-count">' . number_format_i18n($t_unread) . '</span></span>';
        }
    }
    add_submenu_page($parent, 'Support Tickets', $tickets_title, $cap, 'cpp-tickets', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/tickets.php';
    });

    // Hidden: ticket view
    add_submenu_page(null, 'View Ticket', 'View Ticket', $cap, 'cpp-ticket', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/ticket-view.php';
    });

    add_submenu_page($parent, 'Upgrade Requests', 'Upgrade Requests', $cap, 'cpp-upgrade-requests', 'cpp_upgrade_requests_page');

    add_submenu_page($parent, 'Admin Messages', 'Admin Messages', $cap, 'cpp-messages', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/messages.php';
    });

    add_submenu_page($parent, 'Bug Reports', 'Bug Reports', $cap_dev, 'cpp-bug-reports', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/bug-reports.php';
    });

    add_submenu_page($parent, 'CRM Tasks', 'CRM Tasks', $cap, 'cpp-crm-tasks', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/crm-tasks.php';
    });

    // ── Builder submenus (new) ─────────────────────
    add_submenu_page($parent, 'Builder Dashboard', 'Builder Dashboard', $cap, 'cpb-dashboard', 'cpb_admin_dashboard_page');
    add_submenu_page($parent, 'Builder Submissions', 'Builder Submissions', $cap, 'cpb-submissions', 'cpb_admin_submissions_page');
    add_submenu_page($parent, 'Block Library', 'Block Library', $cap, 'cpb-blocks', 'cpb_admin_blocks_page');
    add_submenu_page($parent, 'Builder Settings', 'Builder Settings', $cap, 'cpb-settings', 'cpb_admin_settings_page');

    // ── Live Chat menu ─────────────────────────────
    $livechat_title = 'Live Chat';
    $lc_table = $wpdb->prefix . 'cpp_livechat_messages';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$lc_table}'") === $lc_table) {
        $lc_unread = intval($wpdb->get_var("SELECT COUNT(DISTINCT conversation_id) FROM {$lc_table} WHERE sender_type = 'visitor' AND is_read = 0"));
        if ($lc_unread > 0) {
            $livechat_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $lc_unread . '</span></span>';
        }
    }
    add_submenu_page($parent, 'Live Chat', $livechat_title, $cap, 'cpp-livechat', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/livechat.php';
    });
    add_submenu_page(null, 'Live Chat Settings', 'Live Chat Settings', 'manage_options', 'cpp-livechat-settings', function() {
        require_once CPB_PLUGIN_DIR . 'admin/legacy-pages/livechat-settings.php';
    });
}

/* ── Builder admin page callbacks ────────────── */
function cpb_admin_dashboard_page()  { include CPB_PLUGIN_DIR . 'admin/dashboard.php'; }
function cpb_admin_submissions_page(){ include CPB_PLUGIN_DIR . 'admin/submissions.php'; }
function cpb_admin_blocks_page()     { include CPB_PLUGIN_DIR . 'admin/blocks.php'; }
function cpb_admin_settings_page()   { include CPB_PLUGIN_DIR . 'admin/settings.php'; }

/* ══════════════════════════════════════════════════
   FRONTEND: Original portal + Block Builder
   ══════════════════════════════════════════════════ */

/* ── Original portal frontend shortcodes & AJAX ── */
require_once CPB_PLUGIN_DIR . 'frontend/shortcode.php';

// Ensure shortcodes are registered (fallback if file guard blocked)
add_action('init', function() {
    if (!shortcode_exists('client_portal_dashboard') && function_exists('cpp_dashboard_shortcode')) {
        add_shortcode('client_portal_dashboard', 'cpp_dashboard_shortcode');
        add_shortcode('cpp_dashboard', 'cpp_dashboard_shortcode');
        add_shortcode('client-portal', 'cpp_dashboard_shortcode');
    }
}, 20);
if (file_exists(CPB_PLUGIN_DIR . 'frontend/form-shortcode.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/form-shortcode.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'frontend/plan-shortcode.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/plan-shortcode.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'frontend/ajax-handlers.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/ajax-handlers.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'includes/sso-handler.php')) {
    require_once CPB_PLUGIN_DIR . 'includes/sso-handler.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'frontend/ajax-module-handlers.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/ajax-module-handlers.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'frontend/ajax-form-handler.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/ajax-form-handler.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'frontend/upload-handler.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/upload-handler.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'frontend/ajax-livechat.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/ajax-livechat.php';
}
if (file_exists(CPB_PLUGIN_DIR . 'frontend/ajax-contact-handler.php')) {
    require_once CPB_PLUGIN_DIR . 'frontend/ajax-contact-handler.php';
}

/* ── Auto-render portal on selected page (no shortcode needed) ── */
add_filter('the_content', function($content) {
    if (!is_page() || is_admin()) return $content;
    $portal_page_id = intval(get_option('cpp_portal_page_id', 0));
    if ($portal_page_id < 1 || get_the_ID() !== $portal_page_id) return $content;
    // Already has a portal shortcode — let that render instead
    if (has_shortcode($content, 'client_portal_dashboard') || has_shortcode($content, 'cpp_dashboard') || has_shortcode($content, 'client-portal')) return $content;
    // Render portal dashboard directly
    if (function_exists('cpp_dashboard_shortcode')) {
        return cpp_dashboard_shortcode(array());
    }
    return $content;
}, 5);

/* ── Builder shortcodes (additional) ─────────── */
add_shortcode('client_portal_builder', 'cpb_render_portal');
add_shortcode('cpb_portal', 'cpb_render_portal');
function cpb_render_portal($atts) {
    if (!is_user_logged_in()) {
        ob_start();
        include CPB_PLUGIN_DIR . 'templates/login.php';
        return ob_get_clean();
    }
    ob_start();
    // Theme overrides for full-width portal layout (same as dashboard shortcode)
    echo '<style>
        #Top_bar, header, .sticky-header, #wdes-menu-top-header,
        .wdes-menu-navbar, .wdes-mob-btn, .menu-d-mob,
        .clientarea-shortcuts, #logo,
        footer, .wdes-nav-wrap, .wdes-nav-mobile-menu,
        .elementor-location-header, .elementor-location-footer,
        #Footer, #wpadminbar { display: none !important; }
        html { margin-top: 0 !important; }
        html, body { background: #f5f7fa !important; margin: 0 !important; padding: 0 !important; }
        .e-con, .e-con-inner, .e-con-boxed,
        .elementor-widget-container, .elementor-shortcode,
        .elementor-element.elementor-element-populated,
        [data-elementor-type="wp-page"],
        .elementor .elementor-section.elementor-section-boxed > .elementor-container,
        .container, .blog-content, .layout-width,
        .wide-posts-block, .row,
        .body-article, .text-area, .item-art,
        .inner-item-art, .single-no-feature-img {
            max-width: 100% !important; width: 100% !important;
            padding: 0 !important; margin: 0 !important;
        }
    </style>';
    include CPB_PLUGIN_DIR . 'templates/portal-dashboard.php';
    return ob_get_clean();
}

add_shortcode('cpb_builder', 'cpb_render_builder');
function cpb_render_builder($atts) {
    ob_start();
    include CPB_PLUGIN_DIR . 'templates/builder.php';
    return ob_get_clean();
}

/* ══════════════════════════════════════════════════
   BRIEFSYNC WEBSITE PAGE TEMPLATES
   Custom page templates served from the plugin
   ══════════════════════════════════════════════════ */
add_filter('theme_page_templates', 'cpb_register_page_templates');
function cpb_register_page_templates($templates) {
    $templates['cpb-page-home']       = 'BriefSync: Homepage';
    $templates['cpb-page-about']      = 'BriefSync: About';
    $templates['cpb-page-services']   = 'BriefSync: Services';
    $templates['cpb-page-add-ons']    = 'BriefSync: Add-Ons';
    $templates['cpb-page-contact']    = 'BriefSync: Contact';
    $templates['cpb-page-howitworks'] = 'BriefSync: How It Works';
    $templates['cpb-page-pricing']    = 'BriefSync: Pricing';
    $templates['cpb-page-template']   = 'BriefSync: Blank Template';
    $templates['cpb-page-briefcase']  = 'BriefSync: Briefcase';
    $templates['cpb-page-terms']      = 'BriefSync: Terms';
    $templates['cpb-page-privacy']    = 'BriefSync: Privacy';
    return $templates;
}

add_filter('template_include', 'cpb_load_page_template', 999999);
function cpb_load_page_template($template) {
    // Get the queried page ID (works for front page, regular pages, etc.)
    $post_id = get_queried_object_id();
    if (!$post_id) return $template;

    $page_template = get_post_meta($post_id, '_wp_page_template', true);

    // Auto-fix: if template is missing/default, assign by slug
    if (!$page_template || $page_template === 'default') {
        $slug = get_post_field('post_name', $post_id);
        $slug_map = [
            'pricing'    => 'cpb-page-pricing',
            'add-ons'    => 'cpb-page-add-ons',
            'contact'    => 'cpb-page-contact',
            'about'      => 'cpb-page-about',
            'about-us'   => 'cpb-page-about',
            'how-it-works' => 'cpb-page-howitworks',
            'web-assist'   => 'cpb-page-services',
            'briefcase'  => 'cpb-page-briefcase',
            'terms'      => 'cpb-page-terms',
            'privacy'    => 'cpb-page-privacy',
        ];
        if (isset($slug_map[$slug])) {
            $page_template = $slug_map[$slug];
            update_post_meta($post_id, '_wp_page_template', $page_template);
        } else {
            return $template;
        }
    }

    $map = [
        'cpb-page-home'       => 'page-index.php',
        'cpb-page-about'      => 'page-about.php',
        'cpb-page-services'   => 'page-services.php',
        'cpb-page-add-ons'    => 'page-add-ons.php',
        'cpb-page-contact'    => 'page-contact.php',
        'cpb-page-howitworks' => 'page-howitworks.php',
        'cpb-page-pricing'    => 'page-pricing.php',
        'cpb-page-template'   => 'page-template.php',
        'cpb-page-briefcase'  => 'page-briefcase.php',
        'cpb-page-terms'      => 'page-terms.php',
        'cpb-page-privacy'    => 'page-privacy.php',
    ];
    if (isset($map[$page_template])) {
        $file = CPB_PLUGIN_DIR . 'templates/pages/' . $map[$page_template];
        if (file_exists($file)) {
            return $file;
        }
    }
    return $template;
}

/* ── Services page now has its own template — redirect removed ──── */

/* ── Enqueue original portal frontend assets ──── */
add_action('wp_enqueue_scripts', 'cpb_enqueue_frontend');
function cpb_enqueue_frontend() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;

    // Check for any portal shortcode
    $has_portal = has_shortcode($post->post_content, 'client_portal_dashboard')
               || has_shortcode($post->post_content, 'cpp_dashboard')
               || has_shortcode($post->post_content, 'client-portal');

    $has_builder = has_shortcode($post->post_content, 'client_portal_builder')
                || has_shortcode($post->post_content, 'cpb_portal')
                || has_shortcode($post->post_content, 'cpb_builder');

    // Original portal assets
    if ($has_portal || isset($_GET['cpp_view_ticket'])) {
        wp_enqueue_style('dashicons');

        if (file_exists(CPB_PLUGIN_DIR . 'assets-portal/css/cpp-frontend.css')) {
            $css_ver = filemtime(CPB_PLUGIN_DIR . 'assets-portal/css/cpp-frontend.css');
            wp_enqueue_style('cpp-frontend', CPB_PLUGIN_URL . 'assets-portal/css/cpp-frontend.css', [], $css_ver);
        }
        if (file_exists(CPB_PLUGIN_DIR . 'assets-portal/js/cpp-frontend.js')) {
            $js_ver = filemtime(CPB_PLUGIN_DIR . 'assets-portal/js/cpp-frontend.js');
            wp_enqueue_script('cpp-frontend', CPB_PLUGIN_URL . 'assets-portal/js/cpp-frontend.js', ['jquery'], $js_ver, true);
            wp_localize_script('cpp-frontend', 'cpp_ajax', [
                'ajax_url'     => admin_url('admin-ajax.php'),
                'stats_nonce'  => wp_create_nonce('cpp_stats_nonce'),
                'upload_nonce' => wp_create_nonce('cpp_upload_nonce'),
                'form_nonce'   => wp_create_nonce('cpp_form_submit'),
                'ticket_nonce' => wp_create_nonce('cpp_ticket_nonce'),
            ]);
        }
        if (file_exists(CPB_PLUGIN_DIR . 'assets-portal/js/cpp-uploads.js')) {
            wp_enqueue_script('cpp-uploads', CPB_PLUGIN_URL . 'assets-portal/js/cpp-uploads.js', ['jquery'], CPB_VERSION, true);
        }
        if (file_exists(CPB_PLUGIN_DIR . 'assets-portal/css/cpp-uploads.css')) {
            wp_enqueue_style('cpp-uploads', CPB_PLUGIN_URL . 'assets-portal/css/cpp-uploads.css', [], CPB_VERSION);
        }
    }

    // Builder assets (for builder shortcode pages AND original portal with integrated builder)
    if ($has_builder || $has_portal || isset($_GET['cpb_view'])) {
        wp_enqueue_style('cpb-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', [], '5.3.3');
        wp_enqueue_style('bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css', [], '1.11.3');
        wp_enqueue_style('cpb-frontend', CPB_PLUGIN_URL . 'assets/css/cpb-frontend.css', ['cpb-bootstrap'], CPB_VERSION);

        wp_enqueue_script('cpb-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', [], '5.3.3', true);
        wp_enqueue_script('cpb-frontend', CPB_PLUGIN_URL . 'assets/js/cpb-frontend.js', ['jquery', 'cpb-bootstrap'], CPB_VERSION, true);
        wp_localize_script('cpb-frontend', 'cpb_ajax', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('cpb_nonce'),
            'plugin_url' => CPB_PLUGIN_URL,
            'user_id'    => get_current_user_id(),
        ]);
    }

    // AJAX-to-REST proxy — converts POST AJAX to PUT REST to bypass ModSecurity
    if ($has_portal || $has_builder) {
        wp_enqueue_script('cpp-ajax-proxy', CPB_PLUGIN_URL . 'assets/js/cpp-ajax-proxy.js', [], CPB_VERSION, true);
        wp_add_inline_script('cpp-ajax-proxy', 'window.cppRestProxyUrl = "' . esc_url(rest_url('cpp-proxy/v1/ajax')) . '"; window.cppRestNonce = "' . wp_create_nonce('wp_rest') . '";', 'before');
    }
}

/* ── REST Proxy: forwards PUT/GET requests to wp_ajax handlers ── */
add_action('rest_api_init', function () {
    register_rest_route('cpp-proxy/v1', '/ajax', array(
        'methods'  => array('PUT', 'GET'),
        'callback' => 'cpb_rest_proxy_handler',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ));
});

function cpb_rest_proxy_handler($request) {
    $params = $request->get_json_params();
    if (empty($params)) {
        $params = $request->get_query_params();
    }

    $action = isset($params['action']) ? sanitize_key($params['action']) : '';
    if (!$action) {
        return new WP_REST_Response(array('success' => false, 'data' => 'Missing action'), 400);
    }

    // Only allow cpp_ prefixed actions for security
    if (strpos($action, 'cpp_') !== 0 && strpos($action, 'cpb_') !== 0) {
        return new WP_REST_Response(array('success' => false, 'data' => 'Invalid action'), 403);
    }

    // Set up $_POST and $_REQUEST so AJAX handlers can read params
    foreach ($params as $key => $value) {
        $_POST[$key] = $value;
        $_REQUEST[$key] = $value;
    }

    // Generate and set the nonce the handler expects
    $nonce_action = '';
    if (isset($params['nonce'])) {
        // Pass through client-provided nonce
        $_POST['nonce'] = $params['nonce'];
        $_REQUEST['nonce'] = $params['nonce'];
    }

    // Capture output from wp_send_json_*
    ob_start();
    do_action('wp_ajax_' . $action);
    $output = ob_get_clean();

    if ($output) {
        $decoded = json_decode($output, true);
        if ($decoded !== null) {
            return new WP_REST_Response($decoded, 200);
        }
        return new WP_REST_Response(array('success' => false, 'data' => $output), 500);
    }

    return new WP_REST_Response(array('success' => false, 'data' => 'No handler'), 404);
}

/* ── Preconnect hints for CDN domains — speeds up first paint ── */
add_action('wp_head', 'cpb_resource_hints', 1);
function cpb_resource_hints() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;
    $is_portal = has_shortcode($post->post_content, 'client_portal_dashboard')
              || has_shortcode($post->post_content, 'cpp_dashboard')
              || has_shortcode($post->post_content, 'client-portal');
    if (!$is_portal) return;
    echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="//cdn.jsdelivr.net">' . "\n";
}

/* ── Strip heavy theme/Elementor assets on portal pages ── */
add_action('wp_enqueue_scripts', 'cpb_strip_portal_bloat', 999);
function cpb_strip_portal_bloat() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;

    $is_portal = has_shortcode($post->post_content, 'client_portal_dashboard')
              || has_shortcode($post->post_content, 'cpp_dashboard')
              || has_shortcode($post->post_content, 'client-portal');
    if (!$is_portal) return;

    // Scripts the portal never needs
    $kill_scripts = [
        // Phox theme — map, countdown, animations, duplicate bootstrap
        'phox-ammap', 'ammap', 'phox-worldlow', 'worldLow',
        'phox-countdown', 'jquery-countdown', 'phox-bootstrap',
        'phox-plugins', 'phox-popper', 'phox-custom',
        // Elementor frontend stack
        'elementor-frontend', 'elementor-frontend-modules',
        'elementor-pro-frontend', 'elementor-pro-elements-handlers',
        'elementor-webpack-runtime', 'elementor-pro-webpack-runtime',
        // Elementor addons
        'eael-general', 'essential-addons-elementor',
        // Phox host / popup
        'phox-host-elementor-widgets', 'phox-host-popup',
        'phox-host-micromodal', 'phox-host-anime',
        // WP extras the portal doesn't need
        'backbone', 'underscore', 'wp-i18n', 'wp-hooks',
        'jquery-ui-core',
    ];

    // Also catch by partial handle match for theme scripts
    global $wp_scripts;
    if (isset($wp_scripts->registered)) {
        foreach ($wp_scripts->registered as $handle => $script) {
            $src = isset($script->src) ? $script->src : '';
            if (strpos($src, '/themes/phox/') !== false
             || strpos($src, '/phox-host/') !== false
             || strpos($src, '/elementor/assets/js/') !== false
             || strpos($src, '/elementor-pro/assets/js/') !== false
             || strpos($src, '/essential-addons-for-elementor') !== false) {
                $kill_scripts[] = $handle;
            }
        }
    }

    foreach (array_unique($kill_scripts) as $h) {
        wp_dequeue_script($h);
        wp_deregister_script($h);
    }

    // Kill heavy theme/Elementor/addon styles
    $kill_styles = [
        'domain-search-for-whmcs',
        'google-fonts',
        'elementor-gf-local-roboto',
        'elementor-gf-local-robotoslab',
    ];
    global $wp_styles;
    if (isset($wp_styles->registered)) {
        foreach ($wp_styles->registered as $handle => $style) {
            $src = isset($style->src) ? $style->src : '';
            if (strpos($src, '/elementor/assets/') !== false
             || strpos($src, '/elementor-pro/assets/') !== false
             || strpos($src, '/themes/phox/') !== false
             || strpos($src, '/phox-host/') !== false
             || strpos($src, '/essential-addons-for-elementor') !== false
             || strpos($src, '/uploads/elementor/') !== false) {
                $kill_styles[] = $handle;
            }
        }
    }
    foreach (array_unique($kill_styles) as $h) {
        wp_dequeue_style($h);
        wp_deregister_style($h);
    }
}

/* ── Hide admin bar for non-portal-admins ─────── */
add_filter('show_admin_bar', function ($show) {
    return current_user_can('cpp_manage_portal') ? $show : false;
});

/* ── Ensure role capabilities are up to date ────── */
add_action('admin_init', function() {
    $ver_key = 'cpb_roles_version';
    if (get_option($ver_key) === '2') return;

    // Update Operator: add chat takeover
    $op = get_role('portal_operator');
    if ($op) {
        $op->add_cap('cpp_chat_takeover');
    }

    // Update Admin: add chat takeover
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap('cpp_chat_takeover');
    }

    // Developer does NOT get cpp_chat_takeover
    update_option($ver_key, '2');
});

/* ── Live Chat Widget on public pages ────────── */
add_action('wp_footer', 'cpb_livechat_widget', 99);
function cpb_livechat_widget() {
    if (is_admin()) return;
    $enabled = get_option('cpb_livechat_enabled', '1');
    if ($enabled !== '1') return;

    // Direct output (not wp_enqueue) so it works on standalone templates
    // that call wp_footer() but not wp_head(), and at any hook priority.
    $css_url = CPB_PLUGIN_URL . 'assets/css/cpb-livechat-widget.css?ver=' . CPB_VERSION;
    $js_url  = CPB_PLUGIN_URL . 'assets/js/cpb-livechat-widget.js?ver=' . CPB_VERSION;
    $chat_data = [
        'ajax_url' => admin_url('admin-ajax.php'),
        'rest_url' => rest_url('cpp-chat/v1'),
        'nonce'    => wp_create_nonce('cpb_livechat_nonce'),
        'greeting' => get_option('cpb_livechat_greeting', 'Hi! How can we help you today?'),
        'bot_name' => get_option('cpb_livechat_bot_name', 'BriefSync Assistant'),
        'accent'   => get_option('cpb_livechat_accent_color', '#1E78CD'),
        'plugin_url' => CPB_PLUGIN_URL,
    ];
    // Auto-fill name/email for logged-in users so they skip the prechat form
    if (is_user_logged_in()) {
        $cu = wp_get_current_user();
        $chat_data['user_name']  = $cu->display_name;
        $chat_data['user_email'] = $cu->user_email;
    }
    $config = json_encode($chat_data);
    echo '<link rel="stylesheet" href="' . esc_url($css_url) . '">' . "\n";
    echo '<script>var cpbLiveChat = ' . $config . ';</script>' . "\n";
    // Load jQuery from CDN if not already present (standalone templates)
    echo '<script>if(typeof jQuery==="undefined"){var s=document.createElement("script");s.src="https://code.jquery.com/jquery-3.7.1.min.js";s.onload=function(){var t=document.createElement("script");t.src="' . esc_url($js_url) . '";document.body.appendChild(t);};document.body.appendChild(s);}else{var t=document.createElement("script");t.src="' . esc_url($js_url) . '";document.body.appendChild(t);}</script>' . "\n";
}

/* ── Capture Modal + JS (logged-in users, triggered from chat widget) ── */
add_action('wp_footer', 'cpb_floating_capture_button', 98);
function cpb_floating_capture_button() {
    if (is_admin() || !is_user_logged_in()) return;

    // Find the portal page URL for saving captures
    $portal_pages = get_option('cpp_portal_pages', array());
    $portal_url = '';
    if (!empty($portal_pages)) {
        $portal_url = get_permalink(reset($portal_pages));
    }
    if (!$portal_url) {
        // Try to find by slug
        $page = get_page_by_path('mybriefcase');
        if ($page) $portal_url = get_permalink($page->ID);
    }
    if (!$portal_url) return; // Can't find portal page

    $fb_base = add_query_arg('section', 'feedback', $portal_url);
    $fb_nonce = wp_create_nonce('cpp_feedback_action');
    $rest_nonce = wp_create_nonce('wp_rest');
    $upload_url = esc_url(rest_url('cpp-feedback/v1/upload'));

    include CPB_PLUGIN_DIR . 'templates/partials/floating-capture-sitewide.php';
}

/* ── AJAX handlers (builder) ─────────────────── */
require_once CPB_PLUGIN_DIR . 'includes/ajax-handlers.php';
