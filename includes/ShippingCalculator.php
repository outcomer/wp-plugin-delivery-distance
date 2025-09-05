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
 * Calculate shipping costs based on distance
 */
class ShippingCalculator
{
	private DistanceCalculator $distanceCalculator;

	/**
	 * Constructor.
	 */
	public function __construct(DistanceCalculator $distanceCalculator)
	{
		$this->distanceCalculator = $distanceCalculator;
	}

	/**
	 * Initialize shipping hooks
	 */
	public function init(): void
	{
		add_filter('woocommerce_package_rates', [$this, 'modifyShippingRates'], 10, 2);
		add_action('woocommerce_checkout_update_order_review', [$this, 'updateOrderReview']);
	}

	/**
	 * Modify shipping rates based on distance
	 */
	public function modifyShippingRates(array $rates, array $package): array
	{
		if (empty($rates)) {
			return $rates;
		}

		$deliveryData = $this->getDeliveryDataFromSession();

		if (!$deliveryData) {
			return $rates;
		}

		foreach ($rates as $rateId => $rate) {
			if ($this->isDistanceBasedShippingRate($rate)) {
				$rates[$rateId] = $this->updateRateWithDistancePrice($rate, $deliveryData);
			}
		}

		return $rates;
	}

	/**
	 * Handle order review updates
	 */
	public function updateOrderReview($postedData): void
	{
		// Parse posted data to get delivery information
		parse_str($postedData, $data);

		$deliveryLat      = $data['outcomer_delivery_lat'] ?? null;
		$deliveryLng      = $data['outcomer_delivery_lng'] ?? null;
		$deliveryDistance = $data['outcomer_delivery_distance'] ?? null;
		$deliveryPrice    = $data['outcomer_delivery_price'] ?? null;

		if ($deliveryLat && $deliveryLng && $deliveryDistance && $deliveryPrice) {
			// Store in session for use in shipping rate calculation
			WC()->session->set('outcomer_delivery_data', [
				'lat'       => (float) $deliveryLat,
				'lng'       => (float) $deliveryLng,
				'distance'  => (float) $deliveryDistance,
				'price'     => (int) $deliveryPrice,
				'timestamp' => time(),
			]);
		}
	}

	/**
	 * Clear delivery data from session
	 */
	public function clearDeliveryData(): void
	{
		WC()->session->__unset('outcomer_delivery_data');
	}

	/**
	 * Get available delivery zones info
	 */
	public static function getDeliveryZonesInfo(): array
	{
		$zones      = [];
		$zoneNumber = 1;

		foreach (ODD_DISTANCE_PRICING as $maxDistance => $price) {
			if (false === $price) {
				break; // Skip "no delivery" zone
			}

			$prevDistance = 0;
			if ($zoneNumber > 1) {
				$keys         = array_keys(ODD_DISTANCE_PRICING);
				$prevDistance = $keys[$zoneNumber - 2];
			}

			$zones[] = [
				'zone'          => $zoneNumber,
				'distance_from' => $prevDistance,
				'distance_to'   => $maxDistance,
				'price'         => $price,
			];

			$zoneNumber++;
		}

		return $zones;
	}

	/**
	 * Check if shipping rate should use distance-based pricing
	 */
	private function isDistanceBasedShippingRate(\WC_Shipping_Rate $rate): bool
	{
		// Extract instance ID from rate ID
		$parts = explode(':', $rate->get_id());

		if (count($parts) >= 2) {
			$instanceId = (int) $parts[1];

			return in_array($instanceId, ODD_ENABLED_SHIPPING_INSTANCE_IDS);
		}

		return false;
	}

	/**
	 * Update rate with distance-based price
	 */
	private function updateRateWithDistancePrice(\WC_Shipping_Rate $rate, array $deliveryData): \WC_Shipping_Rate
	{
		$distance        = $deliveryData['distance'];
		$calculatedPrice = $this->distanceCalculator->getPriceByDistance($distance);

		if (false === $calculatedPrice) {
			// Distance too far - hide this shipping method
			$rate->add_meta_data('_outcomer_delivery_unavailable', true);

			return $rate;
		}

		// Update rate cost
		$rate->set_cost($calculatedPrice);

		// Update rate label to include distance info
		$originalLabel = $rate->get_label();
		$zone          = $this->distanceCalculator->getDeliveryZone($distance);
		$newLabel      = sprintf(
			'%s (Distance: %.1fkm, Zone: %d)',
			$originalLabel,
			$distance,
			$zone
		);
		$rate->set_label($newLabel);

		// Add meta data for debugging
		$rate->add_meta_data('_outcomer_distance', $distance);
		$rate->add_meta_data('_outcomer_zone', $zone);
		$rate->add_meta_data('_outcomer_calculated_price', $calculatedPrice);

		return $rate;
	}

	/**
	 * Get delivery data from session or other sources
	 */
	private function getDeliveryDataFromSession(): array|null
	{
		// Try to get from WooCommerce session first
		$sessionData = WC()->session->get('outcomer_delivery_data');

		if ($sessionData && is_array($sessionData)) {
			// Check if data is not too old (5 minutes)
			if (isset($sessionData['timestamp']) && (time() - $sessionData['timestamp']) < 300) {
				return $sessionData;
			}
		}

		// Try to get from POST data (during AJAX update)
		if (!empty($_POST['outcomer_delivery_distance']) && !empty($_POST['outcomer_delivery_price'])) {
			return [
				'lat'       => (float) ($_POST['outcomer_delivery_lat'] ?? 0),
				'lng'       => (float) ($_POST['outcomer_delivery_lng'] ?? 0),
				'distance'  => (float) $_POST['outcomer_delivery_distance'],
				'price'     => (int) $_POST['outcomer_delivery_price'],
				'timestamp' => time(),
			];
		}

		return null;
	}
}
