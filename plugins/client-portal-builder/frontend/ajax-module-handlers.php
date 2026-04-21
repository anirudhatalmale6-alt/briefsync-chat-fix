<?php
if (!defined('ABSPATH')) exit;

/**
 * BriefSync Portal Module AJAX Handlers
 * Handles: Projects, Tasks, Calendar, Chat, Forms, Members (portal versions)
 */

/* ── Delete permission guard ─────────────────────── */
/* Operator and Developer roles cannot delete anything. Only Admin can. */
function cpp_require_delete_permission() {
    if (current_user_can('manage_options')) return; // Admin OK
    wp_send_json_error(array('message' => 'Only administrators can delete items.'));
}

/* ── Generic client access helper ─────────────────── */
function cpp_portal_require_client($nonce_action = 'cpp_portal_nonce') {
    check_ajax_referer($nonce_action, 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    $user_id = get_current_user_id();
    if (!class_exists('CPP_Client_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id($user_id);
    if (!$client) {
        wp_send_json_error(array('message' => 'No client profile found.'));
    }
    return $client;
}

/* ══════════════════════════════════════════════════════
   DB TABLE CREATION
   ══════════════════════════════════════════════════════ */
function cpp_portal_maybe_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Projects
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_projects (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'planning',
        due_date DATE DEFAULT NULL,
        progress_pct TINYINT UNSIGNED DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id)
    ) $charset;");

    // Tasks
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_tasks (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        project_id BIGINT(20) UNSIGNED DEFAULT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'todo',
        priority VARCHAR(16) NOT NULL DEFAULT 'normal',
        due_date DATE DEFAULT NULL,
        parent_id BIGINT(20) UNSIGNED DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY project_id (project_id),
        KEY parent_id (parent_id)
    ) $charset;");

    // Calendar Events
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_events (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        event_date DATE NOT NULL,
        event_end_date DATE DEFAULT NULL,
        event_time VARCHAR(10) DEFAULT NULL,
        all_day TINYINT(1) DEFAULT 1,
        color VARCHAR(20) DEFAULT '#1E78CD',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY event_date (event_date)
    ) $charset;");

    // Chat Threads
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_chat_threads (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        subject VARCHAR(255) NOT NULL DEFAULT 'General',
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY status (status)
    ) $charset;");

    // Chat Messages
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_chat (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        thread_id BIGINT(20) UNSIGNED DEFAULT 0,
        sender_id BIGINT(20) UNSIGNED NOT NULL,
        sender_role VARCHAR(20) NOT NULL DEFAULT 'client',
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY thread_id (thread_id),
        KEY is_read (is_read)
    ) $charset;");

    // Forms
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_forms (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        fields_json LONGTEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id)
    ) $charset;");

    // Form Submissions
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_form_submissions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id BIGINT(20) UNSIGNED NOT NULL,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        data_json LONGTEXT,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY form_id (form_id),
        KEY client_id (client_id)
    ) $charset;");

    // Appointments
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_appointments (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time VARCHAR(10) DEFAULT NULL,
        subject VARCHAR(255) NOT NULL,
        notes TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY appointment_date (appointment_date),
        KEY status (status)
    ) $charset;");

    // Invoices
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_invoices (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        invoice_number VARCHAR(50) NOT NULL,
        description TEXT,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        due_date DATE DEFAULT NULL,
        paid_date DATE DEFAULT NULL,
        notes TEXT,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY status (status),
        KEY due_date (due_date)
    ) $charset;");

    // Backups
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_backups (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        backup_date DATETIME NOT NULL,
        size_mb DECIMAL(10,2) DEFAULT 0.00,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        type VARCHAR(20) NOT NULL DEFAULT 'auto',
        notes TEXT,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY backup_date (backup_date)
    ) $charset;");

    // Backup Subscriptions
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_backup_subscriptions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        plan VARCHAR(100) DEFAULT 'Basic',
        price DECIMAL(10,2) DEFAULT 0.00,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id)
    ) $charset;");

    // Email Accounts
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_email_accounts (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        email_address VARCHAR(255) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        quota_mb INT UNSIGNED DEFAULT 500,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY status (status)
    ) $charset;");

    // Subscriptions
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_subscriptions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        plan_name VARCHAR(255) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly',
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        next_billing_date DATE DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY status (status)
    ) $charset;");

    // Subscription Plans
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_subscription_plans (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        price_monthly DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        price_yearly DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        features_json LONGTEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY status (status)
    ) $charset;");

    // Tech Support
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_tech_support (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        category VARCHAR(32) NOT NULL DEFAULT 'other',
        priority VARCHAR(16) NOT NULL DEFAULT 'normal',
        subject VARCHAR(255) NOT NULL,
        description TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'submitted',
        admin_notes TEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY status (status),
        KEY category (category)
    ) $charset;");

    // Presets
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_presets (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        plan_type VARCHAR(20) NOT NULL DEFAULT 'website',
        thumbnail_url TEXT,
        content_html LONGTEXT,
        block_data LONGTEXT,
        page_type VARCHAR(100) DEFAULT 'Home',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        sort_order INT UNSIGNED DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY plan_type (plan_type),
        KEY status (status),
        KEY sort_order (sort_order)
    ) $charset;");

    // Add block_data column if missing (for existing installs)
    $col_check = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}cpp_portal_presets LIKE 'block_data'");
    if (empty($col_check)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}cpp_portal_presets ADD COLUMN block_data LONGTEXT AFTER content_html");
    }

    // Add parent_id column to tasks if missing (for subtasks)
    $col_check2 = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}cpp_portal_tasks LIKE 'parent_id'");
    if (empty($col_check2)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}cpp_portal_tasks ADD COLUMN parent_id BIGINT(20) UNSIGNED DEFAULT 0 AFTER due_date");
        $wpdb->query("ALTER TABLE {$wpdb->prefix}cpp_portal_tasks ADD KEY parent_id (parent_id)");
    }

    // Client Presets (activated by clients)
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_client_presets (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        preset_id BIGINT(20) UNSIGNED NOT NULL,
        customized_content LONGTEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY preset_id (preset_id)
    ) $charset;");

    // Developer Assistance Sessions
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_dev_sessions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        topic VARCHAR(255) NOT NULL,
        description TEXT,
        scheduled_date DATE DEFAULT NULL,
        scheduled_time VARCHAR(10) DEFAULT NULL,
        timezone VARCHAR(50) DEFAULT 'America/New_York',
        status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY status (status)
    ) $charset;");

    // Developer Assistance Messages
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_dev_messages (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        sender_id BIGINT(20) UNSIGNED NOT NULL,
        sender_type VARCHAR(20) NOT NULL DEFAULT 'client',
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY created_at (created_at)
    ) $charset;");

    // Item Shares (cross-module sharing)
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_item_shares (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        item_type VARCHAR(32) NOT NULL,
        item_id BIGINT(20) UNSIGNED NOT NULL,
        shared_by_client_id BIGINT(20) UNSIGNED NOT NULL,
        shared_with_client_id BIGINT(20) UNSIGNED NOT NULL,
        access_level VARCHAR(20) NOT NULL DEFAULT 'view',
        shared_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY item_lookup (item_type, item_id),
        KEY shared_with (shared_with_client_id),
        UNIQUE KEY unique_share (item_type, item_id, shared_with_client_id)
    ) $charset;");

    // Builder Pages
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_pages (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        client_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        blocks_json LONGTEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY client_id (client_id),
        KEY slug (slug),
        KEY status (status)
    ) $charset;");

    // Page Feedback Comments (point-and-click)
    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cpp_portal_page_comments (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        user_name VARCHAR(255) NOT NULL,
        pin_x DECIMAL(6,2) NOT NULL DEFAULT 0,
        pin_y DECIMAL(6,2) NOT NULL DEFAULT 0,
        comment_text TEXT NOT NULL,
        attachment_url VARCHAR(500) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        parent_id BIGINT(20) UNSIGNED DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY page_id (page_id),
        KEY status (status),
        KEY parent_id (parent_id)
    ) $charset;");
}
add_action('init', function() {
    if (get_option('cpp_portal_tables_v') !== '2.2') {
        cpp_portal_maybe_create_tables();
        update_option('cpp_portal_tables_v', '2.2');
    }
});

/* ══════════════════════════════════════════════════════
   MEMBERS (portal version — no plan restriction)
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_get_members', 'cpp_portal_get_members');
function cpp_portal_get_members() {
    $client = cpp_portal_require_client('cpp_members_nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_members';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, member_name, email_address, role_position, created_at FROM {$table} WHERE client_id = %d ORDER BY created_at DESC",
        intval($client['id'])
    ), ARRAY_A);
    wp_send_json_success(array('items' => $rows ?: array()));
}

add_action('wp_ajax_cpp_portal_create_member', 'cpp_portal_create_member');
function cpp_portal_create_member() {
    $client = cpp_portal_require_client('cpp_members_nonce');
    $name  = isset($_POST['member_name']) ? sanitize_text_field(wp_unslash($_POST['member_name'])) : '';
    $email = isset($_POST['email_address']) ? sanitize_email(wp_unslash($_POST['email_address'])) : '';
    $role  = isset($_POST['role_position']) ? sanitize_text_field(wp_unslash($_POST['role_position'])) : '';
    if (!$name || !$email) wp_send_json_error(array('message' => 'Name and email required.'));

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_client_members', array(
        'client_id' => intval($client['id']),
        'member_name' => $name,
        'email_address' => $email,
        'role_position' => $role,
        'created_at' => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to add member.'));
    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_member', 'cpp_portal_update_member');
function cpp_portal_update_member() {
    $client = cpp_portal_require_client('cpp_members_nonce');
    $id    = intval($_POST['id'] ?? 0);
    $name  = sanitize_text_field(wp_unslash($_POST['member_name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['email_address'] ?? ''));
    $role  = sanitize_text_field(wp_unslash($_POST['role_position'] ?? ''));
    if (!$id || !$name || !$email) wp_send_json_error(array('message' => 'Invalid data.'));

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_client_members',
        array('member_name' => $name, 'email_address' => $email, 'role_position' => $role),
        array('id' => $id, 'client_id' => intval($client['id']))
    );
    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_member', 'cpp_portal_delete_member');
function cpp_portal_delete_member() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_members_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'cpp_client_members', array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* ══════════════════════════════════════════════════════
   PROJECTS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_get_projects', 'cpp_portal_get_projects');
function cpp_portal_get_projects() {
    $client = cpp_portal_require_client('cpp_projects_nonce');
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cpp_portal_projects WHERE client_id = %d ORDER BY updated_at DESC",
        intval($client['id'])
    ), ARRAY_A);
    wp_send_json_success(array('items' => $rows ?: array()));
}

/* Get tasks for a specific project */
add_action('wp_ajax_cpp_portal_get_project_tasks', 'cpp_portal_get_project_tasks');
function cpp_portal_get_project_tasks() {
    $client = cpp_portal_require_client('cpp_projects_nonce');
    $project_id = intval($_POST['project_id'] ?? 0);
    if (!$project_id) wp_send_json_error(array('message' => 'Invalid project ID.'));

    global $wpdb;
    // Verify project ownership
    $owner = $wpdb->get_var($wpdb->prepare(
        "SELECT client_id FROM {$wpdb->prefix}cpp_portal_projects WHERE id = %d", $project_id
    ));
    if (intval($owner) !== intval($client['id']) && !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied.'));
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cpp_portal_tasks WHERE project_id = %d ORDER BY updated_at DESC",
        $project_id
    ), ARRAY_A);
    wp_send_json_success(array('items' => $rows ?: array()));
}

add_action('wp_ajax_cpp_portal_create_project', 'cpp_portal_create_project');
function cpp_portal_create_project() {
    $client = cpp_portal_require_client('cpp_projects_nonce');
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $desc  = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $status = sanitize_key($_POST['status'] ?? 'planning');
    $due   = sanitize_text_field($_POST['due_date'] ?? '');
    if (!$title) wp_send_json_error(array('message' => 'Title required.'));

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_projects', array(
        'client_id' => intval($client['id']),
        'title' => $title,
        'description' => $desc,
        'status' => $status,
        'due_date' => $due ?: null,
        'progress_pct' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create project.'));
    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_project', 'cpp_portal_update_project');
function cpp_portal_update_project() {
    $client = cpp_portal_require_client('cpp_projects_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array('updated_at' => current_time('mysql'));
    if (isset($_POST['title'])) $data['title'] = sanitize_text_field(wp_unslash($_POST['title']));
    if (isset($_POST['description'])) $data['description'] = sanitize_textarea_field(wp_unslash($_POST['description']));
    if (isset($_POST['status'])) $data['status'] = sanitize_key($_POST['status']);
    if (isset($_POST['due_date'])) $data['due_date'] = sanitize_text_field($_POST['due_date']) ?: null;
    if (isset($_POST['progress_pct'])) $data['progress_pct'] = min(100, max(0, intval($_POST['progress_pct'])));

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_projects', $data, array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_project', 'cpp_portal_delete_project');
function cpp_portal_delete_project() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_projects_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'cpp_portal_projects', array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* ══════════════════════════════════════════════════════
   TASKS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_get_tasks', 'cpp_portal_get_tasks');
function cpp_portal_get_tasks() {
    $client = cpp_portal_require_client('cpp_tasks_nonce');
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cpp_portal_tasks WHERE client_id = %d ORDER BY updated_at DESC",
        intval($client['id'])
    ), ARRAY_A);
    wp_send_json_success(array('items' => $rows ?: array()));
}

