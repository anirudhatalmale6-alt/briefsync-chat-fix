<?php
if (!defined('ABSPATH'))
    exit;

/* ---------- Secure File Serving (view & download) ---------- */
add_action('template_redirect', 'cpp_serve_secure_file');
function cpp_serve_secure_file() {
    $is_view = isset($_GET['cpp_view']) && $_GET['cpp_view'] == '1';
    $is_download = isset($_GET['cpp_download']) && $_GET['cpp_download'] == '1';
    if (!$is_view && !$is_download) return;

    $file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!$file_id || !$nonce) {
        wp_die('Invalid request.', 'Error', array('response' => 403));
    }

    $action = $is_view ? 'cpp_view_' . $file_id : 'cpp_download_' . $file_id;
    if (!wp_verify_nonce($nonce, $action)) {
        wp_die('Security check failed.', 'Error', array('response' => 403));
    }

    if (!is_user_logged_in()) {
        wp_die('Please log in to access files.', 'Error', array('response' => 403));
    }

    if (!class_exists('CPP_Upload_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Upload_Manager.php';
    if (!class_exists('CPP_Client_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';

    $um = new CPP_Upload_Manager();
    $cm = new CPP_Client_Manager();
    $file = $um->get($file_id);
    $client = $cm->get_by_user_id(get_current_user_id());

    if (!$file || !$client || intval($file['client_id']) !== intval($client['id'])) {
        wp_die('File not found or access denied.', 'Error', array('response' => 404));
    }

    $path = $file['path'];
    if (empty($path) || !file_exists($path)) {
        wp_die('File no longer exists on disk.', 'Error', array('response' => 404));
    }

    $mime = !empty($file['mime']) ? $file['mime'] : 'application/octet-stream';
    $name = !empty($file['original_name']) ? $file['original_name'] : basename($path);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    if ($is_download) {
        header('Content-Disposition: attachment; filename="' . $name . '"');
    } else {
        header('Content-Disposition: inline; filename="' . $name . '"');
    }
    header('Cache-Control: private, max-age=3600');

    readfile($path);
    exit;
}

/* ---------- Helper: Convert size strings (e.g., "2GB", "512M") to bytes ---------- */
if (!function_exists('cpp_parse_size_string')) {
    function cpp_parse_size_string($str)
    {
        if (is_numeric($str))
            return floatval($str);
        $str = strtoupper(trim($str));
        if (empty($str))
            return null;
        $val = floatval($str);
        if (strpos($str, 'T') !== false)
            return $val * 1099511627776;
        if (strpos($str, 'G') !== false)
            return $val * 1073741824;
        if (strpos($str, 'M') !== false)
            return $val * 1048576;
        if (strpos($str, 'K') !== false)
            return $val * 1024;
        return $val;
    }
}

/* ---------- Helper: Deep recursive search for used/limit patterns ---------- */
if (!function_exists('cpp_extract_disk_usage')) {
    function cpp_extract_disk_usage($response) {
        if (!is_array($response) || !isset($response['data'])) return null;
        
        $data = $response['data'];
        $used = null;
        $limit = 0;

        // Try standard cPanel Quota fields
        if (isset($data['diskused'])) {
            $used = cpp_parse_size_string($data['diskused']);
            $limit = isset($data['disklimit']) ? cpp_parse_size_string($data['disklimit']) : 0;
        } 
        // Try 'megabytes_used' format (found in some API versions)
        elseif (isset($data['megabytes_used'])) {
            $used = floatval($data['megabytes_used']) * 1048576; // MB to Bytes
            $limit = isset($data['megabyte_limit']) ? floatval($data['megabyte_limit']) * 1048576 : 0;
        }

        if ($used !== null) {
            return array(
                'used' => $used,
                'limit' => $limit,
            );
        }
        
        return null;
    }
}

if (!function_exists('cpp_extract_whm_data')) {
    function cpp_extract_whm_data($whm_response) {
        if (!is_array($whm_response)) return null;
        $acct = $whm_response['data']['acct'] ?? null;
        if (!is_array($acct)) return null;
        
        $entry = isset($acct[0]) ? $acct[0] : $acct;
        
        $data = array(
            'bandwidth' => null,
            'disk' => null
        );

        // Bandwidth
        if (isset($entry['totalbytes'])) {
            $data['bandwidth'] = array(
                'used' => floatval($entry['totalbytes']),
                'limit' => isset($entry['limit']) ? floatval($entry['limit']) : 0
            );
        }

        // Disk (Check multiple common field names for disk usage)
        // Some WHM versions use 'diskused', others use 'diskusage' or 'quota'
        $used = null;
        if (isset($entry['diskused'])) $used = floatval($entry['diskused']);
        elseif (isset($entry['diskusage'])) $used = floatval($entry['diskusage']);
        elseif (isset($entry['quota_used'])) $used = floatval($entry['quota_used']);

        $limit = 0;
        if (isset($entry['disklimit'])) $limit = floatval($entry['disklimit']);
        elseif (isset($entry['quota'])) $limit = floatval($entry['quota']);
        elseif (isset($entry['limit'])) $limit = floatval($entry['limit']);

        if ($used !== null) {
            $data['disk'] = array(
                'used' => $used * 1048576, // MB to Bytes
                'limit' => $limit * 1048576
            );
        }

        return $data;
    }
}

if (!function_exists('cpp_bytes_to_human')) {
    function cpp_bytes_to_human($bytes)
    {
        $bytes = floatval($bytes);
        if ($bytes >= 1099511627776)
            return round($bytes / 1099511627776, 2) . ' TB';
        if ($bytes >= 1073741824)
            return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)
            return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)
            return round($bytes / 1024, 2) . ' KB';
        return round($bytes, 0) . ' B';
    }
}

add_action('wp_ajax_cpp_fetch_stats', 'cpp_ajax_fetch_stats');
add_action('wp_ajax_cpp_get_stats', 'cpp_ajax_fetch_stats');

function cpp_ajax_fetch_stats() {
    check_ajax_referer('cpp_stats_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Not logged in'));
    }
    $user_id = get_current_user_id();
    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }
    $manager = new CPP_Client_Manager();
    $client = $manager->get_by_user_id($user_id);
    if (!$client) {
        wp_send_json_error(array('message' => 'No client profile found'));
    }
    $host = !empty($client['server_host']) ? $client['server_host'] : get_option('cpp_server_host');
    if (empty($host) || empty($client['cpanel_username']) || empty($client['cpanel_api_token'])) {
        wp_send_json_error(array('message' => 'cPanel connection not configured for this client.'));
    }
    $username = $client['cpanel_username'];
    $token = $client['cpanel_api_token'];
    $force_refresh = isset($_POST['refresh']) && $_POST['refresh'] == '1';
    
    $transient_key = 'cpp_stats_' . md5($user_id . '|' . $host . '|' . $username);
    
    if (!$force_refresh) {
        $cached = get_transient($transient_key);
        if ($cached !== false && is_array($cached)) {
            wp_send_json_success($cached);
        }
    }
    if (!class_exists('CPP_CPanel_API')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_CPanel_API.php';
    }
    $api = new CPP_CPanel_API($host);
    
    // DISK: Quota/get_quota_info — confirmed working
    $disk_raw = $api->get_disk_usage($username, $token);
    $disk_usage = cpp_extract_disk_usage($disk_raw);

    // BANDWIDTH & DISK: WHM showbw usually has both
    $bandwidth = null;
    $whm_enabled = get_option('cpp_enable_whm', '0') === '1';
    $whm_token = function_exists('cpp_get_decrypted_option') ? cpp_get_decrypted_option('cpp_whm_token') : get_option('cpp_whm_token');
    $whm_user = get_option('cpp_whm_user', 'root');
    
    if ($whm_enabled && !empty($whm_token)) {
        $bw_url = sprintf(
            'https://%s:2087/json-api/showbw?api.version=1&search=%s&searchtype=user',
            $host,
            rawurlencode($username)
        );
        $resp = wp_remote_get($bw_url, array(
            'headers' => array(
                'Authorization' => 'whm ' . $whm_user . ':' . $whm_token,
                'Accept' => 'application/json',
            ),
            'timeout' => 10,
            'sslverify' => false,
        ));
        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            $whm_data = json_decode(wp_remote_retrieve_body($resp), true);
            $extracted_whm = cpp_extract_whm_data($whm_data);
            
            if ($extracted_whm) {
                $bandwidth = $extracted_whm['bandwidth'];
                // Only overwrite disk_usage if cPanel call failed or it's null
                if ($disk_usage === null && $extracted_whm['disk']) {
                    $disk_usage = $extracted_whm['disk'];
                }
            }
        }
    }
    
    // EMAIL COUNT
    $emails = $api->list_emails($username, $token);
    $email_count = 0;
    if (isset($emails['data']) && is_array($emails['data'])) {
        $email_count = count($emails['data']);
    }

    // BUILD RESULT
    $result = array(
        'disk' => $disk_usage ? array(
            'used' => $disk_usage['used'],
            'limit' => $disk_usage['limit'],
            'percent' => $disk_usage['limit'] > 0 ? round(($disk_usage['used'] / $disk_usage['limit']) * 100, 2) : 0,
            'used_human' => cpp_bytes_to_human($disk_usage['used']),
            'limit_human' => $disk_usage['limit'] > 0 ? cpp_bytes_to_human($disk_usage['limit']) : 'Unlimited',
        ) : null,
        'bandwidth' => $bandwidth ? array(
            'used' => $bandwidth['used'],
            'limit' => $bandwidth['limit'],
            'percent' => $bandwidth['limit'] > 0 ? round(($bandwidth['used'] / $bandwidth['limit']) * 100, 2) : 0,
            'used_human' => cpp_bytes_to_human($bandwidth['used']),
            'limit_human' => $bandwidth['limit'] > 0 ? cpp_bytes_to_human($bandwidth['limit']) : 'Unlimited',
        ) : null,
        'emails' => intval($email_count),
        'fetched_at' => current_time('mysql'),
    );
    
    set_transient($transient_key, $result, 300);
    wp_send_json_success($result);
}

