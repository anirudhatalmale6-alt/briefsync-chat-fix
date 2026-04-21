<?php
if (!defined('ABSPATH'))
    exit;

/* ================================================================
   BriefSync Live Chat – AJAX Endpoints
   ================================================================
   Visitor endpoints  : cpb_livechat_start, cpb_livechat_send,
                        cpb_livechat_poll
   Admin endpoints    : cpb_livechat_admin_list, cpb_livechat_admin_get,
                        cpb_livechat_admin_send, cpb_livechat_admin_close,
                        cpb_livechat_save_settings
   ================================================================ */

/* ---------- Hook Registration ---------- */

// Visitor (nopriv + priv) - AJAX
add_action('wp_ajax_cpb_livechat_start',        'cpb_livechat_start');
add_action('wp_ajax_nopriv_cpb_livechat_start',  'cpb_livechat_start');

add_action('wp_ajax_cpb_livechat_send',          'cpb_livechat_send');
add_action('wp_ajax_nopriv_cpb_livechat_send',   'cpb_livechat_send');

add_action('wp_ajax_cpb_livechat_poll',          'cpb_livechat_poll');
add_action('wp_ajax_nopriv_cpb_livechat_poll',   'cpb_livechat_poll');

// REST API routes (bypass ModSecurity blocking admin-ajax.php)
add_action('rest_api_init', function () {
    register_rest_route('cpp-chat/v1', '/start', array(
        'methods'  => array('PUT', 'POST'),
        'callback' => 'cpb_livechat_rest_start',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('cpp-chat/v1', '/send', array(
        'methods'  => array('PUT', 'POST'),
        'callback' => 'cpb_livechat_rest_send',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('cpp-chat/v1', '/poll', array(
        'methods'  => 'GET',
        'callback' => 'cpb_livechat_rest_poll',
        'permission_callback' => '__return_true',
    ));
});

// Admin (priv only)
add_action('wp_ajax_cpb_livechat_admin_list',    'cpb_livechat_admin_list');
add_action('wp_ajax_cpb_livechat_admin_get',     'cpb_livechat_admin_get');
add_action('wp_ajax_cpb_livechat_admin_send',    'cpb_livechat_admin_send');
add_action('wp_ajax_cpb_livechat_admin_close',   'cpb_livechat_admin_close');
add_action('wp_ajax_cpb_livechat_save_settings', 'cpb_livechat_save_settings');

/* ================================================================
   Helper: ensure livechat tables exist (safety net)
   ================================================================ */
function cpb_livechat_ensure_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$conv_table}'") !== $conv_table) {
        $wpdb->query("CREATE TABLE `{$conv_table}` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_key`      VARCHAR(64)     NOT NULL,
            `visitor_name`     VARCHAR(255)    DEFAULT NULL,
            `visitor_email`    VARCHAR(255)    DEFAULT NULL,
            `visitor_ip`       VARCHAR(45)     DEFAULT NULL,
            `visitor_page`     TEXT            DEFAULT NULL,
            `client_id`        BIGINT UNSIGNED DEFAULT NULL,
            `assigned_user_id` BIGINT UNSIGNED DEFAULT NULL,
            `status`           VARCHAR(20)     NOT NULL DEFAULT 'ai',
            `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `session_key` (`session_key`),
            KEY `status` (`status`)
        ) {$charset};");
    }

    if ($wpdb->get_var("SHOW TABLES LIKE '{$msg_table}'") !== $msg_table) {
        $wpdb->query("CREATE TABLE `{$msg_table}` (
            `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` BIGINT UNSIGNED NOT NULL,
            `sender_type`     VARCHAR(20)     NOT NULL DEFAULT 'visitor',
            `sender_id`       BIGINT UNSIGNED DEFAULT NULL,
            `message`         TEXT            NOT NULL,
            `is_read`         TINYINT(1)      NOT NULL DEFAULT 0,
            `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `conversation_id` (`conversation_id`),
            KEY `conv_after` (`conversation_id`, `id`)
        ) {$charset};");
    }
}

/* ================================================================
   Helper: generate random session key
   ================================================================ */
function cpb_livechat_generate_session_key() {
    return bin2hex(random_bytes(16)); // 32 hex chars
}

/* ================================================================
   Helper: format message row for JSON response
   ================================================================ */
function cpb_livechat_format_message($row) {
    return array(
        'id'          => (int) $row->id,
        'sender_type' => $row->sender_type,
        'sender_id'   => $row->sender_id ? (int) $row->sender_id : null,
        'message'     => $row->message,
        'is_read'     => (int) $row->is_read,
        'created_at'  => $row->created_at,
    );
}

/* ================================================================
   REST API Handlers (bypass ModSecurity on admin-ajax.php)
   ================================================================ */
function cpb_livechat_rest_start($request) {
    $params = $request->get_json_params();
    if (empty($params)) $params = $request->get_params();
    $_POST = array_merge($_POST, $params);
    return cpb_livechat_start_handler();
}

function cpb_livechat_rest_send($request) {
    $params = $request->get_json_params();
    if (empty($params)) $params = $request->get_params();

    global $wpdb;
    cpb_livechat_ensure_tables();
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    $session_key = isset($params['session_id']) ? sanitize_text_field($params['session_id']) : '';
    $message     = isset($params['message']) ? sanitize_textarea_field($params['message']) : '';

    if (!$session_key || !$message) {
        return new WP_REST_Response(array('success' => false, 'data' => 'Missing session_id or message'), 400);
    }

    $conversation = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `{$conv_table}` WHERE `session_key` = %s LIMIT 1", $session_key)
    );
    if (!$conversation) {
        return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'Session not found')), 404);
    }
    if ($conversation->status === 'closed') {
        return new WP_REST_Response(array('success' => false, 'data' => 'Closed'), 403);
    }

    $now = current_time('mysql', false);

    // Save visitor message
    $wpdb->insert($msg_table, array(
        'conversation_id' => $conversation->id,
        'sender_type'     => 'visitor',
        'message'         => $message,
        'is_read'         => 0,
        'created_at'      => $now,
    ), array('%d', '%s', '%s', '%d', '%s'));

    $wpdb->update($conv_table, array('updated_at' => $now), array('id' => $conversation->id));

    $reply = '';
    $reply_id = 0;
    $needs_human = false;

    // AI mode
    if ($conversation->status === 'ai') {
        if (!class_exists('CPP_Livechat_AI')) {
            $ai_path = CPP_PLUGIN_DIR . 'includes/classes/CPP_Livechat_AI.php';
            if (file_exists($ai_path)) {
                require_once $ai_path;
            }
        }
        if (class_exists('CPP_Livechat_AI')) {
            $ai = new CPP_Livechat_AI();
            $history = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$msg_table}` WHERE `conversation_id` = %d ORDER BY `id` ASC", $conversation->id)
            );
            $ai_result = $ai->generate_response($message, $history, $conversation);
            $reply = isset($ai_result['reply']) ? $ai_result['reply'] : '';
            $needs_human = !empty($ai_result['needs_human']);
        }

        if ($reply) {
            $ai_now = current_time('mysql', false);
            $wpdb->insert($msg_table, array(
                'conversation_id' => $conversation->id,
                'sender_type'     => 'ai',
                'message'         => $reply,
                'is_read'         => 0,
                'created_at'      => $ai_now,
            ), array('%d', '%s', '%s', '%d', '%s'));
            $reply_id = (int) $wpdb->insert_id;
        }

        if ($needs_human) {
            $wpdb->update($conv_table,
                array('status' => 'waiting', 'updated_at' => current_time('mysql', false)),
                array('id' => $conversation->id)
            );
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'reply'       => $reply,
            'sender_type' => 'ai',
            'id'          => $reply_id,
            'status'      => $conversation->status,
        ),
    ), 200);
}

function cpb_livechat_rest_poll($request) {
    $params = $request->get_query_params();
    $_POST = array_merge($_POST, $params);
    return cpb_livechat_poll_handler();
}

/* ================================================================
   1. cpb_livechat_start – Create or resume a conversation
   ================================================================ */
function cpb_livechat_start() {
    check_ajax_referer('cpb_livechat_nonce', 'nonce');
    cpb_livechat_start_handler();
}

function cpb_livechat_start_handler() {

    global $wpdb;
    cpb_livechat_ensure_tables();

    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    // Accept both session_key (legacy) and session_id (JS widget)
    $session_key  = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';
    if (!$session_key && isset($_POST['session_id'])) {
        $session_key = sanitize_text_field($_POST['session_id']);
    }
    $page_url     = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';
    $visitor_ip   = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';

    // Try to resume existing conversation
    if ($session_key) {
        $conversation = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$conv_table}` WHERE `session_key` = %s LIMIT 1", $session_key)
        );

        if ($conversation && $conversation->status !== 'closed') {
            $messages = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$msg_table}` WHERE `conversation_id` = %d ORDER BY `id` ASC", $conversation->id)
            );

            wp_send_json_success(array(
                'conversation_id' => (int) $conversation->id,
                'session_key'     => $conversation->session_key,
                'session_id'      => $conversation->session_key,
                'status'          => $conversation->status,
                'messages'        => array_map('cpb_livechat_format_message', $messages),
            ));
        }
    }

    // Create new conversation
    $session_key = cpb_livechat_generate_session_key();
    $now = current_time('mysql', false);

    $wpdb->insert($conv_table, array(
        'session_key'  => $session_key,
        'visitor_ip'   => $visitor_ip,
        'visitor_page' => $page_url,
        'status'       => 'ai',
        'created_at'   => $now,
        'updated_at'   => $now,
    ), array('%s', '%s', '%s', '%s', '%s', '%s'));

    $conversation_id = (int) $wpdb->insert_id;

    wp_send_json_success(array(
        'conversation_id' => $conversation_id,
        'session_key'     => $session_key,
        'session_id'      => $session_key,
        'status'          => 'ai',
        'messages'        => array(),
    ));
}

/* ================================================================
   2. cpb_livechat_send – Visitor sends a message
   ================================================================ */
function cpb_livechat_send() {
    check_ajax_referer('cpb_livechat_nonce', 'nonce');
    cpb_livechat_send_handler();
}

function cpb_livechat_send_handler() {
    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    // Accept both session_key (legacy) and session_id (JS widget)
    $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';
    if (!$session_key && isset($_POST['session_id'])) {
        $session_key = sanitize_text_field($_POST['session_id']);
    }
    $message     = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

    if (!$session_key || !$message) {
        wp_send_json_error(array('message' => 'Missing session_key or message.'), 400);
    }

    // Get conversation
    $conversation = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `{$conv_table}` WHERE `session_key` = %s LIMIT 1", $session_key)
    );

    if (!$conversation) {
        wp_send_json_error(array('message' => 'Conversation not found.'), 404);
    }

    if ($conversation->status === 'closed') {
        wp_send_json_error(array('message' => 'This conversation has been closed.'), 403);
    }

    $now = current_time('mysql', false);
    $response_messages = array();

    // Save visitor message
    $wpdb->insert($msg_table, array(
        'conversation_id' => $conversation->id,
        'sender_type'     => 'visitor',
        'sender_id'       => null,
        'message'         => $message,
        'is_read'         => 0,
        'created_at'      => $now,
    ), array('%d', '%s', '%s', '%s', '%d', '%s'));

    $visitor_msg_id = (int) $wpdb->insert_id;
    $response_messages[] = array(
        'id'          => $visitor_msg_id,
        'sender_type' => 'visitor',
        'sender_id'   => null,
        'message'     => $message,
        'is_read'     => 0,
        'created_at'  => $now,
    );

    // Update conversation timestamp
    $wpdb->update($conv_table, array('updated_at' => $now), array('id' => $conversation->id), array('%s'), array('%d'));

    // If conversation is in AI mode, generate AI response
    if ($conversation->status === 'ai') {
        if (!class_exists('CPP_Livechat_AI')) {
            $ai_class_path = CPP_PLUGIN_DIR . 'includes/classes/CPP_Livechat_AI.php';
            if (file_exists($ai_class_path)) {
                require_once $ai_class_path;
            }
        }

        if (class_exists('CPP_Livechat_AI')) {
            $ai = new CPP_Livechat_AI();

            // Get conversation history for context
            $history = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM `{$msg_table}` WHERE `conversation_id` = %d ORDER BY `id` ASC", $conversation->id)
            );

            $ai_result = $ai->generate_response($message, $history, $conversation);
            $ai_text   = isset($ai_result['reply']) ? $ai_result['reply'] : '';
            $needs_human = !empty($ai_result['needs_human']);

            if ($ai_text) {
                $ai_now = current_time('mysql', false);
                $wpdb->insert($msg_table, array(
                    'conversation_id' => $conversation->id,
                    'sender_type'     => 'ai',
                    'sender_id'       => null,
                    'message'         => $ai_text,
                    'is_read'         => 0,
                    'created_at'      => $ai_now,
                ), array('%d', '%s', '%s', '%s', '%d', '%s'));

                $ai_msg_id = (int) $wpdb->insert_id;
                $response_messages[] = array(
                    'id'          => $ai_msg_id,
                    'sender_type' => 'ai',
                    'sender_id'   => null,
                    'message'     => $ai_text,
                    'is_read'     => 0,
                    'created_at'  => $ai_now,
                );
            }

            // If AI flags the conversation as needing a human operator
            if ($needs_human) {
                $wpdb->update(
                    $conv_table,
                    array('status' => 'waiting', 'updated_at' => current_time('mysql', false)),
                    array('id' => $conversation->id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        }
    }

    // If status is 'live', the visitor message is saved but no AI response is generated.
    // The operator will see it on their next poll.

    // Build response - include 'reply' field for JS widget compatibility
    $response = array(
        'messages' => $response_messages,
        'status'   => $conversation->status,
    );

    // If there was an AI/admin reply, add it as top-level fields for the widget JS
    foreach ($response_messages as $rm) {
        if ($rm['sender_type'] !== 'visitor') {
            $response['reply']       = $rm['message'];
            $response['sender_type'] = $rm['sender_type'];
            $response['created_at']  = $rm['created_at'];
            $response['id']          = $rm['id'];
            break;
        }
    }

    wp_send_json_success($response);
}

/* ================================================================
   3. cpb_livechat_poll – Visitor polls for new messages
   ================================================================ */
function cpb_livechat_poll() {
    check_ajax_referer('cpb_livechat_nonce', 'nonce');
    cpb_livechat_poll_handler();
}

function cpb_livechat_poll_handler() {
    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    // Accept both session_key (legacy) and session_id (JS widget)
    $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';
    if (!$session_key && isset($_POST['session_id'])) {
        $session_key = sanitize_text_field($_POST['session_id']);
    }
    $after_id    = isset($_POST['after_id']) ? absint($_POST['after_id']) : 0;

    if (!$session_key) {
        wp_send_json_error(array('message' => 'Missing session_key.'), 400);
    }

    $conversation = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `{$conv_table}` WHERE `session_key` = %s LIMIT 1", $session_key)
    );

    if (!$conversation) {
        wp_send_json_error(array('message' => 'Conversation not found.'), 404);
    }

    $messages = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM `{$msg_table}` WHERE `conversation_id` = %d AND `id` > %d ORDER BY `id` ASC",
            $conversation->id,
            $after_id
        )
    );

    wp_send_json_success(array(
        'messages' => array_map('cpb_livechat_format_message', $messages),
        'status'   => $conversation->status,
    ));
}

/* ================================================================
   4. cpb_livechat_admin_list – List active conversations
   ================================================================ */
function cpb_livechat_admin_list() {
    check_ajax_referer('cpb_livechat_admin_nonce', 'nonce');

    if (!current_user_can('cpp_chat_takeover')) {
        wp_send_json_error(array('message' => 'Permission denied.'), 403);
    }

    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    $conversations = $wpdb->get_results(
        "SELECT * FROM `{$conv_table}` WHERE `status` != 'closed' ORDER BY `updated_at` DESC"
    );

    $result = array();
    foreach ($conversations as $conv) {
        // Last message preview
        $last_msg = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `message` FROM `{$msg_table}` WHERE `conversation_id` = %d ORDER BY `id` DESC LIMIT 1",
                $conv->id
            )
        );

        // Unread count (visitor messages not read)
        $unread_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$msg_table}` WHERE `conversation_id` = %d AND `sender_type` = 'visitor' AND `is_read` = 0",
                $conv->id
            )
        );

        $result[] = array(
            'id'            => (int) $conv->id,
            'visitor_name'  => $conv->visitor_name,
            'visitor_email' => $conv->visitor_email,
            'status'        => $conv->status,
            'last_message'  => $last_msg ? wp_trim_words($last_msg, 20, '...') : '',
            'unread_count'  => $unread_count,
            'created_at'    => $conv->created_at,
            'updated_at'    => $conv->updated_at,
        );
    }

    wp_send_json_success(array('conversations' => $result));
}

