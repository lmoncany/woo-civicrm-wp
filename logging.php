<?php
if (!defined('WPINC')) {
    die;
}

class WC_CiviCRM_Logger {
    const MAX_LOG_SIZE = 1024 * 1024; // 1MB
    const MAX_LOG_FILES = 5; // Keep 5 rotated files
    
    private static $log_file = 'wc-civicrm-integration.log';
    private static $logs_dir = null;

    private static function init_logs_dir() {
        if (self::$logs_dir === null) {
            // Get WordPress uploads directory
            if (function_exists('wp_upload_dir')) {
                $upload_dir = wp_upload_dir();
                $base_dir = $upload_dir['basedir'];
            } else {
                // Fallback to plugin directory if not in WordPress context
                $base_dir = dirname(__FILE__);
            }
            
            self::$logs_dir = $base_dir . '/wc-civicrm-logs';
            
            // Create logs directory if it doesn't exist
            if (!file_exists(self::$logs_dir)) {
                // Try WordPress function first
                if (function_exists('wp_mkdir_p')) {
                    wp_mkdir_p(self::$logs_dir);
                } else {
                    // Fallback to native PHP mkdir
                    mkdir(self::$logs_dir, 0755, true);
                }
                
                // Create .htaccess to prevent direct access
                $htaccess = self::$logs_dir . '/.htaccess';
                if (!file_exists($htaccess)) {
                    file_put_contents($htaccess, 'deny from all');
                }
                
                // Create index.php to prevent directory listing
                $index = self::$logs_dir . '/index.php';
                if (!file_exists($index)) {
                    file_put_contents($index, '<?php // Silence is golden');
                }
            }
        }
        return self::$logs_dir;
    }

    private static function get_current_time() {
        // Try WordPress function first
        if (function_exists('current_time')) {
            return current_time('mysql');
        }
        // Fallback to PHP date
        return date('Y-m-d H:i:s');
    }

    public static function get_log_file_path() {
        $logs_dir = self::init_logs_dir();
        return $logs_dir . '/' . self::$log_file;
    }

    private static function ensure_log_file() {
        $log_file = self::get_log_file_path();
        
        if (!file_exists($log_file)) {
            file_put_contents($log_file, '');
            chmod($log_file, 0644);
            
            // Add file header
            $header = sprintf(
                "# CiviCRM Integration Log\n# Created: %s\n# Plugin Version: %s\n\n",
                date('Y-m-d H:i:s'),
                '1.0'
            );
            file_put_contents($log_file, $header);
        }
        
        // Check file size and rotate if needed
        if (filesize($log_file) > self::MAX_LOG_SIZE) {
            self::rotate_logs();
        }
    }

    private static function rotate_logs() {
        $base_file = self::get_log_file_path();
        
        // Rotate existing backup files
        for ($i = self::MAX_LOG_FILES - 1; $i > 0; $i--) {
            $old_file = $base_file . '.' . $i;
            $new_file = $base_file . '.' . ($i + 1);
            if (file_exists($old_file)) {
                rename($old_file, $new_file);
            }
        }
        
        // Move current log to .1
        if (file_exists($base_file)) {
            rename($base_file, $base_file . '.1');
        }
        
        // Create new log file
        self::ensure_log_file();
        
        // Delete oldest log file if it exists
        $oldest_file = $base_file . '.' . (self::MAX_LOG_FILES + 1);
        if (file_exists($oldest_file)) {
            unlink($oldest_file);
        }
        
        // Log rotation event
        self::log_success('log_rotation', [
            'message' => 'Log file rotated due to size limit',
            'max_size' => self::MAX_LOG_SIZE,
            'max_files' => self::MAX_LOG_FILES
        ]);
    }

    private static function write_log($type, $event, $data) {
        self::ensure_log_file();

        $log_entry = [
            'timestamp' => self::get_current_time(),
            'type' => $type,
            'event' => $event,
            'message' => isset($data['message']) ? $data['message'] : '',
            'data' => $data
        ];

        $log_file = self::get_log_file_path();
        $log_line = json_encode($log_entry) . PHP_EOL;

        file_put_contents($log_file, $log_line, FILE_APPEND);
    }

    public static function log_success($event, $data = []) {
        self::write_log('success', $event, $data);
    }

    public static function log_error($event, $data = []) {
        self::write_log('error', $event, $data);
    }

    public static function clear_logs() {
        $base_file = self::get_log_file_path();
        
        // Delete main log file
        if (file_exists($base_file)) {
            unlink($base_file);
        }
        
        // Delete all rotated log files
        for ($i = 1; $i <= self::MAX_LOG_FILES; $i++) {
            $rotated_file = $base_file . '.' . $i;
            if (file_exists($rotated_file)) {
                unlink($rotated_file);
            }
        }
        
        // Create fresh log file
        self::ensure_log_file();
        
        // Log the cleanup
        self::log_success('logs_cleared', [
            'message' => 'All log files have been cleared',
            'timestamp' => self::get_current_time()
        ]);
    }

    public static function get_logs($include_rotated = false) {
        $logs = [];
        $base_file = self::get_log_file_path();
        
        // Read current log file
        if (file_exists($base_file)) {
            $lines = file($base_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') !== 0) { // Skip header lines
                    $entry = json_decode($line, true);
                    if ($entry) {
                        $logs[] = $entry;
                    }
                }
            }
        }
        
        // Read rotated logs if requested
        if ($include_rotated) {
            for ($i = 1; $i <= self::MAX_LOG_FILES; $i++) {
                $rotated_file = $base_file . '.' . $i;
                if (file_exists($rotated_file)) {
                    $lines = file($rotated_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $line) {
                        if (strpos($line, '#') !== 0) { // Skip header lines
                            $entry = json_decode($line, true);
                            if ($entry) {
                                $logs[] = $entry;
                            }
                        }
                    }
                }
            }
        }
        
        // Sort logs by timestamp descending
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        return $logs;
    }
}
