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

use WC_Shipping_Rate;

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
	}

	/**
	 * Modify shipping rates based on distance
	 */
	public function modifyShippingRates(array $rates, array $package): array
	{
		if (empty($rates)) {
			return $rates;
		}

		// Get delivery address from package
		$address = $this->getAddressFromPackage($package);

		// If no address or geocoding fails, keep the method but don't update price
		if (empty($address)) {
			return $rates; // Keep default rates
		}

		// Use geocoder to get coordinates
		$geocoder     = new Geocoder();
		$locationData = $geocoder->geocodeAddress($address);

		if (!$locationData) {
			return $rates; // Keep default rates if geocoding fails
		}

		// Calculate distance
		$distance = $this->distanceCalculator->calculateDistanceFromStore(
			$locationData['lat'],
			$locationData['lng']
		);

		foreach ($rates as $rateId => $rate) {
			if ($this->isDistanceBasedShippingRate($rate)) {
				$rates[$rateId] = $this->updateRateWithDistance($rate, $distance);
			}
		}

		return $rates;
	}


	/**
	 * Check if shipping rate should use distance-based pricing
	 */
	private function isDistanceBasedShippingRate(WC_Shipping_Rate $rate): bool
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
	private function updateRateWithDistance(WC_Shipping_Rate $rate, float $distance): WC_Shipping_Rate
	{
		// Always update label with distance info, regardless of availability
		$originalLabel = $rate->get_label();

		// Get shipping class based on distance
		$shippingClassId = $this->distanceCalculator->getShippingClassByDistance($distance);

		if (false === $shippingClassId) {
			// Distance too far - show unavailable message in label
			$newLabel = sprintf(
				'%s (%s: %s, %s: %.1fkm)',
				$originalLabel,
				__('zone', 'outcomer-delivery-distance'),
				__('unavailable', 'outcomer-delivery-distance'),
				__('distance', 'outcomer-delivery-distance'),
				$distance
			);
			$rate->set_label($newLabel);

			// Add meta data for debugging
			$rate->add_meta_data('_outcomer_distance', $distance);
			$rate->add_meta_data('_outcomer_available', false);

			return $rate;
		}

		// Get price for this shipping class
		$calculatedPrice = $this->distanceCalculator->getPriceByDistance($distance);

		if (false !== $calculatedPrice) {
			// Update rate cost only if we have valid price
			$rate->set_cost($calculatedPrice);
		}

		// Update rate label to include distance and zone info
		$zone     = $this->distanceCalculator->getDeliveryZone($distance);
		$newLabel = sprintf(
			'%s (%s: %s, %s: %.1fkm)',
			$originalLabel,
			__('zone', 'outcomer-delivery-distance'),
			$zone,
			__('distance', 'outcomer-delivery-distance'),
			$distance
		);
		$rate->set_label($newLabel);

		// Add meta data for debugging
		$rate->add_meta_data('_outcomer_distance', $distance);
		$rate->add_meta_data('_outcomer_shipping_class_id', $shippingClassId);
		$rate->add_meta_data('_outcomer_calculated_price', $calculatedPrice);
		$rate->add_meta_data('_outcomer_zone', $zone);
		$rate->add_meta_data('_outcomer_available', true);

		return $rate;
	}

	/**
	 * Get address from package
	 */
	private function getAddressFromPackage(array $package): string
	{
		$destination = $package['destination'] ?? [];

		$addressParts = [];

		if (!empty($destination['address'])) {
			$addressParts[] = $destination['address'];
		}
		if (!empty($destination['city'])) {
			$addressParts[] = $destination['city'];
		}
		if (!empty($destination['postcode'])) {
			$addressParts[] = $destination['postcode'];
		}

		// Add country from settings
		$countries = ODD_COUNTRY_RESTRICT;
		if (!empty($countries)) {
			$addressParts[] = reset($countries);
		}

		return trim(implode(', ', array_filter($addressParts)));
	}
}
