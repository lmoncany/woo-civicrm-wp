<?php
if (!defined('WPINC')) {
    die;
}

class WC_CiviCRM_Logs_Page {
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Handle clear logs action
        if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
            check_admin_referer('clear_logs', 'clear_logs_nonce');
            
            try {
                WC_CiviCRM_Logger::clear_logs();
                
                // Maintain current view state in redirect
                $redirect_args = [
                    'page' => 'wc-civicrm-logs',
                    'cleared' => '1'
                ];
                
                // Preserve current filters and view state
                if (isset($_GET['include_rotated'])) {
                    $redirect_args['include_rotated'] = '1';
                }
                if (!empty($_GET['type'])) {
                    $redirect_args['type'] = sanitize_text_field($_GET['type']);
                }
                if (!empty($_GET['event'])) {
                    $redirect_args['event'] = sanitize_text_field($_GET['event']);
                }
                if (isset($_GET['paged']) && $_GET['paged'] > 1) {
                    $redirect_args['paged'] = intval($_GET['paged']);
                }
                
                wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
                exit;
            } catch (Exception $e) {
                add_settings_error(
                    'wc_civicrm_logs',
                    'clear_logs_error',
                    'Failed to clear logs: ' . $e->getMessage(),
                    'error'
                );
            }
        }
        
        // Show messages
        settings_errors('wc_civicrm_logs');
        
        // Show success message if logs were cleared
        if (isset($_GET['cleared']) && !get_settings_errors('wc_civicrm_logs')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Logs have been cleared successfully.', 'wc-civicrm'); ?></p>
            </div>
            <?php
        }
        
        // Get logs directory
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/wc-civicrm-logs';
        $log_file = $logs_dir . '/integration.log';
        
        // Get logs including rotated files
        $include_rotated = isset($_GET['include_rotated']) ? (bool)$_GET['include_rotated'] : false;
        $logs = WC_CiviCRM_Logger::get_logs($include_rotated);
        
        // Handle filtering
        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $event_filter = isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '';
        
        // Get unique types and events for filters
        $types = [];
        $events = [];
        foreach ($logs as $log) {
            if (!empty($log['type'])) {
                $types[$log['type']] = ucfirst($log['type']);
            }
            if (!empty($log['event'])) {
                $events[$log['event']] = $log['event'];
            }
        }
        
        // Apply filters
        if (!empty($type_filter) || !empty($event_filter)) {
            $logs = array_filter($logs, function($log) use ($type_filter, $event_filter) {
                if (!empty($type_filter) && $log['type'] !== $type_filter) {
                    return false;
                }
                if (!empty($event_filter) && $log['event'] !== $event_filter) {
                    return false;
                }
                return true;
            });
        }
        
        // Sort logs by timestamp descending
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_items = count($logs);
        $total_pages = ceil($total_items / $per_page);
        
        $logs = array_slice($logs, ($current_page - 1) * $per_page, $per_page);
        
        // Render page
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="logs-header">
                <div class="logs-actions">
                    <form method="post" style="display: inline-block;">
                        <?php wp_nonce_field('clear_logs', 'clear_logs_nonce'); ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="button" onclick="return confirm('Are you sure you want to clear all logs?');">
                            Clear Logs
                        </button>
                    </form>
                    
                    <form method="get" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="page" value="wc-civicrm-logs">
                        <?php if (!empty($type_filter)): ?>
                            <input type="hidden" name="type" value="<?php echo esc_attr($type_filter); ?>">
                        <?php endif; ?>
                        <?php if (!empty($event_filter)): ?>
                            <input type="hidden" name="event" value="<?php echo esc_attr($event_filter); ?>">
                        <?php endif; ?>
                        <?php if (isset($_GET['paged'])): ?>
                            <input type="hidden" name="paged" value="<?php echo intval($_GET['paged']); ?>">
                        <?php endif; ?>
                        <label>
                            <input type="checkbox" name="include_rotated" value="1" <?php checked($include_rotated); ?>>
                            Include Rotated Logs
                        </label>
                        <button type="submit" class="button">Update View</button>
                    </form>
                </div>

                <form method="get" class="logs-filters">
                    <input type="hidden" name="page" value="wc-civicrm-logs">
                    <?php if ($include_rotated): ?>
                        <input type="hidden" name="include_rotated" value="1">
                    <?php endif; ?>
                    
                    <select name="type" class="log-filter">
                        <option value="">All Types</option>
                        <?php foreach ($types as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $type_filter); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="event" class="log-filter">
                        <option value="">All Events</option>
                        <?php foreach ($events as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $event_filter); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="button">Filter</button>
                    <?php if (!empty($type_filter) || !empty($event_filter)): ?>
                        <a href="?page=wc-civicrm-logs<?php echo $include_rotated ? '&include_rotated=1' : ''; ?>" class="button">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <div class="logs-container">
                <?php if (empty($logs)): ?>
                    <p>No logs found.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Type</th>
                                <th>Event</th>
                                <th>Message</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($log['timestamp']))); ?></td>
                                    <td>
                                        <span class="log-type <?php echo esc_attr($log['type']); ?>">
                                            <?php echo esc_html(ucfirst($log['type'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($log['event']); ?></td>
                                    <td><?php echo esc_html($log['message']); ?></td>
                                    <td>
                                        <?php if (!empty($log['data'])): ?>
                                            <button type="button" class="button toggle-details">
                                                Show Details
                                            </button>
                                            <div class="log-details" style="display: none;">
                                                <pre><?php echo esc_html(json_encode($log['data'], JSON_PRETTY_PRINT)); ?></pre>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($total_pages > 1): ?>
                        <div class="tablenav">
                            <div class="tablenav-pages">
                                <span class="displaying-num">
                                    <?php echo sprintf(_n('%s item', '%s items', $total_items), number_format_i18n($total_items)); ?>
                                </span>
                                
                                <span class="pagination-links">
                                    <?php
                                    echo paginate_links([
                                        'base' => add_query_arg('paged', '%#%'),
                                        'format' => '',
                                        'prev_text' => __('&laquo;'),
                                        'next_text' => __('&raquo;'),
                                        'total' => $total_pages,
                                        'current' => $current_page
                                    ]);
                                    ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .logs-header {
                margin: 20px 0;
            }
            .logs-filters {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .log-filter {
                min-width: 150px;
            }
            .logs-container {
                margin-top: 20px;
            }
            .tablenav {
                margin: 20px 0;
            }
            .log-type {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
            }
            .log-type.success {
                background-color: #dff0d8;
                color: #3c763d;
            }
            .log-type.error {
                background-color: #f2dede;
                color: #a94442;
            }
            .log-details {
                margin-top: 10px;
                padding: 10px;
                background: #f9f9f9;
                border: 1px solid #e5e5e5;
                border-radius: 3px;
            }
            .log-details pre {
                margin: 0;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.toggle-details').click(function() {
                var $button = $(this);
                var $details = $button.next('.log-details');
                
                if ($details.is(':visible')) {
                    $details.slideUp();
                    $button.text('Show Details');
                } else {
                    $details.slideDown();
                    $button.text('Hide Details');
                }
            });
        });
        </script>
        <?php
    }
}
