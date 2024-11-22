<?php
// Activation function
function bulk_redirector_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bulk_redirects';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    
    if (!$table_exists) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            from_url varchar(255) NOT NULL,
            to_url varchar(255) NOT NULL,
            redirect_type varchar(3) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_redirect (from_url)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Check/create default settings
    $existing_settings = get_option('bulk_redirector_settings');
    if ($existing_settings === false) {
        $default_settings = array(
            'redirect_type' => '301'
        );
        add_option('bulk_redirector_settings', $default_settings);
    }
}

// Deactivation function
function bulk_redirector_deactivate() {
    // Deactivation code here
}

// Redirect handling - Modify this function
function bulk_redirector_handle_redirect() {
    // Don't redirect in admin or during AJAX
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    global $wpdb;
    
    // Get current full URL including query string
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $current_url = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    
    // Try exact match first
    $table_name = $wpdb->prefix . 'bulk_redirects';
    $redirect = $wpdb->get_row($wpdb->prepare(
        "SELECT to_url, redirect_type FROM $table_name WHERE from_url = %s LIMIT 1",
        $current_url
    ));

    // If no exact match, try with/without trailing slash
    if (!$redirect) {
        $alt_url = rtrim($current_url, '/');
        if ($alt_url === $current_url) {
            $alt_url .= '/';
        }
        
        $redirect = $wpdb->get_row($wpdb->prepare(
            "SELECT to_url, redirect_type FROM $table_name WHERE from_url = %s LIMIT 1",
            $alt_url
        ));
    }

    if ($redirect && $redirect->to_url !== $current_url) {
        // Ensure to_url is a valid URL
        $to_url = filter_var($redirect->to_url, FILTER_VALIDATE_URL) ? $redirect->to_url : home_url($redirect->to_url);
        wp_redirect($to_url, $redirect->redirect_type);
        exit;
    }
}

// Move this earlier in the hook order and add it to both template_redirect and init
add_action('template_redirect', 'bulk_redirector_handle_redirect', 1);
add_action('init', 'bulk_redirector_handle_redirect', 1);

// Helper function for checking circular redirects
function bulk_redirector_is_circular($from_url, $to_url) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bulk_redirects';
    
    $checked_urls = array($from_url);
    $current_url = $to_url;

    while ($current_url) {
        if (in_array($current_url, $checked_urls)) {
            return true; // Circular redirect detected
        }

        $next_url = $wpdb->get_var($wpdb->prepare(
            "SELECT to_url FROM $table_name WHERE from_url = %s",
            $current_url
        ));

        if (!$next_url) break;

        $checked_urls[] = $current_url;
        $current_url = $next_url;
    }

    return false;
}

// Update URL validation function
function bulk_redirector_validate_redirect($from_url, $to_url, $exclude_id = null) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bulk_redirects';
    $errors = array();

    // Ensure URLs start with http:// or https://
    if (!preg_match('~^(?:f|ht)tps?://~i', $from_url)) {
        $from_url = 'https://' . ltrim($from_url, '/');
    }
    if (!preg_match('~^(?:f|ht)tps?://~i', $to_url)) {
        $to_url = 'https://' . ltrim($to_url, '/');
    }

    // Check for safe URLs
    if (!bulk_redirector_is_safe_url($from_url)) {
        $errors[] = __('The "From URL" contains potentially unsafe patterns or admin paths.', 'bulk-redirector');
    }

    if (!bulk_redirector_is_safe_url($to_url)) {
        $errors[] = __('The "To URL" contains potentially unsafe patterns or admin paths.', 'bulk-redirector');
    }

    // Check for duplicate 'from_url'
    $where = $exclude_id ? " AND id != %d" : "";
    $query = $wpdb->prepare(
        "SELECT id FROM $table_name WHERE from_url = %s" . $where,
        array_merge([$from_url], $exclude_id ? [$exclude_id] : [])
    );
    
    if ($wpdb->get_var($query)) {
        $errors[] = __('This "From URL" already exists in redirects.', 'bulk-redirector');
    }

    // Check if 'to_url' matches any existing 'from_url'
    $query = $wpdb->prepare(
        "SELECT from_url FROM $table_name WHERE from_url = %s",
        $to_url
    );
    if ($wpdb->get_var($query)) {
        $errors[] = __('The "To URL" matches an existing "From URL", which would create a redirect chain.', 'bulk-redirector');
    }

    // Check for self-redirect
    if ($from_url === $to_url) {
        $errors[] = __('Cannot redirect a URL to itself.', 'bulk-redirector');
    }

    // Check for circular redirects
    if (bulk_redirector_is_circular($from_url, $to_url)) {
        $errors[] = __('This would create a circular redirect.', 'bulk-redirector');
    }

    return $errors;
}

function bulk_redirector_is_safe_url($url) {
    // Parse the URL
    $parsed = parse_url($url);
    
    // Only check path component for unsafe patterns
    $path = isset($parsed['path']) ? $parsed['path'] : '';

    // Check for wp-admin paths
    if (strpos($path, '/wp-admin') !== false || strpos($path, '/admin') !== false) {
        return false;
    }

    // Check for WordPress login/register pages
    $unsafe_paths = array(
        'wp-login.php',
        'wp-register.php',
        'wp-admin',
        'admin',
        'login',
        'xmlrpc.php',
        'wp-cron.php'
    );

    foreach ($unsafe_paths as $unsafe_path) {
        if (strpos($path, $unsafe_path) !== false) {
            return false;
        }
    }

    // Check for common exploit patterns
    $unsafe_patterns = array(
        '../',       // Directory traversal
        'javascript:', // JavaScript protocol
        'data:',      // Data protocol
        '<script',    // Script tags
        '%3C',        // URL encoded <
        '%3E',        // URL encoded >
        '&&',         // Command injection
        '||',         // Command injection
        ';',          // Command injection
        '${',         // Template injection
        '#{',         // Template injection
    );

    foreach ($unsafe_patterns as $pattern) {
        if (strpos($path, $pattern) !== false) {
            return false;
        }
    }

    return true;
}

// Other helper functions...