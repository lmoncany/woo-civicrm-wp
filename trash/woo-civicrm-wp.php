<?php
/**
 * Plugin Name: TEST CiviCRM Integration
 * Description: Seamlessly integrate WooCommerce with CiviCRM
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: woo-civicrm-wp
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'class-wc-civicrm-logger.php';
require_once plugin_dir_path(__FILE__) . 'settings-page.php';

class WC_CiviCRM_Integration {
    /**
     * Constructor
     */
    public function __construct()
    {
        // Register activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Register deactivation hook
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Initialize plugin
        add_action('init', [$this, 'init']);
    }

    /**
     * Activation hook
     */
    public function activate()
    {
        // Set default settings
        add_option('wc_civicrm_debug_mode', false);
        add_option('wc_civicrm_custom_logging', true);

        // Log activation
        WC_CiviCRM_Logger::log_info('plugin', 'WooCommerce CiviCRM Integration activated');
    }

    /**
     * Deactivation hook
     */
    public function deactivate()
    {
        // Log deactivation
        WC_CiviCRM_Logger::log_info('plugin', 'WooCommerce CiviCRM Integration deactivated');

        // Optional: Clean up scheduled events
        wp_clear_scheduled_hook('wc_civicrm_log_cleanup');
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Load text domain for translations
        load_plugin_textdomain('woo-civicrm-wp', false, basename(dirname(__FILE__)) . '/languages/');
    }

    /**
     * Add settings link to plugins page
     *
     * @param array $links Existing plugin links
     * @return array Modified plugin links
     */
    public function add_settings_link($links)
    {
        $settings_link = [
            'settings' => '<a href="' . admin_url('admin.php?page=wc-civicrm-settings') . '">' . __('Settings', 'woo-civicrm-wp') . '</a>'
        ];
        return array_merge($settings_link, $links);
    }
}

// Initialize the plugin
// new WC_CiviCRM_Integration();
