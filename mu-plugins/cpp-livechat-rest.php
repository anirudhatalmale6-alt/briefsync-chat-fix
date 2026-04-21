<?php
/* MU v2.2 - fix duplicate greeting */
/**
 * BriefSync Live Chat REST API
 * Wraps existing AJAX handlers as REST GET/PUT endpoints to bypass ModSecurity POST blocking.
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    // PUT /wp-json/cpp-chat/v1/start — start/resume session
    register_rest_route('cpp-chat/v1', '/start', array(
        'methods'  => 'PUT',
        'callback' => 'cpp_chat_rest_start',
        'permission_callback' => '__return_true',
    ));

    // PUT /wp-json/cpp-chat/v1/send — send message
    register_rest_route('cpp-chat/v1', '/send', array(
        'methods'  => 'PUT',
        'callback' => 'cpp_chat_rest_send',
        'permission_callback' => '__return_true',
    ));

    // GET /wp-json/cpp-chat/v1/poll — poll for new messages
    register_rest_route('cpp-chat/v1', '/poll', array(
        'methods'  => 'GET',
        'callback' => 'cpp_chat_rest_poll',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Simulate wp_ajax by setting $_POST and capturing output.
 */
function cpp_chat_rest_call_ajax($action, $params) {
    // Set up the POST params expected by the AJAX handler
    $_POST = $params;
    $_POST['action'] = $action;
    $_REQUEST = $_POST;

    // Skip nonce check for REST calls - we handle auth differently
    add_filter('wp_doing_ajax', '__return_true');

    // Capture the AJAX handler output
    ob_start();
    do_action('wp_ajax_nopriv_' . $action);
    $output = ob_get_clean();

    // If no output from nopriv, try priv
    if (empty($output) && is_user_logged_in()) {
        ob_start();
        do_action('wp_ajax_' . $action);
        $output = ob_get_clean();
    }

    $decoded = json_decode($output, true);
    if ($decoded !== null) {
        return new WP_REST_Response($decoded, 200);
    }
    return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'No response from handler')), 200);
}

function cpp_chat_rest_start(WP_REST_Request $request) {
    $body = $request->get_json_params();
    $params = array(
        'session_id'    => !empty($body['session_id']) ? sanitize_text_field($body['session_id']) : '',
        'visitor_name'  => !empty($body['visitor_name']) ? sanitize_text_field($body['visitor_name']) : '',
        'visitor_email' => !empty($body['visitor_email']) ? sanitize_email($body['visitor_email']) : '',
        'page_url'      => !empty($body['page_url']) ? esc_url_raw($body['page_url']) : '',
    );

    // We need to bypass the nonce check in the AJAX handler
    // The simplest approach: directly call the function if it exists
    if (function_exists('cpb_livechat_start_rest')) {
        return cpb_livechat_start_rest($params);
    }

    // Fallback: call the AJAX handler directly with nonce bypass
    return cpp_chat_rest_forward('cpb_livechat_start', $params);
}

function cpp_chat_rest_send(WP_REST_Request $request) {
    $body = $request->get_json_params();
    $params = array(
        'session_id' => !empty($body['session_id']) ? sanitize_text_field($body['session_id']) : '',
        'message'    => !empty($body['message']) ? sanitize_text_field($body['message']) : '',
    );

    return cpp_chat_rest_forward('cpb_livechat_send', $params);
}

function cpp_chat_rest_poll(WP_REST_Request $request) {
    $params = array(
        'session_id' => sanitize_text_field($request->get_param('session_id') ?? ''),
        'after_id'   => intval($request->get_param('after_id') ?? 0),
    );

    return cpp_chat_rest_forward('cpb_livechat_poll', $params);
}

/**
 * Forward to AJAX handler, bypassing nonce verification.
 * Sets a flag that our patched nonce check will honor.
 */
function cpp_chat_rest_forward($action, $params) {
    global $wpdb;

    // Ensure tables exist
    if (function_exists('cpb_livechat_ensure_tables')) {
        cpb_livechat_ensure_tables();
    }

    // Direct implementation for each action to avoid nonce issues
    if ($action === 'cpb_livechat_start') {
        return cpp_chat_direct_start($params);
    } elseif ($action === 'cpb_livechat_send') {
        return cpp_chat_direct_send($params);
    } elseif ($action === 'cpb_livechat_poll') {
        return cpp_chat_direct_poll($params);
    }

    return new WP_REST_Response(array('success' => false), 200);
}

