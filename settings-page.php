<?php
// Enhanced protection against direct access
if (!defined('ABSPATH')) {
    // Show detailed diagnostic page instead of just exiting
    http_response_code(403);
    echo '<!DOCTYPE html>
    <html><head><meta charset="utf-8"><title>WordPress Plugin Error</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; color: #444; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #d63638; }
        .diagnostic { background: #f0f0f1; padding: 15px; border-left: 4px solid #d63638; margin-bottom: 20px; }
        code { background: #e0e0e0; padding: 2px 4px; }
    </style></head><body>
    <h1>Direct Access Not Allowed</h1>
    <div class="diagnostic">
        <p>This file should not be accessed directly. It should be loaded through the WordPress admin interface.</p>
        <p>Current file path: ' . __FILE__ . '</p>
        <p>To fix this issue:</p>
        <ol>
            <li>Access your WordPress admin at <code>https://your-site.com/wp-admin</code></li>
            <li>Navigate to WC CiviCRM Settings from the admin menu</li>
        </ol>
    </div>
    </body></html>';
    exit;
}

class WC_CiviCRM_Settings
{
    use WC_CiviCRM_API_Request;

    public $available_fields = [];
    public $civicrm_url;
    public $auth_token;

    public function __construct()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_test_civicrm_connection', [$this, 'test_connection']);
        add_action('wp_ajax_fetch_civicrm_fields', [$this, 'fetch_fields']);
        add_action('wp_ajax_test_contact_creation', [$this, 'test_contact_creation']);
        add_action('wp_ajax_test_contribution_creation', [$this, 'test_contribution_creation']);
        
        // Try to fetch fields if we have connection settings
        $url = get_option('wc_civicrm_url');
        $token = get_option('wc_civicrm_auth_token');
        if (!empty($url) && !empty($token)) {
            // $this->fetch_available_fields();
        }
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only enqueue on our settings page
        if ($hook !== 'toplevel_page_wc-civicrm-settings') {
            return;
        }

        // Define plugin directory with fallback for file structure
        $plugin_url = plugin_dir_url(__FILE__);
        
        // Check if we're inside a subdirectory
        if (strpos($plugin_url, '/wp/wp-content/plugins/woo-civicrm-wp/') === false) {
            $plugin_url = plugin_dir_url(dirname(__FILE__));
        }

        wp_enqueue_style(
            'wc-civicrm-admin-styles',
            $plugin_url . 'assets/css/admin-styles.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'wc-civicrm-admin-scripts',
            $plugin_url . 'assets/js/admin-scripts.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('wc-civicrm-admin-scripts', 'wc_civicrm_admin_params', [
            'test_connection_nonce' => wp_create_nonce('test_civicrm_connection'),
            'test_contact_creation_nonce' => wp_create_nonce('test_contact_creation'),
            'test_contribution_creation_nonce' => wp_create_nonce('test_contribution_creation'),
            'fetch_fields_nonce' => wp_create_nonce('fetch_civicrm_fields')
        ]);
    }

    /**
     * Render connection status test button and result container
     */
    public function connection_status_callback()
    {
?>
        <div class="wc-civicrm-card">
            <div class="connection-test-container">
                <div class="connection-status">
                    <span class="connection-indicator" id="connection-indicator"></span>
                    <span class="connection-status-text" id="connection-status-text">Unknown</span>
                </div>
                <button id="test-civicrm-connection" class="button button-primary">
                    <span class="dashicons dashicons-database-view"></span>
                    <?php _e('Test CiviCRM Connection', 'woo-civicrm-wp'); ?>
                </button>
                <div id="connection-test-result" class="test-result"></div>
            </div>
        </div>
<?php
    }

    /**
     * Render contact creation test button and result container
     */
    public function contact_creation_callback()
    {
    ?>
        <div class="wc-civicrm-card">
            <div class="contact-creation-test-container">
                <button id="test-contact-creation" class="button button-primary">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php _e('Test Contact Creation', 'woo-civicrm-wp'); ?>
                </button>
                <div id="contact-creation-test-result" class="test-result"></div>
            </div>
        </div>
    <?php
    }

    /**
     * Render contribution creation test button and result container
     */
    public function contribution_creation_callback()
    {
    ?>
        <div class="wc-civicrm-card">
            <div class="contribution-creation-test-container">
                <button id="test-contribution-creation" class="button button-primary">
                    <span class="dashicons dashicons-money-alt"></span>
                    <?php _e('Test Contribution Creation', 'woo-civicrm-wp'); ?>
                </button>
                <div id="contribution-creation-test-result" class="test-result"></div>
            </div>
        </div>
    <?php
    }

    public function register_settings()
    {
        register_setting('wc_civicrm_settings', 'wc_civicrm_url');
        register_setting('wc_civicrm_settings', 'wc_civicrm_auth_token');
        register_setting('wc_civicrm_settings', 'wc_civicrm_field_mappings', [
            'sanitize_callback' => [$this, 'sanitize_field_mappings']
        ]);
        register_setting('wc_civicrm_settings', 'wc_civicrm_debug_mode');
        register_setting('wc_civicrm_settings', 'wc_civicrm_connection_status');

        // Add settings sections
        add_settings_section(
            'wc_civicrm_connection_section',
            'Connection Settings',
            [$this, 'section_callback'],
            'wc-civicrm-settings'
        );
    }

    public function sanitize_field_mappings($mappings)
    {
        if (!is_array($mappings)) {
            return [];
        }

        $sanitized = [];
        foreach ($mappings as $key => $mapping) {
            if (empty($mapping['wc_field']) || empty($mapping['civicrm'])) {
                continue;
            }

            $sanitized[$mapping['wc_field']] = [
                'civicrm' => sanitize_text_field($mapping['civicrm']),
                'type' => sanitize_text_field($mapping['type'])
            ];
        }

        return $sanitized;
    }

    public function section_callback()
    {
        // Validate WordPress environment
        if (!function_exists('add_action')) {
            $this->render_diagnostic_page();
            return;
        }

        // Enable error reporting for debugging
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
        
        // Get current connection status for initial display
        $connection_status = $this->check_connection_status();
        $status_class = $connection_status['status'];
        $status_text = ucfirst($connection_status['status']);
        if ($status_class === 'unknown') {
            $status_text = 'Unknown';
        }
        
        // Minimal critical CSS for tabs to work
        ?>
        <style>
            /* Basic tab styling */
            .wc-civicrm-tabs { 
                display: flex;
                margin-bottom: 0;
                border-bottom: 1px solid #ccc;
            }
            .wc-civicrm-tab-button { 
                background: #f1f1f1;
                border: 1px solid #ccc;
                border-bottom: none;
                padding: 10px 15px;
                margin-right: 5px;
                margin-bottom: -1px;
                cursor: pointer;
            }
            .wc-civicrm-tab-button.active { 
                background: #fff;
                border-bottom-color: #fff;
            }
            .wc-civicrm-tab-content { 
                display: none;
                padding: 15px;
                background: #fff;
                border: 1px solid #ccc;
                border-top: none;
            }
            /* Force first tab to be visible by default */
            #tab-connection { 
                display: block;
            }
            /* Connection status indicator */
            .connection-indicator { 
                display: inline-block;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                margin-right: 5px;
            }
            .connection-indicator.connected { 
                background: #52c41a;
            }
            .connection-indicator.disconnected { 
                background: #ff4d4f;
            }
            /* Ensure form elements are visible */
            input, select, button { 
                opacity: 1 !important;
            }
        </style>
        
        <div class="wrap wc-civicrm-admin-wrapper">
            <h1>WooCommerce CiviCRM Integration</h1>
            <p>Configure your WooCommerce and CiviCRM integration settings.</p>
            
            <form method="post" action="options.php">
                <?php 
                // Make sure WordPress admin is loaded
                if (function_exists('settings_fields')) {
                    settings_fields('wc_civicrm_settings');
                } else {
                    echo '<input type="hidden" name="option_page" value="wc_civicrm_settings" />';
                    
                    // Create our own nonce field if wp_nonce_field isn't available
                    if (function_exists('wp_nonce_field')) {
                        wp_nonce_field('wc_civicrm_settings-options');
                    } else {
                        echo '<input type="hidden" name="_wpnonce" value="' . md5('wc_civicrm_settings-options' . time()) . '" />';
                    }
                }
                ?>
                
                <div class="wc-civicrm-tabs-wrapper">
                    <nav class="wc-civicrm-tabs">
                        <button type="button" class="wc-civicrm-tab-button active" data-tab="tab-connection">Connection</button>
                        <button type="button" class="wc-civicrm-tab-button" data-tab="tab-mappings">Field Mappings</button>
                        <button type="button" class="wc-civicrm-tab-button" data-tab="tab-testing">Testing</button>
                        <button type="button" class="wc-civicrm-tab-button" data-tab="tab-debug">Debug</button>
                    </nav>
                    
                    <!-- First tab: Connection settings -->
                    <div id="tab-connection" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>CiviCRM Connection Settings</h2>
                            <p>Configure the connection between WooCommerce and CiviCRM.</p>
                            <div class="connection-status">
                                <span class="connection-indicator <?php echo $status_class; ?>" id="main-connection-indicator"></span>
                                <span class="connection-status-text" id="main-connection-status-text"><?php echo $status_text; ?></span>
                            </div>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">CiviCRM API URL</th>
                                    <td><?php $this->url_callback(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">API Authentication Token</th>
                                    <td><?php $this->auth_token_callback(); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Second tab: Field Mappings -->
                    <div id="tab-mappings" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>Field Mappings</h2>
                            <?php $this->field_mappings_callback(); ?>
                        </div>
                    </div>
                    
                    <!-- Third tab: Testing -->
                    <div id="tab-testing" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>CiviCRM Integration Tests</h2>
                            <p>Verify your CiviCRM integration settings by running the tests below.</p>
                            
                            <div class="wc-civicrm-test-grid">
                                <?php 
                                $this->connection_status_callback();
                                $this->contact_creation_callback();
                                $this->contribution_creation_callback();
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fourth tab: Debug -->
                    <div id="tab-debug" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>Debug Settings</h2>
                            <p>Configure debug and logging options.</p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Debug Mode</th>
                                    <td><?php $this->debug_mode_callback(); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <?php 
                if (function_exists('submit_button')) {
                    submit_button('Save Settings');
                } else {
                    echo '<input type="submit" class="submit-button" value="Save Settings" />';
                }
                ?>
            </form>
        </div>
        
        <!-- Very simple tab script -->
        <script>
            jQuery(document).ready(function($) {
                // Hide all tab content initially except the first
                $(".wc-civicrm-tab-content").not("#tab-connection").hide();
                
                // Simple tab switching
                $(".wc-civicrm-tab-button").on("click", function() {
                    // Hide all tabs
                    $(".wc-civicrm-tab-content").hide();
                    
                    // Remove active class from all buttons
                    $(".wc-civicrm-tab-button").removeClass("active");
                    
                    // Show the selected tab
                    $(this).addClass("active");
                    var tabId = $(this).data("tab");
                    $("#" + tabId).show();
                });
                
                // Make sure the first tab is visible by default
                $("#tab-connection").show();
                $(".wc-civicrm-tab-button[data-tab='tab-connection']").addClass("active");
                
                // Password toggle functionality
                $(".toggle-password").on("click", function() {
                    var targetId = $(this).data("target");
                    var input = $("#" + targetId);
                    var icon = $(this).find(".dashicons");
                    
                    if (input.attr("type") === "password") {
                        input.attr("type", "text");
                        if (icon.length) {
                            icon.removeClass("dashicons-visibility").addClass("dashicons-hidden");
                        }
                    } else {
                        input.attr("type", "password");
                        if (icon.length) {
                            icon.removeClass("dashicons-hidden").addClass("dashicons-visibility");
                        }
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Display diagnostic page for troubleshooting
     */
    private function render_diagnostic_page() {
        // Check if we're in WordPress context
        $in_wordpress = defined('ABSPATH') && function_exists('get_bloginfo');
        
        // Basic diagnostic information
        $php_version = phpversion();
        $server_info = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        $current_path = __FILE__;
        
        // Get list of WordPress functions if available
        $wp_functions = [];
        if ($in_wordpress) {
            $wp_functions[] = 'WordPress Version: ' . get_bloginfo('version');
            $wp_functions[] = 'WordPress URL: ' . get_bloginfo('url');
            $wp_functions[] = 'WordPress Directory: ' . ABSPATH;
        } else {
            // Check for common WordPress functions
            $core_functions = [
                'add_action', 'add_filter', 'wp_enqueue_script', 'settings_fields',
                'get_option', 'update_option', 'wp_nonce_field', 'wp_create_nonce'
            ];
            
            foreach ($core_functions as $function) {
                $wp_functions[] = $function . ': ' . (function_exists($function) ? 'Available' : 'Not Available');
            }
        }
        
        // Check included files
        $included_files = get_included_files();
        $wp_core_files = array_filter($included_files, function($file) {
            return strpos($file, 'wp-includes') !== false || strpos($file, 'wp-admin') !== false;
        });
        
        // Output diagnostic page
        ?>
        <div class="diagnostic-page" style="padding:20px; max-width:1200px; margin:0 auto; font-family:sans-serif;">
            <h1 style="color:#d63638;">WordPress Integration Diagnostic Page</h1>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>Page Access Information</h2>
                <p><strong>This page has been accessed directly, outside of the normal WordPress admin context.</strong></p>
                <p>To fix this issue:</p>
                <ol>
                    <li>Access your WordPress admin at <code>https://your-site.com/wp-admin</code></li>
                    <li>Navigate to WC CiviCRM Settings from the admin menu</li>
                </ol>
            </div>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>System Information</h2>
                <ul>
                    <li>PHP Version: <?php echo $php_version; ?></li>
                    <li>Server: <?php echo $server_info; ?></li>
                    <li>Current File Path: <?php echo $current_path; ?></li>
                    <li>WordPress Context: <?php echo $in_wordpress ? 'Available' : 'Not Available'; ?></li>
                </ul>
            </div>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>WordPress Function Availability</h2>
                <ul>
                    <?php foreach ($wp_functions as $function_info): ?>
                        <li><?php echo $function_info; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>WordPress Core Files Included</h2>
                <p>Found <?php echo count($wp_core_files); ?> WordPress core files included:</p>
                <ol style="max-height:300px; overflow-y:auto;">
                    <?php foreach (array_slice($wp_core_files, 0, 10) as $file): ?>
                        <li><?php echo $file; ?></li>
                    <?php endforeach; ?>
                    <?php if (count($wp_core_files) > 10): ?>
                        <li>... and <?php echo count($wp_core_files) - 10; ?> more</li>
                    <?php endif; ?>
                </ol>
            </div>
            
            <div style="margin-top:30px; border-top:1px solid #ddd; padding-top:20px;">
                <h2>How to Resolve This Issue</h2>
                <ol>
                    <li>Make sure the plugin is properly installed in your WordPress plugins directory</li>
                    <li>Ensure the plugin is activated in your WordPress admin</li>
                    <li>Access the settings page through the WordPress admin menu, not directly via URL</li>
                    <li>If using a custom setup, make sure WordPress is fully loaded before accessing this page</li>
                </ol>
            </div>
        </div>
        <?php
    }

    public function url_callback()
    {
        $url = get_option('wc_civicrm_url');
    ?>
        <div class="wc-civicrm-field-wrapper">
            <input type="url" name="wc_civicrm_url" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="https://example.com">
            <p class="description">Enter your CiviCRM site URL (e.g., https://example.com)</p>
        </div>
    <?php
    }

    public function auth_token_callback()
    {
        $token = get_option('wc_civicrm_auth_token');
    ?>
        <div class="wc-civicrm-field-wrapper">
            <div class="password-field-container">
                <input type="password" id="civicrm-auth-token" name="wc_civicrm_auth_token" value="<?php echo esc_attr($token); ?>" class="regular-text">
                <button type="button" class="button toggle-password" data-target="civicrm-auth-token">
                    <span class="dashicons dashicons-visibility"></span>
                </button>
            </div>
            <p class="description">Enter your CiviCRM API authentication token</p>
        </div>
    <?php
    }

    public function debug_mode_callback()
    {
        $debug_mode = get_option('wc_civicrm_debug_mode', false);
    ?>
        <div class="wc-civicrm-field-wrapper">
            <label class="toggle-switch">
                <input type="checkbox" name="wc_civicrm_debug_mode" value="1" <?php checked($debug_mode, true); ?>>
                <span class="toggle-slider"></span>
            </label>
            <span class="toggle-label">Enable detailed logging</span>
            <p class="description">When enabled, all API requests and responses will be logged with full details.</p>
        </div>
    <?php
    }

    public function field_mappings_callback()
    {
        $mappings = get_option('wc_civicrm_field_mappings', []);
        $wc_fields = $this->get_woocommerce_fields();
    ?>
        <div class="wc-civicrm-field-mappings-wrapper">
            <div class="field-mappings-header">
                <h3>Field Mappings</h3>
                <p>Map WooCommerce fields to CiviCRM fields. Use the button below to fetch available fields from CiviCRM.</p>
                <div class="field-mappings-actions">
                    <button type="button" id="fetch-fields" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span> Fetch Available Fields
                    </button>
                    <span id="fetch-result"></span>
                </div>
            </div>

            <div class="field-mappings-table-container">
                <table class="wp-list-table widefat fixed striped" id="field-mappings-table">
                    <thead>
                        <tr>
                            <th class="column-wc-field">WooCommerce Field</th>
                            <th class="column-civicrm-field">CiviCRM Field</th>
                            <th class="column-field-type">Field Type</th>
                            <th class="column-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $wc_field => $mapping):
                            $civicrm_field = is_array($mapping) ? $mapping['civicrm'] : $mapping;
                            $field_type = is_array($mapping) ? $mapping['type'] : '';
                        ?>
                            <tr>
                                <td>
                                    <select name="wc_civicrm_field_mappings[<?php echo esc_attr($wc_field); ?>][wc_field]" class="regular-text">
                                        <option value="">Select WooCommerce Field</option>
                                        <?php foreach ($wc_fields as $field => $label): ?>
                                            <option value="<?php echo esc_attr($field); ?>" <?php selected($field, $wc_field); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="wc_civicrm_field_mappings[<?php echo esc_attr($wc_field); ?>][civicrm]"
                                        class="regular-text civicrm-field-select"
                                        data-original-value="<?php echo esc_attr($civicrm_field); ?>"
                                        data-original-type="<?php echo esc_attr($field_type); ?>">
                                        <option value="">Select CiviCRM Field</option>
                                        <option value="<?php echo esc_attr($civicrm_field); ?>" selected>
                                            <?php echo esc_html($civicrm_field); ?>
                                        </option>
                                    </select>
                                    <input type="hidden" name="wc_civicrm_field_mappings[<?php echo esc_attr($wc_field); ?>][type]"
                                        class="field-type-input" value="<?php echo esc_attr($field_type); ?>">
                                </td>
                                <td class="field-type">
                                    <span class="field-type-badge"><?php echo esc_html($field_type); ?></span>
                                </td>
                                <td>
                                    <button type="button" class="button button-small remove-mapping">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4">
                                <button type="button" id="add-mapping" class="button">
                                    <span class="dashicons dashicons-plus"></span> Add New Mapping
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Connection Status Check
                function updateConnectionStatus(status, statusText) {
                    // Update main connection indicator
                    $('#main-connection-indicator')
                        .removeClass('connected disconnected connecting')
                        .addClass(status);
                        
                    $('#main-connection-status-text').text(statusText);
                    
                    // Update the connection status container
                    $('.wc-civicrm-connection-status')
                        .removeClass('connected disconnected')
                        .addClass(status);
                    
                    // Update test tab connection indicator
                    $('#connection-indicator')
                        .removeClass('connected disconnected connecting')
                        .addClass(status);
                        
                    $('#connection-status-text').text(statusText);
                }
                
                // Check initial connection status (if known)
                var initialStatus = <?php echo json_encode($this->check_connection_status()); ?>;
                if (initialStatus.status === 'connected') {
                    updateConnectionStatus('connected', 'Connected');
                } else if (initialStatus.status === 'disconnected') {
                    updateConnectionStatus('disconnected', 'Disconnected');
                } else {
                    updateConnectionStatus('unknown', 'Unknown');
                }
                
                // Test connection handling
                $('#test-civicrm-connection').on('click', function() {
                    var button = $(this);
                    var resultDiv = $('#connection-test-result');
                    
                    // Set status to connecting/testing
                    updateConnectionStatus('connecting', 'Testing connection...');
                    
                    button.addClass('updating-message').prop('disabled', true);
                    resultDiv.removeClass('success error').html('Testing connection...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_civicrm_connection',
                            nonce: wc_civicrm_admin_params.test_connection_nonce
                        },
                        success: function(response) {
                            button.removeClass('updating-message').prop('disabled', false);
                            
                            if (response.success) {
                                resultDiv.addClass('success').html(
                                    '<span class="dashicons dashicons-yes-alt"></span> ' + 
                                    response.data.message
                                );
                                updateConnectionStatus('connected', 'Connected');
                            } else {
                                resultDiv.addClass('error').html(
                                    '<span class="dashicons dashicons-warning"></span> ' + 
                                    response.data.message
                                );
                                updateConnectionStatus('disconnected', 'Disconnected');
                            }
                        },
                        error: function(xhr) {
                            button.removeClass('updating-message').prop('disabled', false);
                            
                            var errorMsg = 'Connection test failed';
                            if (xhr.responseJSON && xhr.responseJSON.data) {
                                errorMsg = xhr.responseJSON.data.message;
                            }
                            
                            resultDiv.addClass('error').html(
                                '<span class="dashicons dashicons-warning"></span> ' + 
                                errorMsg
                            );
                            updateConnectionStatus('disconnected', 'Disconnected');
                        }
                    });
                });
                
                function getNewRow() {
                    const wc_fields = <?php echo json_encode($wc_fields); ?>;
                    let options = '<option value="">Select WooCommerce Field</option>';
                    for (const [value, label] of Object.entries(wc_fields)) {
                        options += `<option value="${value}">${label}</option>`;
                    }

                    const rowId = 'new_' + Math.random().toString(36).substr(2, 9);
                    return `
                        <tr>
                            <td>
                                <select name="wc_civicrm_field_mappings[${rowId}][wc_field]" class="regular-text">
                                    ${options}
                                </select>
                            </td>
                            <td>
                                <select name="wc_civicrm_field_mappings[${rowId}][civicrm]" 
                                        class="regular-text civicrm-field-select"
                                        data-original-value=""
                                        data-original-type="">
                                    <option value="">Select CiviCRM Field</option>
                                </select>
                                <input type="hidden" name="wc_civicrm_field_mappings[${rowId}][type]" 
                                       class="field-type-input" value="">
                            </td>
                            <td class="field-type">
                                <span class="field-type-badge"></span>
                            </td>
                            <td>
                                <button type="button" class="button button-small remove-mapping">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </td>
                        </tr>`;
                }

                $('#add-mapping').click(function() {
                    $('#field-mappings-table tbody').append(getNewRow());
                });

                $(document).on('click', '.remove-mapping', function() {
                    $(this).closest('tr').remove();
                });
                
                // Fetch fields handling
                $('#fetch-fields').on('click', function() {
                    var button = $(this);
                    var resultSpan = $('#fetch-result');
                    
                    button.addClass('updating-message').prop('disabled', true);
                    resultSpan.html('<span class="spinner is-active"></span> Fetching fields...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fetch_civicrm_fields',
                            _ajax_nonce: wc_civicrm_admin_params.fetch_fields_nonce
                        },
                        success: function(response) {
                            button.removeClass('updating-message').prop('disabled', false);
                            
                            if (response.success) {
                                resultSpan.html('<span class="dashicons dashicons-yes-alt success"></span> Fields fetched successfully');
                                
                                // Update field selects with fetched data
                                updateFieldSelects(response.data);
                            } else {
                                resultSpan.html('<span class="dashicons dashicons-warning error"></span> ' + response.data);
                            }
                            
                            // Hide message after 5 seconds
                            setTimeout(function() {
                                resultSpan.html('');
                            }, 5000);
                        },
                        error: function() {
                            button.removeClass('updating-message').prop('disabled', false);
                            resultSpan.html('<span class="dashicons dashicons-warning error"></span> Network error');
                            
                            // Hide message after 5 seconds
                            setTimeout(function() {
                                resultSpan.html('');
                            }, 5000);
                        }
                    });
                });
                
                function updateFieldSelects(fields) {
                    const contactFields = fields.contact_fields || [];
                    const contributionFields = fields.contribution_fields || [];
                    
                    $('.civicrm-field-select').each(function() {
                        const select = $(this);
                        const originalValue = select.data('original-value');
                        const originalType = select.data('original-type');
                        const typeInput = select.siblings('.field-type-input');
                        
                        // Clear existing options except the first one
                        select.find('option:not(:first)').remove();
                        
                        // Add option groups
                        select.append('<optgroup label="Contact Fields">');
                        contactFields.forEach(function(field) {
                            select.append(`<option value="${field.name}" data-type="Contact">${field.label}</option>`);
                        });
                        select.append('</optgroup>');
                        
                        select.append('<optgroup label="Contribution Fields">');
                        contributionFields.forEach(function(field) {
                            select.append(`<option value="${field.name}" data-type="Contribution">${field.label}</option>`);
                        });
                        select.append('</optgroup>');
                        
                        // Re-select original value if it exists
                        if (originalValue) {
                            select.val(originalValue);
                        }
                        
                        // Set change event to update field type
                        select.off('change').on('change', function() {
                            const selected = $(this).find('option:selected');
                            const fieldType = selected.data('type') || '';
                            typeInput.val(fieldType);
                            select.closest('tr').find('.field-type-badge').text(fieldType);
                        });
                    });
                }
                
                // Auto-check connection on page load (if not known)
                if (initialStatus.status === 'unknown') {
                    // Trigger a click on the test connection button after a short delay
                    setTimeout(function() {
                        $('#test-civicrm-connection').trigger('click');
                    }, 1000);
                }
            });
        </script>
<?php
    }

    public function get_woocommerce_fields()
    {
        return [
            // Basic Info
            'billing_first_name' => 'Billing First Name',
            'billing_last_name' => 'Billing Last Name',
            'billing_company' => 'Billing Company',
            'billing_email' => 'Billing Email',
            'billing_phone' => 'Billing Phone',

            // Billing Address
            'billing_address_1' => 'Billing Address 1',
            'billing_address_2' => 'Billing Address 2',
            'billing_city' => 'Billing City',
            'billing_state' => 'Billing State',
            'billing_postcode' => 'Billing Postcode',
            'billing_country' => 'Billing Country',

            // Shipping Address
            'shipping_first_name' => 'Shipping First Name',
            'shipping_last_name' => 'Shipping Last Name',
            'shipping_company' => 'Shipping Company',
            'shipping_address_1' => 'Shipping Address 1',
            'shipping_address_2' => 'Shipping Address 2',
            'shipping_city' => 'Shipping City',
            'shipping_state' => 'Shipping State',
            'shipping_postcode' => 'Shipping Postcode',
            'shipping_country' => 'Shipping Country',

            // Order Information
            'order_number' => 'Order Number',
            'order_currency' => 'Order Currency',
            'order_total' => 'Order Total',
            'order_status' => 'Order Status',
            'payment_method' => 'Payment Method',
            'order_notes' => 'Order Notes'
        ];
    }

    public function test_connection()
    {
        // Verify nonce and user capabilities
        if (!wp_verify_nonce($_POST['nonce'], 'test_civicrm_connection') || !current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Unauthorized access',
                'debug_info' => 'Invalid nonce or insufficient permissions'
            ], 403);
            exit;
        }

        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');

            // Log credentials for debugging
            error_log('CiviCRM Connection Test - URL: ' . $this->civicrm_url);
            error_log(
                'CiviCRM Connection Test - Token: ' .
                    (empty($this->auth_token) ? 'EMPTY' : substr($this->auth_token, 0, 5) . '...' . substr($this->auth_token, -5))
            );

            // Validate credentials are not empty
            if (empty($this->civicrm_url) || empty($this->auth_token)) {
                throw new Exception('CiviCRM URL or Authentication Token is missing');
            }

            // Build the full API endpoint URL - don't pass it as a parameter
            // This fixes the "Unknown api parameter: set_endpoint" error
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contact/get';
            
            // Make API request with basic params
            $response = $this->send_api_request($endpoint, [
                'entity' => 'Contact',
                'action' => 'get',
                'params' => [
                    'select' => ['id'],
                    'checkPermissions' => false,
                    'limit' => 1
                ]
            ]);

            // Additional validation of the response
            if (!isset($response['values']) || empty($response['values'])) {
                throw new Exception('Invalid response from CiviCRM API');
            }

            // Store connection status
            update_option('wc_civicrm_connection_status', [
                'status' => 'connected',
                'last_checked' => time(),
                'message' => 'Successfully connected to CiviCRM'
            ]);

            wp_send_json_success([
                'message' => 'Connection successful',
                'status' => 'connected',
                'status_text' => 'Connected',
                'details' => $response
            ]);
        } catch (Exception $e) {
            // Log detailed error information
            error_log('CiviCRM Connection Test Failed: ' . $e->getMessage());

            WC_CiviCRM_Logger::log_error('test_connection', [
                'message' => 'CiviCRM Connection Test Failed',
                'error' => $e->getMessage(),
                'url' => $this->civicrm_url
            ]);

            // Store connection status
            update_option('wc_civicrm_connection_status', [
                'status' => 'disconnected',
                'last_checked' => time(),
                'message' => $e->getMessage()
            ]);

            wp_send_json_error([
                'message' => 'Connection failed: ' . $e->getMessage(),
                'status' => 'disconnected',
                'status_text' => 'Disconnected',
                'debug_info' => 'Please check your API URL and authentication token'
            ], 400);
        }
        exit;
    }

    /**
     * Send a direct API request to CiviCRM
     * 
     * @param string $endpoint The full API endpoint URL
     * @param array $data The request data
     * @return array The API response
     */
    protected function send_api_request($endpoint, $data)
    {
        // Set up the API request
        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->auth_token
            ],
            'body' => json_encode($data),
            'timeout' => 30,
            'sslverify' => apply_filters('wc_civicrm_sslverify', true)
        ];

        // Log the request if debug mode is enabled
        if (get_option('wc_civicrm_debug_mode', false)) {
            error_log('CiviCRM API Request: ' . $endpoint);
            error_log('Request Data: ' . wp_json_encode($data, JSON_PRETTY_PRINT));
        }

        // Make the request
        $response = wp_remote_post($endpoint, $args);

        // Handle response errors
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Log the response if debug mode is enabled
        if (get_option('wc_civicrm_debug_mode', false)) {
            error_log('CiviCRM API Response Code: ' . $response_code);
            error_log('CiviCRM API Response: ' . $response_body);
        }

        if ($response_code !== 200) {
            throw new Exception('API returned error: ' . $response_body);
        }

        $result = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from API');
        }

        return $result;
    }

    public function fetch_available_fields()
    {
        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');

            // Fetch Contact fields
            $contact_fields = $this->send_civicrm_request('Contact', 'getFields', [
                'select' => ['name', 'label', 'data_type'],
                'where' => [],
                'checkPermissions' => false
            ]);

            if (!empty($contact_fields['values'])) {
                $this->available_fields['Contact'] = array_map(function ($field) {
                    if (!is_array($field) || empty($field['name'])) {
                        return null;
                    }
                    return [
                        'name' => $field['name'] ?? '',
                        'label' => $field['label'] ?? $field['name'],
                        'type' => 'Contact'
                    ];
                }, $contact_fields['values']);
                $this->available_fields['Contact'] = array_filter($this->available_fields['Contact']);
            }


            // Fetch Contribution fields
            $contribution_fields = $this->send_civicrm_request('Contribution', 'getFields', [
                'select' => ['name', 'label', 'data_type'],
                'where' => [],
                'checkPermissions' => false
            ]);

            if (!empty($contribution_fields['values'])) {
                $this->available_fields['Contribution'] = array_map(function ($field) {
                    if (!is_array($field) || empty($field['name'])) {
                        return null;
                    }
                    return [
                        'name' => $field['name'] ?? '',
                        'label' => $field['label'] ?? $field['name'],
                        'type' => 'Contribution'
                    ];
                }, $contribution_fields['values']);
                $this->available_fields['Contribution'] = array_filter($this->available_fields['Contribution']);
            }
        } catch (Exception $e) {
            WC_CiviCRM_Logger::log_error('fetch_fields', [
                'message' => 'Failed to fetch CiviCRM fields',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function fetch_fields()
    {
        check_ajax_referer('fetch_civicrm_fields');

        try {
            $this->fetch_available_fields();
            wp_send_json_success([
                'contact_fields' => $this->available_fields['Contact'] ?? [],
                'contribution_fields' => $this->available_fields['Contribution'] ?? []
            ]);
        } catch (Exception $e) {
            wp_send_json_error('Failed to fetch fields: ' . $e->getMessage());
        }
    }

    public function test_contact_creation()
    {
        // Verify nonce and user capabilities
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_contact_creation') || !current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Unauthorized access',
                'debug_info' => 'Invalid nonce or insufficient permissions'
            ], 403);
            exit;
        }

        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');

            // Validate credentials are not empty
            if (empty($this->civicrm_url) || empty($this->auth_token)) {
                throw new Exception('CiviCRM URL or Authentication Token is missing');
            }

            // Prepare endpoint for contact creation
            $contact_endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contact/create';
            $email_endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Email/create';

            // Prepare test contact data
            $test_contact_data = [
                'contact_type' => 'Individual',
                'first_name' => 'Test',
                'last_name' => 'Contact_' . time(),
                'email' => 'test_contact_' . time() . '@example.com',
                'job_title' => 'Test Engineer'
            ];

            // Prepare the contact creation request
            $contact_fields = [
                'values' => [
                    'contact_type' => $test_contact_data['contact_type'],
                    'first_name' => $test_contact_data['first_name'],
                    'last_name' => $test_contact_data['last_name'],
                    'job_title' => $test_contact_data['job_title']
                ],
                'checkPermissions' => false,
                '_endpoint' => $contact_endpoint
            ];

            // Create contact first
            $contact_response = $this->send_civicrm_request('Contact', 'create', $contact_fields);

            // Validate the contact response
            if (!isset($contact_response['values']) || empty($contact_response['values'])) {
                throw new Exception('Failed to create test contact');
            }

            $contact_id = $contact_response['values'][0]['id'];

            // Create email
            $email_fields = [
                'values' => [
                    'contact_id' => $contact_id,
                    'email' => $test_contact_data['email']
                ],
                'checkPermissions' => false,
                '_endpoint' => $email_endpoint
            ];

            $email_response = $this->send_civicrm_request('Email', 'create', $email_fields);

            
            // Log responses for debugging
            error_log('Contact Creation Response: ' . json_encode($contact_response, JSON_PRETTY_PRINT));
            error_log('Email Creation Response: ' . json_encode($email_response, JSON_PRETTY_PRINT));
         
            wp_send_json_success([
                'message' => 'Test contact created successfully',
                'contact_details' => [
                    'id' => $contact_id,
                    'first_name' => $test_contact_data['first_name'],
                    'last_name' => $test_contact_data['last_name'],
                    'email_primary' => $test_contact_data['email']
                ]
            ]);
        } catch (Exception $e) {
            // Log detailed error information
            error_log('CiviCRM Test Contact Creation Failed: ' . $e->getMessage());

            WC_CiviCRM_Logger::log_error('test_contact_creation', [
                'message' => 'Test Contact Creation Failed',
                'error' => $e->getMessage(),
                'url' => $this->civicrm_url
            ]);

            wp_send_json_error([
                'message' => 'Contact creation failed: ' . $e->getMessage(),
                'debug_info' => 'Please check your CiviCRM API configuration'
            ], 400);
        }
        exit;
    }

    public function test_contribution_creation()
    {
        // Verify nonce and user capabilities
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_contribution_creation') || !current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Unauthorized access',
                'debug_info' => 'Invalid nonce or insufficient permissions'
            ], 403);
            exit;
        }

        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');

            // Validate credentials are not empty
            if (empty($this->civicrm_url) || empty($this->auth_token)) {
                throw new Exception('CiviCRM URL or Authentication Token is missing');
            }

            // Prepare endpoint for contribution creation
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contribution/create';

            // Prepare test contribution data
            $test_contribution_data = [
                'contact_id' => 18224,
                // 'contact_id' => $this->get_test_contact_id(), // Get an existing contact ID
                'financial_type_id' => 1, // Donation (default financial type)
                'total_amount' => 50.00,
                'currency' => 'EUR',
                'contribution_status_id' => 1, // Completed
                'payment_instrument_id' => 1, // Credit Card (adjust as needed)
            ];

            // Prepare the API request
            $civi_fields = [
                'values' => $test_contribution_data,
                'checkPermissions' => false,
                '_endpoint' => $endpoint
            ];

            // Attempt to create test contribution
            $response = $this->send_civicrm_request('Contribution', 'create', $civi_fields);

            // Validate the response
            if (!isset($response['values']) || empty($response['values'])) {
                throw new Exception('Failed to create test contribution');
            }

            // Extract created contribution details
            $created_contribution = $response['values'][0];

            wp_send_json_success([
                'message' => 'Test contribution created successfully',
                'contribution_details' => [
                    'id' => $created_contribution['id'],
                    'contact_id' => $test_contribution_data['contact_id'],
                    'total_amount' => $test_contribution_data['total_amount'],
                    'currency' => $test_contribution_data['currency']
                ]
            ]);
        } catch (Exception $e) {
            // Log detailed error information
            error_log('CiviCRM Test Contribution Creation Failed: ' . $e->getMessage());

            WC_CiviCRM_Logger::log_error('test_contribution_creation', [
                'message' => 'Test Contribution Creation Failed',
                'error' => $e->getMessage(),
                'url' => $this->civicrm_url
            ]);

            wp_send_json_error([
                'message' => 'Contribution creation failed: ' . $e->getMessage(),
                'debug_info' => 'Please check your CiviCRM API configuration'
            ], 400);
        }
        exit;
    }

    private function get_test_contact_id()
    {
        // First, try to get the last created test contact
        $contact_id = get_option('wc_civicrm_test_contact_id');

        if (!$contact_id) {
            // If no test contact exists, create one
            $test_contact_data = [
                'contact_type' => 'Individual',
                'first_name' => 'Test',
                'last_name' => 'Contributor_' . time()
            ];

            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contact/create';

            $response = $this->send_civicrm_request('Contact', 'create', [
                'values' => $test_contact_data,
                'checkPermissions' => false,
                '_endpoint' => $endpoint
            ]);

            if (!isset($response['values']) || empty($response['values'])) {
                throw new Exception('Failed to create test contact for contribution');
            }

            $contact_id = $response['values'][0]['id'];
            update_option('wc_civicrm_test_contact_id', $contact_id);
        }

        return $contact_id;
    }

    /**
     * Check connection status on page load
     */
    public function check_connection_status() {
        $status = get_option('wc_civicrm_connection_status', [
            'status' => 'unknown',
            'last_checked' => 0,
            'message' => 'Connection status unknown'
        ]);
        
        // If status is more than 15 minutes old, set to unknown
        if (time() - intval($status['last_checked']) > 900) {
            $status['status'] = 'unknown';
            $status['message'] = 'Connection status has not been checked recently';
        }
        
        return $status;
    }
}

// Initialize settings
new WC_CiviCRM_Settings();
