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

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Manages shipping method selection and validation
 */
class ShippingMethodSelector
{
	private ShippingValidator $validator;

	/**
	 * Constructor.
	 */
	public function __construct(ShippingValidator $validator)
	{
		$this->validator = $validator;
	}

	/**
	 * Initialize hooks for shipping method selection
	 */
	public function init(): void
	{
		add_action('woocommerce_review_order_before_shipping', [$this, 'validateCurrentSelection']);
	}

	/**
	 * Validate current shipping selection before rendering
	 */
	public function validateCurrentSelection(): void
	{
		$chosenMethods = WC()->session->get('chosen_shipping_methods');
		$packages      = WC()->shipping()->get_packages();

		if (empty($chosenMethods) || empty($packages)) {
			return;
		}

		$needsUpdate = false;

		foreach ($chosenMethods as $key => $chosenMethod) {
			if (!isset($packages[$key]['rates'][$chosenMethod])) {
				continue;
			}

			$rate = $packages[$key]['rates'][$chosenMethod];

			// Check if this is a distance-based shipping method that's unavailable
			if ($this->validator->isDistanceBasedShippingRate($rate) &&
				$this->validator->isRateUnavailable($rate)) {
				// Switch to default method
				$defaultMethod       = $this->getDefaultShippingMethod($packages[$key]['rates']);
				$chosenMethods[$key] = $defaultMethod;
				$needsUpdate         = true;
			}
		}

		// Update session if needed
		if ($needsUpdate) {
			WC()->session->set('chosen_shipping_methods', $chosenMethods);
		}
	}

	/**
	 * Get default shipping method from available rates
	 */
	public function getDefaultShippingMethod(array $rates): ?string
	{
		if (empty(ODD_DEFAULT_SHIPPING_INSTANCE_IDS)) {
			// No default configured, return first available
			return key($rates);
		}

		// Look for default shipping method
		foreach (ODD_DEFAULT_SHIPPING_INSTANCE_IDS as $defaultId) {
			foreach ($rates as $rateId => $rate) {
				if (strpos($rateId, ':'.$defaultId) !== false) {
					return $rateId;
				}
			}
		}

		// Default not found, return first available
		return key($rates);
	}

	/**
	 * Force selection of default shipping method
	 */
	public function forceDefaultShippingMethod(array $rates): void
	{
		if (empty(ODD_DEFAULT_SHIPPING_INSTANCE_IDS)) {
			return;
		}

		$defaultInstanceId = ODD_DEFAULT_SHIPPING_INSTANCE_IDS[0];
		foreach ($rates as $rateId => $rate) {
			if (strpos($rateId, ':'.$defaultInstanceId) !== false) {
				// IMMEDIATELY set the chosen method - no delays, no hooks
				WC()->session->set('chosen_shipping_methods', [$rateId]);
				$_POST['shipping_method'] = [$rateId];
				break;
			}
		}
	}
}
