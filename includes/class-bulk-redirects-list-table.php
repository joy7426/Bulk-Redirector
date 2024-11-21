<?php
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