add_action('wp_ajax_cpp_portal_create_task', 'cpp_portal_create_task');
function cpp_portal_create_task() {
    $client = cpp_portal_require_client('cpp_tasks_nonce');
    $title    = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $desc     = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $status   = sanitize_key($_POST['status'] ?? 'todo');
    $priority = sanitize_key($_POST['priority'] ?? 'normal');
    $due      = sanitize_text_field($_POST['due_date'] ?? '');
    $project  = intval($_POST['project_id'] ?? 0);
    $parent   = intval($_POST['parent_id'] ?? 0);
    if (!$title) wp_send_json_error(array('message' => 'Title required.'));

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_tasks', array(
        'client_id' => intval($client['id']),
        'project_id' => $project ?: null,
        'title' => $title,
        'description' => $desc,
        'status' => $status,
        'priority' => $priority,
        'due_date' => $due ?: null,
        'parent_id' => $parent,
        'created_at' => $now,
        'updated_at' => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create task.'));

    // Email notification
    if (function_exists('cpp_notify')) {
        $cd = cpp_notify_resolve_client($client);
        cpp_notify('task_created', array(
            'client_name'  => $cd['client_name'],
            'client_email' => $cd['client_email'],
            'title'        => $title,
            'priority'     => $priority,
            'due_date'     => $due ?: 'Not set',
        ));
    }

    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_task', 'cpp_portal_update_task');
function cpp_portal_update_task() {
    $client = cpp_portal_require_client('cpp_tasks_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array('updated_at' => current_time('mysql'));
    if (isset($_POST['title'])) $data['title'] = sanitize_text_field(wp_unslash($_POST['title']));
    if (isset($_POST['description'])) $data['description'] = sanitize_textarea_field(wp_unslash($_POST['description']));
    if (isset($_POST['status'])) $data['status'] = sanitize_key($_POST['status']);
    if (isset($_POST['priority'])) $data['priority'] = sanitize_key($_POST['priority']);
    if (isset($_POST['due_date'])) $data['due_date'] = sanitize_text_field($_POST['due_date']) ?: null;

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_tasks', $data, array('id' => $id, 'client_id' => intval($client['id'])));

    // Email notification for status changes
    if (isset($_POST['status']) && function_exists('cpp_notify')) {
        $cd = cpp_notify_resolve_client($client);
        cpp_notify('task_updated', array(
            'client_name'  => $cd['client_name'],
            'client_email' => $cd['client_email'],
            'title'        => $data['title'] ?? '',
            'new_status'   => sanitize_key($_POST['status']),
        ));
    }

    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_task', 'cpp_portal_delete_task');
function cpp_portal_delete_task() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_tasks_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));
    global $wpdb;
    // Delete subtasks too
    $wpdb->delete($wpdb->prefix . 'cpp_portal_tasks', array('parent_id' => $id, 'client_id' => intval($client['id'])));
    $wpdb->delete($wpdb->prefix . 'cpp_portal_tasks', array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* ══════════════════════════════════════════════════════
   CALENDAR EVENTS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_get_events', 'cpp_portal_get_events');
function cpp_portal_get_events() {
    $client = cpp_portal_require_client('cpp_calendar_nonce');
    $month = intval($_POST['month'] ?? date('n'));
    $year  = intval($_POST['year'] ?? date('Y'));
    $start = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $end   = date('Y-m-t', strtotime($start));

    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cpp_portal_events WHERE client_id = %d AND event_date BETWEEN %s AND %s ORDER BY event_date, event_time",
        intval($client['id']), $start, $end
    ), ARRAY_A);

    // Also fetch shared events if shared_ids provided
    $shared_items = array();
    if (!empty($_POST['shared_ids'])) {
        $shared_ids = json_decode(stripslashes($_POST['shared_ids']), true);
        if (is_array($shared_ids) && !empty($shared_ids)) {
            $ids_in = implode(',', array_map('intval', $shared_ids));
            $shared_items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cpp_portal_events WHERE id IN ({$ids_in}) AND event_date BETWEEN %s AND %s ORDER BY event_date, event_time",
                $start, $end
            ), ARRAY_A);
        }
    }

    // Fetch tasks with created_at in this month (start dates)
    $task_events = array();
    $task_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, status, priority, created_at FROM {$wpdb->prefix}cpp_portal_tasks WHERE client_id = %d AND DATE(created_at) BETWEEN %s AND %s ORDER BY created_at",
        intval($client['id']), $start, $end
    ), ARRAY_A);
    if ($task_rows) {
        foreach ($task_rows as $t) {
            $task_events[] = array(
                'id' => 'task_' . $t['id'],
                'title' => $t['title'],
                'event_date' => date('Y-m-d', strtotime($t['created_at'])),
                'event_time' => date('H:i', strtotime($t['created_at'])),
                'color' => '#7367f0',
                'all_day' => 0,
                'description' => 'Task - ' . ucwords(str_replace('_', ' ', $t['status'])),
                '_type' => 'task',
                '_readonly' => true,
            );
        }
    }

    // Fetch projects - both created_at (start) and due_date
    $project_events = array();
    // Projects started this month
    $prj_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, status, created_at, due_date FROM {$wpdb->prefix}cpp_portal_projects WHERE client_id = %d AND DATE(created_at) BETWEEN %s AND %s ORDER BY created_at",
        intval($client['id']), $start, $end
    ), ARRAY_A);
    if ($prj_rows) {
        foreach ($prj_rows as $p) {
            $project_events[] = array(
                'id' => 'project_start_' . $p['id'],
                'title' => $p['title'] . ' (Start)',
                'event_date' => date('Y-m-d', strtotime($p['created_at'])),
                'event_time' => date('H:i', strtotime($p['created_at'])),
                'color' => '#28c76f',
                'all_day' => 0,
                'description' => 'Project Started - ' . ucwords(str_replace('_', ' ', $p['status'])),
                '_type' => 'project',
                '_readonly' => true,
            );
        }
    }
    // Projects due this month
    $prj_due_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, status, due_date FROM {$wpdb->prefix}cpp_portal_projects WHERE client_id = %d AND due_date IS NOT NULL AND due_date BETWEEN %s AND %s ORDER BY due_date",
        intval($client['id']), $start, $end
    ), ARRAY_A);
    if ($prj_due_rows) {
        foreach ($prj_due_rows as $p) {
            $project_events[] = array(
                'id' => 'project_due_' . $p['id'],
                'title' => $p['title'] . ' (Due)',
                'event_date' => $p['due_date'],
                'event_time' => '23:59',
                'color' => '#ea5455',
                'all_day' => 0,
                'description' => 'Project Due - ' . ucwords(str_replace('_', ' ', $p['status'])),
                '_type' => 'project',
                '_readonly' => true,
            );
        }
    }
    // Tasks with due dates this month
    $task_due_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, status, due_date FROM {$wpdb->prefix}cpp_portal_tasks WHERE client_id = %d AND due_date IS NOT NULL AND due_date BETWEEN %s AND %s ORDER BY due_date",
        intval($client['id']), $start, $end
    ), ARRAY_A);
    if ($task_due_rows) {
        foreach ($task_due_rows as $t) {
            $task_events[] = array(
                'id' => 'task_due_' . $t['id'],
                'title' => $t['title'] . ' (Due)',
                'event_date' => $t['due_date'],
                'event_time' => '23:59',
                'color' => '#ff9f43',
                'all_day' => 0,
                'description' => 'Task Due - ' . ucwords(str_replace('_', ' ', $t['status'])),
                '_type' => 'task',
                '_readonly' => true,
            );
        }
    }

    // Merge task/project events into main items
    $all_items = array_merge($rows ?: array(), $task_events, $project_events);

    wp_send_json_success(array('items' => $all_items, 'shared_items' => $shared_items ?: array()));
}

add_action('wp_ajax_cpp_portal_create_event', 'cpp_portal_create_event');
function cpp_portal_create_event() {
    $client = cpp_portal_require_client('cpp_calendar_nonce');
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $desc  = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $date  = sanitize_text_field($_POST['event_date'] ?? '');
    $time  = sanitize_text_field($_POST['event_time'] ?? '');
    $color = sanitize_hex_color($_POST['color'] ?? '#1E78CD') ?: '#1E78CD';
    if (!$title || !$date) wp_send_json_error(array('message' => 'Title and date required.'));

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'cpp_portal_events', array(
        'client_id' => intval($client['id']),
        'title' => $title,
        'description' => $desc,
        'event_date' => $date,
        'event_time' => $time ?: null,
        'all_day' => empty($time) ? 1 : 0,
        'color' => $color,
        'created_at' => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create event.'));
    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_event', 'cpp_portal_update_event');
function cpp_portal_update_event() {
    $client = cpp_portal_require_client('cpp_calendar_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array();
    if (isset($_POST['title'])) $data['title'] = sanitize_text_field(wp_unslash($_POST['title']));
    if (isset($_POST['description'])) $data['description'] = sanitize_textarea_field(wp_unslash($_POST['description']));
    if (isset($_POST['event_date'])) $data['event_date'] = sanitize_text_field($_POST['event_date']);
    if (isset($_POST['event_time'])) { $data['event_time'] = sanitize_text_field($_POST['event_time']) ?: null; $data['all_day'] = empty($_POST['event_time']) ? 1 : 0; }
    if (isset($_POST['color'])) $data['color'] = sanitize_hex_color($_POST['color']) ?: '#1E78CD';

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_events', $data, array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_event', 'cpp_portal_delete_event');
function cpp_portal_delete_event() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_calendar_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'cpp_portal_events', array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* ══════════════════════════════════════════════════════
   CHAT
   ══════════════════════════════════════════════════════ */

// Get all threads for current client
add_action('wp_ajax_cpp_portal_get_threads', 'cpp_portal_get_threads');
function cpp_portal_get_threads() {
    $client = cpp_portal_require_client('cpp_chat_nonce');
    global $wpdb;
    $tt = $wpdb->prefix . 'cpp_portal_chat_threads';
    $mt = $wpdb->prefix . 'cpp_portal_chat';
    $cid = intval($client['id']);

    $threads = $wpdb->get_results($wpdb->prepare(
        "SELECT t.*,
            (SELECT COUNT(*) FROM {$mt} m WHERE m.thread_id = t.id AND m.sender_role != 'client' AND m.is_read = 0) as unread
         FROM {$tt} t WHERE t.client_id = %d ORDER BY t.updated_at DESC LIMIT 100",
        $cid
    ), ARRAY_A);

    // Get last message preview for each thread
    foreach ($threads as &$th) {
        $last = $wpdb->get_row($wpdb->prepare(
            "SELECT message, sender_role, created_at FROM {$mt} WHERE thread_id = %d ORDER BY id DESC LIMIT 1",
            intval($th['id'])
        ), ARRAY_A);
        $th['last_message'] = $last ? wp_trim_words(wp_strip_all_tags($last['message']), 10) : '';
        $th['last_sender'] = $last ? $last['sender_role'] : '';
        $th['last_time'] = $last ? $last['created_at'] : $th['created_at'];
    }
    unset($th);

    wp_send_json_success(array('threads' => $threads ?: array()));
}

// Create a new thread
add_action('wp_ajax_cpp_portal_create_thread', 'cpp_portal_create_thread');
function cpp_portal_create_thread() {
    $client = cpp_portal_require_client('cpp_chat_nonce');
    $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
    if (!$subject) wp_send_json_error(array('message' => 'Subject required.'));

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_chat_threads', array(
        'client_id'  => intval($client['id']),
        'subject'    => $subject,
        'status'     => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create thread.'));
    wp_send_json_success(array('id' => $wpdb->insert_id, 'subject' => $subject));
}

// Get messages for a specific thread
add_action('wp_ajax_cpp_portal_get_chat', 'cpp_portal_get_chat');
function cpp_portal_get_chat() {
    $client = cpp_portal_require_client('cpp_chat_nonce');
    $after_id = intval($_POST['after_id'] ?? 0);
    $thread_id = intval($_POST['thread_id'] ?? 0);
    global $wpdb;
    $t = $wpdb->prefix . 'cpp_portal_chat';
    $cid = intval($client['id']);

    if ($after_id > 0) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE client_id = %d AND thread_id = %d AND id > %d ORDER BY id ASC LIMIT 100",
            $cid, $thread_id, $after_id
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE client_id = %d AND thread_id = %d ORDER BY id DESC LIMIT 50",
            $cid, $thread_id
        ), ARRAY_A);
        $rows = array_reverse($rows);
    }
    // Mark admin messages as read
    $wpdb->query($wpdb->prepare(
        "UPDATE {$t} SET is_read = 1 WHERE client_id = %d AND thread_id = %d AND sender_role != 'client' AND is_read = 0",
        $cid, $thread_id
    ));
    wp_send_json_success(array('messages' => $rows ?: array()));
}

