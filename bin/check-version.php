<?php
/**
 * Verify that all the plugin versions agree, and that they match the git tag
 *
 * @package TAAS_Core
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$plugin_dir = dirname( __DIR__ ) . '/htdocs/wp-content/plugins/taas-core';

$plugin_file = $plugin_dir . '/taas-core.php';
$version_file = $plugin_dir . '/includes/class-taas-version.php';

$version_errors = array();

$plugin_src  = (string) file_get_contents( $plugin_file );
$version_src = (string) file_get_contents( $version_file );

// Fetch version from the plugin header docblock
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $plugin_src, $m );
$header_version = $m[1] ?? '';

// Fetch version from the TAAS_CORE_VERSION define
preg_match( "/define\(\s*'TAAS_CORE_VERSION',\s*'([^']+)'/", $plugin_src, $m );
$constant_version = $m[1] ?? '';

// Tag portion rebuilt from the TAAS_Version class constants
$segments = array();
foreach ( array( 'CONTRACT', 'WP_MAJOR', 'PHP_MAJOR', 'MYSQL_MAJOR', 'APACHE_MAJOR' ) as $name ) {
	if ( preg_match( '/const\s+' . $name . '\s*=\s*(\d+)\s*;/', $version_src, $m ) ) {
		$segments[] = $m[1];
	} else {
		$version_errors[] = "Could not read TAAS_Version::{$name}.";
	}
}
$class_tag = implode( '.', $segments );

if ( '' === $header_version ) {
	$version_errors[] = 'Could not read Version from the plugin header.';
}

if ( '' === $constant_version ) {
	$version_errors[] = 'Could not read TAAS_CORE_VERSION.';
}

if ( $header_version !== $constant_version ) {
	$version_errors[] = "Header version ({$header_version}) does not match TAAS_CORE_VERSION ({$constant_version}).";
}

// Rewrite committed build segment in CI at release to 0
$parts = explode( '.', $header_version );

if ( 6 !== count( $parts ) ) {
	$version_errors[] = "Version must have 6 segments (CONTRACT.WP.PHP.MYSQL.APACHE.BUILD), got {$header_version}.";
} else {
	if ( '0' !== $parts[5] ) {
		$version_errors[] = "Committed build segment must be 0, got {$parts[5]}. Do not hand-edit the build number.";
	}

	$header_tag = implode( '.', array_slice( $parts, 0, 5 ) );

	if ( $header_tag !== $class_tag ) {
		$version_errors[] = "Header baseline ({$header_tag}) does not match TAAS_Version constants ({$class_tag}).";
	}
}

// Compare against a git tag passed as argv[1]
if ( isset( $argv[1] ) && '' !== $argv[1] ) {
	$release_tag = ltrim( $argv[1], 'v' );

	if ( $release_tag !== $class_tag ) {
		$version_errors[] = "Git tag ({$release_tag}) does not match the version baseline ({$class_tag}). Tags carry the 5 baseline segments only; the build is appended by CI.";
	}
}

if ( ! empty( $version_errors ) ) {
	fwrite( STDERR, "Version check failed:\n" );
	foreach ( $version_errors as $version_error ) {
		fwrite( STDERR, "  - {$version_error}\n" );
	}
	exit( 1 );
}

echo "Version check passed: {$header_version} (tag {$class_tag})\n";
exit( 0 );
