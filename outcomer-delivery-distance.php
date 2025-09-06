<?php
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
define('ODD_GOOGLE_API_KEY', 'AIzaSyA-gpgh1bFH3RM0277dI38fNDWDkvY8F7M');
define('ODD_STORE_LAT', 50.0707499);
define('ODD_STORE_LNG', 14.4583567);
define('ODD_COUNTRY_RESTRICT', ['CZ']);
define('ODD_ENABLED_SHIPPING_INSTANCE_IDS', [2]);

// Distance pricing tiers (in km => price in CZK)
define('ODD_DISTANCE_PRICING', [
	1           => 100,   // < 1km
	3           => 150,   // 1-3km
	6           => 160,   // 3-6km
	PHP_INT_MAX => false, // > 6km - no delivery
]);

/**
 * Load plugin textdomain for translations
 */
function loadOutcomerDeliveryTextdomain(): void
{
	load_plugin_textdomain(
		'outcomer-delivery-distance',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
}

add_action('plugins_loaded', 'loadOutcomerDeliveryTextdomain');

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
	if (!checkWooCommerceRequirement()) {
		return;
	}

	// Load text domain
	load_plugin_textdomain('outcomer-delivery-distance', false, dirname(plugin_basename(__FILE__)).'/languages');

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