/* ---------- Upgrade request ---------- */
add_action('wp_ajax_cpp_request_upgrade', 'cpp_ajax_request_upgrade');

function cpp_ajax_request_upgrade()
{
    check_ajax_referer('cpp_request_upgrade', 'nonce');
    if (!is_user_logged_in())
        wp_send_json_error(array('message' => 'Please log in'));

    $user_id = get_current_user_id();
    $plan = isset($_POST['plan']) ? sanitize_key($_POST['plan']) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

    if (!$plan)
        wp_send_json_error(array('message' => 'No plan specified'));

    if (!class_exists('CPP_Client_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';

    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id($user_id);

    if (!$client)
        wp_send_json_error(array('message' => 'Client profile not found'));

    $requests = get_option('cpp_upgrade_requests', array());

    $entry = array(
        'id' => time() . rand(100, 999),
        'user_id' => $user_id,
        'client_id' => $client['id'],
        'plan' => $plan,
        'message' => $message,
        'created_at' => current_time('mysql', 1)
    );

    $requests[] = $entry;
    update_option('cpp_upgrade_requests', $requests);

    $admin_email = get_option('admin_email');
    $subject = sprintf('Upgrade request: client #%d -> %s', intval($client['id']), esc_html($plan));
    $body = "Client ID: " . intval($client['id']) . "\n";
    $body .= "WP user ID: " . intval($user_id) . "\n";
    $body .= "Requested plan: " . $plan . "\n";
    $body .= "Message:\n" . $message . "\n\n";
    $body .= "Manage requests in wp_options (option name: cpp_upgrade_requests).";

    wp_mail($admin_email, $subject, $body);

    wp_send_json_success(array('message' => 'Request submitted'));
}

/* ---------- Tickets ---------- */
add_action('wp_ajax_cpp_create_ticket', 'cpp_ajax_create_ticket');

function cpp_ajax_create_ticket()
{
    check_ajax_referer('cpp_create_ticket', 'nonce');
    if (!is_user_logged_in())
        wp_send_json_error(array('message' => 'Please log in'));

    $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
    $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';

    if (!$subject || !$message)
        wp_send_json_error(array('message' => 'Subject and message required'));

    if (!class_exists('CPP_Client_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    if (!class_exists('CPP_Ticket_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Ticket_Manager.php';

    $cm = new CPP_Client_Manager();
    $tm = new CPP_Ticket_Manager();

    $user_id = get_current_user_id();
    $client = $cm->get_by_user_id($user_id);

    // If this WordPress user is not yet linked to a client record,
    // create a minimal client row on the fly so ticketing still works.
    if (!$client) {
        $new_id = $cm->add_or_update(array(
            'user_id'          => $user_id,
            'cpanel_username'  => '',
            'cpanel_api_token' => '',
            'plan'             => 'website',
        ));

        if ($new_id) {
            $client = $cm->get_by_id($new_id);
        }
    }

    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile could not be created for this account.'));
    }

    // File Upload handling for Create Ticket
    if (!empty($_FILES['attachment']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = $_FILES['attachment'];
        $file_name = sanitize_file_name($file['name']);

        if ($file['size'] > 10 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'File size exceeds 10MB limit.'));
        }
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'zip');
        if (!in_array($ext, $allowed)) {
            wp_send_json_error(array('message' => 'Invalid file type.'));
        }

        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($file, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            $attachment_url = $movefile['url'];
            $message .= "<br><br><strong>🔗 Attachment:</strong> <a href='" . esc_url($attachment_url) . "' target='_blank'>" . esc_html($file_name) . "</a>";

            // Track upload if CPP_Upload_Manager is available
            if (class_exists('CPP_Upload_Manager')) {
                $um = new CPP_Upload_Manager();
                $um->add($user_id, $movefile['file'], $file['size'], $file_name);
            }
        }
        else {
            wp_send_json_error(array('message' => 'File upload failed: ' . $movefile['error']));
        }
    }

    $ticket_id = $tm->create_ticket(array('client_id' => $client['id'], 'wp_user_id' => $user_id, 'subject' => $subject));

    if (!$ticket_id)
        wp_send_json_error(array('message' => 'Failed to create ticket'));

    $msg_id = $tm->add_message($ticket_id, 'client', $user_id, $message);

    // Email notification via central dispatcher
    if (function_exists('cpp_notify')) {
        $cd = cpp_notify_resolve_client($client);
        cpp_notify('ticket_created', array(
            'client_name'  => $cd['client_name'],
            'client_email' => $cd['client_email'],
            'subject'      => $subject,
            'ticket_id'    => $ticket_id,
            'message'      => $message,
        ));
    }

    wp_send_json_success(array('message' => 'Ticket created', 'ticket_id' => $ticket_id));
}

add_action('wp_ajax_cpp_client_reply_ticket', 'cpp_ajax_client_reply_ticket');

function cpp_ajax_client_reply_ticket()
{
    check_ajax_referer('cpp_client_reply_ticket', 'nonce');
    if (!is_user_logged_in())
        wp_send_json_error(array('message' => 'Please log in'));

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';

    if (!$ticket_id || !$message)
        wp_send_json_error(array('message' => 'Missing params'));

    if (!class_exists('CPP_Client_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    if (!class_exists('CPP_Ticket_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Ticket_Manager.php';

    $cm = new CPP_Client_Manager();
    $tm = new CPP_Ticket_Manager();

    $user_id = get_current_user_id();
    $client = $cm->get_by_user_id($user_id);

    if (!$client)
        wp_send_json_error(array('message' => 'Client profile not found'));

    $ticket = $tm->get_ticket($ticket_id);

    if (!$ticket || intval($ticket['client_id']) !== intval($client['id']))
        wp_send_json_error(array('message' => 'Ticket not found or permission denied'));

    // Handle File Attachment
    if (!empty($_FILES['attachment']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = $_FILES['attachment'];
        $file_name = sanitize_file_name($file['name']);

        // Limits
        if ($file['size'] > 10 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'File size exceeds 10MB limit.'));
        }
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'zip');
        if (!in_array($ext, $allowed)) {
            wp_send_json_error(array('message' => 'Invalid file type.'));
        }

        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($file, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            $attachment_url = $movefile['url'];
            $message .= "<br><br><strong>🔗 Attachment:</strong> <a href='" . esc_url($attachment_url) . "' target='_blank'>" . esc_html($file_name) . "</a>";

            // Log it in cpp_uploads mapping if um exists
            if (class_exists('CPP_Upload_Manager')) {
                $um = new CPP_Upload_Manager();
                $um->add(array(
                    'client_id' => $client['id'],
                    'wp_user_id' => $user_id,
                    'filename' => $file_name,
                    'original_name' => $file_name,
                    'mime' => $movefile['type'],
                    'size' => $file['size'],
                    'path' => $movefile['file']
                ));
            }
        }
        else {
            wp_send_json_error(array('message' => 'File upload failed: ' . $movefile['error']));
        }
    }

    $mid = $tm->add_message($ticket_id, 'client', $user_id, $message);

    // Email notification via central dispatcher
    if (function_exists('cpp_notify')) {
        $cd = cpp_notify_resolve_client($client);
        cpp_notify('ticket_reply', array(
            'client_name'  => $cd['client_name'],
            'client_email' => $cd['client_email'],
            'subject'      => $ticket['subject'] ?? 'Support Ticket',
            'sender_name'  => $cd['client_name'],
            'message'      => wp_strip_all_tags($message),
        ));
    }

    wp_send_json_success(array('message' => 'Reply saved', 'message_id' => $mid));
}

add_action('wp_ajax_cpp_client_delete_ticket', 'cpp_ajax_client_delete_ticket');

function cpp_ajax_client_delete_ticket()
{
    cpp_require_delete_permission();
    check_ajax_referer('cpp_ticket_nonce', 'nonce');
    if (!is_user_logged_in())
        wp_send_json_error(array('message' => 'Please log in'));

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;

    if (!$ticket_id)
        wp_send_json_error(array('message' => 'Missing ticket id'));

    if (!class_exists('CPP_Client_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    if (!class_exists('CPP_Ticket_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Ticket_Manager.php';

    $user_id = get_current_user_id();
    $cm = new CPP_Client_Manager();
    $tm = new CPP_Ticket_Manager();

    $client = $cm->get_by_user_id($user_id);

    if (!$client)
        wp_send_json_error(array('message' => 'Client profile not found'));

    $ticket = $tm->get_ticket($ticket_id);

    if (!$ticket || intval($ticket['client_id']) !== intval($client['id'])) {
        wp_send_json_error(array('message' => 'Ticket not found or permission denied'));
    }

    if (!in_array($ticket['status'], array('closed', 'resolved'))) {
        wp_send_json_error(array('message' => 'Only closed or resolved tickets can be deleted'));
    }

    $deleted = $tm->delete_ticket($ticket_id);
    if ($deleted === false)
        wp_send_json_error(array('message' => 'Delete failed'));

    wp_send_json_success(array('message' => 'Ticket deleted'));
}

/* ---------- Admin ticket handlers ---------- */
add_action('wp_ajax_cpp_admin_reply_ticket', 'cpp_ajax_admin_reply_ticket');

function cpp_ajax_admin_reply_ticket()
{
    if (!current_user_can('cpp_manage_portal'))
        wp_send_json_error(array('message' => 'Permission denied'));
    check_ajax_referer('cpp_admin_reply_ticket', 'nonce');

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'open';

    if (!$ticket_id)
        wp_send_json_error(array('message' => 'Missing ticket ID'));

    if (!class_exists('CPP_Ticket_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Ticket_Manager.php';

    $tm = new CPP_Ticket_Manager();
    $ticket = $tm->get_ticket($ticket_id);
    if (!$ticket) {
        wp_send_json_error(array('message' => 'Ticket not found'));
    }

    $status_changed = ($ticket['status'] !== $status);

    // Handle File Attachment for Admin Reply
    if (!empty($_FILES['attachment']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = $_FILES['attachment'];
        $file_name = sanitize_file_name($file['name']);

        // Limits
        if ($file['size'] > 10 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'File size exceeds 10MB limit.'));
        }
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'zip');
        if (!in_array($ext, $allowed)) {
            wp_send_json_error(array('message' => 'Invalid file type.'));
        }

        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($file, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            $attachment_url = $movefile['url'];
            $message .= "<br><br><strong>🔗 Attachment:</strong> <a href='" . esc_url($attachment_url) . "' target='_blank'>" . esc_html($file_name) . "</a>";
        }
        else {
            wp_send_json_error(array('message' => 'File upload failed: ' . $movefile['error']));
        }
    }

    if ($message) {
        $tm->add_message($ticket_id, 'admin', get_current_user_id(), $message);
    }

    if ($status_changed) {
        $tm->update_status($ticket_id, $status);
    }

    if ($message || $status_changed) {
        // Email notification via central dispatcher
        if (function_exists('cpp_notify') && !empty($ticket['wp_user_id'])) {
            $ticket_user = get_userdata(intval($ticket['wp_user_id']));
            if ($ticket_user) {
                $admin_user = wp_get_current_user();
                cpp_notify('ticket_reply', array(
                    'client_name'  => $ticket_user->display_name ?: $ticket_user->user_login,
                    'client_email' => $ticket_user->user_email,
                    'subject'      => $ticket['subject'] ?? 'Support Ticket',
                    'sender_name'  => $admin_user->display_name ?: 'Support Team',
                    'message'      => wp_strip_all_tags($message),
                ));
            }
        }
        wp_send_json_success(array('message' => 'Ticket updated'));
    }
    else {
        wp_send_json_error(array('message' => 'No changes made to update'));
    }
}

add_action('wp_ajax_cpp_delete_ticket', 'cpp_ajax_delete_ticket');

function cpp_ajax_delete_ticket()
{
    cpp_require_delete_permission();
    if (!current_user_can('cpp_manage_portal'))
        wp_send_json_error(array('message' => 'Permission denied'));

    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

    if (!$ticket_id)
        wp_send_json_error(array('message' => 'Missing ticket id'));

    if (!wp_verify_nonce($nonce, 'cpp_delete_ticket_' . $ticket_id))
        wp_send_json_error(array('message' => 'Nonce failed'));

    if (!class_exists('CPP_Ticket_Manager'))
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Ticket_Manager.php';

    $tm = new CPP_Ticket_Manager();
    $deleted = $tm->delete_ticket($ticket_id);

    if ($deleted === false)
        wp_send_json_error(array('message' => 'Delete failed'));

    wp_send_json_success(array('message' => 'Deleted'));
}

/* ---------- Intake Forms ---------- */
add_action('wp_ajax_cpp_get_form_fields', 'cpp_ajax_get_form_fields');

function cpp_ajax_get_form_fields()
{
    check_ajax_referer('cpp_stats_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in'));
    }

    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    if (!$form_id) {
        wp_send_json_error(array('message' => 'Form ID required'));
    }

    if (!class_exists('CPP_Form_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Form_Manager.php';
    }

    $fm = new CPP_Form_Manager();
    $form = $fm->get($form_id);

    if (!$form) {
        wp_send_json_error(array('message' => 'Form not found'));
    }

    $fields = maybe_unserialize($form['fields']);
    wp_send_json_success(array('fields' => $fields));
}

add_action('wp_ajax_cpp_submit_intake_form', 'cpp_ajax_submit_intake_form');

function cpp_ajax_submit_intake_form()
{
    check_ajax_referer('cpp_stats_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in'));
    }

    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    if (!$form_id) {
        wp_send_json_error(array('message' => 'Form ID required'));
    }

    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }

    if (!class_exists('CPP_Submission_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Submission_Manager.php';
    }

    $user_id = get_current_user_id();
    $cm = new CPP_Client_Manager();
    $sm = new CPP_Submission_Manager();

    $client = $cm->get_by_user_id($user_id);
    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found'));
    }

    // Collect answers (handle arrays for checkboxes)
    $answers = array();
    foreach ($_POST as $key => $value) {
        if ($key !== 'action' && $key !== 'nonce' && $key !== 'form_id') {
            if (is_array($value)) {
                $answers[$key] = array_map('sanitize_text_field', $value);
            }
            else {
                $answers[$key] = sanitize_text_field($value);
            }
        }
    }

    // Save submission
    $submission_id = $sm->create(array(
        'form_id' => $form_id,
        'client_id' => $client['id'],
        'wp_user_id' => $user_id,
        'answers' => $answers,
        'files' => array()
    ));

    if (!$submission_id) {
        wp_send_json_error(array('message' => 'Failed to save submission'));
    }

    // Notify admin
    $admin_email = get_option('admin_email');
    $subject = 'New Form Submission from ' . $client['cpanel_username'];
    $body = "A new form has been submitted.\n\n";
    $body .= "Client: " . $client['cpanel_username'] . "\n";
    $body .= "Form ID: " . $form_id . "\n";
    $body .= "Submission ID: " . $submission_id . "\n\n";
    $body .= "View in admin panel to see details.";

    wp_mail($admin_email, $subject, $body);

    wp_send_json_success(array(
        'message' => 'Form submitted successfully',
        'submission_id' => $submission_id
    ));
}

