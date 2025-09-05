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

if (!defined('ABSPATH')) {
	exit;
}

spl_autoload_register(function ($class) {
	$namespace = 'OutcomerDelivery\\';

	// Check if the class uses our namespace
	if (strpos($class, $namespace) !== 0) {
		return;
	}

	// Remove namespace from class name
	$className = str_replace($namespace, '', $class);

	// Convert class name to file path
	$file = ODD_PLUGIN_DIR.'includes/'.$className.'.php';

	// Load the class file if it exists
	if (file_exists($file)) {
		require_once $file;
	}
});