// Send message to a thread
add_action('wp_ajax_cpp_portal_send_chat', 'cpp_portal_send_chat');
function cpp_portal_send_chat() {
    $client = cpp_portal_require_client('cpp_chat_nonce');
    $msg = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
    $attachment_url = isset($_POST['attachment_url']) ? esc_url_raw($_POST['attachment_url']) : '';
    $thread_id = intval($_POST['thread_id'] ?? 0);
    if (!$msg && !$attachment_url) wp_send_json_error(array('message' => 'Message or attachment required.'));
    if (!$thread_id) wp_send_json_error(array('message' => 'Thread required.'));

    global $wpdb;
    $tbl = $wpdb->prefix . 'cpp_portal_chat';

    // Ensure attachment_url column exists
    $col = $wpdb->get_results("SHOW COLUMNS FROM {$tbl} LIKE 'attachment_url'");
    if (empty($col)) {
        $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN attachment_url TEXT DEFAULT NULL AFTER message");
    }

    $now = current_time('mysql');
    $insert_data = array(
        'client_id'   => intval($client['id']),
        'thread_id'   => $thread_id,
        'sender_id'   => get_current_user_id(),
        'sender_role' => 'client',
        'message'     => $msg,
        'is_read'     => 0,
        'created_at'  => $now,
    );
    if ($attachment_url) {
        $insert_data['attachment_url'] = $attachment_url;
    }
    $wpdb->insert($tbl, $insert_data);
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to send.'));

    // Update thread's updated_at
    $wpdb->update($wpdb->prefix . 'cpp_portal_chat_threads',
        array('updated_at' => $now),
        array('id' => $thread_id)
    );

    // Email notification
    if (function_exists('cpp_notify')) {
        $cd = cpp_notify_resolve_client($client);
        $thread_row = $wpdb->get_row($wpdb->prepare(
            "SELECT subject FROM {$wpdb->prefix}cpp_portal_chat_threads WHERE id = %d", $thread_id
        ), ARRAY_A);
        cpp_notify('chat_message', array(
            'client_name'  => $cd['client_name'],
            'client_email' => $cd['client_email'],
            'thread_name'  => $thread_row['subject'] ?? 'Chat',
            'message'      => wp_strip_all_tags($msg ?: '[Attachment]'),
        ));
    }

    wp_send_json_success(array('id' => $wpdb->insert_id, 'created_at' => $now, 'attachment_url' => $attachment_url));
}

/* ══════════════════════════════════════════════════════
   FORMS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_get_forms', 'cpp_portal_get_forms');
function cpp_portal_get_forms() {
    $client = cpp_portal_require_client('cpp_forms_nonce');
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cpp_portal_forms WHERE client_id = %d ORDER BY updated_at DESC",
        intval($client['id'])
    ), ARRAY_A);
    // Get submission counts
    foreach ($rows as &$row) {
        $row['submissions_count'] = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cpp_portal_form_submissions WHERE form_id = %d",
            intval($row['id'])
        )));
    }
    wp_send_json_success(array('items' => $rows ?: array()));
}

add_action('wp_ajax_cpp_portal_create_form', 'cpp_portal_create_form');
function cpp_portal_create_form() {
    $client = cpp_portal_require_client('cpp_forms_nonce');
    $title  = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $desc   = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $fields = isset($_POST['fields_json']) ? wp_unslash($_POST['fields_json']) : '[]';
    if (!$title) wp_send_json_error(array('message' => 'Title required.'));

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_forms', array(
        'client_id' => intval($client['id']),
        'title' => $title,
        'description' => $desc,
        'fields_json' => $fields,
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create form.'));
    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_form', 'cpp_portal_update_form');
function cpp_portal_update_form() {
    $client = cpp_portal_require_client('cpp_forms_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array('updated_at' => current_time('mysql'));
    if (isset($_POST['title'])) $data['title'] = sanitize_text_field(wp_unslash($_POST['title']));
    if (isset($_POST['description'])) $data['description'] = sanitize_textarea_field(wp_unslash($_POST['description']));
    if (isset($_POST['fields_json'])) $data['fields_json'] = wp_unslash($_POST['fields_json']);
    if (isset($_POST['status'])) $data['status'] = sanitize_key($_POST['status']);

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_forms', $data, array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_form', 'cpp_portal_delete_form');
function cpp_portal_delete_form() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_forms_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'cpp_portal_form_submissions', array('form_id' => $id));
    $wpdb->delete($wpdb->prefix . 'cpp_portal_forms', array('id' => $id, 'client_id' => intval($client['id'])));
    wp_send_json_success(array('message' => 'Deleted.'));
}

add_action('wp_ajax_cpp_portal_get_form_submissions', 'cpp_portal_get_form_submissions');
function cpp_portal_get_form_submissions() {
    $client = cpp_portal_require_client('cpp_forms_nonce');
    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id) wp_send_json_error(array('message' => 'Invalid form.'));

    global $wpdb;
    // Verify form belongs to client
    $form = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}cpp_portal_forms WHERE id = %d AND client_id = %d",
        $form_id, intval($client['id'])
    ));
    if (!$form) wp_send_json_error(array('message' => 'Form not found.'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cpp_portal_form_submissions WHERE form_id = %d ORDER BY created_at DESC LIMIT 100",
        $form_id
    ), ARRAY_A);
    wp_send_json_success(array('items' => $rows ?: array()));
}

/* ══════════════════════════════════════════════════════
   PUBLIC FORM SUBMISSION (no login required)
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_briefsync_public_form_submit', 'briefsync_public_form_submit');
add_action('wp_ajax_nopriv_briefsync_public_form_submit', 'briefsync_public_form_submit');
function briefsync_public_form_submit() {
    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id) wp_send_json_error(array('message' => 'Invalid form.'));
    check_ajax_referer('briefsync_form_submit_' . $form_id, 'nonce');

    $data_json = isset($_POST['data_json']) ? wp_unslash($_POST['data_json']) : '{}';

    global $wpdb;
    $form = $wpdb->get_row($wpdb->prepare(
        "SELECT id, client_id FROM {$wpdb->prefix}cpp_portal_forms WHERE id = %d AND status = 'published'",
        $form_id
    ), ARRAY_A);
    if (!$form) wp_send_json_error(array('message' => 'Form not found or not published.'));

    $wpdb->insert($wpdb->prefix . 'cpp_portal_form_submissions', array(
        'form_id'    => $form_id,
        'client_id'  => intval($form['client_id']),
        'data_json'  => $data_json,
        'created_at' => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to save submission.'));
    wp_send_json_success(array('message' => 'Thank you! Your submission has been received.', 'id' => $wpdb->insert_id));
}

/* ══════════════════════════════════════════════════════
   APPOINTMENTS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_create_appointment', 'cpp_portal_create_appointment');
function cpp_portal_create_appointment() {
    $client = cpp_portal_require_client('cpp_appointment_nonce');
    $date    = sanitize_text_field($_POST['appointment_date'] ?? '');
    $time    = sanitize_text_field($_POST['appointment_time'] ?? '');
    $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
    $notes   = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
    if (!$date || !$subject) wp_send_json_error(array('message' => 'Date and subject are required.'));

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'cpp_portal_appointments', array(
        'client_id'        => intval($client['id']),
        'appointment_date' => $date,
        'appointment_time' => $time ?: null,
        'subject'          => $subject,
        'notes'            => $notes,
        'status'           => 'pending',
        'created_at'       => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to book appointment.'));

    // Email notification
    if (function_exists('cpp_notify')) {
        $cd = cpp_notify_resolve_client($client);
        cpp_notify('appointment_booked', array(
            'client_name'  => $cd['client_name'],
            'client_email' => $cd['client_email'],
            'title'        => $subject,
            'date'         => $date . ($time ? ' at ' . $time : ''),
        ));
    }

    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_appointment', 'cpp_portal_update_appointment');
function cpp_portal_update_appointment() {
    $client = cpp_portal_require_client('cpp_appointment_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array();
    if (isset($_POST['status'])) $data['status'] = sanitize_key($_POST['status']);
    if (isset($_POST['appointment_date'])) $data['appointment_date'] = sanitize_text_field($_POST['appointment_date']);
    if (isset($_POST['appointment_time'])) $data['appointment_time'] = sanitize_text_field($_POST['appointment_time']);
    if (isset($_POST['subject'])) $data['subject'] = sanitize_text_field(wp_unslash($_POST['subject']));
    if (isset($_POST['notes'])) $data['notes'] = sanitize_textarea_field(wp_unslash($_POST['notes']));

    global $wpdb;
    $where = array('id' => $id);
    // Non-admins can only update their own appointments
    if (!current_user_can('manage_options')) {
        $where['client_id'] = intval($client['id']);
    }
    $wpdb->update($wpdb->prefix . 'cpp_portal_appointments', $data, $where);
    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_appointment', 'cpp_portal_delete_appointment');
function cpp_portal_delete_appointment() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_appointment_nonce');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    global $wpdb;
    $where = array('id' => $id);
    if (!current_user_can('manage_options')) {
        $where['client_id'] = intval($client['id']);
    }
    $wpdb->delete($wpdb->prefix . 'cpp_portal_appointments', $where);
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* Public appointment submission (no login required) */
add_action('wp_ajax_briefsync_public_appointment_submit', 'briefsync_public_appointment_submit');
add_action('wp_ajax_nopriv_briefsync_public_appointment_submit', 'briefsync_public_appointment_submit');
function briefsync_public_appointment_submit() {
    check_ajax_referer('briefsync_public_appointment', 'nonce');

    $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $email   = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $date    = sanitize_text_field($_POST['appointment_date'] ?? '');
    $time    = sanitize_text_field($_POST['appointment_time'] ?? '');
    $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
    $notes   = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));

    if (!$name || !$email || !$date || !$subject) {
        wp_send_json_error(array('message' => 'Name, email, date, and subject are required.'));
    }

    global $wpdb;
    $full_notes = "Public booking by: {$name} ({$email})" . ($notes ? "\n{$notes}" : '');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_appointments', array(
        'client_id'        => 0,
        'appointment_date' => $date,
        'appointment_time' => $time ?: null,
        'subject'          => $subject,
        'notes'            => $full_notes,
        'status'           => 'pending',
        'created_at'       => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to book appointment.'));
    wp_send_json_success(array('message' => 'Appointment request submitted! We will confirm shortly.', 'id' => $wpdb->insert_id));
}

