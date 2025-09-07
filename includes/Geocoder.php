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
 * Google Geocoding API integration
 */
class Geocoder
{
	private string $apiKey;
	private int $cacheTime = 900; // 15 minutes

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->apiKey = ODD_GOOGLE_API_KEY_SERVER;
	}

	/**
	 * Geocode address to get coordinates
	 */
	public function geocodeAddress(string $address): array|false
	{
		$cacheKey = 'odd_geocode_'.md5($address);
		$cached   = get_transient($cacheKey);

		if (false !== $cached) {
			return $cached;
		}

		$url = add_query_arg([
			'address' => urlencode($address),
			'key'     => $this->apiKey,
			'region'  => 'cz',
		], 'https://maps.googleapis.com/maps/api/geocode/json');

		$response = wp_remote_get($url, [
			'timeout' => 10,
			'headers' => ['Accept' => 'application/json'],
		]);

		if (is_wp_error($response)) {
			error_log('Geocoding API error: '.$response->get_error_message());

			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!$data || $data['status'] !== 'OK' || empty($data['results'])) {
			error_log('Geocoding API response error: '.($data['status'] ?? 'Unknown'));

			return false;
		}

		$result   = $data['results'][0];
		$location = $result['geometry']['location'];

		$geocodeResult = [
			'lat'               => (float) $location['lat'],
			'lng'               => (float) $location['lng'],
			'formatted_address' => $result['formatted_address'],
			'place_id'          => $result['place_id'],
		];

		// Cache the result
		set_transient($cacheKey, $geocodeResult, $this->cacheTime);

		return $geocodeResult;
	}
}
