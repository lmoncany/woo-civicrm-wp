<?php
    if (!defined('WP_UNINSTALL_PLUGIN')) {
      die;
    }

    // Remove options
    delete_option('wc_civicrm_url');
    delete_option('wc_civicrm_auth_token');
    delete_option('wc_civicrm_field_mappings');

    // Remove log file
    $log_file = plugin_dir_path(__FILE__) . 'wc-civicrm.log';
    if (file_exists($log_file)) {
      unlink($log_file);
    }
    ?>
