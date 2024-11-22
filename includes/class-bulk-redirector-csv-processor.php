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
        $stats = [
            'success' => 0,
            'empty_rows' => 0,
            'invalid_urls' => 0,
            'circular_redirects' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'total_processed' => 0
        ];
        $line = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            $stats['total_processed']++;
            
            // Skip first row regardless of content
            if ($line === 1) {
                $stats['total_processed']--;
                continue;
            }

            // Skip empty rows
            if (empty($data) || count($data) < 2) {
                $stats['empty_rows']++;
                continue;
            }

            // Process URLs directly from first two columns
            $from_url = $this->normalize_url(trim($data[0]));
            $to_url = $this->normalize_url(trim($data[1]));

            // Skip if URLs are empty or invalid
            if (empty($from_url) || empty($to_url)) {
                $stats['invalid_urls']++;
                continue;
            }

            // Check for circular redirects
            if ($from_url === $to_url) {
                $stats['circular_redirects']++;
                continue;
            }

            // Check for existing redirect
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE from_url = %s",
                $from_url
            ));

            if ($existing) {
                $stats['duplicates']++;
                continue;
            }

            // Insert redirect
            $result = $wpdb->insert(
                $table_name,
                array(
                    'from_url' => $from_url,
                    'to_url' => $to_url,
                    'redirect_type' => $redirect_type
                ),
                array('%s', '%s', '%s')
            );

            if ($result === false) {
                $stats['errors']++;
            } else {
                $stats['success']++;
            }
        }

        fclose($handle);

        if ($stats['success'] === 0) {
            return $this->error(__('No valid redirects found in CSV file.', 'bulk-redirector'));
        }

        $message = sprintf(
            __('CSV import completed with the following results:<br>
                • Successfully added: %d<br>
                • Empty rows skipped: %d<br>
                • Invalid URLs: %d<br>
                • Circular redirects prevented: %d<br>
                • Duplicates skipped: %d<br>
                • Errors: %d<br>
                • Total rows processed: %d', 'bulk-redirector'),
            $stats['success'],
            $stats['empty_rows'],
            $stats['invalid_urls'],
            $stats['circular_redirects'],
            $stats['duplicates'],
            $stats['errors'],
            $stats['total_processed']
        );

        return array(
            'success' => true,
            'message' => $message,
            'stats' => $stats
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