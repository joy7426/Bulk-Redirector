<?php
class Bulk_Redirector_CSV_Processor {
    private $from_index;
    private $to_index;

    public function __construct() {
        // Empty constructor - no longer need default redirect type
    }

    public function process($file, $redirect_type) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->error(__('Error uploading file.', 'bulk-redirector'));
        }

        // Basic CSV validation
        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'csv') {
            return $this->error(__('Please upload a valid CSV file.', 'bulk-redirector'));
        }

        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            return $this->error(__('Error reading CSV file.', 'bulk-redirector'));
        }

        // Remove UTF-8 BOM if present
        $bom = fgets($handle, 4);
        if ($bom !== false && !in_array($bom, ["\xEF\xBB\xBF", "from"])) {
            rewind($handle);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects';
        $success_count = 0;
        $error_count = 0;
        $line = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            
            // Skip first row regardless of content
            if ($line === 1) {
                continue;
            }

            // Skip empty rows
            if (empty($data) || count($data) < 2) {
                continue;
            }

            // Process URLs directly from first two columns
            $from_url = $this->normalize_url(trim($data[0]));
            $to_url = $this->normalize_url(trim($data[1]));

            // Skip if URLs are empty
            if (empty($from_url) || empty($to_url)) {
                $error_count++;
                continue;
            }

            // Insert redirect
            $result = $wpdb->replace(
                $table_name,
                array(
                    'from_url' => $from_url,
                    'to_url' => $to_url,
                    'redirect_type' => $redirect_type
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

        if ($success_count === 0) {
            return $this->error(__('No valid redirects found in CSV file.', 'bulk-redirector'));
        }

        return array(
            'success' => true,
            'message' => sprintf(
                __('CSV import completed. Added: %d, Errors: %d', 'bulk-redirector'),
                $success_count,
                $error_count
            )
        );
    }

    private function error($message) {
        return array(
            'success' => false,
            'message' => $message
        );
    }

    private function normalize_url($url) {
        $url = trim($url);
        
        // Handle protocol
        if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
            // Check if it's a relative URL
            if (strpos($url, '/') === 0) {
                $url = home_url($url);
            } else {
                $url = 'https://' . $url;
            }
        }
        
        // Remove multiple slashes but keep protocol slashes
        $url = preg_replace('/([^:])(\/{2,})/', '$1/', $url);
        
        return $url;
    }
}