/* ══════════════════════════════════════════════════════
   FINANCE / INVOICES
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_create_invoice', 'cpp_portal_create_invoice');
function cpp_portal_create_invoice() {
    $client = cpp_portal_require_client('cpp_finance_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $inv_number  = sanitize_text_field(wp_unslash($_POST['invoice_number'] ?? ''));
    $description = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
    $amount      = floatval($_POST['amount'] ?? 0);
    $tax         = floatval($_POST['tax'] ?? 0);
    $total       = floatval($_POST['total'] ?? ($amount + $tax));
    $due_date    = sanitize_text_field($_POST['due_date'] ?? '');
    $notes       = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
    $target_client = intval($_POST['client_id'] ?? $client['id']);

    if (!$inv_number || !$description) wp_send_json_error(array('message' => 'Invoice number and description required.'));

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'cpp_portal_invoices', array(
        'client_id'      => $target_client,
        'invoice_number' => $inv_number,
        'description'    => $description,
        'amount'         => $amount,
        'tax'            => $tax,
        'total'          => $total,
        'status'         => 'pending',
        'due_date'       => $due_date ?: null,
        'notes'          => $notes,
        'created_at'     => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create invoice.'));

    // Email notification to the target client
    if (function_exists('cpp_notify')) {
        $target_user = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, cpanel_username FROM {$wpdb->prefix}cpp_clients WHERE id = %d", $target_client
        ), ARRAY_A);
        if ($target_user) {
            $cd = cpp_notify_resolve_client($target_user);
            $currency = get_option('cpp_currency_symbol', '$');
            cpp_notify('invoice_created', array(
                'client_name'  => $cd['client_name'],
                'client_email' => $cd['client_email'],
                'title'        => $description,
                'amount'       => $currency . number_format($total, 2),
            ));
        }
    }

    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_invoice', 'cpp_portal_update_invoice');
function cpp_portal_update_invoice() {
    $client = cpp_portal_require_client('cpp_finance_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array();
    if (isset($_POST['status'])) $data['status'] = sanitize_key($_POST['status']);
    if (isset($_POST['paid_date'])) $data['paid_date'] = sanitize_text_field($_POST['paid_date']) ?: null;
    if (isset($_POST['description'])) $data['description'] = sanitize_text_field(wp_unslash($_POST['description']));
    if (isset($_POST['amount'])) { $data['amount'] = floatval($_POST['amount']); }
    if (isset($_POST['tax'])) { $data['tax'] = floatval($_POST['tax']); }
    if (isset($_POST['total'])) { $data['total'] = floatval($_POST['total']); }
    if (isset($_POST['due_date'])) $data['due_date'] = sanitize_text_field($_POST['due_date']) ?: null;
    if (isset($_POST['notes'])) $data['notes'] = sanitize_textarea_field(wp_unslash($_POST['notes']));

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_invoices', $data, array('id' => $id));
    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_invoice', 'cpp_portal_delete_invoice');
function cpp_portal_delete_invoice() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_finance_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'cpp_portal_invoices', array('id' => $id));
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* ══════════════════════════════════════════════════════
   BACKUPS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_request_backup', 'cpp_portal_request_backup');
function cpp_portal_request_backup() {
    $client = cpp_portal_require_client('cpp_backup_nonce');

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'cpp_portal_backups', array(
        'client_id'   => intval($client['id']),
        'backup_date' => current_time('mysql'),
        'size_mb'     => 0,
        'status'      => 'pending',
        'type'        => 'manual',
        'notes'       => 'Manual backup requested by client',
        'created_at'  => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to request backup.'));
    wp_send_json_success(array('id' => $wpdb->insert_id, 'message' => 'Backup requested.'));
}

add_action('wp_ajax_cpp_portal_toggle_backup_subscription', 'cpp_portal_toggle_backup_subscription');
function cpp_portal_toggle_backup_subscription() {
    $client = cpp_portal_require_client('cpp_backup_nonce');
    $new_status = sanitize_key($_POST['status'] ?? '');
    if (!in_array($new_status, array('active', 'paused'))) wp_send_json_error(array('message' => 'Invalid status.'));

    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_backup_subscriptions';
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE client_id = %d LIMIT 1", intval($client['id'])));

    if ($existing) {
        $wpdb->update($table, array('status' => $new_status), array('id' => intval($existing)));
    } else {
        $wpdb->insert($table, array(
            'client_id'  => intval($client['id']),
            'status'     => $new_status,
            'plan'       => 'Basic',
            'price'      => 0,
            'created_at' => current_time('mysql'),
        ));
    }
    wp_send_json_success(array('message' => 'Backup plan ' . $new_status . '.'));
}

/* ══════════════════════════════════════════════════════
   WE HOST
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_wehost_update_stats', 'cpp_portal_wehost_update_stats');
function cpp_portal_wehost_update_stats() {
    $client = cpp_portal_require_client('cpp_wehost_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $target_uid = intval($_POST['target_user_id'] ?? 0);
    if (!$target_uid) wp_send_json_error(array('message' => 'Invalid user ID.'));

    update_user_meta($target_uid, 'cpp_hosting_disk_used', floatval($_POST['disk_used'] ?? 0));
    update_user_meta($target_uid, 'cpp_hosting_disk_limit', floatval($_POST['disk_limit'] ?? 5));
    update_user_meta($target_uid, 'cpp_hosting_bandwidth_used', floatval($_POST['bw_used'] ?? 0));
    update_user_meta($target_uid, 'cpp_hosting_bandwidth_limit', floatval($_POST['bw_limit'] ?? 50));
    update_user_meta($target_uid, 'cpp_hosting_domains', intval($_POST['domains'] ?? 0));
    update_user_meta($target_uid, 'cpp_hosting_email_accounts', intval($_POST['email_accounts'] ?? 0));
    update_user_meta($target_uid, 'cpp_hosting_server_info', array(
        'php_version'   => sanitize_text_field($_POST['php_version'] ?? ''),
        'mysql_version' => sanitize_text_field($_POST['mysql_version'] ?? ''),
        'ip_address'    => sanitize_text_field($_POST['ip_address'] ?? ''),
    ));

    wp_send_json_success(array('message' => 'Hosting stats updated.'));
}

add_action('wp_ajax_cpp_portal_wehost_buy_space', 'cpp_portal_wehost_buy_space');
function cpp_portal_wehost_buy_space() {
    $client = cpp_portal_require_client('cpp_wehost_nonce');

    $package = sanitize_key($_POST['package'] ?? '');
    $price   = floatval($_POST['price'] ?? 0);
    if (!$package || $price <= 0) wp_send_json_error(array('message' => 'Invalid package.'));

    $descriptions = array(
        '5gb'    => '+5 GB Disk Space',
        '10gb'   => '+10 GB Disk Space',
        '25gb'   => '+25 GB Disk Space',
        '50gb_bw' => '+50 GB Bandwidth',
    );
    $desc = isset($descriptions[$package]) ? $descriptions[$package] : 'Hosting Upgrade: ' . $package;

    global $wpdb;
    $inv_number = 'INV-HST-' . strtoupper(substr(md5(uniqid()), 0, 8));
    $wpdb->insert($wpdb->prefix . 'cpp_portal_invoices', array(
        'client_id'      => intval($client['id']),
        'invoice_number' => $inv_number,
        'description'    => $desc,
        'amount'         => $price,
        'tax'            => 0,
        'total'          => $price,
        'status'         => 'pending',
        'due_date'       => date('Y-m-d', strtotime('+7 days')),
        'notes'          => 'Auto-generated from We Host section',
        'created_at'     => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create invoice.'));
    wp_send_json_success(array('invoice_id' => $wpdb->insert_id, 'message' => 'Invoice created.'));
}

/* ══════════════════════════════════════════════════════
   EMAIL MANAGEMENT
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_create_email_account', 'cpp_portal_create_email_account');
function cpp_portal_create_email_account() {
    $client = cpp_portal_require_client('cpp_email_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $prefix = sanitize_text_field(wp_unslash($_POST['email_prefix'] ?? ''));
    $domain = sanitize_text_field(wp_unslash($_POST['domain'] ?? ''));
    $quota  = intval($_POST['quota_mb'] ?? 500);
    $cid    = intval($_POST['client_id'] ?? $client['id']);

    if (!$prefix || !$domain) wp_send_json_error(array('message' => 'Email prefix and domain required.'));

    $email_address = $prefix . '@' . $domain;

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_email_accounts', array(
        'client_id'     => $cid,
        'email_address' => $email_address,
        'domain'        => $domain,
        'quota_mb'      => $quota,
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create email account.'));
    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_email_account', 'cpp_portal_update_email_account');
function cpp_portal_update_email_account() {
    $client = cpp_portal_require_client('cpp_email_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array('updated_at' => current_time('mysql'));
    if (isset($_POST['quota_mb'])) $data['quota_mb'] = intval($_POST['quota_mb']);
    if (isset($_POST['status'])) $data['status'] = sanitize_key($_POST['status']);

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_email_accounts', $data, array('id' => $id));
    wp_send_json_success(array('message' => 'Updated.'));
}

add_action('wp_ajax_cpp_portal_delete_email_account', 'cpp_portal_delete_email_account');
function cpp_portal_delete_email_account() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_email_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_email_accounts',
        array('status' => 'deleted', 'updated_at' => current_time('mysql')),
        array('id' => $id)
    );
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* ══════════════════════════════════════════════════════
   PAYMENT (PayPal)
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_save_paypal', 'cpp_portal_save_paypal');
function cpp_portal_save_paypal() {
    $client = cpp_portal_require_client('cpp_payment_nonce');

    $paypal_email = sanitize_email(wp_unslash($_POST['paypal_email'] ?? ''));
    if (!$paypal_email || !is_email($paypal_email)) wp_send_json_error(array('message' => 'Valid email required.'));

    update_user_meta(get_current_user_id(), 'cpp_paypal_email', $paypal_email);

    // Auto-sync to WooCommerce if mode is 'woocommerce' or 'both'
    $mode = get_option('cpp_payment_mode', 'portal');
    if (in_array($mode, array('woocommerce', 'both')) && class_exists('WooCommerce')) {
        cpp_portal_do_woo_sync($paypal_email);
    }

    wp_send_json_success(array('message' => 'PayPal email saved.'));
}

/* ── Payment Mode Setting ─────────────────────────── */
add_action('wp_ajax_cpp_portal_set_payment_mode', 'cpp_portal_set_payment_mode');
function cpp_portal_set_payment_mode() {
    check_ajax_referer('cpp_payment_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin only.'));

    $mode = sanitize_key($_POST['mode'] ?? 'portal');
    if (!in_array($mode, array('portal', 'woocommerce', 'both'))) $mode = 'portal';

    update_option('cpp_payment_mode', $mode);

    // If switching to woocommerce/both and PayPal is configured, auto-sync
    if (in_array($mode, array('woocommerce', 'both'))) {
        $paypal_email = get_user_meta(get_current_user_id(), 'cpp_paypal_email', true);
        if ($paypal_email && class_exists('WooCommerce')) {
            cpp_portal_do_woo_sync($paypal_email);
        }
    }

    wp_send_json_success(array('message' => 'Payment mode set to ' . $mode . '.'));
}

/* ── Sync PayPal to WooCommerce ───────────────────── */
add_action('wp_ajax_cpp_portal_sync_woo_paypal', 'cpp_portal_sync_woo_paypal');
function cpp_portal_sync_woo_paypal() {
    check_ajax_referer('cpp_payment_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin only.'));
    if (!class_exists('WooCommerce')) wp_send_json_error(array('message' => 'WooCommerce is not installed.'));

    $paypal_email = get_user_meta(get_current_user_id(), 'cpp_paypal_email', true);
    if (!$paypal_email) wp_send_json_error(array('message' => 'No PayPal email configured. Add your PayPal first.'));

    cpp_portal_do_woo_sync($paypal_email);
    wp_send_json_success(array('message' => 'PayPal synced to WooCommerce.'));
}

/* ── WooCommerce PayPal Sync Helper ───────────────── */
function cpp_portal_do_woo_sync($paypal_email) {
    if (!class_exists('WooCommerce')) return;

    // Try WooCommerce PayPal Payments (PPCP) plugin first
    $ppcp_settings = get_option('woocommerce_ppcp-gateway_settings', array());
    if (is_array($ppcp_settings)) {
        $ppcp_settings['merchant_email'] = $paypal_email;
        $ppcp_settings['enabled'] = 'yes';
        update_option('woocommerce_ppcp-gateway_settings', $ppcp_settings);
    }

    // Also update classic PayPal Standard if present
    $paypal_settings = get_option('woocommerce_paypal_settings', array());
    if (is_array($paypal_settings)) {
        $paypal_settings['email'] = $paypal_email;
        $paypal_settings['enabled'] = 'yes';
        $paypal_settings['receiver_email'] = $paypal_email;
        update_option('woocommerce_paypal_settings', $paypal_settings);
    }
}

/* ══════════════════════════════════════════════════════
   SUBSCRIPTIONS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_subscribe', 'cpp_portal_subscribe');
function cpp_portal_subscribe() {
    $client = cpp_portal_require_client('cpp_subscription_nonce');

    $plan_id = intval($_POST['plan_id'] ?? 0);
    $cycle   = sanitize_key($_POST['billing_cycle'] ?? 'monthly');
    if (!$plan_id) wp_send_json_error(array('message' => 'Invalid plan.'));
    if (!in_array($cycle, array('monthly', 'yearly'))) $cycle = 'monthly';

    global $wpdb;
    $plans_table = $wpdb->prefix . 'cpp_portal_subscription_plans';
    $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$plans_table} WHERE id = %d AND status = 'active'", $plan_id), ARRAY_A);
    if (!$plan) wp_send_json_error(array('message' => 'Plan not found.'));

    $price = $cycle === 'yearly' ? floatval($plan['price_yearly']) : floatval($plan['price_monthly']);
    $next_bill = $cycle === 'yearly' ? date('Y-m-d', strtotime('+1 year')) : date('Y-m-d', strtotime('+1 month'));
    $now = current_time('mysql');

    // Cancel existing active subscriptions
    $sub_table = $wpdb->prefix . 'cpp_portal_subscriptions';
    $wpdb->update($sub_table,
        array('status' => 'cancelled', 'cancelled_at' => $now),
        array('client_id' => intval($client['id']), 'status' => 'active')
    );

    // Create new subscription
    $wpdb->insert($sub_table, array(
        'client_id'         => intval($client['id']),
        'plan_name'         => $plan['name'],
        'price'             => $price,
        'billing_cycle'     => $cycle,
        'status'            => 'active',
        'next_billing_date' => $next_bill,
        'started_at'        => $now,
        'created_at'        => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to subscribe.'));
    $sub_id = $wpdb->insert_id;

    // Auto-generate invoice for the subscription
    $inv_table = $wpdb->prefix . 'cpp_portal_invoices';
    $inv_num   = 'INV-' . strtoupper(substr(md5(time() . $client['id']), 0, 8));
    $due_date  = date('Y-m-d', strtotime('+7 days'));
    $cycle_label = $cycle === 'yearly' ? 'Yearly' : 'Monthly';
    $wpdb->insert($inv_table, array(
        'client_id'      => intval($client['id']),
        'invoice_number' => $inv_num,
        'description'    => $plan['name'] . ' - ' . $cycle_label . ' Subscription',
        'amount'         => $price,
        'tax'            => 0,
        'total'          => $price,
        'status'         => 'pending',
        'due_date'       => $due_date,
        'notes'          => 'Auto-generated from subscription #' . $sub_id,
        'created_at'     => $now,
    ));

    wp_send_json_success(array('id' => $sub_id, 'message' => 'Subscribed to ' . $plan['name'] . '. Invoice created.'));
}

add_action('wp_ajax_cpp_portal_update_subscription_status', 'cpp_portal_update_subscription_status');
function cpp_portal_update_subscription_status() {
    $client = cpp_portal_require_client('cpp_subscription_nonce');

    $new_status = sanitize_key($_POST['status'] ?? '');
    if (!in_array($new_status, array('active', 'cancelled', 'paused'))) wp_send_json_error(array('message' => 'Invalid status.'));

    global $wpdb;
    $sub_table = $wpdb->prefix . 'cpp_portal_subscriptions';
    $sub = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$sub_table} WHERE client_id = %d AND status IN ('active','paused') ORDER BY created_at DESC LIMIT 1",
        intval($client['id'])
    ), ARRAY_A);
    if (!$sub) wp_send_json_error(array('message' => 'No active subscription found.'));

    $data = array('status' => $new_status);
    if ($new_status === 'cancelled') {
        $data['cancelled_at'] = current_time('mysql');
    }

    $wpdb->update($sub_table, $data, array('id' => intval($sub['id'])));
    wp_send_json_success(array('message' => 'Subscription ' . $new_status . '.'));
}

add_action('wp_ajax_cpp_portal_create_subscription_plan', 'cpp_portal_create_subscription_plan');
function cpp_portal_create_subscription_plan() {
    $client = cpp_portal_require_client('cpp_subscription_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $name          = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $description   = sanitize_text_field(wp_unslash($_POST['description'] ?? ''));
    $price_monthly = floatval($_POST['price_monthly'] ?? 0);
    $price_yearly  = floatval($_POST['price_yearly'] ?? 0);
    $features_json = isset($_POST['features_json']) ? wp_unslash($_POST['features_json']) : '[]';

    if (!$name) wp_send_json_error(array('message' => 'Plan name required.'));

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'cpp_portal_subscription_plans', array(
        'name'          => $name,
        'description'   => $description,
        'price_monthly' => $price_monthly,
        'price_yearly'  => $price_yearly,
        'features_json' => $features_json,
        'status'        => 'active',
        'created_at'    => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create plan.'));
    wp_send_json_success(array('id' => $wpdb->insert_id));
}

/* ══════════════════════════════════════════════════════
   TECH SUPPORT
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_create_tech_support', 'cpp_portal_create_tech_support');
function cpp_portal_create_tech_support() {
    $client = cpp_portal_require_client('cpp_techsupport_nonce');

    $category = sanitize_key($_POST['category'] ?? 'other');
    $priority = sanitize_key($_POST['priority'] ?? 'normal');
    $subject  = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
    $desc     = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));

    if (!in_array($category, array('bug', 'feature', 'server', 'other'))) $category = 'other';
    if (!in_array($priority, array('low', 'normal', 'high', 'urgent'))) $priority = 'normal';
    if (!$subject) wp_send_json_error(array('message' => 'Subject required.'));

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_tech_support', array(
        'client_id'   => intval($client['id']),
        'category'    => $category,
        'priority'    => $priority,
        'subject'     => $subject,
        'description' => $desc,
        'status'      => 'submitted',
        'created_at'  => $now,
        'updated_at'  => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to submit request.'));
    wp_send_json_success(array('id' => $wpdb->insert_id));
}

add_action('wp_ajax_cpp_portal_update_tech_support', 'cpp_portal_update_tech_support');
function cpp_portal_update_tech_support() {
    $client = cpp_portal_require_client('cpp_techsupport_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $data = array('updated_at' => current_time('mysql'));
    if (isset($_POST['status'])) {
        $status = sanitize_key($_POST['status']);
        if (in_array($status, array('submitted', 'in_review', 'working', 'resolved', 'closed'))) {
            $data['status'] = $status;
        }
    }
    if (isset($_POST['admin_notes'])) $data['admin_notes'] = sanitize_textarea_field(wp_unslash($_POST['admin_notes']));

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'cpp_portal_tech_support', $data, array('id' => $id));
    wp_send_json_success(array('message' => 'Updated.'));
}

/* ══════════════════════════════════════════════════════
   BLOCK BUILDER (Pages)
   ══════════════════════════════════════════════════════ */

