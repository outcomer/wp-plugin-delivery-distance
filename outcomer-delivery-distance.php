<?php // phpcs:ignore Symfony.Files.AlphanumericFilename.Invalid

/**
 * Plugin Name: Outcomer Delivery Distance
 * Plugin URI: https://outcomer.com
 * Description: Google address autocomplete and distance-based delivery pricing for WooCommerce
 * Version: 1.0.0
 * Author: Outcomer
 * Text Domain: outcomer-delivery-distance
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * WC requires at least: 10.0
 * WC tested up to: 10.5
 */

if (!defined('ABSPATH')) {
	exit;
}

define('ODD_PLUGIN_FILE', __FILE__);
define('ODD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ODD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ODD_VERSION', '1.0.0');

// Configuration constants
// TODO: Create admin settings page and store these values in database instead of hardcoding
defined('ODD_GOOGLE_API_KEY_BROWSER') || define('ODD_GOOGLE_API_KEY_BROWSER', 'YOUR_GOOGLE_API_KEY_BROWSER'); // For JavaScript (with referer restrictions)
defined('ODD_GOOGLE_API_KEY_SERVER') || define('ODD_GOOGLE_API_KEY_SERVER', 'YOUR_GOOGLE_API_KEY_SERVER'); // For server-side Geocoding (with IP restrictions)
defined('ODD_STORE_LAT') || define('ODD_STORE_LAT', 0.0);
defined('ODD_STORE_LNG') || define('ODD_STORE_LNG', 0.0);
defined('ODD_COUNTRY_RESTRICT') || define('ODD_COUNTRY_RESTRICT', ['US' => 'United States']);
defined('ODD_DEFAULT_SHIPPING_INSTANCE_IDS') || define('ODD_DEFAULT_SHIPPING_INSTANCE_IDS', []);
defined('ODD_ENABLED_SHIPPING_INSTANCE_IDS') || define('ODD_ENABLED_SHIPPING_INSTANCE_IDS', []);
defined('ODD_DEBUG') || define('ODD_DEBUG', false);

// Distance range matchers for shipping class slugs
define('ODD_DISTANCE_MATCHERS', [
	// Pattern => callback that returns [min_km, max_km] or false
	'/^(\d+)-(\d+)-km$/' => fn($matches) => [
		'min' => (float) $matches[1],
		'max' => (float) $matches[2],
	],
]);

// Show admin notice about hardcoded configuration
add_action('admin_init', function () {
	$needsConfig = (
		ODD_GOOGLE_API_KEY_BROWSER === 'YOUR_GOOGLE_API_KEY_BROWSER' ||
		ODD_GOOGLE_API_KEY_SERVER === 'YOUR_GOOGLE_API_KEY_SERVER' ||
		ODD_STORE_LAT === 0.0 ||
		ODD_STORE_LNG === 0.0 ||
		empty(ODD_DEFAULT_SHIPPING_INSTANCE_IDS) ||
		empty(ODD_ENABLED_SHIPPING_INSTANCE_IDS)
	);

	if ($needsConfig) {
		add_action('admin_notices', function () {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php _e('Outcomer Delivery Distance: Plugin configuration is incomplete. Please define the required constants in wp-config.php or directly in the plugin file. TODO: Create settings page for this.', 'outcomer-delivery-distance'); ?></p>
			</div>
			<?php
		});
	}
});

/**
 * Check if WooCommerce is active
 */
function checkWooCommerceRequirement(): bool
{
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', function () {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php _e('Outcomer Delivery Distance requires WooCommerce to be installed and active.', 'outcomer-delivery-distance'); ?></p>
			</div>
			<?php
		});

		return false;
	}

	return true;
}

// Initialize plugin
add_action('plugins_loaded', function () {
	// Load text domain
	load_plugin_textdomain('outcomer-delivery-distance', false, dirname(plugin_basename(__FILE__)).'/languages');

	if (!checkWooCommerceRequirement()) {
		return;
	}

	// Load autoloader and main plugin class
	require_once ODD_PLUGIN_DIR.'includes/autoload.php';
	require_once ODD_PLUGIN_DIR.'includes/Plugin.php';

	// Initialize plugin
	$plugin = new \OutcomerDelivery\Plugin();
	$plugin->init();
});

// Activation hook
register_activation_hook(__FILE__, function () {
	if (!checkWooCommerceRequirement()) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(__('This plugin requires WooCommerce to be installed and active.', 'outcomer-delivery-distance'));
	}

	flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
	flush_rewrite_rules();
});