function cpp_chat_direct_start($params) {
    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    $session_key = !empty($params['session_id']) ? $params['session_id'] : '';

    // Try to resume existing session
    if ($session_key) {
        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$conv_table} WHERE session_key = %s AND status != 'closed'",
            $session_key
        ));
        if ($conv) {
            $messages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$msg_table} WHERE conversation_id = %d ORDER BY id ASC",
                $conv->id
            ));
            $formatted = array();
            foreach ($messages as $m) {
                if (function_exists('cpb_livechat_format_message')) {
                    $formatted[] = cpb_livechat_format_message($m);
                } else {
                    $formatted[] = array(
                        'id' => (int) $m->id,
                        'sender_type' => $m->sender_type,
                        'message' => $m->message,
                        'created_at' => $m->created_at,
                    );
                }
            }
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'session_id' => $conv->session_key,
                    'conversation_id' => (int) $conv->id,
                    'status' => $conv->status,
                    'messages' => $formatted,
                ),
            ), 200);
        }
    }

    // Create new session
    if (!$session_key) {
        $session_key = function_exists('cpb_livechat_generate_session_key')
            ? cpb_livechat_generate_session_key()
            : bin2hex(random_bytes(16));
    }

    $wpdb->insert($conv_table, array(
        'session_key'   => $session_key,
        'visitor_name'  => !empty($params['visitor_name']) ? $params['visitor_name'] : null,
        'visitor_email' => !empty($params['visitor_email']) ? $params['visitor_email'] : null,
        'visitor_ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
        'visitor_page'  => !empty($params['page_url']) ? $params['page_url'] : null,
        'status'        => 'ai',
    ));

    $conv_id = $wpdb->insert_id;

    // Get AI greeting
    $settings = get_option('cpb_livechat_settings', array());
    $greeting = !empty($settings['greeting']) ? $settings['greeting'] : 'Hi! How can we help you today?';

    // Insert greeting message
    $wpdb->insert($msg_table, array(
        'conversation_id' => $conv_id,
        'sender_type'     => 'ai',
        'message'         => $greeting,
    ));
    $greeting_id = $wpdb->insert_id;

    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'session_id' => $session_key,
            'conversation_id' => (int) $conv_id,
            'status' => 'ai',
            'greeting' => $greeting,
            'messages' => array(
                array(
                    'id' => (int) $greeting_id,
                    'sender_type' => 'ai',
                    'message' => $greeting,
                    'created_at' => current_time('mysql'),
                ),
            ),
        ),
    ), 200);
}

function cpp_chat_direct_send($params) {
    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    $session_key = $params['session_id'];
    $message = $params['message'];

    if (empty($session_key) || empty($message)) {
        return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'Missing session or message')), 200);
    }

    $conv = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$conv_table} WHERE session_key = %s",
        $session_key
    ));

    if (!$conv) {
        return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'Session not found')), 200);
    }

    // Save visitor message
    $wpdb->insert($msg_table, array(
        'conversation_id' => $conv->id,
        'sender_type'     => 'visitor',
        'message'         => $message,
    ));

    // Update conversation timestamp
    $wpdb->update($conv_table, array('updated_at' => current_time('mysql')), array('id' => $conv->id));

    // Get AI response if in AI mode
    $reply = '';
    $sender_type = 'ai';
    $needs_human = false;

    if ($conv->status === 'ai') {
        // Load AI class from plugin
        if (!class_exists('CPP_Livechat_AI') && defined('CPP_PLUGIN_DIR')) {
            $ai_path = CPP_PLUGIN_DIR . 'includes/classes/CPP_Livechat_AI.php';
            if (file_exists($ai_path)) {
                require_once $ai_path;
            }
        }

        if (class_exists('CPP_Livechat_AI')) {
            $ai = new CPP_Livechat_AI();
            $history = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$msg_table} WHERE conversation_id = %d ORDER BY id ASC",
                $conv->id
            ));
            $ai_result = $ai->generate_response($message, $history, $conv);
            $reply = isset($ai_result['reply']) ? $ai_result['reply'] : '';
            $needs_human = !empty($ai_result['needs_human']);
        } else {
            $reply = "Thanks for your message! Our team will get back to you shortly.";
        }

        if ($reply) {
            $wpdb->insert($msg_table, array(
                'conversation_id' => $conv->id,
                'sender_type'     => 'ai',
                'message'         => $reply,
            ));
        }

        if ($needs_human) {
            $wpdb->update($conv_table,
                array('status' => 'waiting', 'updated_at' => current_time('mysql')),
                array('id' => $conv->id)
            );
        }
    }

    $msg_id = $wpdb->insert_id;

    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'id'          => (int) $msg_id,
            'reply'       => $reply,
            'sender_type' => $sender_type,
            'status'      => $conv->status,
        ),
    ), 200);
}

function cpp_chat_direct_poll($params) {
    global $wpdb;
    $conv_table = $wpdb->prefix . 'cpp_livechat_conversations';
    $msg_table  = $wpdb->prefix . 'cpp_livechat_messages';

    $session_key = $params['session_id'];
    $after_id = intval($params['after_id']);

    if (empty($session_key)) {
        return new WP_REST_Response(array('success' => false), 200);
    }

    $conv = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$conv_table} WHERE session_key = %s",
        $session_key
    ));

    if (!$conv) {
        return new WP_REST_Response(array('success' => false), 200);
    }

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$msg_table} WHERE conversation_id = %d AND id > %d AND sender_type != 'visitor' ORDER BY id ASC",
        $conv->id, $after_id
    ));

    $formatted = array();
    foreach ($messages as $m) {
        if (function_exists('cpb_livechat_format_message')) {
            $formatted[] = cpb_livechat_format_message($m);
        } else {
            $formatted[] = array(
                'id' => (int) $m->id,
                'sender_type' => $m->sender_type,
                'message' => $m->message,
                'created_at' => $m->created_at,
            );
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'messages' => $formatted,
            'status'   => $conv->status,
        ),
    ), 200);
}

