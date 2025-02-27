# Changelog

All notable changes to the WooCommerce to CiviCRM Integration plugin will be documented in this file.

## 1.0.1 - 2023-10-01

### Changed

- Consolidated plugin entry points into a single file
- Removed duplicate `woo-civicrm-wp.php` in favor of `woocommerce-civicrm-plugin.php`
- Improved plugin header documentation
- Added proper version requirements for WooCommerce

### Fixed

- Resolved PHP syntax issues in admin pages
- Fixed potential class loading conflicts

## 1.0.0 - 2023-02-27

### Added

- Initial release of the plugin
- Two-way integration between WooCommerce and CiviCRM
- Automatic contact creation/update in CiviCRM when WooCommerce orders are placed
- Contribution creation in CiviCRM for WooCommerce orders
- Admin interface for configuration with field mapping
- Testing tools for connection, contact creation, and contribution creation
- Detailed logging system for debugging and monitoring
- Settings page for managing integration

### Changed

- N/A (initial release)

### Fixed

- N/A (initial release)

## Development

### Todos

- Standardize naming conventions across the plugin
- Reorganize file structure for better maintenance
- Add more comprehensive documentation
- Implement unit tests