add_action('wp_ajax_cpp_builder_list_pages', 'cpp_builder_list_pages');
function cpp_builder_list_pages() {
    $client = cpp_portal_require_client('cpp_builder_nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_pages';

    if (current_user_can('manage_options')) {
        $pages = $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A);
    } else {
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE client_id = %d ORDER BY updated_at DESC LIMIT 100",
            intval($client['id'])
        ), ARRAY_A);
    }
    wp_send_json_success(array('pages' => $pages ?: array()));
}

add_action('wp_ajax_cpp_builder_get_page', 'cpp_builder_get_page');
function cpp_builder_get_page() {
    $client = cpp_portal_require_client('cpp_builder_nonce');
    $page_id = intval($_POST['page_id'] ?? 0);
    if (!$page_id) wp_send_json_error(array('message' => 'Invalid page ID.'));

    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_pages';

    if (current_user_can('manage_options')) {
        $page = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $page_id), ARRAY_A);
    } else {
        $page = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND client_id = %d",
            $page_id, intval($client['id'])
        ), ARRAY_A);
    }
    if (!$page) wp_send_json_error(array('message' => 'Page not found.'));
    wp_send_json_success(array('page' => $page));
}

add_action('wp_ajax_cpp_builder_save_page', 'cpp_builder_save_page');
function cpp_builder_save_page() {
    $client = cpp_portal_require_client('cpp_builder_nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_pages';
    $now = current_time('mysql');

    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $slug  = sanitize_title(wp_unslash($_POST['slug'] ?? $title));
    $status = in_array($_POST['status'] ?? '', array('draft', 'published')) ? $_POST['status'] : 'draft';
    $blocks_json = wp_unslash($_POST['blocks_json'] ?? '[]');

    if (empty($title)) wp_send_json_error(array('message' => 'Page title is required.'));

    // Validate JSON
    $decoded = json_decode($blocks_json, true);
    if (!is_array($decoded)) wp_send_json_error(array('message' => 'Invalid blocks data.'));
    // Re-encode to sanitize
    $blocks_json = wp_json_encode($decoded);

    $page_id = intval($_POST['page_id'] ?? 0);

    if ($page_id) {
        // Update existing page — verify ownership
        if (current_user_can('manage_options')) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $page_id), ARRAY_A);
        } else {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND client_id = %d",
                $page_id, intval($client['id'])
            ), ARRAY_A);
        }
        if (!$existing) wp_send_json_error(array('message' => 'Page not found.'));

        $wpdb->update($table, array(
            'title'       => $title,
            'slug'        => $slug,
            'status'      => $status,
            'blocks_json' => $blocks_json,
            'updated_at'  => $now,
        ), array('id' => $page_id));
        wp_send_json_success(array('id' => $page_id, 'message' => 'Page updated.'));
    } else {
        // Create new page
        $wpdb->insert($table, array(
            'client_id'   => intval($client['id']),
            'title'       => $title,
            'slug'        => $slug,
            'status'      => $status,
            'blocks_json' => $blocks_json,
            'created_at'  => $now,
            'updated_at'  => $now,
        ));
        if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create page.'));
        wp_send_json_success(array('id' => $wpdb->insert_id, 'message' => 'Page created.'));
    }
}

add_action('wp_ajax_cpp_builder_delete_page', 'cpp_builder_delete_page');
function cpp_builder_delete_page() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_builder_nonce');
    $page_id = intval($_POST['page_id'] ?? 0);
    if (!$page_id) wp_send_json_error(array('message' => 'Invalid page ID.'));

    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_pages';

    if (current_user_can('manage_options')) {
        $wpdb->delete($table, array('id' => $page_id));
    } else {
        $wpdb->delete($table, array('id' => $page_id, 'client_id' => intval($client['id'])));
    }
    wp_send_json_success(array('message' => 'Page deleted.'));
}

/* ── Builder Asset Browser (scan FTP folder) ──────── */
add_action('wp_ajax_cpp_builder_browse_assets', 'cpp_builder_browse_assets');
function cpp_builder_browse_assets() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $upload_dir = wp_upload_dir();
    $base_dir   = $upload_dir['basedir'] . '/briefsync-builder';
    $base_url   = $upload_dir['baseurl'] . '/briefsync-builder';

    // Create base directory if it doesn't exist
    if (!is_dir($base_dir)) {
        wp_mkdir_p($base_dir);
    }

    // Subfolder filter (optional)
    $subfolder = sanitize_file_name($_POST['folder'] ?? '');

    $scan_dir = $base_dir;
    $scan_url = $base_url;
    if ($subfolder && is_dir($base_dir . '/' . $subfolder)) {
        $scan_dir = $base_dir . '/' . $subfolder;
        $scan_url = $base_url . '/' . $subfolder;
    }

    // Get subfolders
    $folders = array();
    $files   = array();

    if (is_dir($scan_dir)) {
        $items = scandir($scan_dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full_path = $scan_dir . '/' . $item;
            if (is_dir($full_path)) {
                // Count images inside and get first as thumbnail
                $img_count = 0;
                $thumb_url = '';
                $sub_items = scandir($full_path);
                foreach ($sub_items as $si) {
                    if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp)$/i', $si)) {
                        $img_count++;
                        if (!$thumb_url) {
                            $thumb_url = $scan_url . '/' . $item . '/' . $si;
                        }
                    }
                }
                $folders[] = array('name' => $item, 'count' => $img_count, 'thumb' => $thumb_url);
            } elseif (preg_match('/\.(jpg|jpeg|png|gif|svg|webp)$/i', $item)) {
                $files[] = array(
                    'name' => $item,
                    'url'  => $scan_url . '/' . $item,
                    'size' => filesize($full_path),
                );
            }
        }
    }

    wp_send_json_success(array(
        'folders'        => $folders,
        'files'          => $files,
        'current_folder' => $subfolder ?: '',
        'base_path'      => 'wp-content/uploads/briefsync-builder' . ($subfolder ? '/' . $subfolder : ''),
    ));
}

/* ── Builder Asset Upload ─────────────────────────── */
add_action('wp_ajax_cpp_builder_upload_asset', 'cpp_builder_upload_asset');
function cpp_builder_upload_asset() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin only.'));

    $upload_dir = wp_upload_dir();
    $base_dir   = $upload_dir['basedir'] . '/briefsync-builder';
    $base_url   = $upload_dir['baseurl'] . '/briefsync-builder';

    $subfolder = sanitize_file_name($_POST['folder'] ?? '');
    $target_dir = $base_dir;
    $target_url = $base_url;
    if ($subfolder) {
        $target_dir = $base_dir . '/' . $subfolder;
        $target_url = $base_url . '/' . $subfolder;
        if (!is_dir($target_dir)) wp_mkdir_p($target_dir);
    }

    if (empty($_FILES['file'])) wp_send_json_error(array('message' => 'No file uploaded.'));

    $file = $_FILES['file'];
    $allowed = array('jpg', 'jpeg', 'png', 'gif', 'svg', 'webp');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) wp_send_json_error(array('message' => 'Only image files allowed.'));

    $filename = sanitize_file_name($file['name']);
    $dest = $target_dir . '/' . $filename;

    // Avoid overwrite
    if (file_exists($dest)) {
        $filename = pathinfo($filename, PATHINFO_FILENAME) . '-' . time() . '.' . $ext;
        $dest = $target_dir . '/' . $filename;
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        wp_send_json_error(array('message' => 'Upload failed.'));
    }

    wp_send_json_success(array(
        'name' => $filename,
        'url'  => $target_url . '/' . $filename,
    ));
}

/* ── Builder Create Folder ────────────────────────── */
add_action('wp_ajax_cpp_builder_create_folder', 'cpp_builder_create_folder');
function cpp_builder_create_folder() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin only.'));

    $folder_name = sanitize_file_name($_POST['folder_name'] ?? '');
    if (!$folder_name) wp_send_json_error(array('message' => 'Folder name required.'));

    $upload_dir = wp_upload_dir();
    $base_dir   = $upload_dir['basedir'] . '/briefsync-builder';
    $new_dir    = $base_dir . '/' . $folder_name;

    if (is_dir($new_dir)) wp_send_json_error(array('message' => 'Folder already exists.'));

    if (!wp_mkdir_p($new_dir)) wp_send_json_error(array('message' => 'Failed to create folder.'));

    wp_send_json_success(array('name' => $folder_name));
}

/* ══════════════════════════════════════════════════════
   PRESET PAGES
   ══════════════════════════════════════════════════════ */

// Get single preset (for preview / edit form)
add_action('wp_ajax_cpp_portal_preset_get', 'cpp_portal_preset_get');
function cpp_portal_preset_get() {
    check_ajax_referer('cpp_preset_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    global $wpdb;
    $preset = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}cpp_portal_presets WHERE id = %d",
        $id
    ), ARRAY_A);

    if (!$preset) wp_send_json_error(array('message' => 'Template not found.'));

    // Non-admins can only see active presets
    if (!current_user_can('manage_options') && $preset['status'] !== 'active') {
        wp_send_json_error(array('message' => 'Template not available.'));
    }

    wp_send_json_success(array('preset' => $preset));
}