// ---------- Submission Status Update ----------
add_action('wp_ajax_cpp_set_submission_status', 'cpp_ajax_set_submission_status');

function cpp_ajax_set_submission_status()
{
    if (!current_user_can('cpp_manage_portal')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

    if (!$submission_id || !$status) {
        wp_send_json_error(array('message' => 'Missing parameters'));
    }

    if (!wp_verify_nonce($nonce, 'cpp_set_submission_status')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }

    // Accept both old and new status labels
    $allowed = array('unread', 'read', 'done', 'pending', 'in_progress', 'completed');
    if (!in_array($status, $allowed)) {
        wp_send_json_error(array('message' => 'Invalid status'));
    }

    // Get current statuses
    $statuses = get_option('cpp_submission_statuses', array());
    $statuses[$submission_id] = $status;
    update_option('cpp_submission_statuses', $statuses);

    wp_send_json_success(array('message' => 'Status updated successfully'));
}

// ---------- Submission Delete ----------
add_action('wp_ajax_cpp_delete_submission', 'cpp_ajax_delete_submission');

function cpp_ajax_delete_submission()
{
    cpp_require_delete_permission();
    if (!current_user_can('cpp_manage_portal')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

    if (!$submission_id) {
        wp_send_json_error(array('message' => 'Missing submission ID'));
    }

    if (!wp_verify_nonce($nonce, 'cpp_delete_submission_' . $submission_id)) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }

    if (!class_exists('CPP_Submission_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Submission_Manager.php';
    }

    $sm = new CPP_Submission_Manager();
    $result = $sm->delete($submission_id);

    if ($result === false) {
        wp_send_json_error(array('message' => 'Failed to delete submission'));
    }

    // Also clean up the status entry
    $statuses = get_option('cpp_submission_statuses', array());
    unset($statuses[$submission_id]);
    update_option('cpp_submission_statuses', $statuses);

    wp_send_json_success(array('message' => 'Submission deleted'));
}

/* ---------- Admin: Process Upgrade Request (Approve/Reject) ---------- */
add_action('wp_ajax_cpp_admin_process_upgrade_request', 'cpp_ajax_admin_process_upgrade_request');

function cpp_ajax_admin_process_upgrade_request()
{
    // Security check
    if (!current_user_can('cpp_manage_portal')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'cpp_upgrade_requests_admin')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }

    $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : '';

    if (!$request_id || !in_array($mode, array('approve', 'reject'))) {
        wp_send_json_error(array('message' => 'Invalid parameters'));
    }

    // Get all requests
    $requests = get_option('cpp_upgrade_requests', array());
    $found = false;
    $target_request = null;

    foreach ($requests as $key => $req) {
        if ($req['id'] === $request_id) {
            $found = true;
            $target_request = $req;

            if ($mode === 'approve') {
                // Update client's plan
                if (!class_exists('CPP_Client_Manager')) {
                    require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
                }

                $cm = new CPP_Client_Manager();
                $client_id = intval($req['client_id']);
                $new_plan = $req['plan'];

                // Only update plan
                $updated = $cm->update($client_id, array('plan' => $new_plan));

                if ($updated === false) {
                    wp_send_json_error(array('message' => 'Failed to update client plan'));
                }

                // Update request status
                $requests[$key]['status'] = 'approved';
                $requests[$key]['processed_at'] = current_time('mysql');

            }
            else if ($mode === 'reject') {
                // Update request status
                $requests[$key]['status'] = 'rejected';
                $requests[$key]['processed_at'] = current_time('mysql');
            }

            break;
        }
    }

    if (!$found) {
        wp_send_json_error(array('message' => 'Request not found'));
    }

    // Save updated requests
    update_option('cpp_upgrade_requests', $requests);

    // Send success response
    wp_send_json_success(array(
        'message' => $mode === 'approve' ? 'Request approved successfully' : 'Request rejected',
        'mode' => $mode
    ));
}

/* ---------- Messages: Mark as Read ---------- */
add_action('wp_ajax_cpp_mark_message_read', 'cpp_ajax_mark_message_read');

function cpp_ajax_mark_message_read()
{
    check_ajax_referer('cpp_mark_message_read', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in'));
    }

    $message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
    if (!$message_id) {
        wp_send_json_error(array('message' => 'Invalid message ID'));
    }

    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }
    if (!class_exists('CPP_Message_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Message_Manager.php';
    }

    $cm = new CPP_Client_Manager();
    $user_id = get_current_user_id();
    $client = $cm->get_by_user_id($user_id);

    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found'));
    }

    $mm = new CPP_Message_Manager();
    $result = $mm->mark_as_read($message_id, $client['id']);

    if ($result) {
        wp_send_json_success(array('message' => 'Message marked as read'));
    }
    else {
        wp_send_json_error(array('message' => 'Failed to mark message as read'));
    }
}

