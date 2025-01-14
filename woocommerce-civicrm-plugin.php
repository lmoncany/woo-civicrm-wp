<?php
/*
    Plugin Name: WooCommerce to CiviCRM Integration
    Description: Automatically creates CiviCRM orders from WooCommerce orders
    Version: 1.0
    Author: Bolt
    Author URI: https://example.com
    License: GPLv2 or later
    Text Domain: wc-civicrm
    */

if (!defined('WPINC')) {
    die;
}

// Load required files
$plugin_dir = plugin_dir_path(__FILE__);
// Main plugin file should require all dependencies at the start
require_once plugin_dir_path(__FILE__) . 'logging.php';
require_once plugin_dir_path(__FILE__) . 'send-civicrm-request.php';
require_once plugin_dir_path(__FILE__) . 'settings-page.php';

// Check for required plugins
register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and active.', 'wc-civicrm'));
    }

    // Set default field mappings if not exists
    if (!get_option('wc_civicrm_field_mappings')) {
        // Customer Information
        $default_mappings = [
            // Basic Info - Contact Fields
            'billing_first_name' => ['field' => 'first_name', 'type' => 'Contact'],
            'billing_last_name' => ['field' => 'last_name', 'type' => 'Contact'],
            'billing_company' => ['field' => 'organization_name', 'type' => 'Contact'],
            'billing_email' => ['field' => 'email', 'type' => 'Contact'],
            'billing_phone' => ['field' => 'phone', 'type' => 'Contact'],

            // Billing Address - Contact Fields
            'billing_address_1' => ['field' => 'street_address', 'type' => 'Contact'],
            'billing_address_2' => ['field' => 'supplemental_address_1', 'type' => 'Contact'],
            'billing_city' => ['field' => 'city', 'type' => 'Contact'],
            'billing_state' => ['field' => 'state_province', 'type' => 'Contact'],
            'billing_postcode' => ['field' => 'postal_code', 'type' => 'Contact'],
            'billing_country' => ['field' => 'country', 'type' => 'Contact'],

            // Shipping Address - Contact Fields
            'shipping_first_name' => ['field' => 'shipping_first_name', 'type' => 'Contact'],
            'shipping_last_name' => ['field' => 'shipping_last_name', 'type' => 'Contact'],
            'shipping_company' => ['field' => 'shipping_organization', 'type' => 'Contact'],
            'shipping_address_1' => ['field' => 'shipping_street_address', 'type' => 'Contact'],
            'shipping_address_2' => ['field' => 'shipping_supplemental_address_1', 'type' => 'Contact'],
            'shipping_city' => ['field' => 'shipping_city', 'type' => 'Contact'],
            'shipping_state' => ['field' => 'shipping_state_province', 'type' => 'Contact'],
            'shipping_postcode' => ['field' => 'shipping_postal_code', 'type' => 'Contact'],
            'shipping_country' => ['field' => 'shipping_country', 'type' => 'Contact'],

            // Order Information - Contribution Fields
            'order_number' => ['field' => 'external_identifier', 'type' => 'Contribution'],
            'order_currency' => ['field' => 'currency', 'type' => 'Contribution'],
            'order_total' => ['field' => 'total_amount', 'type' => 'Contribution'],
            'order_status' => ['field' => 'contribution_status', 'type' => 'Contribution'],
            'payment_method' => ['field' => 'payment_instrument', 'type' => 'Contribution'],
            'order_notes' => ['field' => 'note', 'type' => 'Contribution']
        ];

        update_option('wc_civicrm_field_mappings', $default_mappings);
    }
});

// Initialize plugin only if WooCommerce is active
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        // Check for required files
        $plugin_dir = plugin_dir_path(__FILE__);
        $required_files = [
            $plugin_dir . 'logging.php' => 'Logging',
            $plugin_dir . 'send-civicrm-request.php' => 'API Request',
            $plugin_dir . 'settings-page.php' => 'Settings Page'
        ];

        $missing_files = [];
        foreach ($required_files as $file => $name) {
            if (!file_exists($file)) {
                $missing_files[] = $name;
            }
        }

        if (!empty($missing_files)) {
            add_action('admin_notices', function () use ($missing_files) {
                $list = implode(', ', $missing_files);
                echo '<div class="error"><p>Required files missing in WooCommerce to CiviCRM plugin: ' . $list . '</p></div>';
            });
            return;
        }

        // Load settings page after trait and logger are loaded
        require_once $plugin_dir . 'settings-page.php';

        // Initialize plugin after all dependencies are loaded
        new WooCommerceCiviCRMIntegration();
    } else {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>WooCommerce to CiviCRM plugin requires WooCommerce to be installed and active.</p></div>';
        });
    }
});

