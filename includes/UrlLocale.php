<?php
/**
 * Front-end locale from URL: /nl/, /nl/shop, … → nl_NL, otherwise en_US.
 * If home_url is already …/nl/, every path under that home is Dutch.
 * AJAX uses HTTP_REFERER when the request path has no language prefix.
 */
defined( 'ABSPATH' ) || exit;

/**
 * Path used for language detection (request URI or trusted AJAX referer).
 */
function stwg_get_path_for_language_detection(): ?string {
	if ( wp_doing_ajax() && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$ref       = wp_unslash( (string) $_SERVER['HTTP_REFERER'] );
		$ref_host  = wp_parse_url( $ref, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( $ref_host && $home_host && strtolower( (string) $ref_host ) === strtolower( (string) $home_host ) ) {
			$p = wp_parse_url( $ref, PHP_URL_PATH );
			return is_string( $p ) ? $p : null;
		}
	}

	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return null;
	}

	$p = wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
	return is_string( $p ) ? $p : null;
}

/**
 * Trimmed path from home_url( '/' ), e.g. "nl" for https://example.com/nl/
 */
function stwg_trimmed_home_path(): string {
	$h = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	return is_string( $h ) ? trim( $h, '/' ) : '';
}

/**
 * Dutch URLs: …/nl/, …/nl/shop, or any request when home is already …/nl/
 * (Polylang-style so /nl/shop is not misread as English after stripping home).
 */
function stwg_is_dutch_url_path( string $request_path ): bool {
	$path      = trim( $request_path, '/' );
	$home_path = stwg_trimmed_home_path();

	// e.g. home is https://caffebello.com/nl — storefront is Dutch.
	if ( $home_path === 'nl' ) {
		return true;
	}

	$segments = $path === '' ? [] : explode( '/', $path );
	if ( isset( $segments[0] ) && $segments[0] === 'nl' ) {
		return true;
	}

	if ( $home_path !== '' && str_starts_with( $path, $home_path ) ) {
		$rest = trim( substr( $path, strlen( $home_path ) ), '/' );
		$sub  = $rest === '' ? [] : explode( '/', $rest );
		if ( isset( $sub[0] ) && $sub[0] === 'nl' ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether to apply URL-based locale for this request.
 */
function stwg_use_url_based_locale(): bool {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}

	if ( wp_installing() ) {
		return false;
	}

	// wp-admin screens: keep WordPress / user locale.
	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}

	return true;
}

/**
 * @return string|null nl_NL, en_US, or null to leave locale unchanged.
 */
function stwg_resolve_locale_from_url(): ?string {
	if ( ! stwg_use_url_based_locale() ) {
		return null;
	}

	$path = stwg_get_path_for_language_detection();
	if ( $path === null ) {
		return null;
	}

	return stwg_is_dutch_url_path( $path ) ? 'nl_NL' : 'en_US';
}

/**
 * @param mixed $locale
 * @return mixed
 */
function stwg_filter_locale_from_url( $locale ) {
	$resolved = stwg_resolve_locale_from_url();
	return $resolved !== null ? $resolved : $locale;
}

add_filter( 'locale', 'stwg_filter_locale_from_url', 1000 );
add_filter( 'determine_locale', 'stwg_filter_locale_from_url', 1000 );