/* ================================================================
   5. cpb_livechat_admin_get – Get all messages for a conversation
   ================================================================ */
function cpb_livechat_admin_get() {
    check_ajax_referer('cpb_livechat_admin_nonce', 'nonce');

    if (!current_user_can('cpp_chat_takeover')) {
        wp_send_json_error(array('message' => 'Permission denied.'), 403);
    }

    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
    if (!$conversation_id) {
        wp_send_json_error(array('message' => 'Missing conversation_id.'), 400);
    }

    $conversation = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `{$conv_table}` WHERE `id` = %d LIMIT 1", $conversation_id)
    );

    if (!$conversation) {
        wp_send_json_error(array('message' => 'Conversation not found.'), 404);
    }

    // Fetch all messages
    $messages = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM `{$msg_table}` WHERE `conversation_id` = %d ORDER BY `id` ASC", $conversation_id)
    );

    // Mark visitor messages as read
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE `{$msg_table}` SET `is_read` = 1 WHERE `conversation_id` = %d AND `sender_type` = 'visitor' AND `is_read` = 0",
            $conversation_id
        )
    );

    wp_send_json_success(array(
        'conversation' => array(
            'id'               => (int) $conversation->id,
            'session_key'      => $conversation->session_key,
            'visitor_name'     => $conversation->visitor_name,
            'visitor_email'    => $conversation->visitor_email,
            'visitor_ip'       => $conversation->visitor_ip,
            'visitor_page'     => $conversation->visitor_page,
            'status'           => $conversation->status,
            'assigned_user_id' => $conversation->assigned_user_id ? (int) $conversation->assigned_user_id : null,
            'created_at'       => $conversation->created_at,
            'updated_at'       => $conversation->updated_at,
        ),
        'messages' => array_map('cpb_livechat_format_message', $messages),
    ));
}