add_action('wp_ajax_cpp_get_client_products', 'cpp_ajax_get_client_products');
add_action('wp_ajax_cpp_create_client_product', 'cpp_ajax_create_client_product');
add_action('wp_ajax_cpp_update_client_product', 'cpp_ajax_update_client_product');
add_action('wp_ajax_cpp_delete_client_product', 'cpp_ajax_delete_client_product');
add_action('wp_ajax_cpp_get_client_members', 'cpp_ajax_get_client_members');
add_action('wp_ajax_cpp_create_client_member', 'cpp_ajax_create_client_member');
add_action('wp_ajax_cpp_update_client_member', 'cpp_ajax_update_client_member');
add_action('wp_ajax_cpp_delete_client_member', 'cpp_ajax_delete_client_member');

function cpp_require_ecommerce_access()
{
    check_ajax_referer('cpp_ecommerce_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in'), 401);
    }
    $user_id = get_current_user_id();

    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id($user_id);

    // Check both user meta and client table for fallback
    $plan_meta = get_user_meta($user_id, 'cpp_membership_plan', true);
    $plan_db = !empty($client['plan']) ? $client['plan'] : '';

    // Plans at ecommerce tier or above include ecommerce features
    $ecom_tier_plans = array('ecommerce', 'briefsite', 'membership', 'briefcase', 'subscription', 'briefsync');
    $has_ecom = in_array($plan_db, $ecom_tier_plans)
        || in_array($plan_meta, $ecom_tier_plans)
        || $plan_meta === 'Level 2 - E-commerce';

    if (!$has_ecom) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }
    return $user_id;
}

function cpp_ajax_get_client_products()
{
    $user_id = cpp_require_ecommerce_access();
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_products';
    $query = $wpdb->prepare(
        "SELECT id, product_name, price, description, category, created_at FROM {$table} WHERE client_id = %d ORDER BY created_at DESC",
        $user_id
    );
    $rows = $wpdb->get_results($query, ARRAY_A);

    wp_send_json_success(array('items' => $rows));
}

function cpp_ajax_create_client_product()
{
    $user_id = cpp_require_ecommerce_access();
    $product_name = isset($_POST['product_name']) ? sanitize_text_field(wp_unslash($_POST['product_name'])) : '';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
    $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
    if (!$product_name) {
        wp_send_json_error(array('message' => 'Product name is required'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_products';
    $created_at = current_time('mysql');
    $result = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (client_id, product_name, price, description, category, created_at) VALUES (%d, %s, %f, %s, %s, %s)",
        $user_id,
        $product_name,
        $price,
        $description,
        $category,
        $created_at
    ));
    if (!$result) {
        wp_send_json_error(array('message' => 'Failed to create product'));
    }
    $id = $wpdb->insert_id;
    wp_send_json_success(array(
        'item' => array(
            'id' => $id,
            'product_name' => $product_name,
            'price' => $price,
            'description' => $description,
            'category' => $category,
            'created_at' => $created_at
        )
    ));
}

function cpp_ajax_update_client_product()
{
    $user_id = cpp_require_ecommerce_access();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $product_name = isset($_POST['product_name']) ? sanitize_text_field(wp_unslash($_POST['product_name'])) : '';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
    $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
    if (!$id || !$product_name) {
        wp_send_json_error(array('message' => 'Invalid product'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_products';
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET product_name = %s, price = %f, description = %s, category = %s WHERE id = %d AND client_id = %d",
        $product_name,
        $price,
        $description,
        $category,
        $id,
        $user_id
    ));
    if ($result === false) {
        wp_send_json_error(array('message' => 'Failed to update product'));
    }
    wp_send_json_success(array('message' => 'Product updated'));
}

function cpp_ajax_delete_client_product()
{
    cpp_require_delete_permission();
    check_ajax_referer('cpp_ecommerce_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in'), 401);
    }
    $user_id = get_current_user_id();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(array('message' => 'Invalid product'));
    }
    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id($user_id);
    $plan_meta = get_user_meta($user_id, 'cpp_membership_plan', true);
    $plan_db = !empty($client['plan']) ? $client['plan'] : '';
    $ecom_tier_plans = array('ecommerce', 'briefsite', 'membership', 'briefcase', 'subscription', 'briefsync');
    $has_ecom = in_array($plan_db, $ecom_tier_plans) || in_array($plan_meta, $ecom_tier_plans) || $plan_meta === 'Level 2 - E-commerce';
    if (!$has_ecom) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_products';
    // Try client_id = user_id (used by create); if 0 rows and we have client record, try client_id = client.id
    $client_id_use = $user_id;
    $result = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE id = %d AND client_id = %d",
        $id,
        $client_id_use
    ));
    if ($result === 0 && $client && !empty($client['id'])) {
        $client_id_use = (int) $client['id'];
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE id = %d AND client_id = %d",
            $id,
            $client_id_use
        ));
    }
    if ($result === false) {
        $err = $wpdb->last_error ? $wpdb->last_error : 'Database error';
        wp_send_json_error(array('message' => 'Failed to delete product: ' . $err));
    }
    if ($result === 0) {
        wp_send_json_error(array('message' => 'Product not found or already deleted'));
    }
    wp_send_json_success(array('message' => 'Product deleted'));
}

