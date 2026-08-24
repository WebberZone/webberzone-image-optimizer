<?php
/**
 * WebberZone Image Optimizer Options API.
 *
 * @package WebberZone\Image_Optimizer
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Load the Options API when needed.
if ( ! class_exists( '\WebberZone\Image_Optimizer\Options_API' ) ) {
	require_once __DIR__ . '/class-options-api.php';
}

/**
 * Get all plugin settings.
 *
 * @since 1.0.0
 * @return array Settings
 */
function wzio_get_settings() {
	return \WebberZone\Image_Optimizer\Options_API::get_settings();
}

/**
 * Get an option or its default.
 *
 * @since 1.0.0
 *
 * @param string $key            Option to fetch.
 * @param mixed  $default_value  Default option.
 *
 * @return mixed The option value or the default value if the option does not exist.
 */
function wzio_get_option( $key = '', $default_value = null ) {
	return \WebberZone\Image_Optimizer\Options_API::get_option( $key, $default_value );
}

/**
 * Update an option.
 *
 * @since 1.0.0
 *
 * @param  string          $key   The Key to update.
 * @param  string|bool|int $value The value to set the key to.
 * @return boolean   True if updated, false if not.
 */
function wzio_update_option( $key = '', $value = false ) {
	return \WebberZone\Image_Optimizer\Options_API::update_option( $key, $value );
}

/**
 * Remove an option.
 *
 * @since 1.0.0
 *
 * @param  string $key The Key to update.
 * @return boolean   True if updated, false if not.
 */
function wzio_delete_option( $key = '' ) {
	return \WebberZone\Image_Optimizer\Options_API::delete_option( $key );
}

/**
 * Default settings.
 *
 * @since 1.0.0
 *
 * @return array Default settings
 */
function wzio_settings_defaults() {
	return \WebberZone\Image_Optimizer\Options_API::get_settings_defaults();
}

/**
 * Get the default option for a specific key
 *
 * @since 1.0.0
 *
 * @param string $key Key of the option to fetch.
 * @return mixed
 */
function wzio_get_default_option( $key = '' ) {
	return \WebberZone\Image_Optimizer\Options_API::get_default_option( $key );
}

/**
 * Reset settings.
 *
 * @since 1.0.0
 * @return bool Success status.
 */
function wzio_settings_reset() {
	return \WebberZone\Image_Optimizer\Options_API::reset_settings();
}

/**
 * Update all settings at once.
 *
 * @since 1.0.0
 *
 * @param array $settings Settings array to save.
 * @param bool  $merge    Whether to merge with existing settings. Default true.
 * @param bool  $autoload Whether to autoload the option. Default true.
 * @return bool True if settings were updated, false otherwise.
 */
function wzio_update_settings( array $settings, bool $merge = true, bool $autoload = true ) {
	return \WebberZone\Image_Optimizer\Options_API::update_settings( $settings, $merge, $autoload );
}