// Activate a preset for a client
add_action('wp_ajax_cpp_portal_preset_activate', 'cpp_portal_preset_activate');
function cpp_portal_preset_activate() {
    $client = cpp_portal_require_client('cpp_preset_nonce');
    $preset_id = intval($_POST['preset_id'] ?? 0);
    if (!$preset_id) wp_send_json_error(array('message' => 'Invalid preset ID.'));

    global $wpdb;
    $preset_table = $wpdb->prefix . 'cpp_portal_presets';
    $client_table = $wpdb->prefix . 'cpp_portal_client_presets';

    // Verify preset exists and is active
    $preset = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$preset_table} WHERE id = %d AND status = 'active'",
        $preset_id
    ), ARRAY_A);
    if (!$preset) wp_send_json_error(array('message' => 'Template not found or not available.'));

    // Check plan access (admins bypass plan restrictions)
    $is_admin = current_user_can('manage_options');
    if (!$is_admin) {
        $plan_key = !empty($client['plan']) ? $client['plan'] : 'website';
        $has_ecommerce = in_array($plan_key, array('ecommerce', 'ecommerce_plus', 'enterprise'), true);
        if ($preset['plan_type'] === 'ecommerce' && !$has_ecommerce) {
            wp_send_json_error(array('message' => 'This template requires an Ecommerce plan.'));
        }
    }

    // Check if already activated
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$client_table} WHERE client_id = %d AND preset_id = %d",
        intval($client['id']), $preset_id
    ));
    if ($existing) {
        // Reactivate if inactive
        $wpdb->update($client_table, array('status' => 'active'), array('id' => $existing));
    } else {
        $now = current_time('mysql');
        $wpdb->insert($client_table, array(
            'client_id'  => intval($client['id']),
            'preset_id'  => $preset_id,
            'status'     => 'active',
            'created_at' => $now,
        ));
        if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to activate template.'));
    }

    // Create a builder page from the preset so the user can edit it
    $pages_table = $wpdb->prefix . 'cpp_portal_pages';
    $now = current_time('mysql');
    $page_title = $preset['name'];
    $page_slug  = sanitize_title($preset['name']);
    $blocks_json = !empty($preset['block_data']) ? $preset['block_data'] : '[]';

    $wpdb->insert($pages_table, array(
        'client_id'   => intval($client['id']),
        'title'       => $page_title,
        'slug'        => $page_slug,
        'status'      => 'draft',
        'blocks_json' => $blocks_json,
        'created_at'  => $now,
        'updated_at'  => $now,
    ));
    $new_page_id = $wpdb->insert_id ?: 0;

    wp_send_json_success(array(
        'message' => 'Template activated! Opening editor...',
        'preset_id' => $preset_id,
        'page_id' => $new_page_id,
        'redirect' => 'builder',
    ));
}

// Admin: Create preset template
add_action('wp_ajax_cpp_portal_preset_create', 'cpp_portal_preset_create');
function cpp_portal_preset_create() {
    check_ajax_referer('cpp_preset_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if (!$name) wp_send_json_error(array('message' => 'Template name is required.'));

    $content_html = isset($_POST['content_html']) ? wp_kses_post(wp_unslash($_POST['content_html'])) : '';
    if (!$content_html) wp_send_json_error(array('message' => 'Content HTML is required.'));

    $plan_type = sanitize_key($_POST['plan_type'] ?? 'website');
    if (!in_array($plan_type, array('website', 'ecommerce', 'membership', 'subscription'))) $plan_type = 'website';

    $status = sanitize_key($_POST['status'] ?? 'active');
    if (!in_array($status, array('active', 'draft'))) $status = 'active';

    global $wpdb;
    $now = current_time('mysql');
    $insert_data = array(
        'name'          => $name,
        'description'   => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'plan_type'     => $plan_type,
        'thumbnail_url' => esc_url_raw(wp_unslash($_POST['thumbnail_url'] ?? '')),
        'content_html'  => $content_html,
        'page_type'     => sanitize_text_field(wp_unslash($_POST['page_type'] ?? 'Home')),
        'status'        => $status,
        'sort_order'    => intval($_POST['sort_order'] ?? 0),
        'created_at'    => $now,
        'updated_at'    => $now,
    );

    // Store block builder data if provided
    if (!empty($_POST['block_data'])) {
        $insert_data['block_data'] = wp_unslash($_POST['block_data']);
    }

    $wpdb->insert($wpdb->prefix . 'cpp_portal_presets', $insert_data);
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create template.'));
    wp_send_json_success(array('id' => $wpdb->insert_id, 'message' => 'Template created.'));
}

// Admin: Update preset template
add_action('wp_ajax_cpp_portal_preset_update', 'cpp_portal_preset_update');
function cpp_portal_preset_update() {
    check_ajax_referer('cpp_preset_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if (!$name) wp_send_json_error(array('message' => 'Template name is required.'));

    $plan_type = sanitize_key($_POST['plan_type'] ?? 'website');
    if (!in_array($plan_type, array('website', 'ecommerce', 'membership', 'subscription'))) $plan_type = 'website';

    $status = sanitize_key($_POST['status'] ?? 'active');
    if (!in_array($status, array('active', 'draft'))) $status = 'active';

    global $wpdb;
    $data = array(
        'name'          => $name,
        'description'   => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'plan_type'     => $plan_type,
        'thumbnail_url' => esc_url_raw(wp_unslash($_POST['thumbnail_url'] ?? '')),
        'page_type'     => sanitize_text_field(wp_unslash($_POST['page_type'] ?? 'Home')),
        'status'        => $status,
        'sort_order'    => intval($_POST['sort_order'] ?? 0),
        'updated_at'    => current_time('mysql'),
    );

    if (isset($_POST['content_html'])) {
        $data['content_html'] = wp_kses_post(wp_unslash($_POST['content_html']));
    }

    // Store block builder data if provided
    if (isset($_POST['block_data'])) {
        $data['block_data'] = wp_unslash($_POST['block_data']);
    }

    $wpdb->update($wpdb->prefix . 'cpp_portal_presets', $data, array('id' => $id));
    wp_send_json_success(array('message' => 'Template updated.'));
}

// Admin: Delete preset template
add_action('wp_ajax_cpp_portal_preset_delete', 'cpp_portal_preset_delete');
function cpp_portal_preset_delete() {
    check_ajax_referer('cpp_preset_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin access required.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));

    global $wpdb;
    // Delete associated client presets first
    $wpdb->delete($wpdb->prefix . 'cpp_portal_client_presets', array('preset_id' => $id));
    // Delete the preset
    $wpdb->delete($wpdb->prefix . 'cpp_portal_presets', array('id' => $id));
    wp_send_json_success(array('message' => 'Template deleted.'));
}

/* ══════════════════════════════════════════════════════
   MEMBERSHIP PLAN
   ══════════════════════════════════════════════════════ */
/* ── 14-Day Trial ─────────────────────────────────── */
add_action('wp_ajax_cpp_portal_start_trial', 'cpp_portal_start_trial');
function cpp_portal_start_trial() {
    $client = cpp_portal_require_client('cpp_membership_nonce');
    $user_id = get_current_user_id();

    // Check if trial already used
    $existing_trial = get_user_meta($user_id, 'cpp_trial_start', true);
    if ($existing_trial) {
        wp_send_json_error(array('message' => 'You have already used your free trial.'));
    }

    // Start trial
    $now = current_time('mysql');
    update_user_meta($user_id, 'cpp_trial_start', $now);
    update_user_meta($user_id, 'cpp_trial_plan', 'website'); // Default trial plan

    // Create a trial subscription record
    global $wpdb;
    $sub_table = $wpdb->prefix . 'cpp_portal_subscriptions';
    $wpdb->insert($sub_table, array(
        'client_id'         => intval($client['id']),
        'plan_name'         => 'Free Trial - Briefstart Website',
        'price'             => 0,
        'billing_cycle'     => 'trial',
        'status'            => 'trial',
        'next_billing_date' => date('Y-m-d', strtotime('+14 days')),
        'started_at'        => $now,
        'created_at'        => $now,
    ));

    wp_send_json_success(array('message' => 'Your 14-day free trial has started!'));
}

add_action('wp_ajax_cpp_membership_switch_plan', 'cpp_membership_switch_plan');
function cpp_membership_switch_plan() {
    $client = cpp_portal_require_client('cpp_membership_nonce');
    $plan_key = sanitize_key($_POST['plan'] ?? '');
    $valid_plans = array('plan_1', 'plan_2', 'plan_3', 'plan_4');
    if (!in_array($plan_key, $valid_plans)) wp_send_json_error(array('message' => 'Invalid plan.'));

    global $wpdb;
    // Update client plan in the portal
    $clients_table = $wpdb->prefix . 'cpp_clients';
    $wpdb->update($clients_table, array('plan' => $plan_key), array('id' => intval($client['id'])));

    // Also update wp_options plan mapping
    $plans = get_option('cpp_plans', array());
    // Log plan change
    $now = current_time('mysql');
    $sub_table = $wpdb->prefix . 'cpp_portal_subscriptions';
    $plan_labels = array('plan_1' => 'Briefstart Website', 'plan_2' => 'Briefsite E-commerce', 'plan_3' => 'Briefcase Membership', 'plan_4' => 'Briefsync Subscription');
    $label = isset($plan_labels[$plan_key]) ? $plan_labels[$plan_key] : ucfirst($plan_key);

    // Cancel existing active subs
    $wpdb->update($sub_table, array('status' => 'cancelled', 'cancelled_at' => $now), array('client_id' => intval($client['id']), 'status' => 'active'));

    // Create new subscription record
    $wpdb->insert($sub_table, array(
        'client_id'         => intval($client['id']),
        'plan_name'         => $label,
        'price'             => 0,
        'billing_cycle'     => 'monthly',
        'status'            => 'active',
        'next_billing_date' => date('Y-m-d', strtotime('+1 month')),
        'started_at'        => $now,
        'created_at'        => $now,
    ));

    wp_send_json_success(array('message' => 'Plan switched to ' . $label . '.'));
}

/* ══════════════════════════════════════════════════════
   PROFILE
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_update_profile', 'cpp_portal_update_profile');
function cpp_portal_update_profile() {
    check_ajax_referer('cpp_profile_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $uid = get_current_user_id();
    $fields = array('first_name', 'last_name', 'billing_company', 'billing_phone',
        'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state',
        'billing_postcode', 'billing_country');

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            update_user_meta($uid, $f, sanitize_text_field(wp_unslash($_POST[$f])));
        }
    }

    // Update display name
    if (isset($_POST['first_name']) && isset($_POST['last_name'])) {
        $fn = sanitize_text_field(wp_unslash($_POST['first_name']));
        $ln = sanitize_text_field(wp_unslash($_POST['last_name']));
        wp_update_user(array('ID' => $uid, 'display_name' => trim($fn . ' ' . $ln)));
    }

    wp_send_json_success(array('message' => 'Profile updated.'));
}

/* ══════════════════════════════════════════════════════
   DEVELOPER ASSISTANCE
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_devassist_create_session', 'cpp_devassist_create_session');
function cpp_devassist_create_session() {
    $client = cpp_portal_require_client('cpp_devassist_nonce');
    $topic = sanitize_text_field(wp_unslash($_POST['topic'] ?? ''));
    $desc  = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $date  = sanitize_text_field($_POST['scheduled_date'] ?? '');
    $time  = sanitize_text_field($_POST['scheduled_time'] ?? '');
    if (!$topic) wp_send_json_error(array('message' => 'Topic is required.'));

    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_dev_sessions', array(
        'client_id'      => intval($client['id']),
        'topic'          => $topic,
        'description'    => $desc,
        'scheduled_date' => $date ?: null,
        'scheduled_time' => $time ?: null,
        'timezone'       => 'America/New_York',
        'status'         => 'scheduled',
        'created_at'     => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create session.'));
    wp_send_json_success(array('id' => $wpdb->insert_id, 'message' => 'Session scheduled.'));
}

add_action('wp_ajax_cpp_devassist_send_message', 'cpp_devassist_send_message');
function cpp_devassist_send_message() {
    $client = cpp_portal_require_client('cpp_devassist_nonce');
    $session_id = intval($_POST['session_id'] ?? 0);
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    if (!$session_id || !$message) wp_send_json_error(array('message' => 'Session and message required.'));

    global $wpdb;
    $sess_table = $wpdb->prefix . 'cpp_portal_dev_sessions';
    // Verify session belongs to client (or admin)
    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sess_table} WHERE id = %d", $session_id), ARRAY_A);
    if (!$session) wp_send_json_error(array('message' => 'Session not found.'));
    if (!current_user_can('manage_options') && intval($session['client_id']) !== intval($client['id'])) {
        wp_send_json_error(array('message' => 'Access denied.'));
    }

    $sender_type = current_user_can('manage_options') ? 'developer' : 'client';
    $now = current_time('mysql');
    $wpdb->insert($wpdb->prefix . 'cpp_portal_dev_messages', array(
        'session_id'  => $session_id,
        'sender_id'   => get_current_user_id(),
        'sender_type' => $sender_type,
        'message'     => $message,
        'created_at'  => $now,
    ));
    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to send message.'));

    // Update session status to active if it was scheduled
    if ($session['status'] === 'scheduled') {
        $wpdb->update($sess_table, array('status' => 'active'), array('id' => $session_id));
    }

    wp_send_json_success(array('id' => $wpdb->insert_id, 'message' => 'Message sent.'));
}

add_action('wp_ajax_cpp_devassist_get_messages', 'cpp_devassist_get_messages');
function cpp_devassist_get_messages() {
    $client = cpp_portal_require_client('cpp_devassist_nonce');
    $session_id = intval($_POST['session_id'] ?? 0);
    $after_id = intval($_POST['after_id'] ?? 0);
    if (!$session_id) wp_send_json_error(array('message' => 'Session ID required.'));

    global $wpdb;
    $sess_table = $wpdb->prefix . 'cpp_portal_dev_sessions';
    $msg_table = $wpdb->prefix . 'cpp_portal_dev_messages';

    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sess_table} WHERE id = %d", $session_id), ARRAY_A);
    if (!$session) wp_send_json_error(array('message' => 'Session not found.'));
    if (!current_user_can('manage_options') && intval($session['client_id']) !== intval($client['id'])) {
        wp_send_json_error(array('message' => 'Access denied.'));
    }

    $where_after = $after_id ? $wpdb->prepare(" AND id > %d", $after_id) : '';
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$msg_table} WHERE session_id = %d{$where_after} ORDER BY created_at ASC LIMIT 200",
        $session_id
    ), ARRAY_A);

    wp_send_json_success(array('messages' => $messages ?: array()));
}

/* ══════════════════════════════════════════════════════
   ACCOUNT SETTINGS
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_save_settings', 'cpp_portal_save_settings');
function cpp_portal_save_settings() {
    check_ajax_referer('cpp_settings_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $uid = get_current_user_id();
    $save_type = sanitize_key($_POST['save_type'] ?? 'general');

    if ($save_type === 'notifications') {
        $prefs = array(
            'new_ticket'    => !empty($_POST['notify_ticket']),
            'new_message'   => !empty($_POST['notify_message']),
            'billing'       => !empty($_POST['notify_billing']),
            'subscription'  => !empty($_POST['notify_subscription']),
        );
        update_user_meta($uid, 'cpp_notification_prefs', $prefs);
        wp_send_json_success(array('message' => 'Notification preferences saved.'));
    }

    // General settings
    if (isset($_POST['display_name'])) {
        wp_update_user(array('ID' => $uid, 'display_name' => sanitize_text_field(wp_unslash($_POST['display_name']))));
    }
    if (isset($_POST['language'])) update_user_meta($uid, 'cpp_language', sanitize_key($_POST['language']));
    if (isset($_POST['timezone'])) update_user_meta($uid, 'cpp_timezone', sanitize_text_field($_POST['timezone']));
    if (isset($_POST['date_format'])) update_user_meta($uid, 'cpp_date_format', sanitize_text_field($_POST['date_format']));

    wp_send_json_success(array('message' => 'Settings saved.'));
}

add_action('wp_ajax_cpp_portal_change_password', 'cpp_portal_change_password');
function cpp_portal_change_password() {
    check_ajax_referer('cpp_settings_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current || !$new) wp_send_json_error(array('message' => 'All fields required.'));
    if ($new !== $confirm) wp_send_json_error(array('message' => 'Passwords do not match.'));
    if (strlen($new) < 8) wp_send_json_error(array('message' => 'Password must be at least 8 characters.'));

    $user = wp_get_current_user();
    if (!wp_check_password($current, $user->user_pass, $user->ID)) {
        wp_send_json_error(array('message' => 'Current password is incorrect.'));
    }

    wp_set_password($new, $user->ID);
    // Re-auth the user so they don't get logged out
    wp_set_auth_cookie($user->ID);
    wp_send_json_success(array('message' => 'Password changed successfully.'));
}

/* ══════════════════════════════════════════════════════
   WHITE LABEL
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_portal_save_whitelabel', 'cpp_portal_save_whitelabel');
function cpp_portal_save_whitelabel() {
    check_ajax_referer('cpp_whitelabel_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    // Check plan allows white label
    $client = cpp_portal_require_client('cpp_whitelabel_nonce');
    $plans = get_option('cpp_plans', array());
    $plan_key = !empty($client['plan']) ? $client['plan'] : 'website';

    $settings = array(
        'portal_name' => sanitize_text_field(wp_unslash($_POST['portal_name'] ?? '')),
        'logo_url'    => esc_url_raw($_POST['logo_url'] ?? ''),
        'brand_color' => sanitize_hex_color($_POST['brand_color'] ?? '#1E78CD'),
        'custom_css'  => wp_strip_all_tags($_POST['custom_css'] ?? ''),
    );

    update_option('cpp_whitelabel_' . intval($client['id']), $settings);
    wp_send_json_success(array('message' => 'White label settings saved.'));
}

/* ══════════════════════════════════════════════════════
   ITEM SHARING (Projects, Tasks, Calendar, Storage)
   ══════════════════════════════════════════════════════ */

/**
 * Share an item with another member/client
 * POST: item_type, item_id, member_email, access_level
 */
add_action('wp_ajax_cpp_portal_share_item', 'cpp_portal_share_item');
function cpp_portal_share_item() {
    $client = cpp_portal_require_client('cpp_share_nonce');
    global $wpdb;

    $item_type = sanitize_key($_POST['item_type'] ?? '');
    $item_id   = intval($_POST['item_id'] ?? 0);
    $email     = sanitize_email($_POST['member_email'] ?? '');
    $access    = sanitize_key($_POST['access_level'] ?? 'view');

    $allowed_types = array('projects', 'tasks', 'events', 'uploads');
    if (!in_array($item_type, $allowed_types)) {
        wp_send_json_error(array('message' => 'Invalid item type.'));
    }
    if (!$item_id || !$email) {
        wp_send_json_error(array('message' => 'Missing required fields.'));
    }
    if (!in_array($access, array('view', 'edit'))) {
        $access = 'view';
    }

    // Verify ownership
    $table_map = array(
        'projects' => $wpdb->prefix . 'cpp_portal_projects',
        'tasks'    => $wpdb->prefix . 'cpp_portal_tasks',
        'events'   => $wpdb->prefix . 'cpp_portal_events',
        'uploads'  => $wpdb->prefix . 'cpp_uploads',
    );
    $item_table = $table_map[$item_type];
    $owner = $wpdb->get_var($wpdb->prepare(
        "SELECT client_id FROM {$item_table} WHERE id = %d", $item_id
    ));
    if (intval($owner) !== intval($client['id']) && !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You can only share items you own.'));
    }

    // Find target client by email
    $target_user = get_user_by('email', $email);
    if (!$target_user) {
        wp_send_json_error(array('message' => 'No user found with that email address.'));
    }
    $cm = new CPP_Client_Manager();
    $target_client = $cm->get_by_user_id($target_user->ID);
    if (!$target_client) {
        wp_send_json_error(array('message' => 'That user does not have a portal profile.'));
    }
    if (intval($target_client['id']) === intval($client['id'])) {
        wp_send_json_error(array('message' => 'You cannot share with yourself.'));
    }

    // Insert or update
    $share_table = $wpdb->prefix . 'cpp_portal_item_shares';
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$share_table} WHERE item_type = %s AND item_id = %d AND shared_with_client_id = %d",
        $item_type, $item_id, $target_client['id']
    ));

    if ($existing) {
        $wpdb->update($share_table,
            array('access_level' => $access, 'shared_at' => current_time('mysql')),
            array('id' => $existing)
        );
    } else {
        $wpdb->insert($share_table, array(
            'item_type'            => $item_type,
            'item_id'              => $item_id,
            'shared_by_client_id'  => intval($client['id']),
            'shared_with_client_id' => intval($target_client['id']),
            'access_level'         => $access,
            'shared_at'            => current_time('mysql'),
        ));
    }

    wp_send_json_success(array('message' => 'Item shared with ' . esc_html($target_user->display_name) . '.'));
}

