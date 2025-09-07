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

use WC_Order;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handle checkout integration and validation
 */
class CheckoutHandler
{
	private Geocoder $geocoder;
	private DistanceCalculator $distanceCalculator;

	/**
	 * Constructor.
	 */
	public function __construct(Geocoder $geocoder, DistanceCalculator $distanceCalculator)
	{
		$this->geocoder           = $geocoder;
		$this->distanceCalculator = $distanceCalculator;
	}

	/**
	 * Initialize checkout hooks
	 */
	public function init(): void
	{
		add_filter('woocommerce_checkout_fields', [$this, 'modifyCheckoutFields'], 1010);
		add_action('woocommerce_after_checkout_validation', [$this, 'validateCheckoutAddress'], 10, 2);
		add_action('woocommerce_checkout_create_order', [$this, 'saveDeliveryData'], 10, 2);
		add_action('woocommerce_before_checkout_form', [$this, 'addAutocompleteTemplates']);
	}

	/**
	 * Modify checkout fields to add data attributes for autocomplete
	 */
	public function modifyCheckoutFields(array $formFields): array
	{
		// Add data attributes to address fields for JavaScript targeting
		$addressFields = [
			'billing_address_1',
			'billing_city',
			'billing_postcode',
			'shipping_address_1',
			'shipping_city',
			'shipping_postcode',
		];

		foreach ($addressFields as $fieldKey) {
			if (isset($formFields['billing'][$fieldKey])) {
				$formFields['billing'][$fieldKey]['custom_attributes']['data-outcomer-autocomplete'] = 'true';
				$formFields['billing'][$fieldKey]['autocomplete'] = 'off';
			}
			if (isset($formFields['shipping'][$fieldKey])) {
				$formFields['shipping'][$fieldKey]['custom_attributes']['data-outcomer-autocomplete'] = 'true';
				$formFields['shipping'][$fieldKey]['autocomplete'] = 'off';
			}
		}

		return $formFields;
	}

	/**
	 * Validate address during checkout
	 */
	public function validateCheckoutAddress(array $data, WP_Error $errors): void
	{
		// Check if selected shipping method requires distance validation
		if (!ShippingChecker::isDistanceBasedShippingSelected()) {
			return; // Skip validation if distance-based shipping is not selected
		}

		// Always validate address on server side for security
		$address = $this->buildAddressString($data);

		if (empty($address)) {
			$errors->add(
				'address_required',
				__('Please provide a valid address for delivery calculation.', 'outcomer-delivery-distance')
			);

			return;
		}

		// Server-side geocoding and distance calculation
		$locationData = $this->geocoder->geocodeAddress($address);

		if (!$locationData) {
			$errors->add(
				'address_invalid',
				__('Unable to validate the provided address. Please check and try again.', 'outcomer-delivery-distance')
			);

			return;
		}

		// Calculate distance on server
		$distance = $this->distanceCalculator->calculateDistanceFromStore(
			$locationData['lat'],
			$locationData['lng']
		);

		// Check if delivery is available
		if (!$this->distanceCalculator->isWithinDeliveryRange($distance)) {
			$errors->add(
				'delivery_unavailable',
				sprintf(__('Delivery is not available for this address. Distance: %.2fkm (max 6km)', 'outcomer-delivery-distance'), $distance)
			);

			return;
		}

		// Store server-calculated data for order saving
		$_POST['_server_delivery_lat']      = $locationData['lat'];
		$_POST['_server_delivery_lng']      = $locationData['lng'];
		$_POST['_server_delivery_distance'] = $distance;
		$_POST['_server_delivery_zone']     = $this->distanceCalculator->getDeliveryZone($distance);
	}

	/**
	 * Save delivery data to order meta
	 */
	public function saveDeliveryData(WC_Order $order, array $data): void
	{
		// Only save delivery data if distance-based shipping is selected
		if (!ShippingChecker::isDistanceBasedShippingSelected()) {
			return;
		}

		// Use server-calculated data for security
		$deliveryLat      = $_POST['_server_delivery_lat'] ?? null;
		$deliveryLng      = $_POST['_server_delivery_lng'] ?? null;
		$deliveryDistance = $_POST['_server_delivery_distance'] ?? null;
		$deliveryZone     = $_POST['_server_delivery_zone'] ?? null;

		if ($deliveryLat && $deliveryLng) {
			$order->update_meta_data('_outcomer_delivery_lat', sanitize_text_field((string) $deliveryLat));
			$order->update_meta_data('_outcomer_delivery_lng', sanitize_text_field((string) $deliveryLng));
			$order->update_meta_data('_outcomer_delivery_distance', sanitize_text_field((string) $deliveryDistance));
			$order->update_meta_data('_outcomer_delivery_zone', sanitize_text_field((string) $deliveryZone));
			$order->update_meta_data('_outcomer_delivery_calculated', current_time('mysql'));
		}
	}

