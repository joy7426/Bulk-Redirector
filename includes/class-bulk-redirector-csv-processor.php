<?php
class Bulk_Redirector_CSV_Processor {
    private $default_redirect_type;

    public function __construct() {
        $options = get_option('bulk_redirector_settings');
        $this->default_redirect_type = isset($options['redirect_type']) ? $options['redirect_type'] : '301';
    }

    public function process($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->error(__('Error uploading file.', 'bulk-redirector'));
        }

        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            return $this->error(__('Error reading CSV file.', 'bulk-redirector'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects';
        $success_count = 0;
        $error_count = 0;
        $headers = fgetcsv($handle); // Skip headers

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 2) continue;

            $from_url = esc_url_raw(trim($data[0]));
            $to_url = esc_url_raw(trim($data[1]));

            // Skip if URLs are empty
            if (empty($from_url) || empty($to_url)) {
                $error_count++;
                continue;
            }

            // Check URL safety first
            if (!bulk_redirector_is_safe_url($from_url) || !bulk_redirector_is_safe_url($to_url)) {
                $error_count++;
                continue;
            }

            // Validate redirect
            $errors = bulk_redirector_validate_redirect($from_url, $to_url);
            if (!empty($errors)) {
                $error_count++;
                continue;
            }

            // Insert or update redirect
            $result = $wpdb->replace(
                $table_name,
                array(
                    'from_url' => $from_url,
                    'to_url' => $to_url,
                    'redirect_type' => $this->default_redirect_type
                ),
                array('%s', '%s', '%s')
            );

            if ($result === false) {
                $error_count++;
            } else {
                $success_count++;
            }
        }

        fclose($handle);

        return array(
            'success' => true,
            'message' => sprintf(
                __('Processed CSV file. Success: %d, Errors: %d', 'bulk-redirector'),
                $success_count,
                $error_count
            ),
            'success_count' => $success_count,
            'error_count' => $error_count
        );
    }

    private function error($message) {
        return array(
            'success' => false,
            'message' => $message
        );
    }
}