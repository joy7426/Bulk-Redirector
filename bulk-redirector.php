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

// Include required files
require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/class-bulk-redirects-list-table.php';
require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/class-bulk-redirector-admin.php';
require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/class-bulk-redirector-csv-processor.php';
require_once BULK_REDIRECTOR_PLUGIN_DIR . 'includes/functions.php';

// Initialize plugin
add_action('plugins_loaded', 'bulk_redirector_init');

function bulk_redirector_init() {
    // Initialize admin
    if (is_admin()) {
        new Bulk_Redirector_Admin();
    }
}

// Activation Hook
register_activation_hook(__FILE__, 'bulk_redirector_activate');

function bulk_redirector_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'bulk_redirects';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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

    // Default settings
    $default_settings = array(
        'redirect_type' => '301'
    );
    add_option('bulk_redirector_settings', $default_settings);
}

// Deactivation Hook
register_deactivation_hook(__FILE__, 'bulk_redirector_deactivate');

function bulk_redirector_deactivate() {
    // Deactivation code here
}

// Add Settings Menu
add_action('admin_menu', 'bulk_redirector_add_admin_menu');
add_action('admin_init', 'bulk_redirector_settings_init');

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Bulk_Redirects_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'redirect',
            'plural'   => 'redirects',
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />', // Add checkbox column
            'from_url'      => __('From URL', 'bulk-redirector'),
            'to_url'        => __('To URL', 'bulk-redirector'),
            'redirect_type' => __('Type', 'bulk-redirector'),
            'created_at'    => __('Created', 'bulk-redirector'),
            'actions'       => __('Actions', 'bulk-redirector')
        ];
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="redirects[]" value="%s" />', $item->id);
    }

    public function column_from_url($item) {
        $actions = [
            'edit' => sprintf(
                '<a href="?page=%s&action=edit&redirect=%s">Edit</a>',
                $_REQUEST['page'],
                $item->id
            ),
            'delete' => sprintf(
                '<a href="?page=%s&action=delete&redirect=%s" onclick="return confirm(\'Are you sure?\')">Delete</a>',
                $_REQUEST['page'],
                $item->id
            )
        ];

        return sprintf('%1$s %2$s', $item->from_url, $this->row_actions($actions));
    }

    public function column_actions($item) {
        return sprintf(
            '<a href="?page=%s&action=edit&redirect=%s" class="button button-small">%s</a> ',
            $_REQUEST['page'],
            $item->id,
            __('Edit', 'bulk-redirector')
        );
    }

    public function get_sortable_columns() {
        return [
            'from_url'      => ['from_url', true],
            'to_url'        => ['to_url', false],
            'redirect_type' => ['redirect_type', false],
            'created_at'    => ['created_at', false]
        ];
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects';
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name");

        $orderby = isset($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'created_at';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        
        $search = isset($_POST['s']) ? sanitize_text_field($_POST['s']) : '';
        $where = '';
        if (!empty($search)) {
            $where = $wpdb->prepare(
                " WHERE from_url LIKE %s OR to_url LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name $where ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $per_page,
                ($current_page - 1) * $per_page
            )
        );

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'from_url':
            case 'to_url':
            case 'redirect_type':
                return esc_html($item->$column_name);
            case 'created_at':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at));
            default:
                return print_r($item, true);
        }
    }

    public function get_bulk_actions() {
        return [
            'delete' => 'Delete'
        ];
    }
}

function bulk_redirector_add_admin_menu() {
    $parent_slug = 'bulk_redirector';
    $capability = 'manage_options';

    add_menu_page(
        'Bulk Redirector',
        'Bulk Redirector',
        $capability,
        $parent_slug,
        'bulk_redirector_options_page',
        'dashicons-randomize'
    );

    add_submenu_page(
        $parent_slug,
        __('Settings', 'bulk-redirector'),
        __('Settings', 'bulk-redirector'),
        $capability,
        $parent_slug
    );

    add_submenu_page(
        $parent_slug,
        __('Redirects List', 'bulk-redirector'),
        __('Redirects List', 'bulk-redirector'),
        $capability,
        'bulk-redirector-list',
        'bulk_redirector_list_page'
    );
}