/**
 * Get shares for an item
 * POST: item_type, item_id
 */
add_action('wp_ajax_cpp_portal_get_shares', 'cpp_portal_get_shares');
function cpp_portal_get_shares() {
    $client = cpp_portal_require_client('cpp_share_nonce');
    global $wpdb;

    $item_type = sanitize_key($_POST['item_type'] ?? '');
    $item_id   = intval($_POST['item_id'] ?? 0);

    $share_table = $wpdb->prefix . 'cpp_portal_item_shares';
    $shares = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, u.display_name, u.user_email
         FROM {$share_table} s
         LEFT JOIN {$wpdb->prefix}cpp_clients c ON c.id = s.shared_with_client_id
         LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id
         WHERE s.item_type = %s AND s.item_id = %d",
        $item_type, $item_id
    ), ARRAY_A);

    wp_send_json_success(array('shares' => $shares ?: array()));
}

/**
 * Remove a share
 * POST: share_id
 */
add_action('wp_ajax_cpp_portal_remove_share', 'cpp_portal_remove_share');
function cpp_portal_remove_share() {
    cpp_require_delete_permission();
    $client = cpp_portal_require_client('cpp_share_nonce');
    global $wpdb;

    $share_id = intval($_POST['share_id'] ?? 0);
    $share_table = $wpdb->prefix . 'cpp_portal_item_shares';

    // Verify the share belongs to the current client (either shared_by or admin)
    $share = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$share_table} WHERE id = %d", $share_id
    ), ARRAY_A);

    if (!$share) {
        wp_send_json_error(array('message' => 'Share not found.'));
    }

    if (intval($share['shared_by_client_id']) !== intval($client['id']) && !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }

    $wpdb->delete($share_table, array('id' => $share_id));
    wp_send_json_success(array('message' => 'Share removed.'));
}

/**
 * Helper: Get items shared with this client
 * Used by templates to show shared items alongside owned items
 */
function cpp_portal_get_shared_items($item_type, $client_id) {
    global $wpdb;
    $share_table = $wpdb->prefix . 'cpp_portal_item_shares';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT item_id, access_level, shared_by_client_id FROM {$share_table}
         WHERE item_type = %s AND shared_with_client_id = %d",
        $item_type, intval($client_id)
    ), ARRAY_A);
}

/* ══════════════════════════════════════════════════════
   PAGE FEEDBACK COMMENTS (Point-and-Click)
   ══════════════════════════════════════════════════════ */

// List comments for a page
add_action('wp_ajax_cpp_page_comments_list', 'cpp_page_comments_list');
function cpp_page_comments_list() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $page_id = intval($_POST['page_id'] ?? 0);
    if (!$page_id) wp_send_json_error(array('message' => 'Invalid page ID.'));

    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_page_comments';
    $comments = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE page_id = %d ORDER BY created_at ASC",
        $page_id
    ), ARRAY_A);

    wp_send_json_success(array('comments' => $comments ?: array()));
}

// Add a comment
add_action('wp_ajax_cpp_page_comment_add', 'cpp_page_comment_add');
function cpp_page_comment_add() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $page_id       = intval($_POST['page_id'] ?? 0);
    $pin_x         = floatval($_POST['pin_x'] ?? 0);
    $pin_y         = floatval($_POST['pin_y'] ?? 0);
    $text          = sanitize_textarea_field(wp_unslash($_POST['comment_text'] ?? ''));
    $parent        = intval($_POST['parent_id'] ?? 0);
    $attachment_url = esc_url_raw($_POST['attachment_url'] ?? '');

    if (!$page_id) wp_send_json_error(array('message' => 'Invalid page ID.'));
    if (!$text)    wp_send_json_error(array('message' => 'Comment text is required.'));

    $user = wp_get_current_user();

    global $wpdb;
    $insert_data = array(
        'page_id'      => $page_id,
        'user_id'      => $user->ID,
        'user_name'    => $user->display_name,
        'pin_x'        => $pin_x,
        'pin_y'        => $pin_y,
        'comment_text' => $text,
        'status'       => 'open',
        'parent_id'    => $parent,
        'created_at'   => current_time('mysql'),
    );
    if (!empty($attachment_url)) {
        $insert_data['attachment_url'] = $attachment_url;
    }
    $wpdb->insert($wpdb->prefix . 'cpp_portal_page_comments', $insert_data);

    if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to add comment.'));

    wp_send_json_success(array(
        'id'      => $wpdb->insert_id,
        'message' => 'Comment added.',
    ));
}

// Resolve/reopen a comment
add_action('wp_ajax_cpp_page_comment_resolve', 'cpp_page_comment_resolve');
function cpp_page_comment_resolve() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $id     = intval($_POST['id'] ?? 0);
    $status = sanitize_key($_POST['status'] ?? 'resolved');
    if (!$id) wp_send_json_error(array('message' => 'Invalid comment ID.'));
    if (!in_array($status, array('open', 'resolved'))) $status = 'resolved';

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'cpp_portal_page_comments',
        array('status' => $status),
        array('id' => $id)
    );

    wp_send_json_success(array('message' => 'Comment ' . $status . '.'));
}

// Delete a comment (admin only)
add_action('wp_ajax_cpp_page_comment_delete', 'cpp_page_comment_delete');
function cpp_page_comment_delete() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Admin only.'));

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid comment ID.'));

    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_page_comments';
    // Delete replies too
    $wpdb->delete($table, array('parent_id' => $id));
    $wpdb->delete($table, array('id' => $id));

    wp_send_json_success(array('message' => 'Comment deleted.'));
}

// Move a comment pin (reposition)
add_action('wp_ajax_cpp_page_comment_move', 'cpp_page_comment_move');
function cpp_page_comment_move() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    $id    = intval($_POST['id'] ?? 0);
    $pin_x = floatval($_POST['pin_x'] ?? 0);
    $pin_y = floatval($_POST['pin_y'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid comment ID.'));

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'cpp_portal_page_comments',
        array('pin_x' => round($pin_x, 2), 'pin_y' => round($pin_y, 2)),
        array('id' => $id)
    );

    wp_send_json_success(array('message' => 'Pin moved.'));
}

// Get comment counts per page (for list view)
add_action('wp_ajax_cpp_page_comments_counts', 'cpp_page_comments_counts');
function cpp_page_comments_counts() {
    check_ajax_referer('cpp_builder_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Please log in.'));

    global $wpdb;
    $table = $wpdb->prefix . 'cpp_portal_page_comments';
    $rows = $wpdb->get_results(
        "SELECT page_id, status, COUNT(*) as cnt FROM {$table} WHERE parent_id = 0 GROUP BY page_id, status",
        ARRAY_A
    );

    $counts = array();
    foreach ($rows as $r) {
        $pid = intval($r['page_id']);
        if (!isset($counts[$pid])) $counts[$pid] = array('open' => 0, 'resolved' => 0);
        $counts[$pid][$r['status']] = intval($r['cnt']);
    }

    wp_send_json_success(array('counts' => $counts));
}