function cpp_ajax_get_client_members()
{
    $user_id = cpp_require_ecommerce_access();
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_members';

    $query = $wpdb->prepare(
        "SELECT id, member_name, email_address, role_position, created_at FROM {$table} WHERE client_id = %d ORDER BY created_at DESC",
        $user_id
    );
    $rows = $wpdb->get_results($query, ARRAY_A);

    wp_send_json_success(array('items' => $rows));
}

function cpp_ajax_create_client_member()
{
    $user_id = cpp_require_ecommerce_access();
    $member_name = isset($_POST['member_name']) ? sanitize_text_field(wp_unslash($_POST['member_name'])) : '';
    $email_address = isset($_POST['email_address']) ? sanitize_email(wp_unslash($_POST['email_address'])) : '';
    $role_position = isset($_POST['role_position']) ? sanitize_text_field(wp_unslash($_POST['role_position'])) : '';
    if (!$member_name || !$email_address) {
        wp_send_json_error(array('message' => 'Name and email are required'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_members';
    $created_at = current_time('mysql');
    $result = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (client_id, member_name, email_address, role_position, created_at) VALUES (%d, %s, %s, %s, %s)",
        $user_id,
        $member_name,
        $email_address,
        $role_position,
        $created_at
    ));
    if (!$result) {
        wp_send_json_error(array('message' => 'Failed to create member'));
    }
    $id = $wpdb->insert_id;
    wp_send_json_success(array(
        'item' => array(
            'id' => $id,
            'member_name' => $member_name,
            'email_address' => $email_address,
            'role_position' => $role_position,
            'created_at' => $created_at
        )
    ));
}

function cpp_ajax_update_client_member()
{
    $user_id = cpp_require_ecommerce_access();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $member_name = isset($_POST['member_name']) ? sanitize_text_field(wp_unslash($_POST['member_name'])) : '';
    $email_address = isset($_POST['email_address']) ? sanitize_email(wp_unslash($_POST['email_address'])) : '';
    $role_position = isset($_POST['role_position']) ? sanitize_text_field(wp_unslash($_POST['role_position'])) : '';
    if (!$id || !$member_name || !$email_address) {
        wp_send_json_error(array('message' => 'Invalid member'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_members';
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET member_name = %s, email_address = %s, role_position = %s WHERE id = %d AND client_id = %d",
        $member_name,
        $email_address,
        $role_position,
        $id,
        $user_id
    ));
    if ($result === false) {
        wp_send_json_error(array('message' => 'Failed to update member'));
    }
    wp_send_json_success(array('message' => 'Member updated'));
}

function cpp_ajax_delete_client_member()
{
    cpp_require_delete_permission();
    $user_id = cpp_require_ecommerce_access();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error(array('message' => 'Invalid member'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_members';
    // Use DELETE so it works even if the table has no status column
    $result = $wpdb->delete($table, array('id' => $id, 'client_id' => $user_id), array('%d', '%d'));
    if ($result === false) {
        wp_send_json_error(array('message' => 'Failed to delete member'));
    }
    if ($result === 0) {
        wp_send_json_error(array('message' => 'Member not found or already deleted'));
    }
    wp_send_json_success(array('message' => 'Member deleted'));
}

// ---------- Client Portal Custom Login ----------
add_action('wp_ajax_nopriv_cpp_ajax_login', 'cpp_ajax_login');
add_action('wp_ajax_cpp_ajax_login', 'cpp_ajax_login');

function cpp_ajax_login()
{
    check_ajax_referer('cpp_ajax_login_nonce', 'cpp_login_nonce');

    $username = isset($_POST['username']) ? sanitize_user($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = isset($_POST['remember']) ? true : false;

    if (empty($username) || empty($password)) {
        wp_send_json_error(array('message' => 'Username and password are required.'));
    }

    $user = wp_authenticate($username, $password);

    if (is_wp_error($user)) {
        wp_send_json_error(array('message' => 'Invalid username or password.'));
    }

    // Check if the user is a Client Portal user
    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }

    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id($user->ID);

    if (!$client) {
        wp_send_json_error(array('message' => 'Your account is not linked to a Client Portal profile.'));
    }

    // Log the user in
    wp_set_current_user($user->ID, $user->user_login);
    wp_set_auth_cookie($user->ID, $remember);
    do_action('wp_login', $user->user_login, $user);

    wp_send_json_success(array('message' => 'Login successful.'));
}

// ---------- Pricing Page Signup ----------
add_action('wp_ajax_nopriv_cpp_pricing_signup', 'cpp_pricing_signup_handler');
add_action('wp_ajax_cpp_pricing_signup', 'cpp_pricing_signup_handler');

function cpp_pricing_signup_handler() {
    check_ajax_referer('cpp_pricing_signup_nonce', 'nonce');

    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
    $email      = sanitize_email($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $plan_key   = sanitize_key($_POST['plan'] ?? '');
    $action     = sanitize_key($_POST['signup_action'] ?? 'trial');
    $billing    = sanitize_key($_POST['billing'] ?? 'monthly');
    $domain     = sanitize_text_field($_POST['domain'] ?? '');

    if (empty($first_name) || empty($email) || empty($password)) {
        wp_send_json_error(array('message' => 'Please fill in all required fields.'));
    }
    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'Please enter a valid email address.'));
    }
    if (strlen($password) < 6) {
        wp_send_json_error(array('message' => 'Password must be at least 6 characters.'));
    }

    $full_name = trim($first_name . ' ' . $last_name);
    $existing_user_id = email_exists($email);
    $adding_portal = false;

    if ($existing_user_id) {
        if (is_user_logged_in() && get_current_user_id() === $existing_user_id) {
            $adding_portal = true;
            $user_id = $existing_user_id;
        } else {
            wp_send_json_error(array('message' => 'An account with this email already exists. Please sign in first.'));
        }
    }

    if (!$adding_portal) {
        $username = sanitize_user(strtolower(str_replace(' ', '.', $full_name)));
        $base_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => $user_id->get_error_message()));
        }

        wp_update_user(array(
            'ID'           => $user_id,
            'display_name' => $full_name,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'role'         => 'subscriber',
        ));
    }

    // Create client portal profile
    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }
    $cm = new CPP_Client_Manager();

    $portal_label = ucfirst($plan_key) . ' Portal';
    if ($adding_portal) {
        $existing_portals = $cm->get_all_by_user_id($user_id);
        $portal_label = ucfirst($plan_key) . ' Portal #' . (count($existing_portals) + 1);
    }

    $client_data = array(
        'cpanel_username'  => isset($username) ? $username : get_user_by('id', $user_id)->user_login,
        'cpanel_api_token' => '',
        'user_id'          => $user_id,
        'plan'             => $plan_key,
        'label'            => $portal_label,
        'server_host'      => '',
    );
    if (!empty($domain)) {
        $client_data['domain'] = $domain;
    }
    $client_id = $cm->create($client_data);

    if (!$client_id) {
        if (!$adding_portal) wp_delete_user($user_id);
        wp_send_json_error(array('message' => 'Failed to create portal profile.'));
    }

    // Create subscription record
    global $wpdb;
    $sub_table = $wpdb->prefix . 'cpp_portal_subscriptions';
    $plans_table = $wpdb->prefix . 'cpp_portal_subscription_plans';
    $plan = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$plans_table} WHERE LOWER(name) = %s AND status = 'active'", strtolower($plan_key)
    ), ARRAY_A);

    $now = current_time('mysql');
    $is_trial = ($action === 'trial');

    if ($plan) {
        $price = $billing === 'yearly' ? floatval($plan['price_yearly']) : floatval($plan['price_monthly']);
        if ($is_trial) {
            $status = 'trial';
            $next_bill = date('Y-m-d', strtotime('+7 days'));
        } else {
            $status = 'pending_payment';
            $next_bill = $billing === 'yearly' ? date('Y-m-d', strtotime('+1 year')) : date('Y-m-d', strtotime('+1 month'));
        }
        $wpdb->insert($sub_table, array(
            'client_id'         => $client_id,
            'plan_name'         => $plan['name'],
            'price'             => $is_trial ? 0 : $price,
            'billing_cycle'     => $billing,
            'status'            => $status,
            'next_billing_date' => $next_bill,
            'started_at'        => $now,
            'created_at'        => $now,
        ));
        $subscription_id = $wpdb->insert_id;
    }

    // Log user in
    if (!$adding_portal) {
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
    }

    // Set active portal session
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    $_SESSION['cpp_active_client_id'] = $client_id;

    // For paid signups, create WHMCS order and redirect to payment
    if (!$is_trial && class_exists('BC_API') && method_exists('BC_API', 'call')) {
        $payment_url = cpp_create_whmcs_order($user_id, $plan_key, $billing, $price, $first_name, $last_name, $email, $password);
        if ($payment_url && !is_wp_error($payment_url)) {
            wp_send_json_success(array(
                'message'  => 'Redirecting to payment...',
                'redirect' => $payment_url,
            ));
        }
        // If WHMCS order fails, fall through to portal with pending status
        // Admin will see pending_payment and can handle manually
    }

    $portal_url = home_url('/mybriefcase/');
    wp_send_json_success(array(
        'message'  => $is_trial ? 'Trial started!' : 'Account created! Payment pending.',
        'redirect' => $portal_url,
    ));
}

