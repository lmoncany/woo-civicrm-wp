<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_CiviCRM_Settings {
    use WC_CiviCRM_API_Request;
    
    public $available_fields = [];
    public $civicrm_url;
    public $auth_token;

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_test_civicrm_connection', [$this, 'test_connection']);
        add_action('wp_ajax_fetch_civicrm_fields', [$this, 'fetch_fields']);
        
        // Try to fetch fields if we have connection settings
        $url = get_option('wc_civicrm_url');
        $token = get_option('wc_civicrm_auth_token');
        if (!empty($url) && !empty($token)) {
            $this->fetch_available_fields();
        }
    }

    public function test_connection() {
        check_ajax_referer('test_civicrm_connection');
        
        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');

            // Test connection using System.get
            $response = $this->send_civicrm_request('System', 'get', [
                'select' => ['id'],
                'checkPermissions' => false
            ]);
            
            wp_send_json_success('Connection successful');
        } catch (Exception $e) {
            wp_send_json_error('Connection failed: ' . $e->getMessage());
        }
    }

    public function fetch_available_fields() {
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
            
            if (!empty($contact_fields)) {
                $this->available_fields['Contact'] = array_map(function($field) {
                    if (!is_array($field) || empty($field['name'])) {
                        return null;
                    }
                    return [
                        'name' => $field['name'] ?? '',
                        'label' => $field['label'] ?? $field['name'],
                        'type' => $field['data_type'] ?? 'String'
                    ];
                }, $contact_fields);
                $this->available_fields['Contact'] = array_filter($this->available_fields['Contact']);
            }

            // Fetch Contribution fields
            $contribution_fields = $this->send_civicrm_request('Contribution', 'getFields', [
                'select' => ['name', 'label', 'data_type'],
                'where' => [],
                'checkPermissions' => false
            ]);
            
            if (!empty($contribution_fields)) {
                $this->available_fields['Contribution'] = array_map(function($field) {
                    if (!is_array($field) || empty($field['name'])) {
                        return null;
                    }
                    return [
                        'name' => $field['name'] ?? '',
                        'label' => $field['label'] ?? $field['name'],
                        'type' => $field['data_type'] ?? 'String'
                    ];
                }, $contribution_fields);
                $this->available_fields['Contribution'] = array_filter($this->available_fields['Contribution']);
            }

        } catch (Exception $e) {
            WC_CiviCRM_Logger::log_error('fetch_fields', [
                'message' => 'Failed to fetch CiviCRM fields',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function fetch_fields() {
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

    public function register_settings() {
        register_setting('wc_civicrm_settings', 'wc_civicrm_url');
        register_setting('wc_civicrm_settings', 'wc_civicrm_auth_token');
        register_setting('wc_civicrm_settings', 'wc_civicrm_field_mappings');
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
            function() {
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
            function() {
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
    }

    public function section_callback() {
        echo '<p>Configure the connection between WooCommerce and CiviCRM.</p>';
    }

    public function url_callback() {
        $url = get_option('wc_civicrm_url');
        ?>
        <input type="url" name="wc_civicrm_url" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="https://example.com">
        <p class="description">Enter your CiviCRM site URL (e.g., https://example.com)</p>
        <?php
    }

    public function auth_token_callback() {
        $token = get_option('wc_civicrm_auth_token');
        ?>
        <input type="password" name="wc_civicrm_auth_token" value="<?php echo esc_attr($token); ?>" class="regular-text">
        <p class="description">Enter your CiviCRM API authentication token</p>
        <?php
    }

    public function connection_status_callback() {
        $status = (new WooCommerceCiviCRMIntegration())->check_civicrm_connection();
        $status_text = $status ? 'Connected' : 'Not Connected';
        $status_class = $status ? 'connected' : 'not-connected';
        ?>
        <div class="connection-status-wrapper">
            <span class="connection-status <?php echo esc_attr($status_class); ?>">
                <?php echo esc_html($status_text); ?>
            </span>
            <button type="button" id="test-connection" class="button">Test Connection</button>
            <button type="button" id="fetch-fields" class="button" <?php echo !$status ? 'disabled' : ''; ?>>Fetch Available Fields</button>
            <span id="connection-result"></span>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#test-connection').click(function() {
                var button = $(this);
                var result = $('#connection-result');
                
                button.prop('disabled', true).text('Testing...');
                result.html('');
                
                $.post(ajaxurl, {
                    action: 'test_civicrm_connection',
                    _ajax_nonce: '<?php echo wp_create_nonce("test_civicrm_connection"); ?>'
                })
                .done(function(response) {
                    if (response.success) {
                        result.html('<span class="success">✓ Connection successful</span>');
                        $('#fetch-fields').prop('disabled', false);
                    } else {
                        result.html('<span class="error">✗ ' + response.data + '</span>');
                        $('#fetch-fields').prop('disabled', true);
                    }
                })
                .fail(function() {
                    result.html('<span class="error">✗ Connection failed</span>');
                    $('#fetch-fields').prop('disabled', true);
                })
                .always(function() {
                    button.prop('disabled', false).text('Test Connection');
                });
            });

            $('#fetch-fields').click(function() {
                var button = $(this);
                var result = $('#connection-result');
                
                button.prop('disabled', true).text('Fetching...');
                result.html('');
                    } else {
                        result.html('<span class="error">✗ ' + response.data + '</span>');
                        $('#fetch-fields').prop('disabled', true);
                    }
                })
                .fail(function() {
                    result.html('<span class="error">✗ Connection failed</span>');
                    $('#fetch-fields').prop('disabled', true);
                })
                .always(function() {
                    button.prop('disabled', false).text('Test Connection');
                });
            });

            $('#fetch-fields').click(function() {
                var button = $(this);
                var result = $('#connection-result');
                
                button.prop('disabled', true).text('Fetching...');
                result.html('');
                
                $.post(ajaxurl, {
                    action: 'fetch_civicrm_fields',
                    _ajax_nonce: '<?php echo wp_create_nonce("fetch_civicrm_fields"); ?>'
                })
                .done(function(response) {
                    if (response.success) {
                        result.html('<span class="success">✓ Fields fetched successfully</span>');
                        updateFieldOptions(response.data);
                    } else {
                        result.html('<span class="error">✗ ' + response.data + '</span>');
                    }
                })
                .fail(function() {
                    result.html('<span class="error">✗ Failed to fetch fields</span>');
                })
                .always(function() {
                    button.prop('disabled', false).text('Fetch Available Fields');
                });
            });

            function updateFieldOptions(fields) {
                $('.civicrm-field-select').each(function() {
                    var select = $(this);
                    var currentValue = select.val();
                    var typeCell = select.closest('tr').find('.field-type');
                    
                    select.empty();
                    select.append('<option value="">Select CiviCRM Field</option>');
                    
                    // Group fields by type
                    var fieldsByType = {};
                    fields.forEach(function(field) {
                        if (!fieldsByType[field.type]) {
                            fieldsByType[field.type] = [];
                        }
                        fieldsByType[field.type].push(field);
                    });
                    
                    // Sort field types alphabetically
                    Object.keys(fieldsByType).sort().forEach(function(type) {
                        var group = $('<optgroup></optgroup>')
                            .attr('label', type + ' Fields');
                        
                        // Sort fields within each group by title
                        fieldsByType[type].sort((a, b) => a.title.localeCompare(b.title))
                            .forEach(function(field) {
                                var option = $('<option></option>')
                                    .attr('value', field.name)
                                    .attr('data-type', field.type)
                                    .text(field.title + ' (' + field.name + ')');
                                if (field.name === currentValue) {
                                    option.prop('selected', true);
                                    typeCell.text(field.type);
                                }
                                group.append(option);
                            });
                        
                        select.append(group);
                    });
                });

                // Add change handler for field type display and storage
                $('.civicrm-field-select').off('change').on('change', function() {
                    var select = $(this);
                    var selected = select.find('option:selected');
                    var type = selected.attr('data-type') || '';
                    var row = select.closest('tr');
                    
                    // Update type display
                    row.find('.field-type').text(type);
                    
                    // Update hidden type input
                    row.find('.field-type-input').val(type);
                    
                    // Update select attributes
                    select.attr('data-original-value', selected.val());
                    select.attr('data-original-type', type);
                });

                // Restore original values if available
                $('.civicrm-field-select').each(function() {
                    var select = $(this);
                    var originalValue = select.attr('data-original-value');
                    var originalType = select.attr('data-original-type');
                    
                    if (originalValue && originalType) {
                        select.closest('tr').find('.field-type').text(originalType);
                        select.closest('tr').find('.field-type-input').val(originalType);
                    }
                });
            }
        });
        </script>
        <style>
            .connection-status {
                padding: 5px 10px;
                border-radius: 3px;
                color: white;
                display: inline-block;
                margin-right: 10px;
            }
            .connected { background-color: #46b450; }
            .not-connected { background-color: #dc3232; }
            #connection-result { margin-left: 10px; }
            #connection-result .success { color: #46b450; }
            #connection-result .error { color: #dc3232; }
            #fetch-fields { margin-left: 10px; }
        </style>
        <?php
    }

    public function debug_mode_callback() {
        $debug_mode = get_option('wc_civicrm_debug_mode', false);
        ?>
        <label>
            <input type="checkbox" name="wc_civicrm_debug_mode" value="1" <?php checked($debug_mode, true); ?>>
            Enable detailed logging
        </label>
        <p class="description">When enabled, all API requests and responses will be logged with full details.</p>
        <?php
    }

    public function field_mappings_callback() {
        $mappings = get_option('wc_civicrm_field_mappings', []);
        $wc_fields = $this->get_woocommerce_fields();
        ?>
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
                    $civicrm_field = is_array($mapping) ? $mapping['field'] : $mapping;
                    $field_type = is_array($mapping) ? $mapping['type'] : '';
                ?>
                <tr>
                  <td>
                    <select name="wc_civicrm_field_mappings[<?php echo esc_attr($wc_field); ?>][wc]" class="regular-text">
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
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
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
              #fetch-result .success { color: #46b450; }
              #fetch-result .error { color: #dc3232; }
            </style>
          </div>
        </div>
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
                                <select name="wc_civicrm_field_mappings[${rowId}][wc]" class="regular-text">
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

    public function get_woocommerce_fields() {
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

    public function ajax_fetch_fields() {
        check_ajax_referer('fetch_civicrm_fields');
        
        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');
            
            // Fetch Contact fields using API4 format
            $contact_fields = $this->send_civicrm_request('Contact', 'getFields', [
                'select' => ['name', 'label', 'data_type'],
                'where' => [],
                'checkPermissions' => false
            ]);
            
            // Fetch Contribution fields using API4 format
            $contribution_fields = $this->send_civicrm_request('Contribution', 'getFields', [
                'select' => ['name', 'label', 'data_type'],
                'where' => [],
                'checkPermissions' => false
            ]);
            
            $fields = [];
            
            // Process fields from API4 response
            if (!empty($contact_fields)) {
                foreach ($contact_fields as $field) {
                    if (!empty($field['name']) && !empty($field['label'])) {
                        $fields[] = [
                            'name' => $field['name'],
                            'title' => $field['label'],
                            'type' => 'Contact'
                        ];
                    }
                }
            }
            
            if (!empty($contribution_fields)) {
                foreach ($contribution_fields as $field) {
                    if (!empty($field['name']) && !empty($field['label'])) {
                        $fields[] = [
                            'name' => $field['name'],
                            'title' => $field['label'],
                            'type' => 'Contribution'
                        ];
                    }
                }
            }
            
            wp_send_json_success($fields);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}

// Initialize settings
new WC_CiviCRM_Settings();
