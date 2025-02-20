<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_CiviCRM_Logger {
    /**
     * Log levels
     */
    const LEVEL_ERROR = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_INFO = 'info';
    const LEVEL_DEBUG = 'debug';

    /**
     * Logging context
     */
    const CONTEXT_GENERAL = 'general';
    const CONTEXT_CONNECTION = 'connection';
    const CONTEXT_CONTACT = 'contact';
    const CONTEXT_SYNC = 'sync';

    /**
     * Log an error message
     *
     * @param string $context Context of the log
     * @param array|string $message Message or array of details to log
     * @param array $additional_data Additional data for context
     */
    public static function log_error($context, $message, $additional_data = [])
    {
        self::write_log(self::LEVEL_ERROR, $context, $message, $additional_data);
    }

    /**
     * Log a warning message
     *
     * @param string $context Context of the log
     * @param array|string $message Message or array of details to log
     * @param array $additional_data Additional data for context
     */
    public static function log_warning($context, $message, $additional_data = [])
    {
        self::write_log(self::LEVEL_WARNING, $context, $message, $additional_data);
    }

    /**
     * Log an informational message
     *
     * @param string $context Context of the log
     * @param array|string $message Message or array of details to log
     * @param array $additional_data Additional data for context
     */
    public static function log_info($context, $message, $additional_data = [])
    {
        self::write_log(self::LEVEL_INFO, $context, $message, $additional_data);
    }

    /**
     * Log a debug message
     *
     * @param string $context Context of the log
     * @param array|string $message Message or array of details to log
     * @param array $additional_data Additional data for context
     */
    public static function log_debug($context, $message, $additional_data = [])
    {
        // Only log debug messages if debug mode is enabled
        if (self::is_debug_mode_enabled()) {
            self::write_log(self::LEVEL_DEBUG, $context, $message, $additional_data);
        }
    }

    /**
     * Write log to WordPress debug log
     *
     * @param string $level Log level
     * @param string $context Log context
     * @param array|string $message Message or array of details to log
     * @param array $additional_data Additional data for context
     */
    private static function write_log($level, $context, $message, $additional_data = [])
    {
        // Prepare log message
        $log_message = self::format_log_message($level, $context, $message, $additional_data);

        // Use WordPress error log
        error_log($log_message);

        // Optionally write to a custom log file if needed
        self::write_to_custom_log_file($log_message);
    }

    /**
     * Format log message
     *
     * @param string $level Log level
     * @param string $context Log context
     * @param array|string $message Message or array of details to log
     * @param array $additional_data Additional data for context
     * @return string Formatted log message
     */
    private static function format_log_message($level, $context, $message, $additional_data = [])
    {
        // Convert message to string if it's an array
        if (is_array($message)) {
            $message = json_encode($message);
        }

        // Prepare timestamp
        $timestamp = current_time('mysql');

        // Prepare additional data
        $additional_info = !empty($additional_data) ? ' | ' . json_encode($additional_data) : '';

        // Format log message
        return sprintf(
            '[%s] WC CiviCRM %s | %s | %s%s',
            $timestamp,
            strtoupper($level),
            $context,
            $message,
            $additional_info
        );
    }

    /**
     * Write log to a custom log file
     *
     * @param string $log_message Log message to write
     */
    private static function write_to_custom_log_file($log_message)
    {
        // Check if custom logging is enabled
        if (!self::is_custom_logging_enabled()) {
            return;
        }

        // Get log file path
        $log_file = self::get_custom_log_file_path();

        // Append log message to file
        file_put_contents($log_file, $log_message . PHP_EOL, FILE_APPEND);

        // Rotate log file if it gets too large
        self::rotate_log_file($log_file);
    }

    /**
     * Get path for custom log file
     *
     * @return string Path to log file
     */
    private static function get_custom_log_file_path()
    {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wc-civicrm-logs';

        // Create log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Add .htaccess to prevent direct access
        $htaccess_file = $log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Deny from all\n");
        }

        return $log_dir . '/wc-civicrm-' . date('Y-m-d') . '.log';
    }

    /**
     * Rotate log file if it exceeds maximum size
     *
     * @param string $log_file Path to log file
     */
    private static function rotate_log_file($log_file)
    {
        $max_log_size = 5 * 1024 * 1024; // 5MB
        if (file_exists($log_file) && filesize($log_file) > $max_log_size) {
            $archived_log_file = $log_file . '.' . date('Y-m-d-H-i-s');
            rename($log_file, $archived_log_file);
        }
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public static function is_debug_mode_enabled()
    {
        return (bool) get_option('wc_civicrm_debug_mode', false);
    }

    /**
     * Check if custom logging is enabled
     *
     * @return bool
     */
    public static function is_custom_logging_enabled()
    {
        return (bool) get_option('wc_civicrm_custom_logging', false);
    }

    /**
     * Clear old log files
     */
    public static function clear_old_log_files()
    {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wc-civicrm-logs';

        if (!is_dir($log_dir)) {
            return;
        }

        $log_files = glob($log_dir . '/wc-civicrm-*.log');
        $max_log_age = 30 * 24 * 60 * 60; // 30 days

        foreach ($log_files as $log_file) {
            if (time() - filemtime($log_file) > $max_log_age) {
                unlink($log_file);
            }
        }
    }
}

// Schedule log file cleanup
if (!wp_next_scheduled('wc_civicrm_log_cleanup')) {
    wp_schedule_event(time(), 'daily', 'wc_civicrm_log_cleanup');
}
add_action('wc_civicrm_log_cleanup', ['WC_CiviCRM_Logger', 'clear_old_log_files']);