/**
 * Create WHMCS client + order and return payment URL
 */
function cpp_create_whmcs_order($user_id, $plan_key, $billing, $price, $first_name, $last_name, $email, $password) {
    // Get WHMCS product ID mapped to this plan
    $product_map = get_option('cpp_whmcs_product_map', array());
    $whmcs_product_id = 0;
    foreach ($product_map as $pid => $mapped_plan) {
        if (strtolower($mapped_plan) === strtolower($plan_key)) {
            $whmcs_product_id = intval($pid);
            break;
        }
    }
    if (!$whmcs_product_id) {
        error_log('BriefSync: No WHMCS product mapped for plan: ' . $plan_key);
        return new WP_Error('no_product', 'No WHMCS product mapped for this plan');
    }

    // Check if user already has a WHMCS client ID
    $whmcs_client_id = get_user_meta($user_id, 'whmcs_client_id', true);

    if (empty($whmcs_client_id)) {
        // Create WHMCS client
        $client_result = BC_API::call('AddClient', array(
            'firstname' => $first_name,
            'lastname'  => $last_name,
            'email'     => $email,
            'password2' => $password,
            'address1'  => 'N/A',
            'city'      => 'N/A',
            'state'     => 'N/A',
            'postcode'  => '00000',
            'country'   => 'US',
            'phonenumber' => '0000000000',
        ));

        if (is_wp_error($client_result)) {
            error_log('BriefSync: WHMCS AddClient failed: ' . $client_result->get_error_message());
            return $client_result;
        }

        if (isset($client_result['clientid'])) {
            $whmcs_client_id = intval($client_result['clientid']);
            update_user_meta($user_id, 'whmcs_client_id', $whmcs_client_id);
        } else {
            error_log('BriefSync: WHMCS AddClient no clientid in response');
            return new WP_Error('whmcs_error', 'Failed to create WHMCS client');
        }
    }

    // Map billing cycle to WHMCS format
    $whmcs_cycle = ($billing === 'yearly') ? 'annually' : 'monthly';

    // Create order in WHMCS
    $order_result = BC_API::call('AddOrder', array(
        'clientid'     => $whmcs_client_id,
        'pid'          => array($whmcs_product_id),
        'billingcycle' => array($whmcs_cycle),
        'paymentmethod' => 'paypal',
    ));

    if (is_wp_error($order_result)) {
        error_log('BriefSync: WHMCS AddOrder failed: ' . $order_result->get_error_message());
        return $order_result;
    }

    // Get invoice ID from order
    $invoice_id = isset($order_result['invoiceid']) ? intval($order_result['invoiceid']) : 0;
    if (!$invoice_id) {
        error_log('BriefSync: WHMCS AddOrder no invoiceid: ' . json_encode($order_result));
        return new WP_Error('no_invoice', 'Order created but no invoice generated');
    }

    // Generate SSO URL to invoice payment page
    $sso_result = BC_API::call('CreateSsoToken', array(
        'client_id'   => $whmcs_client_id,
        'destination' => 'viewinvoice',
        'service_id'  => $invoice_id,
    ));

    if (!is_wp_error($sso_result) && isset($sso_result['redirect_url'])) {
        return $sso_result['redirect_url'];
    }

    // Fallback: redirect to WHMCS client area invoice page
    $whmcs_url = '';
    if (class_exists('BC_Settings')) {
        $whmcs_url = rtrim(BC_Settings::get('whmcs_url'), '/');
    }
    if ($whmcs_url) {
        return $whmcs_url . '/viewinvoice.php?id=' . $invoice_id;
    }

    return new WP_Error('no_payment_url', 'Could not generate payment URL');
}

// ---------- Public Subscription Registration ----------
add_action('wp_ajax_nopriv_bss_public_register', 'bss_public_register');
add_action('wp_ajax_bss_public_register', 'bss_public_register');

