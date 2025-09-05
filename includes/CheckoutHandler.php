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
		add_filter('woocommerce_checkout_fields', [$this, 'modifyCheckoutFields']);
		add_action('woocommerce_after_checkout_validation', [$this, 'validateCheckoutAddress'], 10, 2);
		add_action('woocommerce_checkout_create_order', [$this, 'saveDeliveryData'], 10, 2);
		add_action('woocommerce_checkout_process', [$this, 'processDeliveryData']);
		add_filter('woocommerce_form_field_text', [$this, 'addMessageContainerBeforeAddressField'], 10, 4);
	}

	/**
	 * Modify checkout fields to add data attributes for autocomplete
	 */
	public function modifyCheckoutFields(array $fields): array
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
			if (isset($fields['billing'][$fieldKey])) {
				$fields['billing'][$fieldKey]['custom_attributes']['data-outcomer-autocomplete'] = 'true';
			}
			if (isset($fields['shipping'][$fieldKey])) {
				$fields['shipping'][$fieldKey]['custom_attributes']['data-outcomer-autocomplete'] = 'true';
			}
		}

		return $fields;
	}

	/**
	 * Validate address during checkout
	 */
	public function validateCheckoutAddress(array $data, \WP_Error $errors): void
	{
		// Get delivery data from hidden fields
		$deliveryLat      = $_POST['outcomer_delivery_lat'] ?? null;
		$deliveryLng      = $_POST['outcomer_delivery_lng'] ?? null;
		$deliveryDistance = $_POST['outcomer_delivery_distance'] ?? null;
		$deliveryPrice    = $_POST['outcomer_delivery_price'] ?? null;

		// Check if we have delivery data (means JavaScript validation passed)
		if (!$deliveryLat || !$deliveryLng) {
			// Fallback: validate address manually
			$this->fallbackAddressValidation($data, $errors);

			return;
		}

		// Validate delivery data
		$distance = (float) $deliveryDistance;
		$price    = $this->distanceCalculator->getPriceByDistance($distance);

		if (false === $price) {
			$errors->add(
				'delivery_unavailable',
				sprintf(__('Delivery is not available for this address. Distance: %.2fkm (max 6km)', 'outcomer-delivery-distance'), $distance)
			);
		}

		// Check if selected shipping method requires distance validation
		if (!$this->isDistanceBasedShippingSelected()) {
			return; // Skip validation if distance-based shipping is not selected
		}

		// Validate that calculated price matches expected
		if ((int) $deliveryPrice !== $price) {
			$errors->add(
				'delivery_price_mismatch',
				__('Delivery price calculation error. Please refresh and try again.', 'outcomer-delivery-distance')
			);
		}
	}

	/**
	 * Save delivery data to order meta
	 */
	public function saveDeliveryData(\WC_Order $order, array $data): void
	{
		$deliveryLat      = $_POST['outcomer_delivery_lat'] ?? null;
		$deliveryLng      = $_POST['outcomer_delivery_lng'] ?? null;
		$deliveryDistance = $_POST['outcomer_delivery_distance'] ?? null;
		$deliveryZone     = $_POST['outcomer_delivery_zone'] ?? null;

		if ($deliveryLat && $deliveryLng) {
			$order->update_meta_data('_outcomer_delivery_lat', sanitize_text_field($deliveryLat));
			$order->update_meta_data('_outcomer_delivery_lng', sanitize_text_field($deliveryLng));
			$order->update_meta_data('_outcomer_delivery_distance', sanitize_text_field($deliveryDistance));
			$order->update_meta_data('_outcomer_delivery_zone', sanitize_text_field($deliveryZone));
			$order->update_meta_data('_outcomer_delivery_calculated', current_time('mysql'));
		}
	}

	/**
	 * Process delivery data during checkout
	 */
	public function processDeliveryData(): void
	{
		if (!$this->isDistanceBasedShippingSelected()) {
			return;
		}

		// Additional processing if needed
		// For example, logging, external API calls, etc.
	}

	/**
	 * Add delivery message container before billing_address_1 field
	 */
	public function addMessageContainerBeforeAddressField(string $field, string $key, array $args, string $value): string
	{
		// Only add container before billing_address_1 field
		if ('billing_address_1' === $key) {
			$container = '<p id="outcomer-delivery-messages" class="form-row" style="min-height: 50px; margin: 10px 0; display: none;"></p>';
			$field     = $container.$field;
		}

		return $field;
	}

	/**
	 * Fallback validation when JavaScript data is not available
	 */
	private function fallbackAddressValidation(array $data, \WP_Error $errors): void
	{
		if (!$this->isDistanceBasedShippingSelected()) {
			return;
		}

		$address = $this->buildAddressString($data);

		if (empty($address)) {
			$errors->add(
				'address_required',
				__('Please provide a valid address for delivery calculation.', 'outcomer-delivery-distance')
			);

			return;
		}

		// Validate address with geocoder
		$locationData = $this->geocoder->geocodeAddress($address);

		if (!$locationData) {
			$errors->add(
				'address_invalid',
				__('Unable to validate the provided address. Please check and try again.', 'outcomer-delivery-distance')
			);

			return;
		}

		// Calculate distance
		$distance = $this->distanceCalculator->calculateDistanceFromStore(
			$locationData['lat'],
			$locationData['lng']
		);

		if (!$this->distanceCalculator->isWithinDeliveryRange($distance)) {
			$errors->add(
				'delivery_unavailable_fallback',
				sprintf(__('Delivery is not available for this address. Distance: %.2fkm (max 6km)', 'outcomer-delivery-distance'), $distance)
			);
		}
	}

	/**
	 * Check if distance-based shipping method is selected
	 */
	private function isDistanceBasedShippingSelected(): bool
	{
		$chosenMethods = WC()->session->get('chosen_shipping_methods');

		if (empty($chosenMethods)) {
			return false;
		}

		foreach ($chosenMethods as $method) {
			// Extract instance ID from method (e.g., "flat_rate:2" -> "2")
			$parts = explode(':', $method);
			if (count($parts) >= 2) {
				$instanceId = (int) $parts[1];
				if (in_array($instanceId, ODD_ENABLED_SHIPPING_INSTANCE_IDS)) {
					return true;
				}
			}
		}

		return false;
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

		$addressParts[] = 'Czech Republic'; // Force country

		return trim(implode(', ', array_filter($addressParts)));
	}
}