/* ══════════════════════════════════════════════════════
   KNOWLEDGE BASE
   ══════════════════════════════════════════════════════ */

// Get KB categories with article counts (public/portal)
add_action('wp_ajax_cpp_kb_get_categories', 'cpp_kb_get_categories');
function cpp_kb_get_categories() {
    check_ajax_referer('cpp_kb_nonce', 'nonce');
    global $wpdb;
    $cats = $wpdb->get_results(
        "SELECT c.*, COUNT(a.id) as article_count
         FROM {$wpdb->prefix}cpp_kb_categories c
         LEFT JOIN {$wpdb->prefix}cpp_kb_articles a ON a.category_id = c.id AND a.status = 'published'
         GROUP BY c.id
         ORDER BY c.sort_order ASC, c.name ASC",
        ARRAY_A
    );
    wp_send_json_success(array('categories' => $cats ?: array()));
}

// Get articles for a category (or all if no category)
add_action('wp_ajax_cpp_kb_get_articles', 'cpp_kb_get_articles');
function cpp_kb_get_articles() {
    check_ajax_referer('cpp_kb_nonce', 'nonce');
    global $wpdb;
    $cat_id = intval($_POST['category_id'] ?? 0);
    $search = sanitize_text_field($_POST['search'] ?? '');

    $where = "WHERE a.status = 'published'";
    $params = array();
    if ($cat_id) {
        $where .= " AND a.category_id = %d";
        $params[] = $cat_id;
    }
    if ($search) {
        $where .= " AND (a.title LIKE %s OR a.content LIKE %s)";
        $like = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT a.id, a.title, a.slug, a.excerpt, a.category_id, a.views, a.updated_at,
                   c.name as category_name, c.icon as category_icon
            FROM {$wpdb->prefix}cpp_kb_articles a
            LEFT JOIN {$wpdb->prefix}cpp_kb_categories c ON c.id = a.category_id
            {$where}
            ORDER BY a.sort_order ASC, a.title ASC";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }

    $articles = $wpdb->get_results($sql, ARRAY_A);
    wp_send_json_success(array('articles' => $articles ?: array()));
}

// Get single article content + increment views
add_action('wp_ajax_cpp_kb_get_article', 'cpp_kb_get_article');
function cpp_kb_get_article() {
    check_ajax_referer('cpp_kb_nonce', 'nonce');
    global $wpdb;
    $id = intval($_POST['article_id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid article.'));

    $article = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, c.name as category_name, c.icon as category_icon
         FROM {$wpdb->prefix}cpp_kb_articles a
         LEFT JOIN {$wpdb->prefix}cpp_kb_categories c ON c.id = a.category_id
         WHERE a.id = %d AND a.status = 'published'",
        $id
    ), ARRAY_A);

    if (!$article) wp_send_json_error(array('message' => 'Article not found.'));

    // Increment view count
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}cpp_kb_articles SET views = views + 1 WHERE id = %d", $id
    ));

    wp_send_json_success(array('article' => $article));
}

// Admin: Save article (create or update)
add_action('wp_ajax_cpp_kb_save_article', 'cpp_kb_save_article');
function cpp_kb_save_article() {
    check_ajax_referer('cpp_kb_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Permission denied.'));

    global $wpdb;
    $id          = intval($_POST['id'] ?? 0);
    $title       = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $content     = wp_kses_post(wp_unslash($_POST['content'] ?? ''));
    $excerpt     = sanitize_textarea_field(wp_unslash($_POST['excerpt'] ?? ''));
    $category_id = intval($_POST['category_id'] ?? 0);
    $status      = sanitize_key($_POST['status'] ?? 'published');
    $sort_order  = intval($_POST['sort_order'] ?? 0);

    if (!$title) wp_send_json_error(array('message' => 'Title required.'));

    $slug = sanitize_title($title);
    $now = current_time('mysql');

    if ($id) {
        $wpdb->update($wpdb->prefix . 'cpp_kb_articles', array(
            'title'       => $title,
            'slug'        => $slug,
            'content'     => $content,
            'excerpt'     => $excerpt,
            'category_id' => $category_id ?: null,
            'status'      => $status,
            'sort_order'  => $sort_order,
            'updated_at'  => $now,
        ), array('id' => $id));
        wp_send_json_success(array('id' => $id, 'message' => 'Article updated.'));
    } else {
        $wpdb->insert($wpdb->prefix . 'cpp_kb_articles', array(
            'title'       => $title,
            'slug'        => $slug,
            'content'     => $content,
            'excerpt'     => $excerpt,
            'category_id' => $category_id ?: null,
            'status'      => $status,
            'sort_order'  => $sort_order,
            'author_id'   => get_current_user_id(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ));
        if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed to create article.'));
        wp_send_json_success(array('id' => $wpdb->insert_id, 'message' => 'Article created.'));
    }
}

// Admin: Delete article
add_action('wp_ajax_cpp_kb_delete_article', 'cpp_kb_delete_article');
function cpp_kb_delete_article() {
    check_ajax_referer('cpp_kb_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Permission denied.'));
    $id = intval($_POST['article_id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'cpp_kb_articles', array('id' => $id));
    wp_send_json_success(array('message' => 'Deleted.'));
}

// Admin: Save category
add_action('wp_ajax_cpp_kb_save_category', 'cpp_kb_save_category');
function cpp_kb_save_category() {
    check_ajax_referer('cpp_kb_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Permission denied.'));

    global $wpdb;
    $id          = intval($_POST['id'] ?? 0);
    $name        = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $icon        = sanitize_text_field($_POST['icon'] ?? 'bi-folder');
    $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $sort_order  = intval($_POST['sort_order'] ?? 0);

    if (!$name) wp_send_json_error(array('message' => 'Category name required.'));
    $slug = sanitize_title($name);

    if ($id) {
        $wpdb->update($wpdb->prefix . 'cpp_kb_categories', array(
            'name' => $name, 'slug' => $slug, 'icon' => $icon,
            'description' => $description, 'sort_order' => $sort_order,
        ), array('id' => $id));
        wp_send_json_success(array('id' => $id));
    } else {
        $wpdb->insert($wpdb->prefix . 'cpp_kb_categories', array(
            'name' => $name, 'slug' => $slug, 'icon' => $icon,
            'description' => $description, 'sort_order' => $sort_order,
            'created_at' => current_time('mysql'),
        ));
        if (!$wpdb->insert_id) wp_send_json_error(array('message' => 'Failed.'));
        wp_send_json_success(array('id' => $wpdb->insert_id));
    }
}

// Admin: Delete category
add_action('wp_ajax_cpp_kb_delete_category', 'cpp_kb_delete_category');
function cpp_kb_delete_category() {
    check_ajax_referer('cpp_kb_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Permission denied.'));
    $id = intval($_POST['category_id'] ?? 0);
    if (!$id) wp_send_json_error(array('message' => 'Invalid ID.'));
    global $wpdb;
    // Uncategorize articles in this category
    $wpdb->update($wpdb->prefix . 'cpp_kb_articles', array('category_id' => null), array('category_id' => $id));
    $wpdb->delete($wpdb->prefix . 'cpp_kb_categories', array('id' => $id));
    wp_send_json_success(array('message' => 'Deleted.'));
}

/* ══════════════════════════════════════════════════════
   CPANEL BACKUPS (create / delete via cPanel UAPI)
   ══════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_backup_create', 'cpp_ajax_backup_create');
function cpp_ajax_backup_create() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }

    // WordPress-native database backup (excludes plugin files to protect source code)
    $backup_dir = WP_CONTENT_DIR . '/backups';
    if (!is_dir($backup_dir)) {
        wp_mkdir_p($backup_dir);
        file_put_contents($backup_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
        file_put_contents($backup_dir . '/index.php', '<?php // Silence is golden');
    }

    $date_str = date('Y-m-d_H-i-s');
    $filename = 'backup-db-' . $date_str . '.sql';
    $filepath = $backup_dir . '/' . $filename;

    global $wpdb;
    $tables = $wpdb->get_col("SHOW TABLES");
    if (empty($tables)) {
        wp_send_json_error(array('message' => 'No database tables found.'));
        return;
    }

    $sql = "-- BriefSync Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- Database: " . DB_NAME . "\n\n";

    foreach ($tables as $table) {
        $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        if ($create) {
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
        }
        $offset = 0;
        while (true) {
            $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}", ARRAY_A);
            if (empty($rows)) break;
            foreach ($rows as $row) {
                $vals = array();
                foreach ($row as $v) {
                    $vals[] = ($v === null) ? 'NULL' : "'" . $wpdb->_real_escape($v) . "'";
                }
                $sql .= "INSERT INTO `{$table}` VALUES(" . implode(',', $vals) . ");\n";
            }
            $offset += 500;
            if (strlen($sql) > 50 * 1048576) { $sql .= "\n-- Truncated at 50MB\n"; break 2; }
        }
        $sql .= "\n";
    }

    $written = file_put_contents($filepath, $sql);
    if ($written === false) {
        wp_send_json_error(array('message' => 'Failed to write backup file. Check disk space and permissions.'));
        return;
    }

    // Try to gzip
    $final_file = $filename;
    $final_size = $written;
    if (function_exists('gzopen')) {
        $gz = gzopen($filepath . '.gz', 'wb9');
        if ($gz) {
            gzwrite($gz, $sql);
            gzclose($gz);
            @unlink($filepath);
            $final_file = $filename . '.gz';
            $final_size = filesize($filepath . '.gz');
        }
    }

    // Store backup record
    $backups = get_option('cpp_wp_backups', array());
    $backups[] = array('file' => $final_file, 'date' => $date_str, 'size' => $final_size, 'type' => 'Database', 'status' => 'completed');
    $backups = array_slice($backups, -20);
    update_option('cpp_wp_backups', $backups);

    $human = function_exists('cpp_human_size') ? cpp_human_size($final_size) : round($final_size / 1048576, 1) . ' MB';
    wp_send_json_success(array('message' => 'Database backup completed! File: ' . $final_file . ' (' . $human . ')'));
}

// Download WP-native backup files (served through PHP to bypass .htaccess)
add_action('wp_ajax_cpp_backup_download', 'cpp_ajax_backup_download');
function cpp_ajax_backup_download() {
    if (!is_user_logged_in()) {
        wp_die('Not authorized', 403);
    }
    $file = sanitize_file_name($_GET['file'] ?? '');
    if (empty($file) || !preg_match('/\.(sql|sql\.gz|tar\.gz)$/i', $file)) {
        wp_die('Invalid file', 400);
    }
    $path = WP_CONTENT_DIR . '/backups/' . $file;
    if (!file_exists($path)) {
        wp_die('Backup file not found', 404);
    }
    $mime = (substr($file, -3) === '.gz') ? 'application/gzip' : 'application/sql';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    readfile($path);
    exit;
}

add_action('wp_ajax_cpp_backup_delete', 'cpp_ajax_backup_delete');
function cpp_ajax_backup_delete() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    if (!function_exists('cpp_cpanel_api')) {
        wp_send_json_error(array('message' => 'cPanel API not available.'));
    }
    $file = preg_replace('/[^a-zA-Z0-9._\-]/', '', $_POST['file'] ?? '');
    if (empty($file)) {
        wp_send_json_error(array('message' => 'No file specified.'));
    }
    if (!preg_match('/\.(tar\.gz|sql\.gz)$/i', $file)) {
        wp_send_json_error(array('message' => 'Invalid backup file.'));
    }
    // Use cPanel v2 API fileop/unlink since Fileman/trash not available on all cPanel versions
    $host = get_option('cpp_server_host', '');
    $user = get_option('cpp_whm_user', '');
    $pass = '';
    if (function_exists('cpp_get_decrypted_option')) {
        $pass = cpp_get_decrypted_option('cpp_cpanel_pass');
    }
    if (empty($pass)) $pass = get_option('cpp_cpanel_pass', '');
    if (empty($pass)) {
        if (function_exists('cpp_get_decrypted_option')) {
            $pass = cpp_get_decrypted_option('cpp_whm_token');
        }
        if (empty($pass)) $pass = get_option('cpp_whm_token', '');
    }
    if (empty($host) || empty($user) || empty($pass)) {
        wp_send_json_error(array('message' => 'cPanel credentials not configured.'));
    }
    $url = 'https://' . rtrim($host, '/') . ':2083/json-api/cpanel?' . http_build_query(array(
        'cpanel_jsonapi_user' => $user,
        'cpanel_jsonapi_apiversion' => '2',
        'cpanel_jsonapi_module' => 'Fileman',
        'cpanel_jsonapi_func' => 'fileop',
        'op' => 'unlink',
        'sourcefiles' => '/' . $file,
        'dir' => '/',
    ));
    $response = wp_remote_get($url, array(
        'headers' => array('Authorization' => 'Basic ' . base64_encode($user . ':' . $pass)),
        'sslverify' => false,
        'timeout' => 60,
    ));
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $result_ok = false;
    if (!empty($body['cpanelresult']['data'])) {
        foreach ($body['cpanelresult']['data'] as $item) {
            if (!empty($item['result'])) $result_ok = true;
        }
    }
    if ($result_ok) {
        wp_send_json_success(array('message' => 'Backup file deleted.'));
    } else {
        wp_send_json_error(array('message' => 'Failed to delete file.'));
    }
}