function bss_public_register() {
    check_ajax_referer('bss_register_nonce', 'nonce');

    $full_name = sanitize_text_field(wp_unslash($_POST['full_name'] ?? ''));
    $email     = sanitize_email($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $plan_id   = intval($_POST['plan_id'] ?? 0);
    $cycle     = sanitize_key($_POST['billing_cycle'] ?? 'monthly');
    $is_trial  = ($_POST['is_trial'] ?? '0') === '1';
    $domain    = sanitize_text_field($_POST['domain'] ?? '');

    if (empty($full_name) || empty($email) || empty($password)) {
        wp_send_json_error(array('message' => 'All fields are required.'));
    }
    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'Please enter a valid email address.'));
    }
    if (strlen($password) < 6) {
        wp_send_json_error(array('message' => 'Password must be at least 6 characters.'));
    }
    $existing_user_id = email_exists($email);
    $adding_portal = false;

    if ($existing_user_id) {
        // If logged-in user matches, allow adding a new portal
        if (is_user_logged_in() && get_current_user_id() === $existing_user_id) {
            $adding_portal = true;
            $user_id = $existing_user_id;
            $username = get_user_by('id', $user_id)->user_login;
        } else {
            wp_send_json_error(array('message' => 'An account with this email already exists. Please sign in to add a new portal.'));
        }
    }

    if (!$adding_portal && username_exists(sanitize_user($email))) {
        wp_send_json_error(array('message' => 'This username is already taken.'));
    }
    if (!in_array($cycle, array('monthly', 'yearly'))) $cycle = 'monthly';

    // Validate plan
    global $wpdb;
    $plans_table = $wpdb->prefix . 'cpp_portal_subscription_plans';
    $plan = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$plans_table} WHERE id = %d AND status = 'active'", $plan_id
    ), ARRAY_A);
    if (!$plan) {
        wp_send_json_error(array('message' => 'Selected plan is not available.'));
    }

    // Check trial settings from plan's features_json
    $trial_weeks = 0;
    if ($is_trial) {
        $meta = json_decode($plan['features_json'] ?? '{}', true);
        if (is_array($meta) && !empty($meta['__trial_enabled'])) {
            $trial_weeks = intval($meta['__trial_weeks'] ?? 2);
        } else {
            $is_trial = false; // Plan doesn't actually have trial enabled
        }
    }

    if (!$adding_portal) {
        // Create WordPress user (new registration)
        $name_parts = explode(' ', $full_name, 2);
        $first_name = $name_parts[0];
        $last_name  = isset($name_parts[1]) ? $name_parts[1] : '';
        $username   = sanitize_user(strtolower(str_replace(' ', '.', $full_name)));
        $base_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => $user_id->get_error_message()));
        }

        wp_update_user(array(
            'ID'           => $user_id,
            'display_name' => $full_name,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'role'         => 'subscriber',
        ));
    }

    // Create Client Portal profile (new portal for this user)
    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }
    $cm = new CPP_Client_Manager();

    // Auto-generate portal label based on plan name + count
    $portal_label = $plan['name'] . ' Portal';
    if ($adding_portal) {
        $existing_portals = $cm->get_all_by_user_id($user_id);
        $portal_count = count($existing_portals) + 1;
        $portal_label = $plan['name'] . ' Portal #' . $portal_count;
    }

    $client_data = array(
        'cpanel_username'  => $username,
        'cpanel_api_token' => '',
        'user_id'          => $user_id,
        'plan'             => 'website',
        'label'            => $portal_label,
        'server_host'      => '',
    );
    if (!empty($domain)) {
        $client_data['domain'] = $domain;
    }
    $client_id = $cm->create($client_data);

    if (!$client_id) {
        if (!$adding_portal) wp_delete_user($user_id);
        wp_send_json_error(array('message' => 'Failed to create portal profile.'));
    }

    // Create subscription
    $now = current_time('mysql');
    $sub_table = $wpdb->prefix . 'cpp_portal_subscriptions';
    $price = $cycle === 'yearly' ? floatval($plan['price_yearly']) : floatval($plan['price_monthly']);

    if ($is_trial && $trial_weeks > 0) {
        $status = 'trial';
        $next_bill = date('Y-m-d', strtotime('+' . $trial_weeks . ' weeks'));
    } else {
        $status = 'active';
        $next_bill = $cycle === 'yearly' ? date('Y-m-d', strtotime('+1 year')) : date('Y-m-d', strtotime('+1 month'));
    }

    $wpdb->insert($sub_table, array(
        'client_id'         => $client_id,
        'plan_name'         => $plan['name'],
        'price'             => $is_trial ? 0 : $price,
        'billing_cycle'     => $cycle,
        'status'            => $status,
        'next_billing_date' => $next_bill,
        'started_at'        => $now,
        'created_at'        => $now,
    ));

    // Log the user in (or refresh session for portal add)
    if (!$adding_portal) {
        wp_set_current_user($user_id, $username);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', $username, get_user_by('id', $user_id));
    }

    // Auto-switch to the new portal
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    $_SESSION['cpp_active_client_id'] = $client_id;

    if ($adding_portal) {
        $msg = 'New portal created! You are now viewing your ' . $plan['name'] . ' portal.';
    } else {
        $msg = $is_trial
            ? 'Welcome! Your ' . $trial_weeks . '-week free trial of ' . $plan['name'] . ' has started.'
            : 'Welcome! You are now subscribed to ' . $plan['name'] . '.';
    }

    wp_send_json_success(array('message' => $msg));
}

// ---------- Profile Avatar Upload ----------
add_action('wp_ajax_cpp_upload_avatar', 'cpp_ajax_upload_avatar');
function cpp_ajax_upload_avatar()
{
    check_ajax_referer('cpp_upload_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'You must be logged in to do this.'));
    }

    if (empty($_FILES['avatar_file'])) {
        wp_send_json_error(array('message' => 'No file was uploaded.'));
    }

    // Need these files for media_handle_upload
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    // Process the upload
    $attachment_id = media_handle_upload('avatar_file', 0);

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => 'Error uploading file: ' . $attachment_id->get_error_message()));
    }

    // Assign to user meta
    $user_id = get_current_user_id();
    update_user_meta($user_id, 'cpp_custom_avatar_id', $attachment_id);

    // Return the new URL
    $url = wp_get_attachment_image_url($attachment_id, array(64, 64));

    wp_send_json_success(array('url' => $url, 'message' => 'Avatar updated successfully.'));
}

/* ---------- Get Client Uploads ---------- */
add_action('wp_ajax_cpp_get_client_uploads', 'cpp_ajax_get_client_uploads');
add_action('wp_ajax_nopriv_cpp_get_client_uploads', 'cpp_ajax_get_client_uploads');

function cpp_ajax_get_client_uploads()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cpp_upload_nonce')) {
        wp_send_json_error(array('message' => 'Invalid security token'));
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Not logged in'));
    }

    $user_id = get_current_user_id();
    if (!class_exists('CPP_Client_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    }

    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id($user_id);

    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found'));
    }

    if (!class_exists('CPP_Upload_Manager')) {
        require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Upload_Manager.php';
    }

    $fm = new CPP_Upload_Manager();
    $docs = $fm->list_by_client($client['id']);

    // Map necessary URLs securely before handing to JS
    $formatted_docs = array();
    foreach ($docs as $doc) {
        $formatted_docs[] = array(
            'id' => $doc['id'],
            'name' => $doc['original_name'],
            'size' => size_format($doc['size'], 2),
            'mime' => $doc['mime'],
            'date' => human_time_diff(strtotime($doc['created_at']), current_time('timestamp')) . ' ago',
            'is_image' => (strpos($doc['mime'], 'image/') !== false),
            'download_url' => add_query_arg(array(
                'cpp_download' => 1,
                'file_id' => intval($doc['id']),
                'nonce' => wp_create_nonce('cpp_download_' . intval($doc['id']))
            ), home_url('/')),
            'view_url' => add_query_arg(array(
                'cpp_view' => 1,
                'file_id' => intval($doc['id']),
                'nonce' => wp_create_nonce('cpp_view_' . intval($doc['id']))
            ), home_url('/'))
        );
    }

    wp_send_json_success(array('files' => $formatted_docs));
}

// ---------- Dismiss Message ----------
add_action('wp_ajax_cpp_dismiss_message', 'cpp_ajax_dismiss_message');
function cpp_ajax_dismiss_message() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }

    $msg_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
    if (!$msg_id) {
        wp_send_json_error(array('message' => 'Missing ID.'));
    }

    if (!class_exists('CPP_Message_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Message_Manager.php';
    if (!class_exists('CPP_Client_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';

    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id(get_current_user_id());

    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found.'));
    }

    $mm = new CPP_Message_Manager();
    $mm->mark_as_read($msg_id, $client['id']);

    wp_send_json_success(array('message' => 'Message dismissed.'));
}

/* ══════════════════════════════════════════════════════════
   DOMAIN AVAILABILITY CHECKER (public - no login needed)
   ══════════════════════════════════════════════════════════ */
add_action('wp_ajax_cpp_check_domain', 'cpp_ajax_check_domain');
add_action('wp_ajax_nopriv_cpp_check_domain', 'cpp_ajax_check_domain');

function cpp_ajax_check_domain() {
    $domain = isset($_POST['domain']) ? sanitize_text_field(trim($_POST['domain'])) : '';
    if (empty($domain)) {
        wp_send_json_error(array('message' => 'Please enter a domain name.'));
    }

    // Strip protocol and path
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = strtolower(trim($domain));

    // Validate characters
    if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z]{2,})?$/i', $domain)) {
        wp_send_json_error(array('message' => 'Invalid domain name. Use only letters, numbers, and hyphens.'));
    }

    // If user typed just a name (no TLD), we'll check multiple extensions
    $has_tld = (strpos($domain, '.') !== false);
    $tlds = array('.com', '.net', '.org', '.io', '.co', '.dev', '.app', '.xyz', '.online', '.store');

    $results = array();

    if ($has_tld) {
        // Check just the one domain they typed
        $results[] = cpp_check_single_domain($domain);
    } else {
        // Check across popular TLDs
        foreach ($tlds as $tld) {
            $results[] = cpp_check_single_domain($domain . $tld);
        }
    }

    wp_send_json_success(array('domain' => $domain, 'results' => $results));
}