function bulk_redirector_list_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bulk_redirects';

    // Handle bulk actions
    if (isset($_POST['redirects']) && isset($_POST['action']) && $_POST['action'] === 'delete') {
        $ids = array_map('intval', $_POST['redirects']);
        $wpdb->query("DELETE FROM $table_name WHERE id IN (" . implode(',', $ids) . ")");
        add_settings_error('bulk_redirector_messages', 'bulk_delete', __('Selected redirects deleted.', 'bulk-redirector'), 'success');
    }

    // Handle single delete
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['redirect'])) {
        $wpdb->delete($table_name, ['id' => intval($_GET['redirect'])], ['%d']);
        add_settings_error('bulk_redirector_messages', 'delete', __('Redirect deleted.', 'bulk-redirector'), 'success');
    }

    // Handle add/edit form submission
    if (isset($_POST['submit_redirect'])) {
        $from_url = esc_url_raw(trim($_POST['from_url']));
        $to_url = esc_url_raw(trim($_POST['to_url']));
        $redirect_type = sanitize_text_field($_POST['redirect_type']);
        $redirect_id = isset($_POST['redirect_id']) ? intval($_POST['redirect_id']) : null;
        
        if (empty($from_url) || empty($to_url)) {
            add_settings_error('bulk_redirector_messages', 'empty_fields', __('Both URLs are required.', 'bulk-redirector'), 'error');
        } else {
            // Validate redirect
            $errors = bulk_redirector_validate_redirect($from_url, $to_url, $redirect_id);
            
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    add_settings_error('bulk_redirector_messages', 'validation_error', $error, 'error');
                }
            } else {
                $data = [
                    'from_url' => $from_url,
                    'to_url' => $to_url,
                    'redirect_type' => $redirect_type
                ];
                
                if ($redirect_id) {
                    $wpdb->update($table_name, $data, ['id' => $redirect_id]);
                    add_settings_error('bulk_redirector_messages', 'updated', __('Redirect updated.', 'bulk-redirector'), 'success');
                } else {
                    $wpdb->insert($table_name, $data);
                    add_settings_error('bulk_redirector_messages', 'added', __('Redirect added.', 'bulk-redirector'), 'success');
                }
            }
        }
    }

    // Show edit form or list
    if (isset($_GET['action']) && ($_GET['action'] === 'edit' || $_GET['action'] === 'add')) {
        bulk_redirector_edit_form();
    } else {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Redirects List', 'bulk-redirector'); ?></h1>
            <a href="?page=<?php echo $_REQUEST['page']; ?>&action=add" class="page-title-action"><?php echo esc_html__('Add New', 'bulk-redirector'); ?></a>
            <?php settings_errors('bulk_redirector_messages'); ?>
            <form method="post">
                <?php
                $table = new Bulk_Redirects_List_Table();
                $table->prepare_items();
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }
}

function bulk_redirector_edit_form() {
    global $wpdb;
    $redirect = null;
    
    if (isset($_GET['redirect'])) {
        $redirect = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bulk_redirects WHERE id = %d",
            intval($_GET['redirect'])
        ));
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo $redirect ? esc_html__('Edit Redirect', 'bulk-redirector') : esc_html__('Add New Redirect', 'bulk-redirector'); ?></h1>
        <?php settings_errors('bulk_redirector_messages'); ?>
        <form method="post">
            <?php if ($redirect) : ?>
                <input type="hidden" name="redirect_id" value="<?php echo esc_attr($redirect->id); ?>">
            <?php endif; ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="from_url"><?php esc_html_e('From URL', 'bulk-redirector'); ?></label></th>
                    <td>
                        <input name="from_url" type="text" id="from_url" value="<?php echo $redirect ? esc_attr($redirect->from_url) : ''; ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e('The URL to redirect from', 'bulk-redirector'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="to_url"><?php esc_html_e('To URL', 'bulk-redirector'); ?></label></th>
                    <td>
                        <input name="to_url" type="text" id="to_url" value="<?php echo $redirect ? esc_attr($redirect->to_url) : ''; ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e('The URL to redirect to', 'bulk-redirector'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="redirect_type"><?php esc_html_e('Redirect Type', 'bulk-redirector'); ?></label></th>
                    <td>
                        <select name="redirect_type" id="redirect_type" required>
                            <option value="301" <?php selected($redirect ? $redirect->redirect_type : '', '301'); ?>>301 - Permanent</option>
                            <option value="302" <?php selected($redirect ? $redirect->redirect_type : '', '302'); ?>>302 - Temporary</option>
                            <option value="307" <?php selected($redirect ? $redirect->redirect_type : '', '307'); ?>>307 - Temporary (Strict)</option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_redirect" class="button button-primary" value="<?php echo $redirect ? esc_attr__('Update Redirect', 'bulk-redirector') : esc_attr__('Add Redirect', 'bulk-redirector'); ?>">
                <a href="?page=bulk-redirector-list" class="button"><?php esc_html_e('Cancel', 'bulk-redirector'); ?></a>
            </p>
        </form>
    </div>
    <?php
}