/* ================================================================
   6. cpb_livechat_admin_send – Operator sends a message
   ================================================================ */
function cpb_livechat_admin_send() {
    check_ajax_referer('cpb_livechat_admin_nonce', 'nonce');

    if (!current_user_can('cpp_chat_takeover')) {
        wp_send_json_error(array('message' => 'Permission denied.'), 403);
    }

    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
    $message         = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

    if (!$conversation_id || !$message) {
        wp_send_json_error(array('message' => 'Missing conversation_id or message.'), 400);
    }

    $conversation = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM `{$conv_table}` WHERE `id` = %d LIMIT 1", $conversation_id)
    );

    if (!$conversation) {
        wp_send_json_error(array('message' => 'Conversation not found.'), 404);
    }

    $now     = current_time('mysql', false);
    $user_id = get_current_user_id();

    // Save operator message
    $wpdb->insert($msg_table, array(
        'conversation_id' => $conversation_id,
        'sender_type'     => 'operator',
        'sender_id'       => $user_id,
        'message'         => $message,
        'is_read'         => 0,
        'created_at'      => $now,
    ), array('%d', '%s', '%d', '%s', '%d', '%s'));

    $msg_id = (int) $wpdb->insert_id;

    // Update conversation: take over as live, assign operator
    $wpdb->update(
        $conv_table,
        array(
            'status'           => 'live',
            'assigned_user_id' => $user_id,
            'updated_at'       => $now,
        ),
        array('id' => $conversation_id),
        array('%s', '%d', '%s'),
        array('%d')
    );

    wp_send_json_success(array(
        'message' => array(
            'id'          => $msg_id,
            'sender_type' => 'operator',
            'sender_id'   => $user_id,
            'message'     => $message,
            'is_read'     => 0,
            'created_at'  => $now,
        ),
    ));
}

