<?php

namespace OutcomerDelivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Main Plugin class
 */
class Plugin {
	
	private DistanceCalculator $distanceCalculator;
	private Geocoder $geocoder;
	private AjaxHandler $ajaxHandler;
	private CheckoutHandler $checkoutHandler;
	private ShippingCalculator $shippingCalculator;
	
	public function __construct() {
		$this->distanceCalculator = new DistanceCalculator();
		$this->geocoder = new Geocoder();
		$this->ajaxHandler = new AjaxHandler($this->geocoder, $this->distanceCalculator);
		$this->checkoutHandler = new CheckoutHandler($this->geocoder, $this->distanceCalculator);
		$this->shippingCalculator = new ShippingCalculator($this->distanceCalculator);
	}
	
	/**
	 * Initialize the plugin
	 */
	public function init(): void {
		$this->ajaxHandler->init();
		$this->checkoutHandler->init();
		$this->shippingCalculator->init();
		
		add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
	}
	
	/**
	 * Enqueue scripts and styles
	 */
	public function enqueueScripts(): void {
		if (!is_checkout()) {
			return;
		}
		
		// Google Maps JavaScript API
		wp_enqueue_script(
			'google-maps',
			'https://maps.googleapis.com/maps/api/js?key=' . ODD_GOOGLE_API_KEY . '&libraries=places',
			[],
			null,
			true
		);
		
		// Checkout autocomplete script
		wp_enqueue_script(
			'outcomer-checkout-autocomplete',
			ODD_PLUGIN_URL . 'assets/js/checkout-autocomplete.js',
			['jquery', 'google-maps'],
			ODD_VERSION,
			true
		);
		
		// Localize script with data
		wp_localize_script('outcomer-checkout-autocomplete', 'outcomerDelivery', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('outcomer_delivery_nonce'),
			'apiKey' => ODD_GOOGLE_API_KEY,
			'countryRestrict' => ODD_COUNTRY_RESTRICT,
		]);
	}
}