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

namespace OutcomerDelivery\shipping;

use OutcomerDelivery\DistanceCalculator;
use WC_Shipping_Rate;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Calculate shipping costs based on distance (refactored)
 */
class ShippingCalculator
{
	private DistanceCalculator $distanceCalculator;
	private ShippingValidator $validator;
	private ShippingMethodSelector $methodSelector;
	private FragmentUpdater $fragmentUpdater;

	/**
	 * Constructor.
	 */
	public function __construct(DistanceCalculator $distanceCalculator, ShippingValidator $validator, ShippingMethodSelector $methodSelector, FragmentUpdater $fragmentUpdater)
	{
		$this->distanceCalculator = $distanceCalculator;
		$this->validator          = $validator;
		$this->methodSelector     = $methodSelector;
		$this->fragmentUpdater    = $fragmentUpdater;
	}

	/**
	 * Initialize shipping hooks
	 */
	public function init(): void
	{
		add_filter('woocommerce_package_rates', [$this, 'modifyShippingRates'], 10, 2);
		add_filter('woocommerce_cart_shipping_method_full_label', [$this, 'wrapUnavailableLabel'], 10, 2);

		// Initialize sub-components
		$this->methodSelector->init();
		$this->fragmentUpdater->init();
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

		// Validate shipping to this address
		$validation = $this->validator->validateShippingToAddress($address);

		foreach ($rates as $rateId => $rate) {
			if ($this->validator->isDistanceBasedShippingRate($rate)) {
				if (!$validation['available']) {
					// Mark as unavailable
					$this->markRateAsUnavailable($rate, $validation['reason'], $validation['distance']);
					$rates[$rateId] = $rate;
				} else {
					// Update with distance-based pricing
					$rates[$rateId] = $this->updateRateWithDistance($rate, $validation['distance']);
				}
			}
		}

		// Force default method if no distance-based methods are available
		if (!$validation['available']) {
			$this->methodSelector->forceDefaultShippingMethod($rates);
		}

		return $rates;
	}

	/**
	 * Wrap unavailable shipping labels with span
	 */
	public function wrapUnavailableLabel($label, $method)
	{
		$meta = $method->get_meta_data();

		// Check if method is marked as unavailable
		if (isset($meta['_outcomer_available']) && !$meta['_outcomer_available']) {
			$reason = $meta['_outcomer_error_reason'] ?? 'unknown';

			return sprintf('<span class="outcomer-shipping-unavailable outcomer-reason-%s">%s</span>', $reason, $label);
		}

		return $label;
	}

	/**
	 * Update rate with distance-based price
	 */
	private function updateRateWithDistance(WC_Shipping_Rate $rate, float $distance): WC_Shipping_Rate
	{
		$originalLabel = $rate->get_label();

		// Get shipping class based on distance
		$shippingClassId = $this->distanceCalculator->getShippingClassByDistance($distance);

		// Get price for this shipping class
		$calculatedPrice = $this->distanceCalculator->getPriceByDistance($distance);

		if (false !== $calculatedPrice) {
			$rate->set_cost($calculatedPrice);
		}

		// Update rate label to include distance and zone info
		$zone = $this->distanceCalculator->getDeliveryZone($distance);

		$newLabel = sprintf(
			'%s (%s: %s, %s: %.1fkm)',
			$originalLabel,
			__('zone', 'outcomer-delivery-distance'),
			$zone,
			__('distance', 'outcomer-delivery-distance'),
			$distance
		);
		$rate->set_label($newLabel);

		// Add meta data
		$rate->add_meta_data('_outcomer_distance', $distance);
		$rate->add_meta_data('_outcomer_shipping_class_id', $shippingClassId);
		$rate->add_meta_data('_outcomer_calculated_price', $calculatedPrice);
		$rate->add_meta_data('_outcomer_zone', $zone);
		$rate->add_meta_data('_outcomer_available', true);

		return $rate;
	}

	/**
	 * Mark rate as unavailable
	 */
	private function markRateAsUnavailable(WC_Shipping_Rate $rate, string $reason, ?float $distance = null): void
	{
		$originalLabel = $rate->get_label();

		switch ($reason) {
			case 'no_address':
				$newLabel = sprintf(
					'%s (%s)',
					$originalLabel,
					__('No address provided', 'outcomer-delivery-distance')
				);
				break;

			case 'geocoding_failed':
				$newLabel = sprintf(
					'%s (%s)',
					$originalLabel,
					__('Address validation failed', 'outcomer-delivery-distance')
				);
				break;

			case 'distance_too_far':
				$newLabel = sprintf(
					'%s (%s: %s, %s: %.1fkm)',
					$originalLabel,
					__('zone', 'outcomer-delivery-distance'),
					__('unavailable', 'outcomer-delivery-distance'),
					__('distance', 'outcomer-delivery-distance'),
					$distance ?? 0
				);
				break;

			default:
				$newLabel = $originalLabel;
				break;
		}

		// Don't wrap here - will be wrapped via filter
		$rate->set_label($newLabel);
		$rate->set_cost(0);
		$rate->add_meta_data('_outcomer_available', false);
		$rate->add_meta_data('_outcomer_error_reason', $reason);

		if (!is_null($distance)) {
			$rate->add_meta_data('_outcomer_distance', $distance);
		}
	}

	/**
	 * Get address from package
	 */
	private function getAddressFromPackage(array $package): string
	{
		$destination  = $package['destination'] ?? [];
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

		return trim(implode(', ', array_filter($addressParts)));
	}
}