function cpp_check_single_domain($fqdn) {
    $available = true;

    // Method 1: RDAP lookup (authoritative for most TLDs)
    $tld = substr($fqdn, strrpos($fqdn, '.') + 1);
    $rdap_servers = array(
        'com' => 'https://rdap.verisign.com/com/v1/domain/',
        'net' => 'https://rdap.verisign.com/net/v1/domain/',
        'org' => 'https://rdap.org.foundation/v1/domain/',
        'io'  => 'https://rdap.nic.io/domain/',
        'co'  => 'https://rdap.nic.co/domain/',
        'dev' => 'https://rdap.nic.google/domain/',
        'app' => 'https://rdap.nic.google/domain/',
    );

    if (isset($rdap_servers[$tld])) {
        $url = $rdap_servers[$tld] . urlencode($fqdn);
        $response = wp_remote_get($url, array('timeout' => 5, 'sslverify' => false));
        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $available = false; // Domain found = taken
            }
            // 404 = not found = available
        }
    } else {
        // Fallback: DNS check for TLDs without known RDAP
        if (checkdnsrr($fqdn, 'ANY') || checkdnsrr($fqdn, 'A') || checkdnsrr($fqdn, 'NS')) {
            $available = false;
        }
    }

    return array(
        'domain'    => $fqdn,
        'available' => $available,
        'tld'       => '.' . $tld,
    );
}

// ---------- CRM / Project Management Tasks (Kanban) ----------
if (!defined('CPP_CRM_STATUSES')) {
    define('CPP_CRM_STATUSES', array('todo', 'in_progress', 'review', 'done'));
}

function cpp_ajax_get_crm_tasks() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    if (!class_exists('CPP_Client_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id(get_current_user_id());
    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found.'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_crm_tasks';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table) . "'") !== $table) {
        wp_send_json_success(array('tasks' => array(), 'count' => 0));
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, client_id, title, status, amount, created_at, updated_at FROM {$table} WHERE client_id = %d ORDER BY updated_at DESC",
        $client['id']
    ), ARRAY_A);
    $tasks = array();
    foreach ($rows as $r) {
        $tasks[] = array(
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'status' => $r['status'],
            'amount' => $r['amount'] !== null ? floatval($r['amount']) : null,
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        );
    }
    wp_send_json_success(array('tasks' => $tasks, 'count' => count($tasks)));
}

function cpp_ajax_add_crm_task() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    check_ajax_referer('cpp_crm_nonce', 'nonce');
    if (!class_exists('CPP_Client_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id(get_current_user_id());
    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found.'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_crm_tasks';
    if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table) . "'") !== $table) {
        wp_send_json_error(array('message' => 'CRM not available.'));
    }
    $limit = 999;
    if (defined('CPP_FREEMIUM_ENABLED') && CPP_FREEMIUM_ENABLED && defined('CPP_FREEMIUM_TASK_LIMIT')) {
        $limit = (int) CPP_FREEMIUM_TASK_LIMIT;
    }
    $current = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE client_id = %d", $client['id']));
    if ($current >= $limit) {
        wp_send_json_error(array('message' => 'Task limit reached. Upgrade to add more.', 'upgrade' => true));
    }
    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    if (strlen($title) < 1) {
        wp_send_json_error(array('message' => 'Title is required.'));
    }
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'todo';
    if (!in_array($status, CPP_CRM_STATUSES, true)) {
        $status = 'todo';
    }
    $amount = null;
    if (isset($_POST['amount']) && $_POST['amount'] !== '') {
        $amount = floatval($_POST['amount']);
    }
    $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
    $wpdb->insert($table, array(
        'client_id' => $client['id'],
        'title' => $title,
        'description' => $description ?: null,
        'status' => $status,
        'amount' => $amount,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ), array('%d', '%s', '%s', '%s', '%f', '%s', '%s'));
    if ($wpdb->last_error) {
        wp_send_json_error(array('message' => 'Failed to create task.'));
    }
    $id = (int) $wpdb->insert_id;
    wp_send_json_success(array(
        'id' => $id,
        'title' => $title,
        'description' => $description,
        'status' => $status,
        'amount' => $amount,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ));
}

function cpp_ajax_update_crm_task_status() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    check_ajax_referer('cpp_crm_nonce', 'nonce');
    if (!class_exists('CPP_Client_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id(get_current_user_id());
    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found.'));
    }
    $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    if (!$task_id || !in_array($status, CPP_CRM_STATUSES, true)) {
        wp_send_json_error(array('message' => 'Invalid task or status.'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_crm_tasks';
    $updated = $wpdb->update($table,
        array('status' => $status, 'updated_at' => current_time('mysql')),
        array('id' => $task_id, 'client_id' => $client['id']),
        array('%s', '%s'),
        array('%d', '%d')
    );
    if ($updated === false) {
        wp_send_json_error(array('message' => 'Failed to update.'));
    }
    wp_send_json_success(array('task_id' => $task_id, 'status' => $status));
}

function cpp_ajax_update_crm_task() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    check_ajax_referer('cpp_crm_nonce', 'nonce');
    if (!class_exists('CPP_Client_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id(get_current_user_id());
    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found.'));
    }
    $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
    if (!$task_id) {
        wp_send_json_error(array('message' => 'Invalid task.'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_crm_tasks';

    // Verify ownership
    $owner = $wpdb->get_var($wpdb->prepare("SELECT client_id FROM {$table} WHERE id = %d", $task_id));
    if ((int) $owner !== (int) $client['id']) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }

    $update = array('updated_at' => current_time('mysql'));
    $formats = array('%s');

    if (isset($_POST['title'])) {
        $title = sanitize_text_field(wp_unslash($_POST['title']));
        if (strlen($title) < 1) {
            wp_send_json_error(array('message' => 'Title is required.'));
        }
        $update['title'] = $title;
        $formats[] = '%s';
    }
    if (isset($_POST['description'])) {
        $update['description'] = sanitize_textarea_field(wp_unslash($_POST['description']));
        $formats[] = '%s';
    }
    if (isset($_POST['status'])) {
        $status = sanitize_text_field($_POST['status']);
        if (in_array($status, CPP_CRM_STATUSES, true)) {
            $update['status'] = $status;
            $formats[] = '%s';
        }
    }
    if (isset($_POST['amount'])) {
        $update['amount'] = $_POST['amount'] !== '' ? floatval($_POST['amount']) : null;
        $formats[] = '%f';
    }

    $wpdb->update($table, $update, array('id' => $task_id), $formats, array('%d'));
    if ($wpdb->last_error) {
        wp_send_json_error(array('message' => 'Failed to update task.'));
    }
    wp_send_json_success(array('task_id' => $task_id));
}

function cpp_ajax_delete_crm_task() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    check_ajax_referer('cpp_crm_nonce', 'nonce');
    if (!class_exists('CPP_Client_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id(get_current_user_id());
    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found.'));
    }
    $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
    if (!$task_id) {
        wp_send_json_error(array('message' => 'Invalid task.'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_crm_tasks';
    $deleted = $wpdb->delete($table, array('id' => $task_id, 'client_id' => $client['id']), array('%d', '%d'));
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Failed to delete task.'));
    }
    wp_send_json_success(array('task_id' => $task_id));
}

add_action('wp_ajax_cpp_get_crm_tasks', 'cpp_ajax_get_crm_tasks');
add_action('wp_ajax_cpp_add_crm_task', 'cpp_ajax_add_crm_task');
add_action('wp_ajax_cpp_update_crm_task_status', 'cpp_ajax_update_crm_task_status');
add_action('wp_ajax_cpp_update_crm_task', 'cpp_ajax_update_crm_task');
add_action('wp_ajax_cpp_delete_crm_task', 'cpp_ajax_delete_crm_task');

/* CRM: add product (no ecommerce plan required, uses cpp_crm_nonce) */
function cpp_ajax_add_crm_product() {
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in.'));
    }
    check_ajax_referer('cpp_crm_nonce', 'nonce');
    if (!class_exists('CPP_Client_Manager')) require_once CPP_PLUGIN_DIR . 'includes/classes/CPP_Client_Manager.php';
    $cm = new CPP_Client_Manager();
    $client = $cm->get_by_user_id(get_current_user_id());
    if (!$client) {
        wp_send_json_error(array('message' => 'Client profile not found.'));
    }
    $product_name = isset($_POST['product_name']) ? sanitize_text_field(wp_unslash($_POST['product_name'])) : '';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
    if (!$product_name) {
        wp_send_json_error(array('message' => 'Product name required.'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'cpp_client_products';
    $ok = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (client_id, product_name, price, description, category, created_at) VALUES (%d, %s, %f, %s, %s, %s)",
        $client['id'], $product_name, $price, '', $category, current_time('mysql')
    ));
    if ($ok === false) {
        wp_send_json_error(array('message' => 'Failed to create product.'));
    }
    wp_send_json_success(array('id' => $wpdb->insert_id));
}
add_action('wp_ajax_cpp_add_crm_product', 'cpp_ajax_add_crm_product');