function bulk_redirector_settings_init() {
    register_setting(
        'bulk_redirector_settings', 
        'bulk_redirector_settings',
        array(
            'sanitize_callback' => 'bulk_redirector_validate_settings'
        )
    );

    add_settings_section(
        'bulk_redirector_settings_section',
        __('Redirect Settings', 'bulk-redirector'),
        'bulk_redirector_settings_section_callback',
        'bulk_redirector'
    );

    add_settings_field(
        'redirect_type',
        __('Redirect Type', 'bulk-redirector'),
        'bulk_redirector_redirect_type_render',
        'bulk_redirector',
        'bulk_redirector_settings_section'
    );

    add_settings_field(
        'csv_upload',
        __('Upload CSV', 'bulk-redirector'),
        'bulk_redirector_csv_upload_render',
        'bulk_redirector',
        'bulk_redirector_settings_section'
    );
}

function bulk_redirector_validate_settings($input) {
    $output = array();
    $error = false;

    if (empty($input['redirect_type'])) {
        add_settings_error(
            'bulk_redirector_messages',
            'bulk_redirector_error',
            __('Please select a redirect type.', 'bulk-redirector'),
            'error'
        );
        $error = true;
    }

    if ($error) {
        // Preserve previous valid settings if there's an error
        return get_option('bulk_redirector_settings');
    }

    $output['redirect_type'] = sanitize_text_field($input['redirect_type']);
    return $output;
}

function bulk_redirector_settings_section_callback() {
    echo __('Configure your redirection settings here.', 'bulk-redirector');
}

function bulk_redirector_redirect_type_render() {
    $options = get_option('bulk_redirector_settings');
    $redirect_type = isset($options['redirect_type']) ? $options['redirect_type'] : '';
    ?>
    <select name='bulk_redirector_settings[redirect_type]' required>
        <option value='' <?php selected($redirect_type, ''); ?>>-- Select Redirect Type --</option>
        <option value='301' <?php selected($redirect_type, '301'); ?>>301 - Permanent</option>
        <option value='302' <?php selected($redirect_type, '302'); ?>>302 - Temporary</option>
        <option value='307' <?php selected($redirect_type, '307'); ?>>307 - Temporary (Strict)</option>
    </select>
    <p class="description">
        <?php _e('Select the type of redirect you want to use.', 'bulk-redirector'); ?>
    </p>
    <?php
}

function bulk_redirector_csv_upload_render() {
    ?>
    <input type="file" name="csv_file" accept=".csv" />
    <p class="description">
        <?php _e('Upload a CSV file with two columns: "from_url" and "to_url"', 'bulk-redirector'); ?>
    </p>
    <?php
}

function bulk_redirector_options_page() {
    if (isset($_FILES['csv_file'])) {
        bulk_redirector_process_csv($_FILES['csv_file']);
    }
    ?>
    <div class="wrap">
        <h2>Bulk Redirector Settings</h2>
        <?php
        // Show error/update messages
        settings_errors('bulk_redirector_messages');
        ?>
        <form action="" method="post" enctype="multipart/form-data">
            <?php
            settings_fields('bulk_redirector_settings');
            do_settings_sections('bulk_redirector');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function bulk_redirector_process_csv($file) {
    $processor = new Bulk_Redirector_CSV_Processor();
    $result = $processor->process($file);
    
    add_settings_error(
        'bulk_redirector_messages',
        $result['success'] ? 'csv_upload_success' : 'csv_upload_error',
        $result['message'],
        $result['success'] ? 'success' : 'error'
    );
}

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

// Add redirect handling
add_action('template_redirect', 'bulk_redirector_handle_redirect');

function bulk_redirector_handle_redirect() {
    global $wpdb;
    $current_url = rtrim($_SERVER['REQUEST_URI'], '/');
    $table_name = $wpdb->prefix . 'bulk_redirects';

    $redirect = $wpdb->get_row($wpdb->prepare(
        "SELECT to_url, redirect_type FROM $table_name WHERE from_url = %s",
        $current_url
    ));

    if ($redirect) {
        wp_redirect($redirect->to_url, $redirect->redirect_type);
        exit;
    }
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bulk_redirector_settings_link');

function bulk_redirector_settings_link($links) {
    $settings_link = '<a href="admin.php?page=bulk_redirector">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}