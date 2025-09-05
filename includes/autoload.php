<?php
/**
 * PSR-4 Autoloader for Outcomer Delivery Distance plugin
 */

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
	$file = ODD_PLUGIN_DIR . 'includes/' . $className . '.php';
	
	// Load the class file if it exists
	if (file_exists($file)) {
		require_once $file;
	}
});