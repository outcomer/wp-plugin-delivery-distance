<?php
/**
 * This file is part of the Outcomer package.
 *
 * (c) David Evdoshchenko <773021792e@gmail.com>
 *
 * @author David Evdoshchenko <773021792e@gmail.com>
 *
 * @package Outcomer\DeliveryDistance
 */

declare(strict_types = 1);

namespace OutcomerDelivery;

use OutcomerDelivery\shipping\ShippingValidator;
use OutcomerDelivery\shipping\ShippingMethodSelector;
use OutcomerDelivery\shipping\FragmentUpdater;
use OutcomerDelivery\shipping\ShippingCalculator;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Main Plugin class
 */
class Plugin
{
	private DistanceCalculator $distanceCalculator;
	private Geocoder $geocoder;
	private CheckoutHandler $checkoutHandler;
	private $shippingCalculator;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->distanceCalculator = new DistanceCalculator();
		$this->geocoder           = new Geocoder();
		$this->checkoutHandler    = new CheckoutHandler($this->geocoder, $this->distanceCalculator);

		// Initialize refactored shipping components
		$validator       = new ShippingValidator($this->distanceCalculator);
		$methodSelector  = new ShippingMethodSelector($validator);
		$fragmentUpdater = new FragmentUpdater();

		$this->shippingCalculator = new ShippingCalculator(
			$this->distanceCalculator,
			$validator,
			$methodSelector,
			$fragmentUpdater
		);
	}

	/**
	 * Initialize the plugin
	 */
	public function init(): void
	{
		$this->checkoutHandler->init();
		$this->shippingCalculator->init();

		add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
	}

	/**
	 * Enqueue scripts and styles
	 */
	public function enqueueScripts(): void
	{
		if (!is_checkout()) {
			return;
		}

		// Google Maps JavaScript API with async loading and callback
		// Updated to use v=beta for new Places API features
		wp_enqueue_script(
			'google-maps',
			'https://maps.googleapis.com/maps/api/js?key='.ODD_GOOGLE_API_KEY_BROWSER.'&libraries=places&v=beta&loading=async&callback=initGoogleMapsCallback',
			[],
			null,
			true
		);

		// Add async attribute to the script tag
		add_filter('script_loader_tag', function ($tag, $handle) {
			if ('google-maps' === $handle) {
				return str_replace(' src', ' async src', $tag);
			}

			return $tag;
		}, 10, 2);

		// Checkout autocomplete script
		$scriptFile    = ODD_PLUGIN_DIR.'assets/js/checkout-autocomplete.js';
		$scriptVersion = file_exists($scriptFile) ? filemtime($scriptFile) : ODD_VERSION;
		$scriptVersion = 1;

		wp_enqueue_script(
			'outcomer-checkout-autocomplete',
			ODD_PLUGIN_URL.'assets/js/checkout-autocomplete.js',
			[
				'jquery',
				'google-maps',
			],
			$scriptVersion,
			true
		);

		// Enqueue CSS
		$cssFile    = ODD_PLUGIN_DIR.'assets/css/checkout-autocomplete.css';
		$cssVersion = file_exists($cssFile) ? filemtime($cssFile) : ODD_VERSION;

		wp_enqueue_style(
			'outcomer-checkout-autocomplete',
			ODD_PLUGIN_URL.'assets/css/checkout-autocomplete.css',
			[],
			$cssVersion
		);

		// Localize script with data
		wp_localize_script('outcomer-checkout-autocomplete', 'outcomerDelivery', [
			'ajaxUrl'         => admin_url('admin-ajax.php'),
			'nonce'           => wp_create_nonce('outcomer_delivery_nonce'),
			'apiKey'          => ODD_GOOGLE_API_KEY_BROWSER,
			'countryRestrict' => array_keys(ODD_COUNTRY_RESTRICT),
			'currency'        => get_woocommerce_currency(),
		]);
	}
}
