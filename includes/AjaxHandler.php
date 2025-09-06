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
 * AJAX endpoints for frontend communication
 */
class AjaxHandler
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
	 * Initialize AJAX hooks
	 */
	public function init(): void
	{
		add_action('wp_ajax_outcomer_get_predictions', [$this, 'getPlacePredictions']);
		add_action('wp_ajax_nopriv_outcomer_get_predictions', [$this, 'getPlacePredictions']);

		add_action('wp_ajax_outcomer_calculate_delivery', [$this, 'calculateDeliveryFromCoordinates']);
		add_action('wp_ajax_nopriv_outcomer_calculate_delivery', [$this, 'calculateDeliveryFromCoordinates']);
	}

	/**
	 * Get place predictions for autocomplete
	 */
	public function getPlacePredictions(): void
	{
		if (!$this->verifyNonce()) {
			wp_die('Security check failed', 'Error', ['response' => 403]);
		}

		$input = sanitize_text_field($_POST['input'] ?? '');

		if (strlen($input) < 3) {
			wp_send_json_error('Input too short');
		}

		$predictions = $this->fetchPlacePredictions($input);

		if (false === $predictions) {
			wp_send_json_error('Failed to fetch predictions');
		}

		wp_send_json_success($predictions);
	}

	/**
	 * Calculate delivery cost from coordinates
	 */
	public function calculateDeliveryFromCoordinates(): void
	{
		if (!$this->verifyNonce()) {
			wp_die('Security check failed', 'Error', ['response' => 403]);
		}

		$address = sanitize_text_field($_POST['address'] ?? '');
		$lat     = floatval($_POST['lat'] ?? 0);
		$lng     = floatval($_POST['lng'] ?? 0);

		if (empty($address) || !$lat || !$lng) {
			wp_send_json_error('Address and coordinates are required');
		}

		// Calculate distance and delivery cost
		$distance = $this->distanceCalculator->calculateDistanceFromStore($lat, $lng);
		$price    = $this->distanceCalculator->getPriceByDistance($distance);
		$zone     = $this->distanceCalculator->getDeliveryZone($distance);

		if (false === $price) {
			wp_send_json_error('Delivery not available for this address (distance: '.round($distance, 2).'km)');
		}

		wp_send_json_success([
			'distance'          => round($distance, 2),
			'price'             => $price,
			'zone'              => $zone,
			'coordinates'       => [
				'lat' => $lat,
				'lng' => $lng,
			],
			'formatted_address' => $address,
		]);
	}

	/**
	 * Fetch place predictions from Google Places API
	 */
	private function fetchPlacePredictions(string $input): array|false
	{
		$cacheKey = 'odd_predictions_'.md5($input);
		$cached   = get_transient($cacheKey);

		if (false !== $cached) {
			return $cached;
		}

		$url = add_query_arg([
			'input'      => urlencode($input),
			'key'        => ODD_GOOGLE_API_KEY,
			'types'      => 'address',
			'components' => 'country:'.implode('|country:', ODD_COUNTRY_RESTRICT),
			'language'   => get_locale(),
		], 'https://maps.googleapis.com/maps/api/place/autocomplete/json');

		$response = wp_remote_get($url, [
			'timeout' => 10,
			'headers' => ['Accept' => 'application/json'],
		]);

		if (is_wp_error($response)) {
			error_log('Places Autocomplete API error: '.$response->get_error_message());

			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!$data || $data['status'] !== 'OK') {
			error_log('Places Autocomplete API response error: '.($data['status'] ?? 'Unknown'));

			return false;
		}

		$predictions = [];
		foreach ($data['predictions'] as $prediction) {
			$predictions[] = [
				'place_id'              => $prediction['place_id'],
				'description'           => $prediction['description'],
				'structured_formatting' => $prediction['structured_formatting'] ?? [],
			];
		}

		// Cache the result for 5 minutes
		set_transient($cacheKey, $predictions, 300);

		return $predictions;
	}

	/**
	 * Verify AJAX nonce
	 */
	private function verifyNonce(): bool
	{
		return (bool) wp_verify_nonce($_POST['nonce'] ?? '', 'outcomer_delivery_nonce');
	}
}