class WooCommerceCiviCRMIntegration
{
    use WC_CiviCRM_API_Request {
        WC_CiviCRM_API_Request::send_civicrm_request as protected;
    }

    private $civicrm_url;
    private $auth_token;
    private $field_mappings;

    private function load_settings()
    {
        $this->civicrm_url = get_option('wc_civicrm_url');
        $this->auth_token = get_option('wc_civicrm_auth_token');
        $this->field_mappings = get_option('wc_civicrm_field_mappings', []);
    }

    public function __construct()
    {
        $this->load_settings();

        // Order processing hooks
        add_action('woocommerce_order_status_changed', [$this, 'debug_order_status'], 10, 4);
        add_action('woocommerce_checkout_order_processed', [$this, 'debug_order_created'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'handle_order_completed']);
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_completed']);
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 4);

        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_test_civicrm_connection', [$this, 'ajax_test_connection']);
    }

    public function handle_status_change($order_id, $old_status, $new_status, $order)
    {
        WC_CiviCRM_Logger::log_success('status_change', [
            'message' => 'Order status change detected',
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'settings' => [
                'has_url' => !empty($this->civicrm_url),
                'has_token' => !empty($this->auth_token),
                'has_mappings' => !empty($this->field_mappings)
            ]
        ]);

        if ($new_status === 'completed') {
            $this->handle_order_completed($order_id);
        }
    }

    public function ajax_test_connection()
    {
        check_ajax_referer('test_civicrm_connection');

        try {
            WC_CiviCRM_Logger::log_success('connection_test', [
                'message' => 'Testing CiviCRM connection'
            ]);

            $result = $this->check_civicrm_connection();

            if ($result) {
                WC_CiviCRM_Logger::log_success('connection_test', [
                    'message' => 'Connection test successful'
                ]);
                wp_send_json_success('Connection successful');
            } else {
                WC_CiviCRM_Logger::log_error('connection_test', [
                    'message' => 'Connection test failed',
                    'error' => 'API check returned false'
                ]);
                wp_send_json_error('Connection failed - API check returned false');
            }
        } catch (Exception $e) {
            WC_CiviCRM_Logger::log_error('connection_test', [
                'message' => 'Connection test failed',
                'error' => $e->getMessage()
            ]);
            wp_send_json_error('Connection failed: ' . $e->getMessage());
        }
    }

    public function debug_order_status($order_id, $old_status, $new_status, $order)
    {
        WC_CiviCRM_Logger::log_success('debug_order_status', [
            'message' => 'Order status changed',
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'settings' => [
                'has_url' => !empty($this->civicrm_url),
                'has_token' => !empty($this->auth_token),
                'has_mappings' => !empty($this->field_mappings)
            ]
        ]);
    }

    public function debug_order_created($order_id)
    {
        WC_CiviCRM_Logger::log_success('debug_order_created', [
            'message' => 'New order created',
            'order_id' => $order_id,
            'settings' => [
                'has_url' => !empty($this->civicrm_url),
                'has_token' => !empty($this->auth_token),
                'has_mappings' => !empty($this->field_mappings)
            ]
        ]);
    }

    public function handle_order_completed($order_id)
    {
        try {
            // Check if CiviCRM settings are configured
            if (empty($this->civicrm_url) || empty($this->auth_token)) {
                WC_CiviCRM_Logger::log_error('order_processing', [
                    'message' => 'CiviCRM settings not configured',
                    'order_id' => $order_id,
                    'settings' => [
                        'has_url' => !empty($this->civicrm_url),
                        'has_token' => !empty($this->auth_token),
                        'has_mappings' => !empty($this->field_mappings)
                    ]
                ]);
                return;
            }

            WC_CiviCRM_Logger::log_success('order_processing', [
                'message' => 'Starting order processing',
                'order_id' => $order_id,
                'hook' => current_filter(),
                'settings' => [
                    'has_url' => !empty($this->civicrm_url),
                    'has_token' => !empty($this->auth_token),
                    'has_mappings' => !empty($this->field_mappings)
                ]
            ]);

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Order not found: ' . $order_id);
            }

            WC_CiviCRM_Logger::log_success('order_processing', [
                'message' => 'Extracting order data',
                'order_id' => $order_id
            ]);

            $order_data = $this->extract_order_data($order);

            WC_CiviCRM_Logger::log_success('order_processing', [
                'message' => 'Creating/updating contact',
                'order_id' => $order_id,
                'order_data' => $order_data
            ]);

            // First create/update contact
            $contact_id = $this->get_or_create_contact($order_data);

            WC_CiviCRM_Logger::log_success('order_processing', [
                'message' => 'Contact created/updated successfully',
                'order_id' => $order_id,
                'contact_id' => $contact_id
            ]);

            // Then create the order
            WC_CiviCRM_Logger::log_success('order_processing', [
                'message' => 'Creating CiviCRM contribution',
                'order_id' => $order_id,
                'contact_id' => $contact_id
            ]);

            $civi_order = $this->create_civicrm_order($contact_id, $order_data);

            WC_CiviCRM_Logger::log_success('order_completed', [
                'message' => 'Order processed successfully',
                'wc_order_id' => $order_id,
                'civicrm_contribution_id' => $civi_order['id'],
                'contact_id' => $contact_id,
                'status' => 'completed'
            ]);
        } catch (Exception $e) {
            WC_CiviCRM_Logger::log_error('order_failed', [
                'message' => 'Order processing failed',
                'order_id' => $order_id,
                'error' => $e->getMessage(),
                'data' => isset($order_data) ? $order_data : null,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to let WordPress handle it
        }
    }

    private function extract_order_data($order)
    {
        $data = [
            // Basic Info
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_company' => $order->get_billing_company(),
            'billing_email' => $order->get_billing_email(),
            'billing_phone' => $order->get_billing_phone(),

            // Billing Address
            'billing_address_1' => $order->get_billing_address_1(),
            'billing_address_2' => $order->get_billing_address_2(),
            'billing_city' => $order->get_billing_city(),
            'billing_state' => $order->get_billing_state(),
            'billing_postcode' => $order->get_billing_postcode(),
            'billing_country' => $order->get_billing_country(),

            // Shipping Address
            'shipping_first_name' => $order->get_shipping_first_name(),
            'shipping_last_name' => $order->get_shipping_last_name(),
            'shipping_company' => $order->get_shipping_company(),
            'shipping_address_1' => $order->get_shipping_address_1(),
            'shipping_address_2' => $order->get_shipping_address_2(),
            'shipping_city' => $order->get_shipping_city(),
            'shipping_state' => $order->get_shipping_state(),
            'shipping_postcode' => $order->get_shipping_postcode(),
            'shipping_country' => $order->get_shipping_country(),

            // Order Information
            'order_number' => $order->get_order_number(),
            'order_currency' => $order->get_currency(),
            'order_total' => $order->get_total(),
            'order_status' => $order->get_status(),
            'payment_method' => $order->get_payment_method(),
            'order_notes' => $order->get_customer_note(),

            // Additional fields for internal use
            'transaction_id' => $order->get_transaction_id(),
            'order_id' => $order->get_id()
        ];

        // Filter out empty values
        return array_filter($data, function ($value) {
            return !empty($value) || $value === 0 || $value === '0';
        });
    }

    private function create_civicrm_order($contact_id, $order_data)
    {
        // Map WooCommerce fields to CiviCRM fields
        $contribution_data = [];
        foreach ($this->field_mappings as $wc_field => $mapping) {
            $civicrm_field = is_array($mapping) ? $mapping['field'] : $mapping;
            $field_type = is_array($mapping) ? $mapping['type'] : '';

            if (isset($order_data[$wc_field]) && $field_type === 'Contribution') {
                $contribution_data[$civicrm_field] = $order_data[$wc_field];
            }
        }

        // Create contribution using API4 format
        $response = $this->send_civicrm_request('Contribution', 'create', [
            'values' => array_merge([
                'contact_id' => $contact_id,
                'financial_type_id' => 1, // Donation
                'payment_instrument_id' => 1, // Credit Card
                'receive_date' => date('Y-m-d H:i:s'),
                'source' => 'WooCommerce Order #' . $order_data['order_id'],
                'contribution_status_id' => 1, // Completed
                'is_pay_later' => 0,
            ], $contribution_data),
            'checkPermissions' => false
        ]);

        if (empty($response)) {
            throw new Exception('Failed to create contribution');
        }

        return $response[0];
    }

    private function get_or_create_contact($order_data)
    {
        try {
            // Map WooCommerce fields to CiviCRM fields
            $contact_data = [];
            foreach ($this->field_mappings as $wc_field => $mapping) {
                $civicrm_field = is_array($mapping) ? $mapping['field'] : $mapping;
                $field_type = is_array($mapping) ? $mapping['type'] : '';

                if (isset($order_data[$wc_field]) && $field_type === 'Contact') {
                    $contact_data[$civicrm_field] = $order_data[$wc_field];
                }
            }

            // Ensure required fields
            $contact_params = [
                'values' => array_merge([
                    'contact_type' => !empty($order_data['billing_company']) ? 'Organization' : 'Individual',
                ], $contact_data)
            ];

            // Try to find existing contact using API4 format
            $response = $this->send_civicrm_request('Contact', 'get', [
                'select' => ['id', 'contact_type'],
                'where' => [
                    ['email', '=', $order_data['billing_email']]
                ],
                'limit' => 1
            ]);

            if (!empty($response)) {
                $contact_id = $response[0]['id'];

                // Update existing contact using API4 format
                $this->send_civicrm_request('Contact', 'update', [
                    'where' => [['id', '=', $contact_id]],
                    'values' => $contact_data,
                    'checkPermissions' => false
                ]);

                return $contact_id;
            }

            // Create new contact using API4 format
            $response = $this->send_civicrm_request('Contact', 'create', [
                'values' => array_merge([
                    'contact_type' => !empty($order_data['billing_company']) ? 'Organization' : 'Individual'
                ], $contact_data),
                'checkPermissions' => false
            ]);

            return $response[0]['id'];
        } catch (Exception $e) {
            throw new Exception('Failed to get or create contact: ' . $e->getMessage());
        }
    }

    public function add_admin_pages()
    {
        // Add main menu
        add_menu_page(
            'WooCommerce CiviCRM',
            'WC CiviCRM',
            'manage_options',
            'wc-civicrm-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-generic'
        );

        // Add Settings submenu
        add_submenu_page(
            'wc-civicrm-settings',
            'WooCommerce CiviCRM Settings',
            'Settings',
            'manage_options',
            'wc-civicrm-settings'
        );

        // Add Logs submenu
        add_submenu_page(
            'wc-civicrm-settings',
            'CiviCRM Integration Logs',
            'Logs',
            'manage_options',
            'wc-civicrm-logs',
            function () {
                if (!class_exists('WC_CiviCRM_Logs_Page')) {
                    require_once plugin_dir_path(__FILE__) . 'logs-page.php';
                }
                $logs_page = new WC_CiviCRM_Logs_Page();
                $logs_page->render();
            }
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('wc_civicrm_settings');
                do_settings_sections('wc-civicrm-settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function check_civicrm_connection()
    {
        try {
            // Use System.get to check API access
            $response = $this->send_civicrm_request('System', 'get', [
                'select' => ['id'],
                'where' => [],
                'checkPermissions' => false
            ]);

            // API4 returns an array of results
            return is_array($response);
        } catch (Exception $e) {
            WC_CiviCRM_Logger::log_error('connection_test', [
                'message' => 'Connection test failed',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function show_admin_notices()
    {
        if (!empty($_GET['wc_civicrm_notice'])) {
            $notice = sanitize_text_field($_GET['wc_civicrm_notice']);
            $class = strpos($notice, 'error') !== false ? 'error' : 'updated';
        ?>
            <div class="notice <?php echo esc_attr($class); ?>">
                <p><?php echo esc_html(urldecode($notice)); ?></p>
            </div>
<?php
        }
    }

    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'wc-civicrm') !== false) {
            wp_enqueue_style(
                'wc-civicrm-admin',
                plugins_url('assets/css/admin.css', __FILE__)
            );
            wp_enqueue_script(
                'wc-civicrm-admin',
                plugins_url('assets/js/admin.js', __FILE__),
                ['jquery'],
                '1.0',
                true
            );
        }
    }
}
