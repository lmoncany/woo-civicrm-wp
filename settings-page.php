<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
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

        wp_enqueue_script(
            'wc-civicrm-admin-scripts',
            plugin_dir_url(__FILE__) . 'admin-scripts.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('wc-civicrm-admin-scripts', 'wc_civicrm_admin_params', [
            'test_connection_nonce' => wp_create_nonce('test_civicrm_connection'),
            'test_contact_creation_nonce' => wp_create_nonce('test_contact_creation'),
            'test_contribution_creation_nonce' => wp_create_nonce('test_contribution_creation')
        ]);
    }

    /**
     * Render connection status test button and result container
     */
    public function connection_status_callback()
    {
?>
        <div class="connection-test-container">
            <button id="test-civicrm-connection" class="button button-secondary">
                <?php _e('Test CiviCRM Connection', 'woo-civicrm-wp'); ?>
            </button>
            <div id="connection-test-result"></div>
        </div>
        <button id="test-contribution-creation" class="button button-secondary">
            Test Contribution Creation
        </button>
        <div id="contribution-creation-test-result"></div>

    <?php
    }

    /**
     * Render contact creation test button and result container
     */
    public function contact_creation_callback()
    {
    ?>
        <div class="contact-creation-test-container">
            <button id="test-contact-creation" class="button button-secondary">
                <?php _e('Test Contact Creation', 'woo-civicrm-wp'); ?>
            </button>
            <div id="contact-creation-test-result"></div>
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


        // Connection Settings Section
        add_settings_section(
            'wc_civicrm_main',
            'CiviCRM Connection Settings',
            [$this, 'section_callback'],
            'wc-civicrm-settings'
        );

        add_settings_field(
            'wc_civicrm_url',
            'CiviCRM API URL',
            [$this, 'url_callback'],
            'wc-civicrm-settings',
            'wc_civicrm_main'
        );

        add_settings_field(
            'wc_civicrm_auth_token',
            'API Authentication Token',
            [$this, 'auth_token_callback'],
            'wc-civicrm-settings',
            'wc_civicrm_main'
        );

        add_settings_field(
            'wc_civicrm_connection_status',
            'Connection Status',
            [$this, 'connection_status_callback'],
            'wc-civicrm-settings',
            'wc_civicrm_main'
        );

        // Debug Settings Section
        add_settings_section(
            'wc_civicrm_debug',
            'Debug Settings',
            function () {
                echo '<p>Configure debug and logging options.</p>';
            },
            'wc-civicrm-settings'
        );

        add_settings_field(
            'wc_civicrm_debug_mode',
            'Debug Mode',
            [$this, 'debug_mode_callback'],
            'wc-civicrm-settings',
            'wc_civicrm_debug'
        );

        // Field Mappings Section
        add_settings_section(
            'wc_civicrm_mappings',
            'Field Mappings',
            function () {
                echo '<p>Configure how WooCommerce fields map to CiviCRM fields.</p>';
            },
            'wc-civicrm-settings'
        );

        add_settings_field(
            'wc_civicrm_field_mappings',
            'Field Mappings',
            [$this, 'field_mappings_callback'],
            'wc-civicrm-settings',
            'wc_civicrm_mappings'
        );

        // Testing Section
        add_settings_section(
            'wc_civicrm_testing',
            'CiviCRM Integration Tests',
            function () {
                echo '<p>Verify your CiviCRM integration settings.</p>';
            },
            'wc-civicrm-settings'
        );

        add_settings_field(
            'wc_civicrm_connection_test',
            'Connection Test',
            [$this, 'connection_status_callback'],
            'wc-civicrm-settings',
            'wc_civicrm_testing'
        );

        add_settings_field(
            'wc_civicrm_contact_creation_test',
            'Contact Creation Test',
            [$this, 'contact_creation_callback'],
            'wc-civicrm-settings',
            'wc_civicrm_testing'
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
        echo '<p>Configure the connection between WooCommerce and CiviCRM.</p>';
    }

    public function url_callback()
    {
        $url = get_option('wc_civicrm_url');
    ?>
        <input type="url" name="wc_civicrm_url" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="https://example.com">
        <p class="description">Enter your CiviCRM site URL (e.g., https://example.com)</p>
    <?php
    }

    public function auth_token_callback()
    {
        $token = get_option('wc_civicrm_auth_token');
    ?>
        <input type="password" name="wc_civicrm_auth_token" value="<?php echo esc_attr($token); ?>" class="regular-text">
        <p class="description">Enter your CiviCRM API authentication token</p>
    <?php
    }

    public function debug_mode_callback()
    {
        $debug_mode = get_option('wc_civicrm_debug_mode', false);
    ?>
        <label>
            <input type="checkbox" name="wc_civicrm_debug_mode" value="1" <?php checked($debug_mode, true); ?>>
            Enable detailed logging
        </label>
        <p class="description">When enabled, all API requests and responses will be logged with full details.</p>
    <?php
    }

    public function field_mappings_callback()
    {
        $mappings = get_option('wc_civicrm_field_mappings', []);
        $wc_fields = $this->get_woocommerce_fields();
    ?>
        <form method="post" action="options.php">
            <?php settings_fields('wc_civicrm_settings'); ?>
            <div id="field-mappings-container">
                <div class="field-mappings-container">
                    <div class="field-mappings-header">
                        <h3>Field Mappings</h3>
                        <p>Map WooCommerce fields to CiviCRM fields. Click "Fetch Available Fields" to get the latest field list from CiviCRM.</p>
                    </div>

                    <table class="form-table" id="field-mappings-table">
                        <thead>
                            <tr>
                                <th>WooCommerce Field</th>
                                <th>CiviCRM Field</th>
                                <th>Field Type</th>
                                <th></th>
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
                                    <td class="field-type"><?php echo esc_html($field_type); ?></td>
                                    <td>
                                        <button type="button" class="button remove-mapping">Remove</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="field-mappings-actions">
                        <button type="button" id="add-mapping" class="button">Add New Mapping</button>
                        <button type="button" id="fetch-fields" class="button">Fetch Available Fields</button>
                        <span id="fetch-result"></span>
                    </div>

                    <style>
                        .field-mappings-container {
                            margin: 20px 0;
                            padding: 20px;
                            background: #fff;
                            border: 1px solid #ccd0d4;
                            box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
                        }

                        .field-mappings-header {
                            margin-bottom: 20px;
                        }

                        .field-mappings-header h3 {
                            margin: 0 0 10px;
                        }

                        .field-mappings-actions {
                            margin-top: 20px;
                            display: flex;
                            gap: 10px;
                            align-items: center;
                        }

                        #field-mappings-table select {
                            width: 100%;
                        }

                        .field-type {
                            color: #666;
                            font-size: 12px;
                        }

                        #fetch-result {
                            margin-left: 10px;
                        }

                        #fetch-result .success {
                            color: #46b450;
                        }

                        #fetch-result .error {
                            color: #dc3232;
                        }
                    </style>
                </div>
            </div>
            <?php submit_button('Save Mappings'); ?>
        </form>
        <script>
            jQuery(document).ready(function($) {
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
                            <td class="field-type"></td>
                            <td>
                                <button type="button" class="button remove-mapping">Remove</button>
                            </td>
                        </tr>`;
                }

                $('#add-mapping').click(function() {
                    $('#field-mappings-table tbody').append(getNewRow());
                });

                $(document).on('click', '.remove-mapping', function() {
                    $(this).closest('tr').remove();
                });
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

            // Modify the send_civicrm_request method to use the instance URL
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contact/get';

            // Test connection using Contact.get with full URL
            $response = $this->send_civicrm_request('Contact', 'get', [
                'select' => ['id'],
                'checkPermissions' => false,
                'limit' => 1,
                '_endpoint' => $endpoint  // Pass the full endpoint
            ]);

            // Additional validation of the response
            if (!isset($response['values']) || empty($response['values'])) {
                throw new Exception('Invalid response from CiviCRM API');
            }

            // // Log successful connection
            // WC_CiviCRM_Logger::log_info('test_connection', [
            //     'message' => 'CiviCRM Connection Test Successful',
            //     'url' => $this->civicrm_url
            // ]);

            wp_send_json_success([
                'message' => 'Connection successful',
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

            wp_send_json_error([
                'message' => 'Connection failed: ' . $e->getMessage(),
                'debug_info' => 'Please check your API URL and authentication token'
            ], 400);
        }
        exit;
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
}

// Initialize settings
new WC_CiviCRM_Settings();
