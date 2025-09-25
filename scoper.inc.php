<?php

/**
 * PHP-Scoper configuration for dependency prefixing (compatible with 0.18.x).
 *
 * Goal:
 * - Prefix ALL third-party dependencies to avoid version conflicts with other plugins/themes.
 * - Output to vendor-scoped/ with its own autoloader.
 * - We do NOT exclude Composer or Google namespaces — our code resolves classes
 *   dynamically to the prefixed versions when present.
 */

use Isolated\Symfony\Component\Finder\Finder;

return [
	// Unique prefix for third-party code.
	'prefix' => 'WpmudevPluginTest\\Vendor',

	// Scope everything in vendor/
	'finders' => [
		Finder::create()->files()->in(__DIR__ . '/vendor'),
	],

	// Keep globals unexposed by default.
	'expose-global-classes'   => false,
	'expose-global-functions' => false,
	'expose-global-constants' => false,

	// Output directory for prefixed code.
	'output-dir' => 'vendor-scoped',

	// No additional patchers at this time.
	'patchers' => [],
];