	/**
	 * Add HTML templates for autocomplete elements
	 */
	public function addAutocompleteTemplates(): void
	{
		?>
		<!-- Outcomer Autocomplete Templates -->
		<div id="outcomer-autocomplete-templates" style="display: none;">
			<!-- Wrapper template -->
			<div class="outcomer-autocomplete-wrapper">
				<div class="outcomer-search-icon">
					<svg width="25" height="24" viewBox="0 0 25 24" fill="none">
						<path fill-rule="evenodd" clip-rule="evenodd" d="M15.56 13.27L21.29 19l-1.49 1.49-5.73-5.73A6.42 6.42 0 0110.3 16a6.5 6.5 0 116.5-6.5c0 1.41-.47 2.7-1.24 3.77zM10.3 5a4.5 4.5 0 10-.01 8.99A4.5 4.5 0 0010.3 5z" fill="#5E5E5E"></path>
					</svg>
				</div>
				<!-- Input will be moved here -->
				<button type="button" class="outcomer-clear-button" aria-label="Clear input" style="display: none;">
					<svg width="21" height="20" viewBox="0 0 21 20" fill="none">
						<path fill-rule="evenodd" clip-rule="evenodd" d="M10.8 0a10 10 0 100 20 10 10 0 100-20zm2.59 6L10.8 8.59 8.21 6 6.8 7.41 9.39 10 6.8 12.59 8.21 14l2.59-2.59L13.39 14l1.41-1.41L12.21 10l2.59-2.59L13.39 6zM2.8 10a8.01 8.01 0 0016 0 8.01 8.01 0 00-16 0z" fill="#1F1F1F"></path>
					</svg>
				</button>
				<div class="outcomer-autocomplete-suggestions" style="display: none;"></div>
			</div>
			
			<!-- Suggestion item template -->
			<div class="outcomer-autocomplete-item-template">
				<div class="outcomer-autocomplete-item">
					<div class="outcomer-autocomplete-item-icon">
						<svg viewBox="0 -960 960 960" class="place-autocomplete-element-prediction-item-icon">
							<path d="M480-480q33 0 56.5-23.5T560-560q0-33-23.5-56.5T480-640q-33 0-56.5 23.5T400-560q0 33 23.5 56.5T480-480zm0 294q122-112 181-203.5T720-552q0-109-69.5-178.5T480-800q-101 0-170.5 69.5T240-552q0 71 59 162.5T480-186zm0 106Q319-217 239.5-334.5T160-552q0-150 96.5-239T480-880q127 0 223.5 89T800-552q0 100-79.5 217.5T480-80zm0-480z"></path>
						</svg>
					</div>
					<div class="outcomer-autocomplete-item-text">
						<div class="outcomer-autocomplete-item-main-text"></div>
						<div class="outcomer-autocomplete-item-secondary-text"></div>
					</div>
				</div>
			</div>
			
			<!-- Powered by footer template -->
			<div class="outcomer-powered-by-template">
				<div class="outcomer-powered-by">
					<span><?php echo esc_html(__('Powered by', 'outcomer-delivery-distance')); ?></span>
					<span class="outcomer-brand">
						<span class="letter-o1">O</span><span class="letter-u">u</span><span class="letter-t">t</span><span class="letter-c">c</span><span class="letter-o2">o</span><span class="letter-m">m</span><span class="letter-e">e</span><span class="letter-r">r</span>
					</span>
				</div>
			</div>
		</div>
		<?php
	}


	/**
	 * Build address string from checkout data
	 */
	private function buildAddressString(array $data): string
	{
		$addressParts = [];

		// Use shipping address if different, otherwise billing
		$useShipping = !empty($data['ship_to_different_address']);

		if ($useShipping) {
			$addressParts[] = $data['shipping_address_1'] ?? '';
			$addressParts[] = $data['shipping_city'] ?? '';
			$addressParts[] = $data['shipping_postcode'] ?? '';
		} else {
			$addressParts[] = $data['billing_address_1'] ?? '';
			$addressParts[] = $data['billing_city'] ?? '';
			$addressParts[] = $data['billing_postcode'] ?? '';
		}

		// Add country from settings
		$countries = ODD_COUNTRY_RESTRICT;
		if (!empty($countries)) {
			$addressParts[] = reset($countries); // Берем первое значение (название страны)
		}

		return trim(implode(', ', array_filter($addressParts)));
	}
}