/* ================================================================
   7. cpb_livechat_admin_close – Close a conversation
   ================================================================ */
function cpb_livechat_admin_close() {
    check_ajax_referer('cpb_livechat_admin_nonce', 'nonce');

    if (!current_user_can('cpp_chat_takeover')) {
        wp_send_json_error(array('message' => 'Permission denied.'), 403);
    }

    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';

    $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
    if (!$conversation_id) {
        wp_send_json_error(array('message' => 'Missing conversation_id.'), 400);
    }

    $updated = $wpdb->update(
        $conv_table,
        array('status' => 'closed', 'updated_at' => current_time('mysql', false)),
        array('id' => $conversation_id),
        array('%s', '%s'),
        array('%d')
    );

    if ($updated === false) {
        wp_send_json_error(array('message' => 'Failed to close conversation.'), 500);
    }

    wp_send_json_success(array('message' => 'Conversation closed.'));
}

/* ================================================================
   8. cpb_livechat_save_settings – Save widget settings
   ================================================================ */
function cpb_livechat_save_settings() {
    check_ajax_referer('cpb_livechat_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'), 403);
    }

    $fields = array(
        'enabled'         => 'sanitize_text_field',
        'greeting'        => 'sanitize_textarea_field',
        'bot_name'        => 'sanitize_text_field',
        'offline_message' => 'sanitize_textarea_field',
        'accent_color'    => 'sanitize_hex_color',
    );

    $saved = array();
    foreach ($fields as $key => $sanitizer) {
        if (isset($_POST[$key])) {
            $value = call_user_func($sanitizer, $_POST[$key]);
            update_option('cpb_livechat_' . $key, $value);
            $saved[$key] = $value;
        }
    }

    wp_send_json_success(array(
        'message'  => 'Settings saved.',
        'settings' => $saved,
    ));
}
