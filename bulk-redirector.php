<?php
/*
Plugin Name: Bulk Redirector
Plugin URI: 
Description: A plugin to manage redirections in bulk using CSV files
Version: 1.0.0
Author: Md Mohaimenul Islam Joy
Author URI: 
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: bulk-redirector
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BULK_REDIRECTOR_VERSION', '1.0.0');
define('BULK_REDIRECTOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BULK_REDIRECTOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include only the core functions file here
require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/functions.php';

// Initialize plugin
function bulk_redirector_init() {
    if (is_admin()) {
        require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/class-bulk-redirects-list-table.php';
        require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/class-bulk-redirector-csv-processor.php';
        require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/class-bulk-redirector-admin.php';
        
        $admin = new Bulk_Redirector_Admin();
        $admin->init();
    }
}

// Hooks
add_action('init', 'bulk_redirector_init');
register_activation_hook(__FILE__, 'bulk_redirector_activate');
register_deactivation_hook(__FILE__, 'bulk_redirector_deactivate');

// Move the redirect hook to init for earlier execution
remove_action('template_redirect', 'bulk_redirector_handle_redirect');
add_action('init', 'bulk_redirector_handle_redirect', 1); // Priority 1 to run early

// That's all for the main file - everything else should be in includes/